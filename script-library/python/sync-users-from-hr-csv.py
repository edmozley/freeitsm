# @title       Sync people from an HR spreadsheet
# @summary     Reads a CSV export from your HR system and creates the people FreeITSM is missing, updates the ones that changed, and leaves the rest alone.
# @module      people
# @changes     yes
# @level       intermediate
# @endpoints   GET /users, POST /users, PATCH /users/{id}
# @permissions users.read, users.create, users.update
#
# WHAT IT DOES: for every row in the spreadsheet it asks FreeITSM "do you know
# this email address?" and then
#   - creates the person if not (a "requester" - someone who can raise tickets
#     and sign in to the self-service portal),
#   - updates only the fields that differ if so,
#   - or reports them as unchanged.
# Matching on one key and then creating-or-updating is called an "upsert".
#
# WHAT IT EXPECTS: a CSV file with a header row. Only "email" is required; any
# other column may be left out, and an EMPTY cell means "leave this as it is in
# FreeITSM" - never "clear it". So a half-filled export cannot wipe data.
#
#   email,display_name,preferred_name,employee_id,active
#   jo.bloggs@example.com,Josephine Bloggs,Jo,E1001,yes
#   sam.patel@example.com,Samir Patel,Sam,E1002,yes
#   old.leaver@example.com,Old Leaver,,E0950,no
#
#   email           the match key - one row per person, compared ignoring case
#   display_name    full name as it should appear on tickets
#   preferred_name  what they like to be called
#   employee_id     the payroll / HR number (needs a recent FreeITSM - see below)
#   active          yes / no. "no" marks them as left: kept on record, but they
#                   can no longer sign in to the portal
#
# Most HR systems can export to CSV. If yours names the columns differently,
# rename them in the header row, or change the cell(row, "<name>") lines below.
#
# Run it - first WITHOUT --apply to see what it would do (standard library only):
#   python sync-users-from-hr-csv.py --url https://helpdesk.example.com --csv hr-export.csv
#   python sync-users-from-hr-csv.py --url https://helpdesk.example.com --csv hr-export.csv --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# It needs the permissions in the @permissions line above.

import argparse
import csv
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Sync people from an HR CSV into FreeITSM.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The HR export to read.
parser.add_argument("--csv", default=str(Path(__file__).with_name("hr-export.csv")))
# Without --apply the script only reads and prints the plan: no create or update
# call is ever sent.
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


# ---- One helper for every call --------------------------------------------------
def api(method, path, body=None):
    """Call the API and return the "data" part of the reply.

    Success looks like {"data": ...}, failure like {"error": {"code", "message"}};
    on failure we stop and print FreeITSM's own message (e.g. 409 conflict when
    an address is already taken).
    """
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))["data"]
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def cell(row, column):
    """A CSV cell, trimmed; None when the cell is empty or the column is missing.
    None is our signal for "the spreadsheet says nothing about this field"."""
    value = (row.get(column) or "").strip()
    return value or None


# ---- Read the spreadsheet -------------------------------------------------------
# utf-8-sig, not plain utf-8: Excel's "CSV UTF-8" starts the file with an invisible
# marker (a BOM) that would otherwise end up glued to the first column's name.
csv_path = Path(args.csv)
if not csv_path.exists():
    sys.exit(f"CSV file not found: {csv_path}")
with csv_path.open(newline="", encoding="utf-8-sig") as f:
    rows = list(csv.DictReader(f))
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to sync"
print(f"{len(rows)} rows in {csv_path} -> {BASE}   [{mode}]")

# Check the whole file BEFORE touching FreeITSM, so a bad row stops the run at the
# start rather than half way through. Two rows for one address would make the
# second silently undo the first, so that is an error too.
YES, NO = {"yes", "true", "1"}, {"no", "false", "0"}
seen = {}
for line, row in enumerate(rows, start=2):   # the header is line 1
    email = cell(row, "email")
    if not email:
        sys.exit(f"Line {line}: the email cell is empty. Every row needs one - it is how people are matched.")
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email):
        sys.exit(f"Line {line}: '{email}' does not look like an email address.")
    active = cell(row, "active")
    if active and active.lower() not in YES | NO:
        sys.exit(f"Line {line}: active must be yes or no, not '{active}'.")
    if email.lower() in seen:
        sys.exit(f"Line {line}: {email} is also on line {seen[email.lower()]}. One row per person.")
    seen[email.lower()] = line

# ---- Sync each person -----------------------------------------------------------
created = updated = unchanged = 0
for row in rows:
    # FreeITSM stores addresses in lower case, and GET /users?email= compares
    # exactly, so lower-case it here as well.
    email = cell(row, "email").lower()

    # What the spreadsheet wants. Only columns with a value go in, so an empty
    # cell leaves FreeITSM's value alone.
    want = {}
    for column in ("display_name", "preferred_name", "employee_id"):
        value = cell(row, column)
        if value is not None:
            want[column] = value
    active = cell(row, "active")
    if active is not None:
        want["is_active"] = active.lower() in YES

    # Look the person up by address. quote() because an address may hold a "+"
    # (jo+it@example.com), which would otherwise arrive as a space.
    found = api("GET", "/users?email=" + urllib.parse.quote(email, safe=""))

    if not found:
        # ---- Not in FreeITSM: create ----
        # FreeITSM picks the person's company from the domain of their address,
        # the same as when you add someone by hand. Add "tenant_id" to body to set
        # it yourself (see System -> Companies for the ids).
        body = {"email": email, **{k: v for k, v in want.items() if k != "is_active"}}
        if not args.apply:
            print(f"  would create  {email}  ({want.get('display_name', 'name from the address')})")
            created += 1
            continue
        new = api("POST", "/users", body)
        print(f"  created       {email} -> person #{new['id']}")
        # POST /users has no is_active field - everyone starts active - so a row
        # that arrives already marked "no" needs a second call.
        if want.get("is_active") is False:
            api("PATCH", f"/users/{new['id']}", {"is_active": False})
            print("                and marked as left")
        created += 1
        continue

    # ---- Already there: compare field by field and send only the differences ----
    # Sending only what changed keeps the person's history honest and means a
    # second run with the same file changes nothing at all.
    user = found[0]
    patch, changes = {}, []
    for k, wanted in want.items():
        have = user.get(k)
        # Plain == is case-sensitive, so "jo" to "Jo" IS a change.
        same = bool(have) == wanted if k == "is_active" else (have or "") == wanted
        if not same:
            patch[k] = wanted
            changes.append(f"{k} '{have}' -> '{wanted}'")
    if not patch:
        print(f"  unchanged     {email} (#{user['id']})")
        unchanged += 1
        continue
    if not args.apply:
        print(f"  would update  {email} (#{user['id']}): {'; '.join(changes)}")
    else:
        api("PATCH", f"/users/{user['id']}", patch)
        print(f"  updated       {email} (#{user['id']}): {'; '.join(changes)}")
    updated += 1

# People in FreeITSM but NOT in the spreadsheet are deliberately left alone: an
# HR export often covers one office or one company, and treating "missing" as
# "left" would mark everyone else as a leaver. Use the "active" column instead.
verb = "" if args.apply else "would be "
print(f"\nDone: {created} {verb}created, {updated} {verb}updated, {unchanged} unchanged.")
