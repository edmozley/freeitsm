# @title       Change freeze clash check
# @summary     Finds open changes whose work or outage window falls inside a change freeze on the calendar, and can leave one comment on each asking for it to be moved.
# @module      changes
# @changes     yes
# @level       intermediate
# @endpoints   GET /calendar-categories, GET /calendar/events, GET /change-statuses, GET /changes, GET /changes/{id}/comments, POST /changes/{id}/comments
# @permissions reference.read, calendar_events.read, changes.read, change_comments.read, change_comments.create
#
# WHY: a change freeze (month end, Christmas, a big launch) is usually agreed in
# a meeting and put in the calendar - and then somebody schedules a change in the
# middle of it anyway, because nothing joins the two up. This script does: it
# reads the freezes from the calendar and every open change's schedule, and lists
# the ones that clash. Run it daily, or before each CAB meeting.
#
# HOW A FREEZE IS RECOGNISED: it is any calendar event in one category - "Change
# freeze" unless you pass --category. Create that category once (Calendar ->
# Settings), then add each freeze as an event in it. The event's title is used in
# the messages, so call it something like "Year-end freeze".
#
# WHAT COUNTS AS A CLASH: the change's WORK window (work start to work end) or its
# OUTAGE window overlaps the freeze, even by a minute. A change with a work start
# but no end is treated as a moment at its start. Changes with no work start are
# not scheduled yet, so they cannot clash. Closed changes (Completed, Failed,
# Rejected, Cancelled) are left out.
#
# TIMES: both sides are LOCAL wall-clock times with no time zone. Calendar events
# are stored as server-local times and a change's windows exactly as typed, so the
# two are compared as they are - no conversion. An ALL-DAY freeze runs from
# midnight at the start of its first day to midnight at the END of its last day.
#
# WITH --apply: each clashing change gets ONE comment naming the freeze(s). The
# comment carries a marker such as [freeze-clash event #12]; before writing, the
# script reads the change's comments and skips any freeze already mentioned. So
# it is safe to run every day - a change is told once per freeze, not every day.
# Comments on changes are always internal (never shown to requesters).
#
# Run it - first WITHOUT --apply to see the clashes (standard library only):
#   python change-freeze-clash-check.py --url https://helpdesk.example.com
#   python change-freeze-clash-check.py --url https://helpdesk.example.com --days 120 --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you. Comments are
# written as the analyst the key acts as.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="List FreeITSM changes scheduled inside a change freeze.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The calendar category that holds your freezes (matched ignoring capitals).
parser.add_argument("--category", default="Change freeze")
# How far ahead to look for freezes, counting from midnight today. A freeze that
# has already started and is still running is included.
parser.add_argument("--days", type=int, default=90)
# Without --apply the script only prints the clashes.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

# Titles can hold any character - an emoji, Chinese. When the output is redirected
# to a file on Windows, Python writes in the old code page and would crash on
# those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if not 1 <= args.days <= 366:
    sys.exit("--days must be between 1 and 366.")

# ---- The key --------------------------------------------------------------------
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()

# Every REST call lives under /api/v1 on your server.
BASE = args.url.rstrip("/") + "/api/v1"


# ---- Helpers --------------------------------------------------------------------
def api_raw(method, path, body=None):
    """Call the API and return FreeITSM's WHOLE reply ({"data": ..., "meta": ...});
    on failure stop with FreeITSM's own message ({"error": {"code", "message"}})."""
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


def all_pages(path, filters):
    """Every row of a list. Pages hold at most 100 rows (the server's cap) and
    meta.total_pages says when to stop."""
    rows, page = [], 1
    while True:
        reply = api_raw("GET", path + "?" + urllib.parse.urlencode({**filters, "per_page": 100, "page": page}))
        rows += reply["data"]
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


def local_time(text):
    """FreeITSM's local times come in two spellings - "2026-12-20 18:00:00" from the
    calendar and "2026-12-20T18:00:00" from a change. Both have no zone; both
    become a plain (naive) datetime, so they compare directly."""
    return datetime.strptime(text.replace("T", " ")[:19], "%Y-%m-%d %H:%M:%S")


def overlaps(a_start, a_end, b_start, b_end):
    """Two windows clash when each starts before the other ends. A window with no
    length (a change with no end) is a moment: it clashes when it falls inside."""
    if a_end <= a_start:
        return b_start <= a_start < b_end
    return a_start < b_end and a_end > b_start


def fmt(moment):
    return moment.strftime("%a %d %b %Y %H:%M")


mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to comment on the changes"
print(f"Change freeze clash check -> {BASE}   [{mode}]")

# ---- 1. The freeze category -----------------------------------------------------------
# GET /calendar-categories lists every category with its id. We match the name
# ignoring capitals, then filter the events by that id.
categories = api("GET", "/calendar-categories")
category = next((c for c in categories if c["name"].strip().lower() == args.category.strip().lower()), None)
if category is None:
    names = ", ".join(c["name"] for c in categories) or "(none)"
    sys.exit(f"No calendar category called '{args.category}'. Create it in Calendar -> Settings, "
             f"or pass --category with one of: {names}")

