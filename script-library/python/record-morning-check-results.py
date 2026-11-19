# @title       Record morning check results
# @summary     Runs a simple test for each of your morning checks - ping, an open port or a web page - and records Green or Red on today's board.
# @module      morning-checks
# @changes     yes
# @level       intermediate
# @endpoints   GET /morning-checks/board, GET /morning-check-statuses, POST /morning-checks/results
# @permissions morning_check_results.read, reference.read, morning_check_results.record
#
# WHAT IT DOES: many morning checks are "is it up?" questions a machine can answer
# before anyone is in. This script:
#   1. reads today's Morning Checks board - every active check, and any result
#      already recorded for today,
#   2. for each check it knows how to test (the TESTS table just below), runs
#      the test from THIS computer: a ping, a TCP port, or a web page,
#   3. records the result - your "pass" status (Green) or your "fail" status
#      (Red) - with a note saying exactly what was tested and what happened.
#
# Checks not in the table are left alone for a person to do. Checks that already
# have a result today are skipped unless you add --overwrite, so a result an
# analyst typed in by hand is never replaced by the script - and running it twice
# records nothing new the second time.
#
# Run it - first WITHOUT --apply to see the test results without recording them:
#   python record-morning-check-results.py --url https://helpdesk.example.com
#   python record-morning-check-results.py --url https://helpdesk.example.com --apply
#
# Run it on a machine that can reach the things being tested (a management
# server, say), from cron or Task Scheduler at 07:00, and the board is half done
# before the first analyst logs in.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.

import argparse
import datetime
import json
import os
import platform
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- YOUR CHECKS: edit this table -----------------------------------------------
# check  = the check's name EXACTLY as it is on the Morning Checks board.
# test   = ping  - target is a host name or IP address; passes if it replies.
#          tcp   - target is host:port; passes if the port accepts a connection
#                  (a database, RDP, SMTP...). Use this where ping is blocked.
#          url   - target is a web address; passes on any answer below 400.
# The three below are examples - replace them with your own.
TESTS = [
    {"check": "File server pings",    "test": "ping", "target": "fs01.example.local"},
    {"check": "SQL server port open", "test": "tcp",  "target": "sql01.example.local:1433"},
    {"check": "Intranet home page",   "test": "url",  "target": "https://intranet.example.local/"},
]

