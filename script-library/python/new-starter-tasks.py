# @title       New starter tasks
# @summary     From one name and start date, creates a new starter task with a checklist of subtasks - accounts, laptop, induction - each due a set number of days before or after the start.
# @module      tasks
# @changes     yes
# @level       intermediate
# @endpoints   GET /tasks, POST /tasks
# @permissions tasks.read, tasks.create
#
# WHAT IT DOES: you give it a person's name and start date. It creates:
#   - one task, "Jane Smith - new starter (2026-12-07)", due on the start date,
#   - under it, one SUBTASK for each line of the CHECKLIST table below, each due
#     a set number of days before (negative) or after (positive) the start date.
# Subtasks show on the task as a progress count ("3 of 9 done"), so the whole
# onboarding is one card on the Tasks board.
#
# A due date that would land on a Saturday or Sunday is moved back to the Friday
# before, so nothing is due on a day nobody is in.
#
# SAFE TO RUN AGAIN: it looks for the parent task by its exact title first, and
# for each subtask by its title under that parent, and only creates what is
# missing. Add a line to the table, run it again for the same person, and only
# the new subtask appears. (The tasks API has no "client_reference" to
# deduplicate for us, so we look first.)
#
# Run it - first WITHOUT --apply to see the list and the dates (standard library only):
#   python new-starter-tasks.py --url https://helpdesk.example.com --name "Jane Smith" --start-date 2026-12-07
#   python new-starter-tasks.py --url https://helpdesk.example.com --name "Jane Smith" --start-date 2026-12-07 --apply
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

# ---- YOUR CHECKLIST: edit this table ------------------------------------------------
# days = when it is due, counted from the start date: -5 is five days before,
#        0 is the first day, 7 is a week after.
# task = what to do. The person's name is added in front automatically.
# note = optional detail, saved as the subtask's description.
CHECKLIST = [
    {"days": -10, "task": "Order laptop and phone",           "note": "Standard build unless the manager asked for something else."},
    {"days": -5,  "task": "Create network account and email", "note": "Add to the department groups and distribution lists."},
    {"days": -5,  "task": "Add to HR and payroll systems",    "note": ""},
    {"days": -3,  "task": "Set up laptop and phone",          "note": "Install line-of-business apps; enrol in device management."},
    {"days": -2,  "task": "Order building pass",              "note": ""},
    {"days": 0,   "task": "First-day IT induction",           "note": "Hand over kit, set the password, enrol MFA, walk through the portal."},
    {"days": 1,   "task": "Security awareness training",      "note": ""},
    {"days": 7,   "task": "One-week check-in",                "note": "Anything missing? Access working? Raise tickets for gaps."},
]

parser = argparse.ArgumentParser(description="Create a new starter checklist as FreeITSM tasks.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The new starter's name, as you want it to appear on the tasks.
parser.add_argument("--name", required=True)
# Their first day, as YYYY-MM-DD.
parser.add_argument("--start-date", required=True)
# Without --apply the script only prints the tasks and dates it would create.
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


def all_tasks(query):
    """Every task on every page of a list (at most 100 a page)."""
    rows, page = [], 1
    while True:
        reply = api_raw("GET", f"/tasks?{query}&per_page=100&page={page}")
        rows += reply["data"]
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


# ---- Work out the dates ----------------------------------------------------------------
try:
    start = datetime.date.fromisoformat(args.start_date)
except ValueError:
    sys.exit(f"--start-date must be YYYY-MM-DD, e.g. 2026-12-07 (got '{args.start_date}').")


def due_date(days):
    d = start + datetime.timedelta(days=days)
    # weekday(): Monday is 0 ... Saturday 5, Sunday 6. Both move back to Friday.
    if d.weekday() >= 5:
        d -= datetime.timedelta(days=d.weekday() - 4)
    return d.isoformat()


parent_title = f"{args.name} - new starter ({args.start_date})"
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to create the tasks"
print(f"{parent_title} -> {BASE}   [{mode}]")

# ---- 1. The parent task: find it, or create it ------------------------------------------
# ?q= is a "contains" search over title and description, and the list shows
# top-level tasks only (not subtasks) - so we search, then insist on the exact title.
matches = all_tasks(urllib.parse.urlencode({"q": parent_title}))
parent = next((t for t in matches if t["title"] == parent_title), None)
existing_titles = set()
if parent:
    print(f"Found task #{parent['id']} - adding only what is missing.")
    # parent_task_id=N lists that task's subtasks instead of top-level tasks.
    existing_titles = {s["title"] for s in all_tasks(f"parent_task_id={parent['id']}")}
elif args.apply:
    # New tasks start in your default status (usually To Do) and priority.
    parent = api("POST", "/tasks", {
        "title": parent_title,
        "description": f"Onboarding checklist for {args.name}, starting {start.strftime('%A')} {start.day} "
                       f"{start.strftime('%B %Y')}. Created by the new-starter-tasks script.",
        "due_date": args.start_date,
    })
    print(f"Created task #{parent['id']}")
else:
    print(f"Would create task '{parent_title}', due {args.start_date}")

# ---- 2. One subtask per checklist line -------------------------------------------------
created = 0
for item in sorted(CHECKLIST, key=lambda i: i["days"]):
    title = f"{args.name}: {item['task']}"
    due = due_date(item["days"])
    if title in existing_titles:
        print(f"  already there  {due}  {title}")
        continue
    if not args.apply:
        print(f"  would add      {due}  {title}")
        continue

    body = {
        "title": title,
        # parent_task_id makes it a subtask. It also inherits the parent's company.
        "parent_task_id": parent["id"],
        "due_date": due,
    }
    if item["note"]:
        body["description"] = item["note"]
    task = api("POST", "/tasks", body)
    print(f"  added          {due}  {title}  (task #{task['id']})")
    created += 1
if args.apply:
    print(f"\nCreated {created} subtask(s).")
