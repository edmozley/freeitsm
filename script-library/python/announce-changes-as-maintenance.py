# @title       Announce approved changes as planned maintenance
# @summary     Puts a "planned maintenance" notice on the status page for each approved change scheduled in the next few days - one per change, never twice - and can resolve them once the work is over.
# @module      service-status
# @changes     yes
# @level       intermediate
# @endpoints   GET /change-statuses, GET /changes, GET /service-status/services, GET /service-impact-levels,
#              GET /service-incident-statuses, GET /service-status/incidents, POST /service-status/incidents,
#              PATCH /service-status/incidents/{id}
# @permissions reference.read, changes.read, services.read, service_incidents.read, service_incidents.create,
#              service_incidents.update
#
# WHAT IT DOES: users hate surprises. Once a change is approved and has a date,
# this script tells them, on the Service Status board:
#
#   1. finds the change statuses that mean "approved and going ahead" - by their
#      STAGE (approved, implementing), so renamed statuses still work,
#   2. lists the changes in those statuses whose work starts in the next --days,
#   3. works out which status-page services each change touches: the services
#      whose name appears in the change's title ("Upgrade Email firmware" ->
#      Email), or else the --service you give as the default,
#   4. looks for an announcement already made for that change - its title
#      starts with the change reference, e.g. "[CHG-0042]" - then
#   5. creates one if there is none, or refreshes its message if the change has
#      been moved to a new time since. A change is never announced twice.
#   6. with --resolve-finished: resolves the announcements whose change has
#      finished (its work end has passed, or it was closed or cancelled).
#
# READ THIS FIRST - how FreeITSM shows it. A status-page incident has no start
# time of its own: from the moment it is created, the services on it show the
# impact you chose (Maintenance) until it is resolved. So an announcement made
# today for work on Saturday shows "Maintenance" from today. That is why the
# window is only 2 days ahead by default; use --days 1 to announce the day before.
#
# The Service Status SCREEN has a proper "Planned maintenance" list that waits
# until the start time before it turns into an incident - but the REST API does
# not offer it (yet), so a script cannot use it. When it does, this script
# should move to it; until then, this is the closest a script can get.
#
# Run it - first WITHOUT --apply to see what it would do:
#   python announce-changes-as-maintenance.py --url https://helpdesk.example.com --service "Network"
#   python announce-changes-as-maintenance.py --url https://helpdesk.example.com --service "Network" --apply --resolve-finished
# Schedule it daily; each run only adds what is new.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Announce approved changes as planned maintenance on the status page.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far ahead to look, in days from now.
parser.add_argument("--days", type=int, default=2)
# Which change stages count as "going ahead". Each change status has a stage:
# draft, awaiting, approved, implementing, done or stopped. Out of the box
# "Approved" is approved, and "Scheduled" and "In Progress" are implementing.
parser.add_argument("--stages", default="approved,implementing")
# The service(s) to use when a change's title names none, comma-separated,
# exactly as on the Service Status board. Empty = such changes are skipped.
parser.add_argument("--service", default="")
# The impact to show. Must be one of YOUR impact levels - out of the box the
# list includes Maintenance, which is meant for exactly this.
parser.add_argument("--impact", default="Maintenance")
# The incident status for a new announcement. Empty = FreeITSM's default status
# (Investigating, out of the box) - which reads oddly for planned work, so
# consider adding a status such as "Scheduled" under Service Status -> Settings
# and passing its name here.
parser.add_argument("--incident-status", default="")
# Also resolve announcements whose change is finished.
parser.add_argument("--resolve-finished", action="store_true")
# Updates are INTERNAL unless you say otherwise, so a script can never publish to
# the self-service portal by accident. Add --public once you are happy - that is
# the point of an announcement, but it is your call.
parser.add_argument("--public", action="store_true")
# Without --apply nothing is written: it reads, then says what it would do.
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
    """Call the API and return the whole reply ({"data", "meta"}) - the lists need
    meta for paging. Stops with FreeITSM's own message on failure
    (for example 422 "Unknown or inactive impact level")."""
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


