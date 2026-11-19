# @title       Weekly SLA breach report
# @summary     Checks the SLA of every ticket logged in the last 7 days and summarises which missed their response or resolution target.
# @module      tickets
# @changes     no
# @level       intermediate
# @endpoints   GET /tickets, GET /tickets/{id}/sla
# @permissions tickets.read, ticket_sla.read
#
# WHAT IT SHOWS: one call per ticket, then a summary. The ticket list does not
# carry SLA figures - they are worked out when you ask, from the ticket's
# priority, its SLA calendar (working hours, bank holidays) and every status it
# has been in (statuses like "On Hold" pause the clock). So the script:
#
#   1. Lists the tickets logged in the last --days days (paged).
#   2. Asks GET /tickets/{id}/sla for each one.
#   3. Counts breaches and prints the tickets that missed a target.
#
# One call per ticket is fine for a week of tickets. For thousands, run it out
# of hours: every call counts towards the key's rate limit (System -> API).
#
# Run it - every Monday morning from cron, say (Python 3.8 or later, standard
# library only):
#   python weekly-sla-breach-report.py --url https://helpdesk.example.com
#   python weekly-sla-breach-report.py --url https://helpdesk.example.com --days 30
#
# A read-only key is enough: tickets.read and ticket_sla.read.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Weekly FreeITSM SLA breach report.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far back to look, by when the ticket was LOGGED.
parser.add_argument("--days", type=int, default=7)
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()
BASE = args.url.rstrip("/") + "/api/v1"


def api(method, path):
    """Call the API and return the whole reply ({"data", "meta"}) because the
    ticket list pages; stop with FreeITSM's own error message on failure."""
    request = urllib.request.Request(
        BASE + path, method=method,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def name_of(ref, empty=""):
    return ref["name"] if ref else empty


# ---- 1. The tickets logged in the window -----------------------------------------
# created_since takes an ISO 8601 date and time; the "Z" means UTC, which is how
# FreeITSM stores every date. No state= filter: a ticket closed late is exactly
# what this report is for, so open AND closed tickets count. sort=id keeps the
# pages steady if new tickets arrive while we read (see export-open-tickets-csv).
since = (datetime.now(timezone.utc) - timedelta(days=args.days)).strftime("%Y-%m-%dT%H:%M:%SZ")
tickets = []
page = 1
while True:
    query = urllib.parse.urlencode({"created_since": since, "sort": "id", "per_page": 100, "page": page})
    reply = api("GET", f"/tickets?{query}")
    tickets.extend(reply["data"])
    page += 1
    if page > reply["meta"]["total_pages"]:
        break
print(f"{len(tickets)} ticket(s) logged since {since}")

# ---- 2. Ask for each ticket's SLA ------------------------------------------------
# The answer looks like:
#   { "enabled": true, "reason_disabled": null,
#     "response":   { "target_minutes": 60,  "elapsed_minutes": 75,  "breached": true,  "percent": 100, ... },
#     "resolution": { "target_minutes": 480, "elapsed_minutes": 120, "breached": false, ... } }
# Minutes are WORKING minutes on the SLA calendar, not wall-clock minutes.
# "enabled": false comes with a reason - no priority, a priority with no target,
# SLAs not switched on yet - and response/resolution are then null. A priority
# may have only one of the two targets, so either can be null on its own too.
measured = 0
not_measured = Counter()      # reason -> how many
response_breaches = 0
resolution_breaches = 0
breached_rows = []
for i, t in enumerate(tickets, 1):
    print(f"  checking {i}/{len(tickets)} {t['ticket_number']}", end="\r", file=sys.stderr)
    ticket_id = t["id"]
    sla = api("GET", f"/tickets/{ticket_id}/sla")["data"]
    if not sla["enabled"]:
        not_measured[sla.get("reason_disabled") or "not measured"] += 1
        continue
    measured += 1
    missed = []
    if sla["response"] and sla["response"]["breached"]:
        response_breaches += 1
        missed.append(f"response {sla['response']['elapsed_minutes']}/{sla['response']['target_minutes']} min")
    if sla["resolution"] and sla["resolution"]["breached"]:
        resolution_breaches += 1
        missed.append(f"resolution {sla['resolution']['elapsed_minutes']}/{sla['resolution']['target_minutes']} min")
    if missed:
        breached_rows.append((t["ticket_number"], name_of(t["priority"]), name_of(t["status"]),
                              name_of(t["assigned_analyst"], "(unassigned)"), ", ".join(missed), t["subject"]))
print(" " * 60, end="\r", file=sys.stderr)   # clear the progress line

# ---- 3. The summary -----------------------------------------------------------------
# Paste it into an email, or write breached_rows out with the csv module to keep
# a history.
print(f"\nSLA report - tickets logged in the last {args.days} day(s)")
print(f"  Tickets                {len(tickets)}")
print(f"  Measured against SLA   {measured}")
print(f"  Response breaches      {response_breaches}")
print(f"  Resolution breaches    {resolution_breaches}")
if measured:
    ok = measured - len(breached_rows)
    print(f"  Met every target       {ok} ({round(100 * ok / measured)}%)")
if not_measured:
    print("\n  Not measured:")
    for reason, count in not_measured.most_common():
        print(f"    {count:4}  {reason}")
if breached_rows:
    print("\nTickets that missed a target (working minutes taken / allowed):")
    headings = ("Ticket", "Priority", "Status", "Analyst", "Missed", "Subject")
    widths = [max(len(str(r[c])) for r in breached_rows + [headings]) for c in range(len(headings))]
    for row in [headings] + breached_rows:
        print("  " + "  ".join(str(v).ljust(w) for v, w in zip(row, widths)))
