# @title       Upcoming changes as a calendar file
# @summary     Writes the changes scheduled in the next few days to an .ics file that Outlook, Google Calendar or any calendar app can open.
# @module      changes
# @changes     no
# @level       intermediate
# @endpoints   GET /changes
# @permissions changes.read
#
# WHY: a change calendar people actually look at is the one already in their own
# calendar. Run this every morning, put the .ics file on a share or web server,
# and subscribe to it - or just double-click it to import the week's changes.
#
# WHAT IT USES: each change's scheduled work window (work_start_at / work_end_at).
# Changes without a work start are not scheduled yet, so they are left out, and
# so are closed ones (completed, failed, rejected, cancelled).
#
# TIME ZONES - read this once: FreeITSM stores a change's work window as a LOCAL
# wall-clock time with no time zone ("2026-11-20T22:00:00" means 10pm, as typed).
# A calendar file wants an exact moment, so this script reads those times as the
# time zone of the computer it runs on and writes them in UTC (the "Z" times in
# the file). Run it on a machine set to your service desk's time zone, or pass
# --time-zone with a name such as Europe/London (Python 3.9+; on Windows that
# also needs the tzdata package, so the computer's own zone is the safer default).
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python upcoming-changes-to-ics.py --url https://helpdesk.example.com
#   python upcoming-changes-to-ics.py --url https://helpdesk.example.com --days 30 --out-file /srv/www/changes.ics
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Write upcoming FreeITSM changes to an .ics calendar file.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How many days ahead to look, counting today as day one.
parser.add_argument("--days", type=int, default=14)
# The calendar file to write. Overwritten each run.
parser.add_argument("--out-file", default=str(Path(__file__).with_name("upcoming-changes.ics")))
# The time zone the work windows were typed in. Empty = this computer's.
parser.add_argument("--time-zone", default="")
args = parser.parse_args()

# Titles can hold any character - an emoji, Chinese. When the output is redirected
# to a file on Windows, Python writes in the old code page and would crash on
# those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if not 1 <= args.days <= 366:
    sys.exit("--days must be between 1 and 366.")

# None means "this computer's zone": Python's astimezone() on a time with no zone
# attached reads it as local time, daylight saving included.
zone = None
if args.time_zone:
    from zoneinfo import ZoneInfo
    try:
        zone = ZoneInfo(args.time_zone)
    except Exception:
        sys.exit(f"Unknown time zone {args.time_zone!r} (on Windows: pip install tzdata, or leave --time-zone out).")

# ---- The key --------------------------------------------------------------------
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()

# Every REST call lives under /api/v1 on your server.
BASE = args.url.rstrip("/") + "/api/v1"