def get_all(path, params):
    """Every row of a paged list (at most 100 a page; meta.total_pages says when to stop)."""
    rows, page = [], 1
    while True:
        reply = api("GET", f"{path}?" + urllib.parse.urlencode({**params, "per_page": 100, "page": page}))
        rows.extend(reply["data"])
        page += 1
        if page > reply["meta"]["total_pages"]:
            return rows


def nice(when):
    """'2026-11-25T22:00:00' -> '2026-11-25 22:00'. Change times are WALL-CLOCK
    times - exactly as the change says, with no time zone - so they are shown as is."""
    return when.replace("T", " ")[:16] if when else "?"


def prefix(change):
    """How an announcement names its change - and how the next run finds it."""
    return f"[{change['change_number']}]"


mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply"
print(f"Planned maintenance from changes on {BASE}   [{mode}]")

# ---- 1. Check every name typed above against YOUR lists -------------------------------
# All of these can be renamed in Settings, so we read them rather than trusting
# a name. Doing it BEFORE any write means a typo stops the run with a helpful list.
stages = {s.strip().lower() for s in args.stages.split(",") if s.strip()}
statuses = [s for s in api("GET", "/change-statuses")["data"]
            if s["is_active"] and not s["is_closed"] and (s["stage"] or "") in stages]
if not statuses:
    sys.exit(f"No active, open change status has a stage in: {', '.join(sorted(stages))}")
print("Change statuses: " + ", ".join(s["name"] for s in statuses))

levels = [lv for lv in api("GET", "/service-impact-levels")["data"] if lv["is_active"]]
level = next((lv for lv in levels if lv["name"].lower() == args.impact.lower()), None)
if level is None:
    sys.exit(f"'{args.impact}' is not an active impact level. Yours are: " + ", ".join(lv["name"] for lv in levels))

incident_statuses = [s for s in api("GET", "/service-incident-statuses")["data"] if s["is_active"]]
new_status = None
if args.incident_status:
    new_status = next((s for s in incident_statuses if s["name"].lower() == args.incident_status.lower()), None)
    if new_status is None:
        sys.exit(f"'{args.incident_status}' is not an active incident status. Yours are: "
                 + ", ".join(s["name"] for s in incident_statuses))
resolved_status = next((s for s in incident_statuses if s["is_resolved"]), None)
if args.resolve_finished and resolved_status is None:
    sys.exit("--resolve-finished needs an active incident status marked as resolved (Service Status -> Settings).")

# Only ACTIVE services are listed - an inactive one is not on the board.
services = api("GET", "/service-status/services")["data"]
by_name = {s["name"].lower(): s for s in services}
default_services = []
for name in [n.strip() for n in args.service.split(",") if n.strip()]:
    if name.lower() not in by_name:
        sys.exit(f"No active service is called '{name}'. Yours are: " + ", ".join(s["name"] for s in services))
    default_services.append(by_name[name.lower()])


def services_for(change):
    """Every service whose whole name appears as words in the change's title (so
    "Mail" does not match "Voicemail"), or else the --service default."""
    title = change["title"].lower()
    found = [s for s in services if re.search(r"(?<!\w)" + re.escape(s["name"].lower()) + r"(?!\w)", title)]
    return found or default_services


# ---- 2. The changes going ahead in the window ------------------------------------------
# work_start_from / work_start_to filter on the change's work start. They are
# wall-clock times too - no Z, no offset - so we send this computer's local time.
# status_id takes one status, so we ask once per status.
now = datetime.now().replace(microsecond=0)
window = {"work_start_from": now.isoformat(), "work_start_to": (now + timedelta(days=args.days)).isoformat(),
          "sort": "work_start_at"}
changes = []
for st in statuses:
    changes.extend(get_all("/changes", {**window, "status_id": st["id"]}))
