# @title       Warranty expiry report
# @summary     Lists the assets whose warranty runs out in the next N days, and those already out of warranty, optionally saved as a CSV.
# @module      assets
# @changes     no
# @level       starter
# @endpoints   GET /assets
# @permissions assets.read
#
# WHAT IT SHOWS: two lists - assets whose warranty has already ended, and assets
# whose warranty ends within the next --days days (90 unless you say otherwise) -
# soonest first, with how many days are left. Handy for a monthly "what needs
# renewing or replacing" email.
#
# WHAT IT TEACHES:
#   - PAGING. GET /assets returns at most 100 assets per call. The reply's "meta"
#     part says how many pages there are, so we ask for page 1, 2, 3... until done.
#   - DATE LOGIC ON THE CLIENT. FreeITSM gives each asset's warranty end as a
#     plain date (lifecycle.warranty_expiry, "2027-03-31", or null when nobody
#     recorded one). The script works out the days left itself.
#     (FreeITSM can also filter for you - GET /assets?warranty_within_days=90 -
#     which is quicker on a big estate. Doing it here shows how, and lets one pass
#     produce both lists plus a count of assets with no date at all.)
#
# Retired assets are left out, as on the asset screens. Leased kit has its own
# end date: change "warranty_expiry" to "lease_expiry" below for a lease report.
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python warranty-expiry-report.py --url https://helpdesk.example.com
#   python warranty-expiry-report.py --url https://helpdesk.example.com --days 180 --out warranty.csv
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# A key with just assets.read is enough - this script only reads.

import argparse
import csv
import json
import os
import sys
import urllib.error
import urllib.request
from datetime import date
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Report assets whose warranty is ending.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far ahead to look, in days.
parser.add_argument("--days", type=int, default=90)
# Optional: also save the report as a CSV file (opens in Excel).
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
    """Call the API and return the WHOLE reply, not just "data": list endpoints
    put the paging details in "meta" beside it, and we need both. On failure stop
    with FreeITSM's own {"error": {"code", "message"}} explanation."""
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


# ---- Fetch every asset, a page at a time ------------------------------------------
# per_page=100 is the most FreeITSM allows. meta looks like
# {"page": 1, "per_page": 100, "total": 734, "total_pages": 8}.
assets = []
page = 1
while True:
    reply = api("GET", f"/assets?per_page=100&sort=warranty_expiry&page={page}")
    assets.extend(reply["data"])
    total_pages = reply["meta"]["total_pages"]
    print(f"  read page {page} of {max(1, total_pages)}")
    if page >= total_pages:
        break
    page += 1

# ---- Work out the days left for each one ------------------------------------------
today = date.today()
rows = []
no_date = 0
for a in assets:
    end = a["lifecycle"]["warranty_expiry"]
    if not end:
        no_date += 1
        continue
    # date.fromisoformat reads "2027-03-31" exactly; subtracting two dates gives
    # whole days, so "ends today" is 0.
    left = (date.fromisoformat(end) - today).days
    if left > args.days:
        continue   # comfortably in warranty - not in the report
    rows.append({
        "days_left": left,
        "warranty_ends": end,
        "hostname": a["hostname"],
        "type": (a["type"] or {}).get("name") or "",
        "manufacturer": a["hardware"]["manufacturer"] or "",
        "model": a["hardware"]["model"] or "",
        "serial": a["hardware"]["service_tag"] or "",
        "asset_id": a["id"],
    })
rows.sort(key=lambda r: r["days_left"])
expired = [r for r in rows if r["days_left"] < 0]
expiring = [r for r in rows if r["days_left"] >= 0]


def show(items):
    for r in items:
        print(f"  {r['days_left']:>6}  {r['warranty_ends']}  {r['hostname']:<20} {r['type']:<12} {r['model']:<24} {r['serial']}")


# ---- Print it -------------------------------------------------------------------
print(f"\nWarranty report for {BASE} - {len(assets)} assets checked, {today.isoformat()}")
print(f"\nAlready out of warranty: {len(expired)}")
show(expired)
print(f"\nWarranty ends in the next {args.days} days: {len(expiring)}")
show(expiring)
# Worth knowing: an asset with no date can never appear above, so a big number
# here means the report is quieter than it should be.
print(f"\nAssets with no warranty date recorded: {no_date}")

if args.out:
    # utf-8-sig adds the marker Excel looks for, so accented names open correctly.
    with open(args.out, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()) if rows else ["days_left"])
        writer.writeheader()
        writer.writerows(rows)
    print(f"Saved {len(rows)} rows to {args.out}")