# ---- One helper for every call --------------------------------------------------
def api(method, path):
    """Return FreeITSM's WHOLE reply - {"data": [...], "meta": {...}} - because a list
    says in "meta" how many pages there are. On failure FreeITSM answers
    {"error": {"code": "...", "message": "..."}}; we stop and print that message."""
    request = urllib.request.Request(
        BASE + path, method=method,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


# ---- Calendar text rules (RFC 5545) ---------------------------------------------
def ics_text(text):
    """Inside a text value, backslash, semicolon and comma have meanings of their
    own, so they are escaped with a backslash; a line break becomes the two
    characters \\n."""
    text = "" if text is None else str(text)
    text = text.replace("\\", "\\\\").replace(";", "\\;").replace(",", "\\,")
    return text.replace("\r\n", "\\n").replace("\n", "\\n").replace("\r", "\\n")


def fold(line):
    """A line in an .ics file may be at most 75 BYTES (not characters) long. Longer
    ones are "folded": broken, with each continuation line starting with a space.
    We count UTF-8 bytes and never split a character - an accented letter is two
    bytes, an emoji four."""
    out, used = [], 0
    for ch in line:
        size = len(ch.encode("utf-8"))
        if used + size > 75:
            out.append("\r\n ")
            used = 1          # the leading space counts towards the next line's 75
        out.append(ch)
        used += size
    return "".join(out)


def wall_clock(text):
    """FreeITSM's wall-clock text ("2026-11-20T22:00:00", no zone) -> a datetime
    in the zone the times were typed in."""
    naive = datetime.strptime(text, "%Y-%m-%dT%H:%M:%S")
    return naive.replace(tzinfo=zone) if zone else naive.astimezone()


def ics_utc(moment):
    """An exact moment in calendar format: UTC, ending in Z."""
    return moment.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


# ---- 1. Ask for the scheduled changes ----------------------------------------------
# work_start_from / work_start_to filter on the work start, and - like the times
# themselves - take a wall-clock value with NO "Z" (FreeITSM refuses one, so a
# client cannot shift the window by accident). From midnight today, so a change
# that started this morning is still in today's file.
# state=open leaves out every closed status. sort=work_start_at = soonest first.
now_there = datetime.now(timezone.utc).astimezone(zone)   # zone None = this computer
today = now_there.replace(hour=0, minute=0, second=0, microsecond=0)
frm = today.strftime("%Y-%m-%dT%H:%M:%S")
to = (today + timedelta(days=args.days)).strftime("%Y-%m-%dT%H:%M:%S")
filters = {"state": "open", "sort": "work_start_at", "per_page": 100,
           "work_start_from": frm, "work_start_to": to}
changes, page = [], 1
while True:
    # Pages hold at most 100 rows (the server's cap); meta.total_pages says when to stop.
    reply = api("GET", "/changes?" + urllib.parse.urlencode({**filters, "page": page}))
    changes.extend(reply["data"])
    if page >= reply["meta"]["total_pages"]:
        break
    page += 1
# The zone's name, for the messages: yours, or the one this computer is set to.
zone_name = args.time_zone or datetime.now().astimezone().tzname()
print(f"{len(changes)} open change(s) start between {frm} and {to} ({zone_name})")

# ---- 2. One VEVENT per change ---------------------------------------------------------
# UID must stay the same for the same change on every run: that is what lets a
# calendar that re-imports or subscribes to the file UPDATE an event instead of
# adding a second copy. The host part keeps ids from two FreeITSMs apart.
host = urllib.parse.urlparse(args.url).hostname or "freeitsm"
stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
lines = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//FreeITSM script library//Upcoming changes//EN",
         "CALSCALE:GREGORIAN", "METHOD:PUBLISH", "X-WR-CALNAME:FreeITSM changes"]

for c in changes:
    s = c["schedule"]
    start = wall_clock(s["work_start_at"])
    # No end typed in? Show it as one hour, so it is visible rather than a zero-length blip.
    end = wall_clock(s["work_end_at"]) if s["work_end_at"] else start + timedelta(hours=1)

    # The description: the facts a reader needs to decide whether it affects them.
    # Each field can be null, so every lookup checks first.
    details = [f"Status: {c['status']['name']}"]
    if c.get("change_type"):
        details.append(f"Type: {c['change_type']['name']}")
    if c["risk"].get("level"):
        details.append(f"Risk: {c['risk']['level']}")
    if c.get("assigned_to"):
        details.append(f"Assigned to: {c['assigned_to']['name']}")
    if s["outage_start_at"]:
        details.append(f"Outage: {s['outage_start_at']} to {s['outage_end_at']} ({zone_name})")
    if not s["work_end_at"]:
        details.append("No end time set in FreeITSM - shown as one hour.")

    # Approved or being carried out = CONFIRMED; still a draft or awaiting approval
    # = TENTATIVE. status.stage is used rather than the name, because a status can
    # be renamed but its stage cannot.
    confirmed = c["status"].get("stage") in ("approved", "implementing")
    lines += [
        "BEGIN:VEVENT",
        f"UID:change-{c['id']}@{host}",
        f"DTSTAMP:{stamp}",
        f"DTSTART:{ics_utc(start)}",
        f"DTEND:{ics_utc(end)}",
        "SUMMARY:" + ics_text(f"{c['change_number']} {c['title']}"),
        "DESCRIPTION:" + ics_text("\n".join(details)),
        "STATUS:" + ("CONFIRMED" if confirmed else "TENTATIVE"),
        "TRANSP:TRANSPARENT",     # does not mark the reader as busy
        "END:VEVENT",
    ]
    print(f"  {c['change_number']}  {s['work_start_at']}  {c['title']}")
lines.append("END:VCALENDAR")

# ---- 3. Write the file --------------------------------------------------------------
# The standard says lines end with CRLF, and the text is UTF-8 (no byte order
# mark, which some calendar apps reject). newline="" stops Python turning our
# \r\n into \r\r\n on Windows.
with open(args.out_file, "w", encoding="utf-8", newline="") as f:
    f.write("\r\n".join(fold(line) for line in lines) + "\r\n")
print(f"\nWrote {args.out_file}")
