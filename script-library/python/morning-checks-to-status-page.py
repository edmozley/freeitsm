# @title       Red morning checks to the status page
# @summary     Reads today's morning checks and declares an incident on the matching status-page service for each one that failed - or updates the incident already open - and can resolve them once the checks are green again.
# @module      service-status
# @changes     yes
# @level       intermediate
# @endpoints   GET /morning-checks/board, GET /morning-check-statuses, GET /service-status/services,
#              GET /service-impact-levels, GET /service-incident-statuses,
#              GET /service-status/incidents, POST /service-status/incidents, PATCH /service-status/incidents/{id}
# @permissions morning_check_results.read, reference.read, services.read, service_incidents.read,
#              service_incidents.create, service_incidents.update
#
# WHAT IT DOES: the morning checks say what is broken; the status page tells
# your users. This script joins the two, so a red check at 07:00 is on the
# status page before the phones start ringing:
#
#   1. reads the day's Morning Checks board (every active check and its result),
#   2. works out which status-page SERVICE each check belongs to - from the
#      MAP below (or a --map-file), or else the service whose name appears in
#      the check's name ("Email server pings" -> "Email"),
#   3. for each service with a check in a failing status (Red by default):
#        - no open incident on it    -> declares one,
#        - the open incident is OURS -> updates it with today's failures,
#        - a PERSON declared it      -> leaves it alone (they are on it, and a
#                                       script should not rewrite their words),
#   4. with --resolve: resolves OUR incident on a service whose checks are all
#      green again. An incident a person declared is never resolved by a script.
#
# "Ours" = the incident's title is exactly "<service> - morning checks failing".
# The title is how the next run recognises its own work, so do not rename these
# incidents by hand (or the next run will declare a new one beside it).
#
# Running it twice changes nothing the second time: FreeITSM only records a new
# update on an incident when its status, message or services actually change,
# and the message here is built from the board, which has not changed.
#
# Run it - first WITHOUT --apply to see what it would do:
#   python morning-checks-to-status-page.py --url https://helpdesk.example.com
#   python morning-checks-to-status-page.py --url https://helpdesk.example.com --apply --resolve
# Schedule it a few minutes after the checks are done (or after
# record-morning-check-results.py), and again later in the morning so
# incidents resolve when the checks are re-done green.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.

import argparse
import datetime
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

# ---- YOUR MAP: edit this, or pass --map-file ------------------------------------
# check name (exactly as on the Morning Checks board) -> service name (exactly as
# on the Service Status board). A list maps one check to several services.
# Checks not listed here fall back to the name match described above; checks
# that match nothing are reported and left out.
MAP = {
    # "SQL server port open": "Finance system",
    # "Internet line pings":  ["Email", "Website"],
}

