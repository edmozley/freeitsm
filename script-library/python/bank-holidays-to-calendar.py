# @title       Bank holidays into the calendar
# @summary     Reads the official UK bank holiday list from GOV.UK and adds every upcoming one to the FreeITSM calendar - running it again adds nothing twice.
# @module      calendar
# @changes     yes
# @level       intermediate
# @endpoints   GET /calendar/events, POST /calendar/events, GET /calendar-categories
# @permissions calendar_events.read, calendar_events.create, reference.read
#
# WHAT IT DOES:
#   1. downloads https://www.gov.uk/bank-holidays.json - a free, public list kept
#      by the UK government, with no key needed - and keeps the upcoming dates
#      for one part of the UK (England and Wales, Scotland, or Northern Ireland:
#      they differ, e.g. St Andrew's Day is Scotland only),
#   2. reads the FreeITSM calendar for the same dates,
#   3. adds an all-day event for each bank holiday that is not there yet.
#
# Step 2 is what makes it safe to run again: an event with the same title on the
# same day counts as "already there". So you can schedule it once a month and new
# dates appear as GOV.UK publishes them, with no duplicates. (The calendar API has
# no "client_reference" to deduplicate for us, so we look first.)
#
# It also shows a pattern worth copying: read ANOTHER web service, reshape what
# it returns, then write it into FreeITSM.
#
# Run it - first WITHOUT --apply to see what it would add (standard library only):
#   python bank-holidays-to-calendar.py --url https://helpdesk.example.com
#   python bank-holidays-to-calendar.py --url https://helpdesk.example.com --division scotland --category "Holidays" --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.

import argparse
import datetime
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Add UK bank holidays to the FreeITSM calendar.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Which part of the UK - the three names GOV.UK uses.
parser.add_argument("--division", default="england-and-wales",
                    choices=["england-and-wales", "scotland", "northern-ireland"])
# Optional: put the events in this calendar category (by name, as shown under
# Calendar -> Settings). Leave it out for no category.
parser.add_argument("--category", default="")
# Put in front of each holiday's name, so the events are easy to spot and search
# for: "Bank holiday: Christmas Day".
parser.add_argument("--title-prefix", default="Bank holiday: ")
# Without --apply the script only reads both sides and prints what it would add.
parser.add_argument("--apply", action="store_true")
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
BASE = args.url.rstrip("/") + "/api/v1"


# ---- Helpers --------------------------------------------------------------------------
def api_raw(method, path, body=None):
    """Call the API and return the whole reply ({"data": ..., "meta": ...}), or stop
    with FreeITSM's own message on failure."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def api(method, path, body=None):
    """The usual case: just the "data" part."""
    return api_raw(method, path, body)["data"]


# ---- 1. The bank holidays from GOV.UK -------------------------------------------------
# The file looks like {"england-and-wales": {"events": [{"title": "Christmas Day",
# "date": "2026-12-25", "notes": "", "bunting": true}, ...]}, "scotland": ...}
try:
    with urllib.request.urlopen("https://www.gov.uk/bank-holidays.json", timeout=30) as response:
        govuk = json.loads(response.read().decode("utf-8"))
except urllib.error.URLError as e:
    sys.exit(f"Could not read the GOV.UK bank holiday list: {e}")
today = datetime.date.today().isoformat()
# Dates are YYYY-MM-DD text, which sorts and compares correctly as plain strings.
upcoming = sorted((h for h in govuk[args.division]["events"] if h["date"] >= today), key=lambda h: h["date"])

mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to add the events"
print(f"{len(upcoming)} upcoming bank holidays for {args.division} -> {BASE}   [{mode}]")
if not upcoming:
    sys.exit(0)

# ---- 2. Optional category, checked before anything is written ----------------------------
category_id = None
if args.category:
    cats = [c for c in api("GET", "/calendar-categories") if c["is_active"]]
    cat = next((c for c in cats if c["name"].lower() == args.category.lower()), None)
    if cat is None:
        sys.exit(f"'{args.category}' is not an active calendar category. Yours are: " + ", ".join(c["name"] for c in cats))
    category_id = cat["id"]

# ---- 3. What is already in the calendar for those dates --------------------------------
# The calendar works in the server's LOCAL time with no time zone, as
# "YYYY-MM-DD HH:MM:SS" - a time zone suffix such as Z is refused. "from" is
# inclusive and "to" exclusive, so we go to the day after the last holiday.
# Lists come a page at a time (at most 100), so we keep asking until we have them all.
day_after = datetime.date.fromisoformat(upcoming[-1]["date"]) + datetime.timedelta(days=1)
window = urllib.parse.urlencode({"from": upcoming[0]["date"] + " 00:00:00",
                                 "to": day_after.isoformat() + " 00:00:00"})
existing = set()
page = 1
while True:
    reply = api_raw("GET", f"/calendar/events?{window}&per_page=100&page={page}")
    for ev in reply["data"]:
        # start_at comes back as "2026-12-25 00:00:00"; the date part is enough.
        existing.add((ev["start_at"][:10], ev["title"]))
    if page >= reply["meta"]["total_pages"]:
        break
    page += 1

# ---- 4. Add the missing ones ----------------------------------------------------------
added = 0
for h in upcoming:
    title = args.title_prefix + h["title"]
    if (h["date"], title) in existing:
        print(f"  already there  {h['date']}  {title}")
        continue
    if not args.apply:
        print(f"  would add      {h['date']}  {title}")
        continue

    # GOV.UK's notes say things like "Substitute day" when a holiday moves.
    description = f"UK bank holiday ({args.division}), from GOV.UK."
    if h.get("notes"):
        description += f" {h['notes']}."
    ev = api("POST", "/calendar/events", {
        "title": title,
        "description": description,
        # An all-day event starts and ends at midnight on its day - the same
        # shape FreeITSM's own generated calendar entries use.
        "start_at": h["date"],
        "end_at": h["date"],
        "all_day": True,
        "category_id": category_id,
    })
    print(f"  added          {h['date']}  {title}  (event #{ev['id']})")
    added += 1
if args.apply:
    print(f"\nAdded {added} event(s).")