changes.sort(key=lambda c: c["schedule"]["work_start_at"] or "")
print(f"{len(changes)} change(s) start in the next {args.days} day(s).\n")

# All announcements this script has made - open and resolved. q= is a "contains"
# search on the title; every one of ours starts "[CHG-". Read once, used below.
announced = get_all("/service-status/incidents", {"q": "[CHG-"})

made = refreshed = 0
for change in changes:
    sched = change["schedule"]
    # The outage window is what users feel; fall back to the work window.
    start = sched["outage_start_at"] or sched["work_start_at"]
    end = sched["outage_end_at"] or sched["work_end_at"]
    message = (f"Planned maintenance from {nice(start)} to {nice(end)} for change {change['change_number']}: "
               f"{change['title']}.")
    title = f"{prefix(change)} Planned maintenance: {change['title']}"

    # ---- 4. Already announced? -------------------------------------------------------
    existing = next((i for i in announced if i["title"].startswith(prefix(change) + " ")), None)
    if existing:
        if existing["status"] and existing["status"]["is_resolved"]:
            print(f"  done    {change['change_number']}: announcement #{existing['id']} is already resolved - left alone")
        elif (existing["comment"] or "") == message:
            print(f"  same    {change['change_number']}: already announced as #{existing['id']}")
        else:
            # The change was moved (or retitled) since it was announced: refresh the
            # message. FreeITSM logs this as a new update on the announcement.
            print(f"  UPDATE  {change['change_number']}: #{existing['id']} - {message}")
            if args.apply:
                api("PATCH", f"/service-status/incidents/{existing['id']}", {"comment": message, "is_internal": not args.public})
            refreshed += 1
        continue

    # ---- 5. Announce it --------------------------------------------------------------
    affected = services_for(change)
    if not affected:
        print(f"  ?       {change['change_number']} '{change['title']}': names no service - pass --service to give a default")
        continue
    print(f"  NEW     {change['change_number']} on {', '.join(s['name'] for s in affected)}: {message}")
    body = {
        "title": title,
        # Each affected service at the maintenance impact - what turns its dot on
        # the board to the Maintenance colour.
        "services": [{"service_id": s["id"], "impact_level_id": level["id"]} for s in affected],
        "comment": message,
        "is_internal": not args.public,
    }
    if new_status:
        body["status_id"] = new_status["id"]
    if args.apply:
        inc = api("POST", "/service-status/incidents", body)["data"]
        print(f"          announced as incident #{inc['id']}")
    made += 1

# ---- 6. Resolve the announcements whose change is over --------------------------------
resolved = 0
if args.resolve_finished:
    for inc in announced:
        if inc["status"] and inc["status"]["is_resolved"]:
            continue
        m = re.match(r"\[(CHG-\d+)\]", inc["title"])
        if not m:
            continue
        # q=CHG-0042 finds a change by its reference (any status, open or closed).
        change = next((c for c in api("GET", "/changes?" + urllib.parse.urlencode({"q": m.group(1)}))["data"]
                       if c["change_number"] == m.group(1)), None)
        if change is None:
            continue                                 # deleted, or outside this key's companies - leave it to a person
        end = change["schedule"]["work_end_at"]
        over = change["status"] and change["status"]["is_closed"]
        if not over and end and end <= now.isoformat():
            over = True                              # ISO text compares in time order
        if not over:
            continue
        why = f"is {change['status']['name']}" if change["status"] and change["status"]["is_closed"] else f"ended {nice(end)}"
        print(f"  RESOLVE #{inc['id']}: {m.group(1)} {why}")
        if args.apply:
            api("PATCH", f"/service-status/incidents/{inc['id']}", {
                "status_id": resolved_status["id"],
                "comment": f"The planned maintenance for {m.group(1)} is complete.",
                "is_internal": not args.public,
            })
        resolved += 1

verb = "" if args.apply else "would be "
print(f"\n{made} announcement(s) {verb}made, {refreshed} {verb}refreshed, {resolved} {verb}resolved.")