parser = argparse.ArgumentParser(description="Turn failed morning checks into status-page incidents.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Optional: a JSON file with the same shape as MAP above, e.g.
#   { "SQL server port open": "Finance system", "Internet line pings": ["Email", "Website"] }
# Its entries are added to (and win over) the ones in MAP.
parser.add_argument("--map-file", default="")
# Which morning check statuses mean "failed", comma-separated - YOUR labels
# (Morning Checks -> Settings). Out of the box: Green, Amber and Red.
parser.add_argument("--fail-status", default="Red")
# Which status means "fine again" - used by --resolve.
parser.add_argument("--ok-status", default="Green")
# The impact to show on the status page. Must be one of YOUR impact levels
# (Service Status -> Settings) - out of the box: Major Outage, Partial Outage,
# Degraded, Maintenance, Operational.
parser.add_argument("--impact", default="Partial Outage")
# The day's board to read, as YYYY-MM-DD. Defaults to today on THIS computer.
parser.add_argument("--date", default=datetime.date.today().isoformat())
# Also resolve OUR incidents whose checks are all green again.
parser.add_argument("--resolve", action="store_true")
# Updates are INTERNAL unless you say otherwise, so a script can never publish to
# the self-service portal by accident. Add --public to let users see them.
parser.add_argument("--public", action="store_true")
# Without --apply nothing is written: it reads, then says what it would do.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if args.map_file:
    MAP.update(json.loads(Path(args.map_file).read_text(encoding="utf-8")))

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
    (for example 422 "Unknown or inactive impact level")."""
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


def our_title(service_name):
    """The title this script gives its incidents - and how it finds them again."""
    return f"{service_name} - morning checks failing"


mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply"
print(f"Morning checks for {args.date} -> status page on {BASE}   [{mode}]")

# ---- 1. Check every name typed above against YOUR lists -------------------------------
# Morning check statuses, impact levels and incident statuses can all be renamed
# in Settings, so we read them rather than trusting a name. Doing it BEFORE any
# write means a typo stops the run with a helpful list instead of a half-made change.
mc_statuses = [s for s in api("GET", "/morning-check-statuses") if s["is_active"]]
mc_labels = {s["label"].lower() for s in mc_statuses}
fail_labels = {x.strip().lower() for x in args.fail_status.split(",") if x.strip()}
for wanted in fail_labels | {args.ok_status.lower()}:
    if wanted not in mc_labels:
        sys.exit(f"'{wanted}' is not an active morning check status. Yours are: " + ", ".join(s["label"] for s in mc_statuses))

levels = [lv for lv in api("GET", "/service-impact-levels") if lv["is_active"]]
level = next((lv for lv in levels if lv["name"].lower() == args.impact.lower()), None)
if level is None:
    sys.exit(f"'{args.impact}' is not an active impact level. Yours are: " + ", ".join(lv["name"] for lv in levels))

# Resolving needs a status flagged "resolved" - the first active one in your list
# (out of the box: Resolved).
resolved_status = next((s for s in api("GET", "/service-incident-statuses") if s["is_active"] and s["is_resolved"]), None)
if args.resolve and resolved_status is None:
    sys.exit("--resolve needs an active incident status marked as resolved (Service Status -> Settings).")

# The status board's services. Only ACTIVE services are listed - an inactive one
# is not on the board, so there is nothing to put an incident on.
services = api("GET", "/service-status/services")
by_name = {s["name"].lower(): s for s in services}


def services_for(check_name):
    """The services a check belongs to: from MAP first, otherwise every service
    whose whole name appears as words in the check's name (so "Mail" does not
    match "Voicemail check"), longest name first - "Email" before "Mail"."""
    mapped = MAP.get(check_name)
    if mapped is not None:
        names = [mapped] if isinstance(mapped, str) else list(mapped)
        found = []
        for n in names:
            if n.lower() not in by_name:
                sys.exit(f"The map sends '{check_name}' to '{n}', but no active service has that name.")
            found.append(by_name[n.lower()])
        return found
    for s in sorted(services, key=lambda s: -len(s["name"])):
        if re.search(r"(?<!\w)" + re.escape(s["name"].lower()) + r"(?!\w)", check_name.lower()):
            return [s]
    return []


# ---- 2. Read the board and sort each check under its service(s) -------------------
board = api("GET", f"/morning-checks/board?date={args.date}")
failing = {}   # service id -> list of (check name, label, notes)
green = {}     # service id -> True while every result seen so far is OK
unmatched = []
for check in board["checks"]:
    result = check["result"]
    if result is None:
        continue                                     # not done yet - says nothing either way
    label = result["status"]["label"] if result["status"] else (result["orphan_label"] or "")
    targets = services_for(check["name"])
    if not targets:
        if label.lower() in fail_labels:
            unmatched.append(check["name"])
        continue
    for s in targets:
        if label.lower() in fail_labels:
            failing.setdefault(s["id"], []).append((check["name"], label, (result["notes"] or "").strip()))
            green[s["id"]] = False
        elif label.lower() == args.ok_status.lower():
            green.setdefault(s["id"], True)
        else:
            green[s["id"]] = False                   # Amber, say: not failing, not fine either

for name in unmatched:
    print(f"  ?       '{name}' failed but matches no service - add it to MAP")
if not failing and not args.resolve:
    print("No failed checks on a mapped service - nothing to do.")

# ---- 3. One incident per failing service ------------------------------------------
for svc in services:
    sid = svc["id"]
    if sid not in failing and not (args.resolve and green.get(sid)):
        continue
    # state=open means "its status is not a resolved one". Open first, newest first.
    open_incidents = api("GET", f"/service-status/incidents?state=open&service_id={sid}&per_page=100")
    ours = next((i for i in open_incidents if i["title"] == our_title(svc["name"])), None)

    if sid in failing:
        message = f"Morning checks on {args.date} found a problem: " + "; ".join(
            f"{name} is {label}" + (f" ({notes})" if notes else "") for name, label, notes in failing[sid])
        if ours is None and open_incidents:
            # A person (or another tool) has already declared an incident here.
            # Their words stay as they are - we only report it.
            other = open_incidents[0]
            print(f"  -       {svc['name']}: already in incident #{other['id']} '{other['title']}' - left alone")
            continue
        if ours is None:
            print(f"  DECLARE {svc['name']} at '{level['name']}': {message}")
            if args.apply:
                inc = api("POST", "/service-status/incidents", {
                    "title": our_title(svc["name"]),
                    # The affected service with its impact - this is what turns its
                    # dot on the board from green to amber or red.
                    "services": [{"service_id": sid, "impact_level_id": level["id"]}],
                    "comment": message,
                    "is_internal": not args.public,
                    # No status_id: FreeITSM uses your default (Investigating).
                })
                print(f"          declared incident #{inc['id']}")
            continue
        # Our incident is still open: post today's state as the latest update.
        # Only the comment is sent - leaving "services" out keeps the impact as it
        # is, in case an analyst has raised or lowered it by hand.
        if (ours["comment"] or "") == message:
            print(f"  same    {svc['name']}: incident #{ours['id']} already says this")
            continue
        print(f"  UPDATE  {svc['name']}: incident #{ours['id']} - {message}")
        if args.apply:
            api("PATCH", f"/service-status/incidents/{ours['id']}", {"comment": message, "is_internal": not args.public})
        continue

    # ---- 4. Every check on this service is green again: resolve OUR incident -------
    if ours is not None:
        message = f"Morning checks on {args.date} are all {args.ok_status} again."
        print(f"  RESOLVE {svc['name']}: incident #{ours['id']} - {message}")
        if args.apply:
            inc = api("PATCH", f"/service-status/incidents/{ours['id']}",
                      {"status_id": resolved_status["id"], "comment": message, "is_internal": not args.public})
            # A resolved status stamps resolved_at; the service's dot goes back to
            # normal once no open incident is left on it.
            print(f"          now {inc['status']['name']}")
