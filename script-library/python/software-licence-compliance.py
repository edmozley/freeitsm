# @title       Software licence compliance
# @summary     Compares the seats you have bought with the machines the software is installed on, and flags anything over-deployed or due for renewal.
# @module      software
# @changes     no
# @level       intermediate
# @endpoints   GET /software/licences, GET /software/apps/{id}
# @permissions software_licences.read, software_inventory.read
#
# WHAT IT SHOWS: one line per licensed application - seats bought, machines it is
# installed on, and the difference - with the ones that are OVER (more installs
# than seats) at the top. Licences past or near their renewal date are listed
# underneath. Run it before a vendor audit, or monthly as a scheduled task.
#
# HOW IT WORKS - two endpoints, each doing what it is best at:
#   1. GET /software/licences, every page: which applications you hold licences
#      for, and each licence's renewal date and status.
#   2. GET /software/apps/{id} for each of those applications: its "compliance"
#      block, which FreeITSM works out with the same rules as the Software
#      screen - only Active licences count, add up their seats, count distinct
#      machines with it installed, and ignore system components (runtimes and
#      updates that Windows lists but nobody buys). Asking FreeITSM rather than
#      adding up ourselves means this report and the screen always agree.
#
# A licence with no seat count (a site licence, for example) makes FreeITSM
# report the application as "unmetered": covered whatever the number of installs.
#
# Software you have NOT recorded a licence for does not appear here - the report
# starts from licences. Add them under Software -> Licences to have them checked.
#
# EXIT CODE: 0 when everything is covered, 2 when anything is over-deployed - so a
# scheduled task or monitoring tool can raise an alert on the number alone.
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python software-licence-compliance.py --url https://helpdesk.example.com
#   python software-licence-compliance.py --url https://helpdesk.example.com --out licences.csv
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# It needs the two read permissions in the @permissions line - it changes nothing.

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
parser = argparse.ArgumentParser(description="Software licence compliance report.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Optional: also save the compliance table as a CSV file (opens in Excel).
parser.add_argument("--out")
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
    """Call the API and return the WHOLE reply: lists carry their paging details
    in "meta" beside "data". Stops with FreeITSM's own error message on failure."""
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


# ---- 1. Every licence, a page at a time -----------------------------------------
# No status filter: we want Expired and Cancelled ones too, to show an
# application whose only licence has lapsed. per_page=100 is the most allowed.
licences = []
page = 1
while True:
    reply = api("GET", f"/software/licences?per_page=100&sort=renewal_date&page={page}")
    licences.extend(reply["data"])
    if page >= reply["meta"]["total_pages"]:
        break
    page += 1
print(f"Read {len(licences)} licences from {BASE}")
if not licences:
    print("No licences recorded - add them under Software -> Licences.")
    sys.exit(0)

# ---- 2. The compliance numbers, one call per application --------------------------
# Several licences can belong to one application (bought in batches), so collect
# the distinct application ids first and ask about each one once.
app_ids = sorted({l["app"]["id"] for l in licences})
report = []
for app_id in app_ids:
    app = api("GET", f"/software/apps/{app_id}")["data"]
    c = app["compliance"]   # {installs, licensed_seats, unmetered_licences, seats_available}
    if c["unmetered_licences"]:
        state = "COVERED (unmetered licence)"
    elif c["seats_available"] < 0:
        state = "OVER"
    elif c["seats_available"] == 0:
        state = "FULL"   # compliant, but the next install is not
    else:
        state = "OK"
    report.append({
        "state": state,
        # How many installs are not covered; 0 when compliant. Unmetered = never short.
        "shortfall": 0 if c["unmetered_licences"] or c["seats_available"] >= 0 else -c["seats_available"],
        "application": app["name"],
        "publisher": app["publisher"] or "",
        "seats": "unlimited" if c["unmetered_licences"] else c["licensed_seats"],
        "installs": c["installs"],
        "app_id": app["id"],
    })
# Worst first: biggest shortfall at the top, then by name.
report.sort(key=lambda r: (-r["shortfall"], r["application"].lower()))

print("\nLicences against installations:")
print(f"  {'State':<28} {'Short':>5} {'Seats':>9} {'Installs':>8}  Application")
for r in report:
    print(f"  {r['state']:<28} {r['shortfall']:>5} {str(r['seats']):>9} {r['installs']:>8}  {r['application']} ({r['publisher']})")

over = [r for r in report if r["state"] == "OVER"]
if over:
    print(f"\n{len(over)} application(s) installed on more machines than you have seats for.")
    print("See which machines with GET /software/apps/{id}/machines, or on the Software screen.")
else:
    print("\nEvery licensed application is within its seats.")

# ---- Renewals worth a look -------------------------------------------------------
# FreeITSM works out renewal_status for each licence: "overdue" (date passed),
# "due_soon" (inside the licence's notice period, 30 days unless set), or "ok".
# Only Active licences matter here - an Expired one is already known to be over.
renewals = [l for l in licences if l["status"] == "Active" and l["renewal_status"] in ("overdue", "due_soon")]
if renewals:
    print("\nRenewals due:")
    for l in renewals:
        print(f"  {l['renewal_status']:<9} {l['renewal_date']}  {l['app']['name']} - {l['licence_type']}")

if args.out:
    # utf-8-sig adds the marker Excel looks for, so accented names open correctly.
    with open(args.out, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=list(report[0].keys()))
        writer.writeheader()
        writer.writerows(report)
    print(f"\nSaved {len(report)} rows to {args.out}")

# Exit code 2 = something is over-deployed (see the header).
sys.exit(2 if over else 0)
