# @title       Monday morning briefing
# @summary     Writes one self-contained HTML page for the start of the week: open tickets by priority with those breaching or close to breaching SLA, changes in the next 7 days, open service incidents, this morning's checks, background job health and contracts ending soon.
# @module      tickets
# @changes     no
# @level       advanced
# @endpoints   GET /tickets, GET /tickets/{id}/sla, GET /changes, GET /service-status/incidents, GET /morning-checks/board, GET /jobs/health, GET /contracts
# @permissions tickets.read, ticket_sla.read, changes.read, service_incidents.read, morning_check_results.read, job_health.read, contracts.read
#
# WHAT IT SHOWS: one report from seven modules. Each section is one or two
# calls, and the script turns the answers into a single HTML file you can open,
# email or put on a shared drive - no internet needed to view it (no external
# styles, scripts or fonts). Every value from FreeITSM is HTML-escaped before it
# goes into the page, so a ticket subject like "<script>" shows as text.
#
#   1. Open tickets       GET /tickets?state=open (paged), counted by priority.
#   2. SLA                GET /tickets/{id}/sla for open tickets that HAVE a
#                         priority (no priority = no SLA), oldest first, at most
#                         --max-sla-checks of them - one call each, and every
#                         call counts towards the key's rate limit.
#   3. Changes            GET /changes?state=open with work starting in the next
#                         --change-days days.
#   4. Service incidents  GET /service-status/incidents?state=open.
#   5. Morning checks     GET /morning-checks/board - today's checks and results.
#   6. Background jobs    GET /jobs/health - SLA emails, webhooks, mail import...
#   7. Contracts          GET /contracts?expiring_within_days=--contract-days.
#
# "At risk" means a target that is not yet met or missed but has used
# --at-risk-percent of its time or more (80 by default - the same as FreeITSM's
# own default warning threshold in Tickets -> Settings -> SLA). SLA minutes are
# WORKING minutes on the ticket's SLA calendar, and paused statuses ("On Hold")
# stop the clock - FreeITSM works all of that out; the script only reads it.
#
# Run it - every Monday at 07:30 from cron or Task Scheduler, say (Python 3.8 or
# later, standard library only):
#   python monday-briefing.py --url https://helpdesk.example.com
#   python monday-briefing.py --url https://helpdesk.example.com --out-file /srv/share/briefing.html
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass --key-file). A read-only key is
# enough, with the seven permissions above. Job health is install-wide, so it
# also needs a key (and an analyst) that reaches every company - an
# administrator's key, in practice. Everything else shows only what the key's
# companies may see.

import argparse
import html
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

# Names and subjects can hold any character - an emoji, Chinese. When the output
# is redirected to a file on Windows, Python writes in the old code page and
# would crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

