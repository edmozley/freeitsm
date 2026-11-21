# @title       Workflow failures report
# @summary     Lists the workflows whose runs failed in the last 7 days, with how often, the failure rate and the latest error - as a table and optionally a CSV file.
# @module      workflows
# @changes     no
# @level       intermediate
# @endpoints   GET /workflows, GET /workflow-executions, GET /workflows/{id}/executions
# @permissions workflows.read, workflow_executions.read
#
# WHAT IT SHOWS: filtering a run log on the server, then grouping on the client.
# A workflow that fails does so quietly - the ticket simply is not assigned, the
# email is not sent - so a weekly look at the run log catches what nobody
# noticed. The script:
#
#   1. Reads every workflow once (GET /workflows), to know each one's trigger,
#      whether it is still switched on, and which company it belongs to.
#   2. Asks the install-wide run log (GET /workflow-executions) for the runs
#      that FAILED in the window, and the runs that were ABORTED (see below).
#   3. Groups them per workflow, and for each one asks how many runs it had in
#      the same window (GET /workflows/{id}/executions), to give a failure rate:
#      "3 of 3 failed" is broken, "3 of 900 failed" is a hiccup.
#   4. Prints the table, most failures first, and writes a CSV if asked.
#
# A run's status is one of: running, success, failed, skipped (its conditions did
# not match - normal), aborted (FreeITSM stopped it before it started: loop
# protection, or the module it needs is switched off). Aborted runs did not do
# their job either, so they are counted unless you pass --failed-only.
#
# A run whose workflow has since been DELETED is still in the log (the name is a
# snapshot taken when it ran); it is listed as "(deleted)".
#
# Run it (Python 3.8 or later, standard library only):
#   python workflow-failures-report.py --url https://helpdesk.example.com
#   python workflow-failures-report.py --url https://helpdesk.example.com --days 30 --out-file workflow-failures.csv
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass --key-file). A read-only key is
# enough: workflows.read and workflow_executions.read. A key limited to some
# companies sees only those companies' runs - and runs with no company (a
# settings event, say) need a key that reaches every company.

import argparse
import csv
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

# Names and errors can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Report FreeITSM workflows whose runs failed recently.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far back to look, by when the run STARTED.
parser.add_argument("--days", type=int, default=7)
# Count only 'failed' runs, not 'aborted' ones.
parser.add_argument("--failed-only", action="store_true")
# Also save the table to this CSV file. Leave it out to only print it.
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