# ---- 2. The freezes in the window -------------------------------------------------------
# GET /calendar/events needs a from + to window, as NAIVE local times (no "Z" -
# the calendar refuses one). It returns every event that overlaps the window, so
# a freeze that began last week and runs into today is included.
today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
window_to = today + timedelta(days=args.days)
events = all_pages("/calendar/events", {
    "from": today.strftime("%Y-%m-%d %H:%M:%S"),
    "to": window_to.strftime("%Y-%m-%d %H:%M:%S"),
    "category_id": category["id"],
})
freezes = []
for e in events:
    start = local_time(e["start_at"])
    end = local_time(e["end_at"]) if e["end_at"] else start
    if e["all_day"]:
        # An all-day event is stored as midnight on its first and last day. The
        # freeze covers the whole of the last day too, so it ends at the NEXT midnight.
        start = start.replace(hour=0, minute=0, second=0)
        end = end.replace(hour=0, minute=0, second=0) + timedelta(days=1)
    freezes.append({"id": e["id"], "title": e["title"], "start": start, "end": max(end, start)})
print(f"Freezes in '{category['name']}' over the next {args.days} day(s): {len(freezes)}")
for f in freezes:
    print(f"  #{f['id']}  {f['title']}: {fmt(f['start'])} to {fmt(f['end'])}")
if not freezes:
    print("Nothing to check.")
    sys.exit(0)

# ---- 3. Which change statuses count as finished --------------------------------------------
# A status is finished when it is ticked "closed", or its stage is done or
# stopped. The stage is checked too because a status can be renamed - and on some
# installs Cancelled is not ticked closed - but its stage says what it means.
finished = {s["id"] for s in api("GET", "/change-statuses")
            if s["is_closed"] or s.get("stage") in ("done", "stopped")}

# ---- 4. The scheduled changes ------------------------------------------------------------------
# work_start_to keeps only changes that START before the last freeze ends - one
# starting later cannot clash. There is deliberately no lower limit: a long
# change that started before a freeze can still run into it. Like the times, the
# filter is wall-clock with no "Z".
last_end = max(f["end"] for f in freezes)
changes = all_pages("/changes", {"work_start_to": last_end.strftime("%Y-%m-%dT%H:%M:%S"), "sort": "work_start_at"})

clashes = []   # (change, [(freeze, which window, its start, its end), ...])
checked = 0
for c in changes:
    s = c["schedule"]
    if not s["work_start_at"] or (c["status"] and c["status"]["id"] in finished):
        continue
    checked += 1
    windows = []
    ws = local_time(s["work_start_at"])
    windows.append(("work", ws, local_time(s["work_end_at"]) if s["work_end_at"] else ws))
    if s["outage_start_at"]:
        os_ = local_time(s["outage_start_at"])
        windows.append(("outage", os_, local_time(s["outage_end_at"]) if s["outage_end_at"] else os_))
    hit = []
    for f in freezes:
        for kind, a, b in windows:
            if overlaps(a, b, f["start"], f["end"]):
                hit.append((f, kind, a, b))
                break   # one reason per freeze is enough
    if hit:
        clashes.append((c, hit))

print(f"\nOpen scheduled changes checked: {checked}; clashing: {len(clashes)}\n")
for c, hit in clashes:
    status = c["status"]["name"] if c["status"] else "no status"
    print(f"  {c['change_number']}  {c['title']}  ({status})")
    for f, kind, a, b in hit:
        print(f"      {kind} {fmt(a)} to {fmt(b)}  clashes with  #{f['id']} {f['title']}")

# ---- 5. With --apply: one comment per change, once per freeze -------------------------------------
# The marker in each comment is how the script recognises its own earlier
# comment. GET /changes/{id}/comments returns every comment (newest first, no
# paging), so one call per clashing change is enough.
if not args.apply:
    if clashes:
        print("\nPreview only - add --apply to comment on these changes.")
    sys.exit(0)

written = skipped = 0
for c, hit in clashes:
    existing = " ".join(m["text"] or "" for m in api("GET", f"/changes/{c['id']}/comments"))
    new = [h for h in hit if f"[freeze-clash event #{h[0]['id']}]" not in existing]
    if not new:
        print(f"  skip  {c['change_number']}  (already told about this freeze)")
        skipped += 1
        continue
    lines = ["This change is scheduled inside a change freeze:"]
    for f, kind, a, b in new:
        lines.append(f"- its {kind} window ({fmt(a)} to {fmt(b)}) overlaps '{f['title']}' "
                     f"({fmt(f['start'])} to {fmt(f['end'])}).")
    lines.append("Please reschedule it, or record the agreed exception here.")
    lines.append(" ".join(f"[freeze-clash event #{f['id']}]" for f, *_ in new))
    api("POST", f"/changes/{c['id']}/comments", {"text": "\n".join(lines)})
    print(f"  commented  {c['change_number']}  {c['title']}")
    written += 1
print(f"\nCommented on {written} change(s); skipped {skipped} already told.")
