# @title       Link matching tickets to a problem
# @summary     Finds the tickets whose subject or number contains a keyword and links each one to a problem, skipping any already linked.
# @module      problems
# @changes     yes
# @level       intermediate
# @endpoints   GET /problems/{id}, GET /tickets, POST /problems/{id}/tickets
# @permissions problems.read, tickets.read, problem_links.create
#
# WHY: when you open a problem ("VPN drops every afternoon") the evidence is the
# pile of incidents behind it. Linking them by hand, one ticket at a time, is
# slow; this does the search and the linking in one go, and the problem then
# shows every affected ticket and how many there are.
#
# HOW IT WORKS - search first, act second:
#   1. Read the problem, including the tickets already linked to it.
#   2. Search tickets for the keyword. The search looks at the SUBJECT and the
#      ticket NUMBER only - not the body - so pick a word people put in subjects.
#   3. Print what it would link. Nothing is written until you add --apply.
#   4. With --apply, link each new match. Ones already linked are skipped, so it
#      is safe to run again tomorrow to pick up new tickets.
#
# Run it - first WITHOUT --apply to see what it would do (standard library only):
#   python link-tickets-to-problem.py --url https://helpdesk.example.com --problem-id 12 --keyword VPN
#   python link-tickets-to-problem.py --url https://helpdesk.example.com --problem-id 12 --keyword VPN --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you. Each link is
# recorded in the problem's history as the analyst the key acts as.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Link tickets that match a keyword to a FreeITSM problem.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The problem's id - the number in its address, not the PRB-... reference.
parser.add_argument("--problem-id", type=int, required=True)
# The text to look for. "VPN" also matches "VPNs" and "OpenVPN": it is a
# "contains" search, upper or lower case alike.
parser.add_argument("--keyword", required=True)
# open, closed or all. A problem is about the pattern, so past (closed) incidents
# count as evidence too - hence "all". Use open to link only live ones.
parser.add_argument("--state", choices=["open", "closed", "all"], default="all")
# A safety net: a keyword like "e" matches everything. If more tickets match than
# this, the script stops and asks you to narrow it (or raise --max).
parser.add_argument("--max", type=int, default=50)
# Without --apply the script only searches and prints the plan.
parser.add_argument("--apply", action="store_true")
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


class Conflict(Exception):
    """FreeITSM said 409 conflict - for a link, "already linked"."""


# ---- One helper for every call --------------------------------------------------
def api(method, path, body=None):
    """Call the API and return FreeITSM's WHOLE reply ({"data": ..., "meta": ...}).

    On failure stop with FreeITSM's own message ({"error": {"code", "message"}}) -
    for example 422 when a ticket belongs to a different company than the problem.
    The one exception is 409 conflict, raised as Conflict so the caller can decide
    it is harmless.
    """
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")
        if e.code == 409:
            raise Conflict(detail)
        sys.exit(f"{method} {path} failed: HTTP {e.code} {detail}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to link"

# ---- 1. The problem, and what is already linked -----------------------------------
# GET /problems/{id} includes linked_tickets. We keep their ids in a set so the
# check in step 3 is instant, however many there are.
problem = api("GET", f"/problems/{args.problem_id}")["data"]
linked = {t["id"] for t in problem["linked_tickets"]}
print(f"{problem['problem_number']} {problem['title']}   [{mode}]")
print(f"Already linked: {len(linked)} ticket(s)")
if problem["status"] and problem["status"]["is_closed"]:
    print("Note: this problem is closed. Links can still be added, but check it is the right one.")

# ---- 2. Search the tickets ---------------------------------------------------------
# q= matches the subject or ticket number. Results come a page at a time (at most
# 100 per page, the server's cap); meta.total tells us the full count on the first
# page, so we can apply the --max safety net before fetching the rest.
filters = {"q": args.keyword, "state": args.state, "sort": "created_at", "per_page": 100}
first = api("GET", "/tickets?" + urllib.parse.urlencode({**filters, "page": 1}))
if first["meta"]["total"] > args.max:
    sys.exit(f"{first['meta']['total']} tickets match '{args.keyword}' - more than --max {args.max}. "
             "Use a more specific keyword, or raise --max if you mean it.")
tickets = list(first["data"])
for page in range(2, first["meta"]["total_pages"] + 1):
    tickets += api("GET", "/tickets?" + urllib.parse.urlencode({**filters, "page": page}))["data"]
print(f"Tickets matching '{args.keyword}' ({args.state}): {len(tickets)}\n")

# ---- 3. Link the new ones ----------------------------------------------------------
done = skipped = 0
for t in tickets:
    label = f"{t['ticket_number']}  {t['subject']}"
    if t["id"] in linked:
        print(f"  skip  {label}  (already linked)")
        skipped += 1
        continue
    if not args.apply:
        print(f"  would link  {label}")
        done += 1
        continue
    # The body is just {"ticket_id": n}. If somebody linked the same ticket in the
    # seconds since step 1, FreeITSM answers 409 "conflict" rather than making a
    # second link - that is not a failure for us, so we treat it as a skip.
    try:
        api("POST", f"/problems/{args.problem_id}/tickets", {"ticket_id": t["id"]})
        print(f"  linked  {label}")
        done += 1
    except Conflict:
        print(f"  skip  {label}  (linked meanwhile)")
        skipped += 1

verb = "Linked" if args.apply else "Would link"
print(f"\n{verb} {done} ticket(s); skipped {skipped} already linked.")
