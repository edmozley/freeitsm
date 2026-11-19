# @title       Declare a service incident
# @summary     Puts a service into an incident on the status board - or, if it already has one open, posts an update or resolves it.
# @module      service-status
# @changes     yes
# @level       intermediate
# @endpoints   GET /service-status/services, GET /service-impact-levels, GET /service-incident-statuses,
#              GET /service-status/incidents, POST /service-status/incidents, PATCH /service-status/incidents/{id}
# @permissions services.read, reference.read, service_incidents.read, service_incidents.create, service_incidents.update
#
# WHAT IT DOES: you name a service as it appears on the Service Status board
# (for example "Email") and say how badly it is affected. The script:
#   1. finds the service by name,
#   2. checks the impact level (and status, if you give one) against the lists
#      configured in YOUR FreeITSM - they are renameable, so nothing is assumed,
#   3. looks for an incident that is already OPEN on that service, then
#   4. either creates a new incident, or updates the open one: a new message,
#      a new status (say "Identified", or "Resolved" to close it), a new impact.
#
# Step 3 is what makes it safe to call from a monitoring tool every few minutes:
# the first failure declares the incident, later calls only add updates to it,
# and nobody ends up with ten copies of "Email is down".
#
# Run it - first WITHOUT --apply to see what it would do (standard library only):
#   python declare-service-incident.py --url https://helpdesk.example.com --service "Email" --impact "Major Outage" --message "Users cannot send or receive mail."
#   python declare-service-incident.py --url https://helpdesk.example.com --service "Email" --impact "Major Outage" --message "Users cannot send or receive mail." --apply
# Later, on the same incident:
#   ... --service "Email" --status "Identified" --message "A failed certificate renewal - being replaced now." --apply
#   ... --service "Email" --status "Resolved" --message "Mail is flowing again." --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Declare or update a service incident in FreeITSM.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The service's name exactly as it appears on the Service Status board.
parser.add_argument("--service", required=True)
# How badly the service is affected. Must be one of YOUR impact levels - out of
# the box: Major Outage, Partial Outage, Degraded, Maintenance, Operational.
# Left out: a NEW incident uses Major Outage, an update keeps the impact it had.
parser.add_argument("--impact", default="")
# The incident's title - only used when a NEW incident is created.
parser.add_argument("--title", default="")
# The update to post: what is happening, in words your users understand.
parser.add_argument("--message", default="")
# The incident status to set, e.g. Investigating, Identified, Monitoring or
# Resolved. Leave it out on a new incident and FreeITSM uses Investigating.
parser.add_argument("--status", default="")
# Updates are INTERNAL unless you say otherwise, so a script can never publish to
# the self-service portal by accident. Add --public to let users see this one.
parser.add_argument("--public", action="store_true")
# Without --apply the script only looks things up and prints what it would do.
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


mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to make the change"
print(f"Service incident for '{args.service}' on {BASE}   [{mode}]")

# ---- 1. Find the service ----------------------------------------------------------
# ?q= is a "contains" search, so "Mail" would also find "Voicemail". We ask for
# the short list, then insist on an exact (case-insensitive) match ourselves.
# The list only shows ACTIVE services - an inactive one is not on the board.
candidates = api("GET", "/service-status/services?q=" + urllib.parse.quote(args.service))
svc = next((s for s in candidates if s["name"].lower() == args.service.lower()), None)
if svc is None:
    close = ", ".join(s["name"] for s in candidates)
    sys.exit(f"No active service is called '{args.service}'." + (f" Close matches: {close}" if close else ""))
print(f"Service     : {svc['name']} (#{svc['id']}) - currently {svc['current_status']['name']}")

# ---- 2. Check the impact level and status against YOUR lists ------------------------
# Both lists can be renamed and extended under Service Status -> Settings, so we
# read them rather than trusting a name typed here. Doing it BEFORE any write
# means a typo stops the run with a helpful list instead of a half-made change.
levels = [lv for lv in api("GET", "/service-impact-levels") if lv["is_active"]]
impact_name = args.impact or "Major Outage"
level = next((lv for lv in levels if lv["name"].lower() == impact_name.lower()), None)
if level is None:
    sys.exit(f"'{impact_name}' is not an active impact level. Yours are: " + ", ".join(lv["name"] for lv in levels))