parser = argparse.ArgumentParser(description="Run simple tests and record morning check results in FreeITSM.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Optional: a JSON file holding the same table as TESTS above, if you would rather
# keep your list outside the script, e.g.
#   [ { "check": "File server pings", "test": "ping", "target": "fs01" } ]
parser.add_argument("--tests-file", default="")
# The status labels to record. They must match YOUR Morning Checks statuses
# (Morning Checks -> Settings); out of the box they are Green, Amber and Red.
parser.add_argument("--pass-status", default="Green")
parser.add_argument("--fail-status", default="Red")
# The day to record against, as YYYY-MM-DD. Defaults to today on THIS computer.
parser.add_argument("--date", default=datetime.date.today().isoformat())
# Replace results already recorded for the day. Off by default so a result an
# analyst entered by hand is never overwritten.
parser.add_argument("--overwrite", action="store_true")
# Without --apply the tests run and are printed, but nothing is recorded.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if args.tests_file:
    # Accept "Check"/"Test"/"Target" too, so one file serves both languages.
    TESTS = [{k.lower(): v for k, v in row.items()}
             for row in json.loads(Path(args.tests_file).read_text(encoding="utf-8"))]

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
    """Call the API; return the "data" part, or stop with FreeITSM's own message
    (for example 422 "Notes are required for the 'Red' status")."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read().decode("utf-8"))["data"]
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


# ---- The three tests ---------------------------------------------------------------
# Each returns (ok, detail). The detail goes into the result's notes, so whoever
# reads the board sees WHY it is red.
def test_ping(host):
    # One echo request, using the operating system's own ping command: Windows
    # counts with -n, Linux and macOS with -c. Many firewalls drop ping - use a
    # tcp test for those.
    count = "-n" if platform.system() == "Windows" else "-c"
    result = subprocess.run(["ping", count, "1", host], capture_output=True, timeout=30)
    # Windows ping can exit 0 on "Destination host unreachable", so also look for a
    # TTL in the reply - only a real answer has one.
    ok = result.returncode == 0 and b"ttl=" in result.stdout.lower()
    return (True, f"ping {host} replied") if ok else (False, f"ping {host} - no reply")


def test_tcp(target, timeout=5):
    host, port = target.rsplit(":", 1)
    try:
        # A timeout, because a host that silently drops the packet would otherwise
        # keep us waiting 20 seconds or more.
        with socket.create_connection((host, int(port)), timeout=timeout):
            return True, f"port {port} on {host} is open"
    except OSError as e:
        return False, f"port {port} on {host} - {e}"


def test_url(address):
    started = time.monotonic()
    try:
        # urlopen follows redirects and raises HTTPError for 4xx/5xx answers.
        with urllib.request.urlopen(address, timeout=15) as response:
            ms = int((time.monotonic() - started) * 1000)
            return True, f"{address} answered HTTP {response.status} in {ms} ms"
    except urllib.error.HTTPError as e:
        return False, f"{address} - HTTP {e.code}"
    except (urllib.error.URLError, OSError) as e:
        return False, f"{address} - {getattr(e, 'reason', e)}"


RUNNERS = {"ping": test_ping, "tcp": test_tcp, "url": test_url}

mode = "APPLYING" if args.apply else "PREVIEW - tests run, nothing is recorded; add --apply to record"
print(f"Morning checks for {args.date} on {BASE}   [{mode}]")

# ---- 1. Look up your pass and fail statuses ------------------------------------------
# Statuses are configurable, so we check the labels exist and are active before
# testing anything. requires_notes matters: FreeITSM refuses a result in a status
# that needs notes when none are sent - this script always sends a note anyway.
statuses = [s for s in api("GET", "/morning-check-statuses") if s["is_active"]]
by_label = {s["label"].lower(): s for s in statuses}
labels = ", ".join(s["label"] for s in statuses)
for wanted in (args.pass_status, args.fail_status):
    if wanted.lower() not in by_label:
        sys.exit(f"'{wanted}' is not an active morning check status. Yours are: {labels}")
passed, failed = by_label[args.pass_status.lower()], by_label[args.fail_status.lower()]

# ---- 2. Read the day's board --------------------------------------------------------
# The board is every ACTIVE check with its result for that date, or null if
# nobody has done it yet - exactly what the Morning Checks screen shows.
board = api("GET", f"/morning-checks/board?date={args.date}")
tests = {t["check"].lower(): t for t in TESTS}
recorded = 0
for check in board["checks"]:
    test = tests.get(check["name"].lower())
    if test is None:
        print(f"  -       {check['name']}: no automatic test - left for a person")
        continue
    if check["result"] and not args.overwrite:
        r = check["result"]
        already = r["status"]["label"] if r["status"] else r["orphan_label"]
        print(f"  skip    {check['name']}: already recorded today as {already} (add --overwrite to replace)")
        continue

    # ---- 3. Run the test --------------------------------------------------------------
    runner = RUNNERS.get(test["test"])
    if runner is None:
        sys.exit(f"Check '{check['name']}': unknown test '{test['test']}' - use ping, tcp or url.")
    ok, detail = runner(test["target"])
    status = passed if ok else failed
    print(f"  {status['label']:<7} {check['name']}: {detail}")
    if not args.apply:
        continue

    # ---- 4. Record it -------------------------------------------------------------------
    # One result per check per day: posting again for the same day replaces it.
    # The analyst the key acts as is recorded as the one who did it.
    api("POST", "/morning-checks/results", {
        "check_id": check["id"],
        "status_id": status["id"],
        "date": args.date,
        "notes": f"Automatic check: {detail}",
    })
    recorded += 1
if args.apply:
    print(f"\nRecorded {recorded} result(s).")
