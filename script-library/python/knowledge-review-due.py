# @title       Knowledge articles due for review
# @summary     Lists the knowledge articles that are overdue for review or due soon, grouped by the analyst who owns them.
# @module      knowledge
# @changes     no
# @level       starter
# @endpoints   GET /knowledge/articles
# @permissions knowledge.read
#
# WHY: an article nobody has looked at for two years is worse than no article,
# because people trust it. FreeITSM gives every article an optional "next review
# date" and an owner. This script asks for the ones whose date has passed or is
# coming up, and prints them owner by owner - ready to paste into an email to
# each owner, or to run every Monday from cron or Task Scheduler.
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python knowledge-review-due.py --url https://helpdesk.example.com
#   python knowledge-review-due.py --url https://helpdesk.example.com --days 7 --include-drafts
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
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="List knowledge articles due for review.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far ahead counts as "due soon". FreeITSM's own "upcoming" filter looks 30
# days ahead (the same window as the Knowledge module's review screen), so this
# can narrow that window but not widen it - see the note in step 2.
parser.add_argument("--days", type=int, default=30, choices=range(0, 31), metavar="0-30")
# Published articles only, unless you add this. A draft can still be wrong, so
# turn it on if your team keeps long-lived drafts.
parser.add_argument("--include-drafts", action="store_true")
args = parser.parse_args()

# Titles can hold any character - an emoji, Chinese. When the output is redirected
# to a file on Windows, Python writes in the old code page and would crash on
# those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")

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
    """Call the API and return FreeITSM's WHOLE reply - {"data": [...], "meta": {...}} -
    because a list also tells us, in "meta", how many pages there are.

    On failure FreeITSM answers {"error": {"code": "...", "message": "..."}}; we
    stop and print that message, which is usually all you need to fix it.
    """
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


# ---- Paging ---------------------------------------------------------------------
def all_articles(filters):
    """Lists come back a page at a time: at most 100 rows per page (per_page is
    capped at 100 on the server), and meta.total_pages says how many pages there
    are. Keep asking for the next page until we have them all."""
    rows, page = [], 1
    while True:
        query = urllib.parse.urlencode({**filters, "per_page": 100, "page": page})
        reply = api("GET", f"/knowledge/articles?{query}")
        rows.extend(reply["data"])
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


# ---- 1. Build the filter --------------------------------------------------------
# review=overdue  -> next review date is before today
# review=upcoming -> next review date is today or within the next 30 days
# Both are worked out on the server in UTC, so "today" below is the UTC date too,
# which keeps our day counts in step with FreeITSM's.
# sort=next_review_date puts the most urgent first.
common = {"sort": "next_review_date"}
if args.include_drafts:
    common["include_unpublished"] = "true"

# ---- 2. Ask for both lists --------------------------------------------------------
# Articles with NO review date are not in either list - FreeITSM has nothing to
# compare. Ask for them with review=none if you want to chase those too.
today = datetime.now(timezone.utc).date()
overdue = all_articles({"review": "overdue", **common})
upcoming = all_articles({"review": "upcoming", **common})

# "upcoming" is always 30 days on the server; --days narrows it here, on our side.
# (To look further than 30 days ahead you would list articles without a review
# filter, sorted by next_review_date, and stop once the dates pass your limit.)
limit = today + timedelta(days=args.days)
upcoming = [a for a in upcoming if date.fromisoformat(a["next_review_date"]) <= limit]

# ---- 3. One row per article, grouped by owner --------------------------------------
# owner is {"id", "name"} or None. An article with no owner is the most likely to
# be forgotten, so it gets its own group rather than disappearing.
groups = defaultdict(list)
for a in overdue + upcoming:
    diff = (date.fromisoformat(a["next_review_date"]) - today).days
    if diff < 0:
        when = f"{-diff} day(s) OVERDUE"
    elif diff == 0:
        when = "due today"
    else:
        when = f"due in {diff} day(s)"
    owner = a["owner"]["name"] if a.get("owner") else "(no owner)"
    groups[owner].append((diff, a, when))

# ---- 4. Print them, owner by owner ------------------------------------------------
print(f"Knowledge review report from {BASE}   (UTC date {today}, looking {args.days} day(s) ahead)")
if not groups:
    print("Nothing is overdue or due soon. Good.")
    sys.exit(0)
total = late = 0
for owner in sorted(groups):
    items = sorted(groups[owner], key=lambda item: item[0])
    print(f"\n{owner} - {len(items)} article(s)")
    for diff, a, when in items:
        draft = "" if a["is_published"] else "  [draft]"
        print(f"  #{a['id']:<6} {when:<22} {a['next_review_date']}  {a['title']}{draft}")
        total += 1
        late += diff < 0
print(f"\n{total} article(s) need review: {late} overdue, {total - late} due soon.")
