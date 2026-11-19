# @title       Time logged per analyst this month
# @summary     Adds up the time each analyst logged on tickets this month (or any month you name) and prints the totals.
# @module      tickets
# @changes     no
# @level       intermediate
# @endpoints   GET /tickets, GET /tickets/{id}/time-entries
# @permissions tickets.read, ticket_time_entries.read
#
# WHAT IT SHOWS: combining two endpoints. Time is logged against a ticket, so
# there is no single "all time entries" list - you walk the tickets, ask each
# one for its time entries, and add them up yourself:
#
#   1. List the tickets that could hold time for the month (paged).
#   2. GET /tickets/{id}/time-entries for each.
#   3. Keep the entries dated inside the month and total them per analyst.
#
# Run it (Python 3.8 or later, standard library only):
#   python time-logged-per-analyst.py --url https://helpdesk.example.com
#   python time-logged-per-analyst.py --url https://helpdesk.example.com --month 2026-10
#
# A read-only key is enough: tickets.read and ticket_time_entries.read.
#
# If time tracking is switched off for the API (Tickets -> Settings -> Time
# tracking, which can differ per company), those tickets answer 404 "Time
# tracking is not enabled for this ticket's company" and the script stops there -
# give the key only the companies that track time, or switch it on. FreeITSM
# refuses rather than answering "no time", because "no time" would be untrue.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Time logged per analyst on FreeITSM tickets, for one month.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The month to total, as YYYY-MM. Empty = the current month. Months are counted
# in UTC, the way FreeITSM stores times: an entry at 00:30 on the 1st in a
# timezone ahead of UTC belongs to the previous month here.
parser.add_argument("--month", default="")
# Which tickets to look at - see step 1 for why this is not just "this month".
parser.add_argument("--lookback-days", type=int, default=90)
# Look at EVERY ticket instead: exact, but one call per ticket on the whole
# install. Fine for a few thousand tickets, slow beyond that.
parser.add_argument("--all-tickets", action="store_true")
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


def parse_utc(text):
    """FreeITSM dates look like "2026-11-19T09:30:00Z" (UTC). Python before 3.11
    cannot read the "Z", so swap it for the "+00:00" every version understands."""
    return datetime.fromisoformat(text.replace("Z", "+00:00"))


def hours(minutes):
    return f"{minutes // 60}:{minutes % 60:02d}"     # 7:30 = seven and a half hours


# ---- The month, as a UTC window [start, end) ------------------------------------
if args.month:
    start = datetime.strptime(args.month, "%Y-%m").replace(tzinfo=timezone.utc)
else:
    start = datetime.now(timezone.utc).replace(day=1, hour=0, minute=0, second=0, microsecond=0)
end = (start + timedelta(days=32)).replace(day=1)     # the 1st of the next month
print(f"Time logged in {start:%B %Y} (UTC)")

# ---- 1. The tickets that could hold time for the month ---------------------------
# TRAP: logging time does NOT change a ticket's updated_at. So "tickets updated
# this month" would miss an old ticket that only had time logged on it. We look
# back --lookback-days before the month starts as well - an analyst who logs time
# usually adds a note or reply now and then - or, with --all-tickets, at every
# ticket. Open and closed both count; tickets in the trash are left out.
filters = {"sort": "id", "per_page": 100}
if not args.all_tickets:
    filters["updated_since"] = (start - timedelta(days=args.lookback_days)).strftime("%Y-%m-%dT%H:%M:%SZ")
tickets = []
page = 1
while True:
    reply = api("GET", "/tickets?" + urllib.parse.urlencode({**filters, "page": page}))
    tickets.extend(reply["data"])
    page += 1
    if page > reply["meta"]["total_pages"]:
        break
print(f"Checking time on {len(tickets)} ticket(s)...")

# ---- 2 + 3. Each ticket's time entries, totalled per analyst -----------------------
# Each entry looks like:
#   { "id": 12, "minutes": 45, "notes": "Rebuilt profile",
#     "analyst": { "id": 4, "name": "Sam Patel" }, "entry_at": "2026-11-03T10:15:00Z" }
# entry_at is when the work was done (the analyst can back-date it), which is
# what a monthly report wants - not when it was typed in.
totals = {}    # analyst name -> {"minutes", "entries", "tickets"}
for i, t in enumerate(tickets, 1):
    print(f"  {i}/{len(tickets)} {t['ticket_number']}", end="\r", file=sys.stderr)
    ticket_id = t["id"]
    for e in api("GET", f"/tickets/{ticket_id}/time-entries")["data"]:
        if not (start <= parse_utc(e["entry_at"]) < end):
            continue
        # An entry whose analyst has since been removed has analyst = None.
        who = e["analyst"]["name"] if e["analyst"] else "(removed analyst)"
        row = totals.setdefault(who, {"minutes": 0, "entries": 0, "tickets": set()})
        row["minutes"] += e["minutes"]
        row["entries"] += 1
        row["tickets"].add(ticket_id)
print(" " * 60, end="\r", file=sys.stderr)   # clear the progress line

# ---- The report ---------------------------------------------------------------------
# Write these rows out with the csv module instead to feed a timesheet or billing system.
if not totals:
    print("No time logged in that month.")
    sys.exit(0)
print(f"\n{'Analyst':<28} {'Hours':>7} {'Minutes':>8} {'Entries':>8} {'Tickets':>8}")
for who, row in sorted(totals.items(), key=lambda kv: kv[1]["minutes"], reverse=True):
    print(f"{who:<28} {hours(row['minutes']):>7} {row['minutes']:>8} {row['entries']:>8} {len(row['tickets']):>8}")
total = sum(r["minutes"] for r in totals.values())
print(f"\nTotal: {hours(total)} across {len(totals)} analyst(s)")
