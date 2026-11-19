# @title       Export open tickets to a CSV file
# @summary     Pages through every open ticket and saves them to a CSV file you can open in Excel.
# @module      tickets
# @changes     no
# @level       starter
# @endpoints   GET /tickets
# @permissions tickets.read
#
# WHAT IT SHOWS: paging. FreeITSM never hands back a whole table in one go - a
# list answers with one PAGE of results (at most 100) plus a "meta" block that
# says how many there are in total:
#
#   { "data": [ ...up to 100 tickets... ],
#     "meta": { "page": 1, "per_page": 100, "total": 230, "total_pages": 3 } }
#
# So the script asks for page 1, reads total_pages, and keeps asking for the
# next page until it has them all. Every list in the API pages the same way, so
# once you have this loop you can list anything.
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python export-open-tickets-csv.py --url https://helpdesk.example.com
#   python export-open-tickets-csv.py --url https://helpdesk.example.com --out-file /reports/open.csv
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass --key-file). It only needs
# tickets.read - a read-only key is the right key for a report.

import argparse
import csv
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Export open FreeITSM tickets to CSV.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Where to write the CSV. Defaults to open-tickets.csv in the current folder.
parser.add_argument("--out-file", default="open-tickets.csv")
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


# ---- One helper for every call --------------------------------------------------
def api(method, path):
    """Call the API and return the WHOLE reply - "data" AND "meta" - because
    paging needs meta["total_pages"]. On failure stop with FreeITSM's own
    {"error": {"code", "message"}} explanation."""
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


# ---- Page through the open tickets ---------------------------------------------
# state=open   - only tickets whose status is not a "closed" one. FreeITSM decides
#                that from the status's is_closed flag, so your own status names
#                (e.g. "Resolved") work without listing them here.
# sort=id      - a FIXED order. The default is newest first, so a ticket logged
#                while we are on page 2 would push every row down by one and we
#                would see one ticket twice. Sorting by id means new tickets land
#                at the END and never shift the pages we have not read yet.
# per_page=100 - the most FreeITSM allows; fewer calls than the default of 25.
#
# Want a different set? Add filters to query, for example:
#   &assigned_analyst_id=4   &priority=High   &department_id=2   &company_id=3
query = "state=open&sort=id&per_page=100"
tickets = []
page = 1
while True:
    reply = api("GET", f"/tickets?{query}&page={page}")
    tickets.extend(reply["data"])
    meta = reply["meta"]
    print(f"  page {page} of {max(1, meta['total_pages'])}: {len(tickets)} ticket(s) so far (total {meta['total']})")
    page += 1
    if page > meta["total_pages"]:
        break


# ---- Flatten each ticket into one CSV row ----------------------------------------
# A ticket's status, priority and so on come back as small objects like
# {"id": 2, "name": "In Progress"} - or None when nobody set one. A CSV cell holds
# one value, so we take the name. Add or remove columns here to suit your report;
# GET /tickets/{id} in the API docs lists every field a ticket has.
def name_of(ref):
    return ref["name"] if ref else ""


columns = ["Number", "Subject", "Status", "Priority", "Type", "Department",
           "AssignedTo", "Requester", "Company", "Created", "Updated", "Id"]
# utf-8-sig puts a byte-order mark first, which is how Excel knows the file is
# UTF-8 - without it accented names turn into junk when double-clicked open.
# newline="" stops the csv module writing blank lines between rows on Windows.
with open(args.out_file, "w", newline="", encoding="utf-8-sig") as f:
    writer = csv.writer(f)
    writer.writerow(columns)
    for t in tickets:
        writer.writerow([
            t["ticket_number"], t["subject"],
            name_of(t["status"]), name_of(t["priority"]), name_of(t["ticket_type"]),
            name_of(t["department"]), name_of(t["assigned_analyst"]),
            t["requester"]["email"] if t["requester"] else "",
            name_of(t["company"]),
            t["created_at"] or "", t["updated_at"] or "",   # ISO 8601, UTC
            t["id"],
        ])

print(f"Saved {len(tickets)} open ticket(s) to {args.out_file}")