today = date.today()
parser = argparse.ArgumentParser(description="Write a Monday morning briefing page from FreeITSM.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Where to write the page. Defaults to monday-briefing-<date>.html here.
parser.add_argument("--out-file", default=f"monday-briefing-{today.isoformat()}.html")
# Check the SLA of at most this many open tickets (oldest first).
parser.add_argument("--max-sla-checks", type=int, default=200)
# A target this far used (percent) or more counts as "at risk".
parser.add_argument("--at-risk-percent", type=int, default=80)
# Changes whose work starts within this many days from today.
parser.add_argument("--change-days", type=int, default=7)
# Contracts ending within this many days from today.
parser.add_argument("--contract-days", type=int, default=60)
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")

# ---- The key --------------------------------------------------------------------
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()
APP_URL = args.url.rstrip("/") + "/"
BASE = APP_URL + "api/v1"


# ---- Calling the API ------------------------------------------------------------
def api(path):
    """GET a path and return the whole reply ({"data", "meta"}); stop with
    FreeITSM's own error message on failure, e.g. 403 "This key lacks
    changes.read"."""
    request = urllib.request.Request(
        BASE + path, headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"GET {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def get_all(path, params):
    """Every page of a paged list: {"data": [...], "meta": {"total_pages": 3}}.
    100 is the most a page can hold."""
    rows, page = [], 1
    while True:
        reply = api(f"{path}?" + urllib.parse.urlencode({**params, "per_page": 100, "page": page}))
        rows += reply["data"]
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


# ---- Small helpers for the page ---------------------------------------------------
def e(text):
    """Escape any text for HTML. Every value from the API goes through this."""
    return html.escape("" if text is None else str(text))


def safe_colour(c):
    """A colour from FreeITSM goes into a style attribute, so only a real #hex
    colour is used; anything else falls back to grey."""
    return c if isinstance(c, str) and re.fullmatch(r"#[0-9A-Fa-f]{3,8}", c) else "#9ca3af"


def minutes(m):
    """135 working minutes -> "2h 15m"; negative = over by that much."""
    sign, m = ("-" if m < 0 else ""), abs(int(m))
    return f"{sign}{m // 60}h {m % 60}m" if m >= 60 else f"{sign}{m}m"


def when(iso):
    """An ISO date and time in UTC ("...Z") -> this computer's local time."""
    if not iso:
        return ""
    d = datetime.strptime(iso, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc).astimezone()
    return f"{d.strftime('%a')} {d.day} {d.strftime('%b %H:%M')}"   # d.day: "7 Nov", not "07 Nov"


def name_of(ref, empty=""):
    return ref["name"] if ref else empty


def table(rows, columns, cells, empty):
    """A <table> from a list of rows: columns are the headings, cells a function
    that turns one row into a list of ALREADY-ESCAPED cell HTML."""
    if not rows:
        return f'<p class="none">{e(empty)}</p>'
    out = ["<table><thead><tr>", *(f"<th>{e(c)}</th>" for c in columns), "</tr></thead><tbody>"]
    for r in rows:
        out.append("<tr>" + "".join(f"<td>{cell}</td>" for cell in cells(r)) + "</tr>")
    out.append("</tbody></table>")
    return "".join(out)


# ---- 1. Open tickets, by priority ---------------------------------------------------
# sort=id gives oldest first and keeps the pages steady while tickets arrive.
print("Reading open tickets...")
open_tickets = get_all("/tickets", {"state": "open", "sort": "id"})
by_priority = Counter(name_of(t["priority"], "(no priority)") for t in open_tickets).most_common()

# ---- 2. SLA for the open tickets ------------------------------------------------------
# GET /tickets/{id}/sla answers {enabled, reason_disabled, response, resolution};
# each target is {target_minutes, elapsed_minutes, remaining_minutes, percent,
# breached, achieved_at} or None when the priority has no such target. A target
# with achieved_at set is finished (responded to / resolved): it can still have
# been breached, but it can no longer be "at risk".
candidates = [t for t in open_tickets if t["priority"]]
to_check = candidates[:args.max_sla_checks]
breached, at_risk = [], []
not_measured = Counter()      # reason -> how many, e.g. "Priority has no SLA target configured"
for i, t in enumerate(to_check, 1):
    print(f"  checking SLA {i}/{len(to_check)} {t['ticket_number']}", end="\r", file=sys.stderr)
    ticket_id = t["id"]
    sla = api(f"/tickets/{ticket_id}/sla")["data"]
    if not sla["enabled"]:
        not_measured[sla.get("reason_disabled") or "not measured"] += 1
        continue
    missed, close = [], []
    for kind in ("response", "resolution"):
        target = sla[kind]
        if not target:
            continue
        if target["breached"]:
            missed.append(f"{kind} over by {minutes(-target['remaining_minutes'])}")
        elif not target["achieved_at"] and target["percent"] >= args.at_risk_percent:
            close.append(f"{kind} {minutes(target['remaining_minutes'])} left ({round(target['percent'])}%)")
    # A ticket with a missed target goes in the breached list only, but its
    # detail still says if the other target is close.
    if missed:
        breached.append((t, ", ".join(missed + close)))
    elif close:
        at_risk.append((t, ", ".join(close)))
print(" " * 60, end="\r", file=sys.stderr)   # clear the progress line
unchecked = len(candidates) - len(to_check)

# ---- 3. Changes starting in the next --change-days days -------------------------------
# work_start_from / work_start_to are WALL-CLOCK times - the time as it shows on
# the change, no "Z" - so plain dates are right here. _to is "before", so the
# day after the window ends.
print("Reading changes, incidents, checks, jobs and contracts...")
changes = get_all("/changes", {
    "state": "open", "sort": "work_start_at",
    "work_start_from": today.isoformat(),
    "work_start_to": (today + timedelta(days=args.change_days + 1)).isoformat(),
})

# ---- 4. Open service incidents -------------------------------------------------------
incidents = get_all("/service-status/incidents", {"state": "open"})

# ---- 5. Today's morning checks --------------------------------------------------------
# One reply, not paged: {date, checks: [{name, due, result: {status: {label,
# colour}, notes} or None}]}. A check that is not due today is still listed; the
# briefing shows the due ones and any that were recorded anyway.
board = api("/morning-checks/board")["data"]
checks = [c for c in board["checks"] if c["due"] or c["result"]]
checks_not_done = sum(1 for c in checks if not c["result"])

# ---- 6. Background job health ----------------------------------------------------------
# status per job: healthy, never_seen, overdue, failing, partial, stuck, not_needed
# or not_used (and unknown). The last two mean "this install does not use it",
# so they are left out.
jobs = api("/jobs/health")["data"]
job_rows = [j for j in jobs["jobs"] if j["status"] not in ("healthy", "not_needed", "not_used")]

# ---- 7. Contracts ending soon ----------------------------------------------------------
# FreeITSM works the window out against today in its own time zone, and only
# counts ACTIVE contracts.
contracts = get_all("/contracts", {"expiring_within_days": args.contract_days, "sort": "contract_end"})


# ---- Build the page ------------------------------------------------------------------
def ticket_link(t):
    return f'<a href="{e(APP_URL + "tickets/?ticket_id=" + str(t["id"]))}">{e(t["ticket_number"])}</a>'


def dot(colour):
    return f'<span class="dot" style="background:{safe_colour(colour)}"></span>'


def wall_clock(value):
    """Change schedule times are wall-clock ("2026-11-24T19:00:00", no Z): shown as they are."""
    return value.replace("T", " ")[:16] if value else ""


def sla_cells(row):
    t, detail = row
    return [ticket_link(t), e(name_of(t["priority"])), e(name_of(t["assigned_analyst"], "(unassigned)")),
            e(detail), e(t["subject"])]


def change_cells(c):
    link = f'<a href="{e(APP_URL + "change-management/?change_id=" + str(c["id"]))}">{e(c["change_number"])}</a>'
    return [link, e(wall_clock(c["schedule"]["work_start_at"])), e(wall_clock(c["schedule"]["work_end_at"])),
            e(c["title"]), e(name_of(c["status"])), e(c["risk"]["level"]), e(name_of(c["assigned_to"]))]


def incident_cells(s):
    services = ", ".join(f"{v['name']} ({v['impact']['name']})" if v["impact"] else v["name"] for v in s["services"])
    status = dot(s["status"]["colour"]) + e(s["status"]["name"]) if s["status"] else ""
    return [e(s["title"]), status, e(services), e(when(s["created_at"])), e(s["comment"])]


def check_cells(c):
    r = c["result"]
    if not r:
        result = "<em>not done yet</em>"
    elif r["status"]:
        result = dot(r["status"]["colour"]) + e(r["status"]["label"])
    else:
        result = e(r["orphan_label"])
    return [e(c["name"]), result, e(r["notes"]) if r else ""]


def job_cells(j):
    detail = "; ".join(p.get("message") or p["code"] for p in j["problems"])
    return [e(j["name"]), e(j["status"]), e(j["affects"]), e(when(j["last_scheduled_success_at"])), e(detail)]


def contract_cells(c):
    customer = c.get("customer") or {}
    party = (c["supplier"]["name"] if c.get("supplier") else
             customer["company"]["name"] if customer.get("company") else
             customer["person"]["name"] if customer.get("person") else "")
    return [e(c["contract_number"]), e(c["dates"]["end"]), e(c["title"]), e(party), e(name_of(c["owner"]))]


tiles = [
    ("Open tickets", len(open_tickets), ""),
    ("SLA breached", len(breached), "bad" if breached else ""),
    ("SLA at risk", len(at_risk), "warn" if at_risk else ""),
    (f"Changes, next {args.change_days} days", len(changes), ""),
    ("Open incidents", len(incidents), "bad" if incidents else ""),
    ("Checks not done", checks_not_done, "warn" if checks_not_done else ""),
    ("Jobs needing attention", len(job_rows), "bad" if job_rows else ""),
    (f"Contracts ending, {args.contract_days} days", len(contracts), ""),
]
note = f"Checked the SLA of {len(to_check)} open ticket(s) with a priority."
for reason, count in not_measured.most_common():
    note += f" Not measured: {count} - {reason}."
if unchecked:
    note += f" {unchecked} newer ticket(s) not checked - raise --max-sla-checks to include them."
sla_columns = ["Ticket", "Priority", "Analyst", "SLA", "Subject"]
now = datetime.now()

page = f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monday briefing - {e(f"{today.day} {today.strftime('%B %Y')}")}</title>
<style>
body {{ font-family: Segoe UI, Arial, sans-serif; color: #1f2937; background: #f3f4f6; margin: 0; padding: 16px; }}
main {{ max-width: 1100px; margin: 0 auto; }}
h1 {{ font-size: 22px; margin: 0 0 4px; }} h2 {{ font-size: 17px; margin: 0 0 10px; }}
.sub {{ color: #6b7280; margin: 0 0 16px; }}
section {{ background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin: 0 0 14px; overflow-x: auto; }}
.tiles {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 0 0 14px; }}
.tile {{ background: #fff; border: 1px solid #e5e7eb; border-left: 4px solid #9ca3af; border-radius: 8px; padding: 10px 12px; }}
.tile b {{ display: block; font-size: 24px; }} .tile span {{ color: #6b7280; font-size: 13px; }}
.tile.bad {{ border-left-color: #dc2626; }} .tile.warn {{ border-left-color: #f59e0b; }}
table {{ border-collapse: collapse; width: 100%; font-size: 14px; }}
th, td {{ text-align: left; padding: 6px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }}
th {{ color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; }}
.none {{ color: #6b7280; margin: 0; }} .note {{ color: #6b7280; font-size: 13px; margin: 8px 0 0; }}
.dot {{ display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }}
h3 {{ font-size: 14px; margin: 14px 0 6px; }} a {{ color: #2563eb; }}
</style></head><body><main>
<h1>Monday briefing</h1>
<p class="sub">{e(f"{now.strftime('%A')} {now.day} {now.strftime('%B %Y, %H:%M')}")} - {e(APP_URL)}</p>
<div class="tiles">{"".join(f'<div class="tile {cls}"><b>{e(n)}</b><span>{e(label)}</span></div>' for label, n, cls in tiles)}</div>
<section><h2>Open tickets</h2>
{table(by_priority, ["Priority", "Open"], lambda p: [e(p[0]), e(p[1])], "No open tickets.")}
<h3>SLA breached ({len(breached)})</h3>
{table(breached, sla_columns, sla_cells, "None.")}
<h3>SLA at risk - {args.at_risk_percent}% or more of the time used ({len(at_risk)})</h3>
{table(at_risk, sla_columns, sla_cells, "None.")}
<p class="note">{e(note)} Times are working time on each ticket's SLA calendar.</p></section>
<section><h2>Changes - work starting in the next {args.change_days} days</h2>
{table(changes, ["Change", "Starts", "Ends", "Title", "Status", "Risk", "Assigned to"], change_cells, "No changes scheduled.")}</section>
<section><h2>Open service incidents</h2>
{table(incidents, ["Incident", "Status", "Services affected", "Since", "Latest comment"], incident_cells, "No open incidents.")}</section>
<section><h2>Morning checks - {e(board["date"])}</h2>
{table(checks, ["Check", "Result", "Notes"], check_cells, "No checks due today.")}</section>
<section><h2>Background jobs - {e(jobs["status"])}</h2>
{table(job_rows, ["Job", "Status", "Affects", "Last scheduled success", "Detail"], job_cells, "Every job in use is healthy.")}</section>
<section><h2>Contracts ending in the next {args.contract_days} days</h2>
{table(contracts, ["Contract", "Ends", "Title", "With", "Owner"], contract_cells, "No contracts ending.")}</section>
<p class="note">Made by the FreeITSM monday-briefing script. Read-only: nothing was changed.</p>
</main></body></html>
"""

# UTF-8; <meta charset="utf-8"> tells the browser.
out = Path(args.out_file).resolve()
out.write_text(page, encoding="utf-8")
print(f"Briefing saved to {out}")
print(f"  {len(open_tickets)} open ticket(s), {len(breached)} breached, {len(at_risk)} at risk; {len(changes)} change(s); "
      f"{len(incidents)} incident(s); {checks_not_done} check(s) not done; {len(job_rows)} job(s) to look at; "
      f"{len(contracts)} contract(s) ending")