# ---- One helper for every call --------------------------------------------------
def api(path):
    """GET a path and return the whole reply ({"data", "meta"}) because the run
    log pages; stop with FreeITSM's own error message on failure, e.g. 403
    "This key lacks ..."."""
    request = urllib.request.Request(
        BASE + path, headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"GET {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def get_all(path):
    """Every page of a paged list: {"data": [...], "meta": {"total_pages": 3}}."""
    rows, page = [], 1
    while True:
        sep = "&" if "?" in path else "?"
        reply = api(f"{path}{sep}per_page=100&page={page}")
        rows += reply["data"]
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


# ---- 1. Every workflow, by id -------------------------------------------------------
# GET /workflows is NOT paged - it returns every workflow the key can see in one
# reply, most recently updated first - so one call is enough.
workflows = {w["id"]: w for w in api("/workflows")["data"]}

# ---- 2. The failed (and aborted) runs in the window ----------------------------------
# started_since takes an ISO 8601 date and time; "Z" = UTC, which is how FreeITSM
# stores every date. status= takes ONE value, so it is one list per status.
since = (datetime.now(timezone.utc) - timedelta(days=args.days)).strftime("%Y-%m-%dT%H:%M:%SZ")
statuses = ["failed"] if args.failed_only else ["failed", "aborted"]
runs = []
for status in statuses:
    query = urllib.parse.urlencode({"status": status, "started_since": since})
    runs += get_all(f"/workflow-executions?{query}")
print(f"{len(runs)} run(s) did not complete since {since}")

# ---- 3. Group per workflow ----------------------------------------------------------
# A run of a deleted workflow has workflow.id = None; those are grouped by the
# name it had when it ran.
groups = {}
for r in runs:
    wid = r["workflow"]["id"]
    key = ("id", wid) if wid is not None else ("name", r["workflow"]["name"])
    g = groups.setdefault(key, {"id": wid, "name": r["workflow"]["name"], "failed": 0, "aborted": 0, "latest": None})
    g["failed" if r["status"] == "failed" else "aborted"] += 1
    # started_at is ISO 8601 in UTC, so comparing the text sorts by time.
    if g["latest"] is None or r["started_at"] > g["latest"]["started_at"]:
        g["latest"] = r


def local_time(iso):
    """"2026-11-21T09:30:00Z" -> "2026-11-21 09:30" in this computer's time zone."""
    return datetime.strptime(iso, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc).astimezone().strftime("%Y-%m-%d %H:%M")


rows = []
since_query = urllib.parse.urlencode({"started_since": since, "per_page": 1})
for g in groups.values():
    wf = workflows.get(g["id"]) if g["id"] is not None else None
    # How many runs in the same window, of any status? meta.total answers that
    # without fetching them: ask for a page of one and read the count.
    total_runs = ""
    if g["id"] is not None:
        workflow_id = g["id"]
        total_runs = api(f"/workflows/{workflow_id}/executions?{since_query}")["meta"]["total"]
    bad = g["failed"] + g["aborted"]
    if g["id"] is None:
        state = "(deleted)"
    else:
        state = "on" if wf and wf["is_active"] else "off"
    company = (wf["company"]["name"] if wf["company"] else "shared") if wf else ""
    rows.append({
        "Workflow": g["name"],
        "State": state,
        "Trigger": wf["trigger_event"] if wf else g["latest"]["trigger_event"],
        "Company": company,
        "Failed": g["failed"],
        "Aborted": g["aborted"],
        "Runs": total_runs,
        "FailRate": f"{round(100 * bad / total_runs)}%" if total_runs else "",
        "LatestAt": local_time(g["latest"]["started_at"]),
        "LatestRunId": g["latest"]["id"],
        "LatestError": g["latest"]["error_message"] or "",
    })

# ---- 4. The report ----------------------------------------------------------------
# Most failures first. Times are in this computer's time zone.
rows.sort(key=lambda r: (-(r["Failed"] + r["Aborted"]), r["Workflow"]))
print(f"\nWorkflow runs that did not complete - the last {args.days} day(s)")
if not rows:
    print("  none - every run in the window finished.")
else:
    # The table shortens long errors; the CSV keeps them whole. Open a run in
    # Workflows -> Run log (or GET /workflow-executions/{id}) for its step log.
    headings = ("Workflow", "State", "Trigger", "Failed", "Aborted", "Runs", "FailRate", "LatestAt", "LatestError")
    table = [headings] + [tuple(
        (str(r[h])[:67] + "...") if h == "LatestError" and len(str(r[h])) > 70 else str(r[h])
        for h in headings) for r in rows]
    widths = [max(len(row[c]) for row in table) for c in range(len(headings))]
    for row in table:
        print("  " + "  ".join(v.ljust(w) for v, w in zip(row, widths)))
    off = sum(1 for r in rows if r["State"] == "off")
    if off:
        print(f"\n  {off} of these are switched off now - perhaps already dealt with.")
if args.out_file:
    # utf-8-sig writes a byte order mark, which is what Excel needs to show
    # accented names correctly.
    with open(args.out_file, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=["Workflow", "State", "Trigger", "Company", "Failed", "Aborted",
                                               "Runs", "FailRate", "LatestAt", "LatestRunId", "LatestError"])
        writer.writeheader()
        writer.writerows(rows)
    print(f"\nSaved to {args.out_file}")