status_row = None
if args.status:
    statuses = [st for st in api("GET", "/service-incident-statuses") if st["is_active"]]
    status_row = next((st for st in statuses if st["name"].lower() == args.status.lower()), None)
    if status_row is None:
        sys.exit(f"'{args.status}' is not an active incident status. Yours are: " + ", ".join(st["name"] for st in statuses))

# ---- 3. Is there already an open incident on this service? ------------------------
# state=open means "its status is not a resolved one". The list is ordered open
# first, most recently updated first, so the first row is the live incident.
open_incidents = api("GET", f"/service-status/incidents?state=open&service_id={svc['id']}&per_page=100")

if not open_incidents:
    # ---- 4a. Declare a new incident ------------------------------------------------
    if status_row and status_row["is_resolved"]:
        # Nothing is open, so there is nothing to resolve - say so rather than
        # creating an incident that is closed the moment it exists.
        print(f"Nothing is open on {svc['name']}, so there is nothing to mark '{args.status}'.")
        sys.exit(0)
    title = args.title or f"{svc['name']} - {level['name']}"
    body = {
        "title": title,
        # The affected services, each with its own impact. This is what turns the
        # service's dot on the board from green to red.
        "services": [{"service_id": svc["id"], "impact_level_id": level["id"]}],
        "is_internal": not args.public,
    }
    if args.message:
        body["comment"] = args.message
    if status_row:
        body["status_id"] = status_row["id"]

    if not args.apply:
        print(f"Would DECLARE a new incident '{title}' - {svc['name']} at '{level['name']}'")
        if args.message:
            print(f"  with the update: {args.message}")
        sys.exit(0)
    incident = api("POST", "/service-status/incidents", body)
    print(f"Declared incident #{incident['id']} '{incident['title']}' - status {incident['status']['name']}")
    sys.exit(0)

# ---- 4b. Update the open incident -----------------------------------------------
incident = open_incidents[0]
incident_id = incident["id"]
print(f"Open incident: #{incident_id} '{incident['title']}' - status {incident['status']['name']}")
if len(open_incidents) > 1:
    print(f"  ({len(open_incidents)} incidents are open on this service; updating the most recent.)")

# A PATCH only changes the fields you send. Two rules the API applies:
#   - "services" REPLACES the whole list of affected services. One incident can
#     cover several services, so we send every service it already has, changing
#     only the impact on ours (and only if you passed --impact) - otherwise the
#     others would drop off it.
#   - FreeITSM records a new update (the timeline entry users see) only when the
#     status, message or services actually change, so running the same command
#     twice does not post the same message twice.
services = []
for s in incident["services"]:
    ours = s["service_id"] == svc["id"] and args.impact
    impact_id = level["id"] if ours else s["impact"]["id"]
    services.append({"service_id": s["service_id"], "impact_level_id": impact_id})
patch = {"services": services, "is_internal": not args.public}
if args.message:
    patch["comment"] = args.message
if status_row:
    patch["status_id"] = status_row["id"]

if not args.apply:
    what = [f"impact '{level['name']}'"] if args.impact else []
    if status_row:
        what.append(f"status '{status_row['name']}'")
    if args.message:
        what.append(f"update '{args.message}'")
    print(f"Would UPDATE incident #{incident_id}: " + ", ".join(what))
    sys.exit(0)
incident = api("PATCH", f"/service-status/incidents/{incident_id}", patch)
# A resolved status also stamps resolved_at, and the service goes back to its
# normal colour on the board once no open incident is left on it.
note = f" - resolved at {incident['resolved_at']}" if incident.get("resolved_at") else ""
print(f"Updated incident #{incident['id']} - status {incident['status']['name']}{note}")
