# @title       Leaver checklist
# @summary     For someone leaving, finds them by email, lists the equipment they hold and their open tickets, and creates a task to collect the kit.
# @module      people
# @changes     yes
# @level       intermediate
# @endpoints   GET /users, GET /assets, GET /tickets, GET /tasks, POST /tasks
# @permissions users.read, assets.read, tickets.read, tasks.read, tasks.create
#
# WHAT IT DOES: one job that crosses four modules, the way a real leaver does.
#   1. People   - find the person by email address.
#   2. Assets   - every asset assigned to them (laptop, phone, monitor...).
#   3. Tickets  - their tickets that are still open, so nothing is left hanging.
#   4. Tasks    - one task, "Leaver: collect equipment from <email>", with the
#                 asset list and the open tickets written into it, due a few days
#                 from now, so someone owns getting the kit back.
#
# SAFE TO RUN AGAIN: before creating the task it looks for an OPEN task with the
# same title, and if there is one it says so instead of making a second.
#
# Run it - first WITHOUT --apply to see the checklist without creating the task
# (standard library only):
#   python leaver-checklist.py --url https://helpdesk.example.com --email jo.bloggs@example.com
#   python leaver-checklist.py --url https://helpdesk.example.com --email jo.bloggs@example.com --apply --assign-to 3
#
# Ideas for your own version: mark the person as left afterwards (PATCH /users/{id}
# with is_active false - add users.update to the key), add a note to each open
# ticket, or create one task per asset instead of one for all of them.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# It needs the permissions in the @permissions line above.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Leaver checklist: equipment, open tickets, and a collection task.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The leaver's email address, as held in FreeITSM.
parser.add_argument("--email", required=True)
# How many days from today the collection task is due.
parser.add_argument("--due-in-days", type=int, default=5)
# Optional: the id of the analyst who should own the task (see System ->
# Analysts). Left out, the task is unassigned and shows in the task queue.
parser.add_argument("--assign-to", type=int)
# Without --apply the script only reads and prints the checklist.
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


# ---- Helpers --------------------------------------------------------------------
def api(method, path, body=None):
    """Call the API and return the WHOLE reply ({"data": ..., "meta": ...}) -
    get_all() needs "meta" for paging. Stops with FreeITSM's own error message."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def get_all(path):
    """Every page of a list: asks for 100 at a time (the most FreeITSM allows) and
    follows meta.total_pages, so someone with 150 open tickets still gets them all."""
    items, page = [], 1
    while True:
        reply = api("GET", f"{path}&per_page=100&page={page}")
        items.extend(reply["data"])
        if page >= reply["meta"]["total_pages"]:
            return items
        page += 1


mode = "APPLYING" if args.apply else "PREVIEW - no task will be created; add --apply to create it"
print(f"Leaver checklist for {args.email} -> {BASE}   [{mode}]")

# ---- 1. The person ---------------------------------------------------------------
# ?email= is an exact match on the stored (lower-case) address.
people = api("GET", "/users?email=" + urllib.parse.quote(args.email.strip().lower(), safe=""))["data"]
if not people:
    sys.exit(f"Nobody in FreeITSM has the address {args.email}.")
person = people[0]
name = person["display_name"] or person["email"]
print(f"\n1. Person: {name} (#{person['id']})" + ("" if person["is_active"] else "  - already marked as left"))

# ---- 2. Their equipment ----------------------------------------------------------
# assigned_user_id lists the assets this person currently holds. Retired assets
# are left out (the API's default), since nobody needs to collect those.
assets = get_all(f"/assets?assigned_user_id={person['id']}&sort=hostname")
print(f"\n2. Equipment they hold: {len(assets)}")
asset_lines = []
for a in assets:
    what = " ".join(x for x in [(a["type"] or {}).get("name"), a["hardware"]["manufacturer"], a["hardware"]["model"]] if x)
    serial = f"serial {a['hardware']['service_tag']}" if a["hardware"]["service_tag"] else "no serial"
    asset_lines.append(f"{a['hostname']} - {what} ({serial})")
    print(f"   {asset_lines[-1]}")

# ---- 3. Their open tickets ---------------------------------------------------------
# state=open means "in a status that is not closed". user_id is the requester.
tickets = get_all(f"/tickets?user_id={person['id']}&state=open&sort=created_at")
print(f"\n3. Open tickets they raised: {len(tickets)}")
ticket_lines = []
for t in tickets:
    who = t["assigned_analyst"]["name"] if t["assigned_analyst"] else "unassigned"
    status = (t["status"] or {}).get("name")
    ticket_lines.append(f"{t['ticket_number']} - {t['subject']} [{status}, {who}]")
    print(f"   {ticket_lines[-1]}")

# ---- 4. The collection task --------------------------------------------------------
title = f"Leaver: collect equipment from {person['email']}"

# Look before creating. ?q= searches titles with SQL LIKE, where "_" in an address
# matches any character - so we also compare the title exactly ourselves.
existing = [t for t in get_all("/tasks?state=open&q=" + urllib.parse.quote(title, safe="")) if t["title"] == title]
if existing:
    print(f"\n4. Task already open: #{existing[0]['id']} '{title}' - not creating another.")
    sys.exit(0)

# A plain-text description: what to collect and what to tidy up. Whoever picks
# the task up has everything in one place without opening the person's record.
description = "\n".join(
    [f"{name} ({person['email']}) is leaving.", "", f"Equipment to collect ({len(assets)}):"]
    + ([f"- {l}" for l in asset_lines] or ["- none assigned in FreeITSM - check with their manager"])
    + ["", f"Open tickets to close or hand over ({len(tickets)}):"]
    + ([f"- {l}" for l in ticket_lines] or ["- none"]))
due = (date.today() + timedelta(days=args.due_in_days)).isoformat()   # the API wants YYYY-MM-DD

body = {"title": title, "description": description, "due_date": due}
if args.assign_to:
    body["assigned_analyst_id"] = args.assign_to

if not args.apply:
    print(f"\n4. Would create task '{title}', due {due}:")
    print("\n".join("   " + l for l in description.split("\n")))
    sys.exit(0)
# The task gets the company's default status and priority. Add "priority" or
# "status" (by name) to body to choose others.
task = api("POST", "/tasks", body)["data"]
print(f"\n4. Created task #{task['id']} '{title}', due {due}.")
