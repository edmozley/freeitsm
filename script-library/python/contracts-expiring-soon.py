# @title       Contracts expiring soon
# @summary     Lists active contracts ending - or reaching their notice deadline - in the next few weeks, as plain text ready to paste into an email.
# @module      contracts
# @changes     no
# @level       starter
# @endpoints   GET /contracts
# @permissions contracts.read
#
# WHAT IT DOES: asks FreeITSM for two lists and prints them as a short report:
#   1. contracts whose NOTICE date falls in the window - the last day to tell the
#      supplier you are leaving or renegotiating. This is usually the date that
#      matters: miss it and many contracts renew themselves for another term.
#   2. contracts whose END date falls in the window.
# Both lists only include ACTIVE contracts - FreeITSM applies that rule itself.
#
# It only reads, so it is safe to run whenever you like - for example every
# Monday from cron or Task Scheduler, with --out-file, and attach or paste the file.
#
# Run it (standard library only):
#   python contracts-expiring-soon.py --url https://helpdesk.example.com
#   python contracts-expiring-soon.py --url https://helpdesk.example.com --days 90 --out-file renewals.txt
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# The report shows only the contracts the key's analyst and companies may see.

import argparse
import datetime
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

parser = argparse.ArgumentParser(description="Report contracts ending or reaching notice soon.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far ahead to look, in days from today.
parser.add_argument("--days", type=int, default=60)
# Also save the report to this file (UTF-8). Leave it out to only print it.
parser.add_argument("--out-file", default="")
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


# ---- Fetch every page of a list ---------------------------------------------------
def get_all(path):
    """Return every row of a list, page by page.

    Lists come back a page at a time: {"data": [...], "meta": {"page": 1,
    "per_page": 100, "total": 230, "total_pages": 3}}. 100 is the most a page can
    hold, so we ask for 100 and keep going until we have them all. On failure we
    stop with FreeITSM's own explanation, e.g. 403 "This key lacks contracts.read".
    """
    rows, page = [], 1
    while True:
        sep = "&" if "?" in path else "?"
        request = urllib.request.Request(
            f"{BASE}{path}{sep}per_page=100&page={page}",
            headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                reply = json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            sys.exit(f"GET {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
        except urllib.error.URLError as e:
            sys.exit(f"Could not reach {BASE}: {e.reason}")
        rows += reply["data"]
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


# ---- Ask for the two lists ------------------------------------------------------------
# The window filters are worked out by FreeITSM against TODAY in its own time
# zone, so the script needs no date arithmetic to choose the contracts.
# sort=contract_end puts the most urgent first; notice_date is not sortable, so
# that list is sorted here.
by_notice = sorted(get_all(f"/contracts?notice_within_days={args.days}&sort=contract_end"),
                   key=lambda c: c["dates"]["notice_date"])
by_end = get_all(f"/contracts?expiring_within_days={args.days}&sort=contract_end")

today = datetime.date.today()


# ---- Turn one contract into one readable line ---------------------------------------
def line(c, date_text):
    when = datetime.date.fromisoformat(date_text)
    left = (when - today).days
    left_text = "today" if left == 0 else "tomorrow" if left == 1 else f"in {left} days"
    # Who the contract is with: a supplier, or for a customer contract the
    # customer company or person.
    customer = c.get("customer") or {}
    if c.get("supplier"):
        party = c["supplier"]["name"]
    elif customer.get("company"):
        party = customer["company"]["name"]
    elif customer.get("person"):
        party = customer["person"]["name"]
    else:
        party = "no supplier recorded"
    amount = c["value"]["amount"]
    value = ""
    if amount is not None:
        value = " - " + " ".join(part for part in (c["value"]["currency"], f"{amount:,.2f}") if part)
    owner = f" - owner {c['owner']['name']}" if c.get("owner") else ""
    return f"  {when.strftime('%a')} {when.day} {when.strftime('%b %Y')} ({left_text})  {c['contract_number']} {c['title']} - {party}{value}{owner}"


# ---- Build the report ---------------------------------------------------------------
lines = [f"Contracts - the next {args.days} days (as of {today.day} {today.strftime('%B %Y')})", ""]
lines.append(f"Notice deadlines ({len(by_notice)}) - tell the supplier by this date:")
lines += [line(c, c["dates"]["notice_date"]) for c in by_notice] or ["  none"]
lines.append("")
lines.append(f"Contracts ending ({len(by_end)}):")
lines += [line(c, c["dates"]["end"]) for c in by_end] or ["  none"]
lines.append("")
lines.append(f"Full details: {args.url.rstrip('/')}/contracts/")

report = "\n".join(lines)
print(report)
if args.out_file:
    Path(args.out_file).write_text(report + "\n", encoding="utf-8")
    print(f"\nSaved to {args.out_file}")
