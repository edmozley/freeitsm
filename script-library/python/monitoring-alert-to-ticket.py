# @title       Turn a monitoring alert into a ticket
# @summary     Logs a ticket for a monitoring alert - or, if that alert already has an open ticket, adds a note to it instead of a duplicate.
# @module      tickets
# @changes     yes
# @level       intermediate
# @endpoints   GET /tickets, POST /tickets, POST /tickets/{id}/notes
# @permissions tickets.read, tickets.create, ticket_notes.create
#
# WHAT IT SHOWS: "look first, then act". A monitoring tool (PRTG, Zabbix, Nagios,
# Uptime Kuma, a cron job...) fires the same alert again and again while a
# problem lasts. Logging a new ticket every time buries the service desk, so:
#
#   1. Look for an OPEN ticket with exactly the same subject.
#   2. Found one?  Add a note to it ("fired again at ...").
#      None?       Create a new ticket.
#
# Once that ticket is closed, the next alert opens a fresh one - which is what
# you want: a problem that comes back is a new incident.
#
# HOW TO CALL IT - from your monitoring tool's "run a program" action, either
# with arguments (Python 3.8 or later, standard library only):
#   python monitoring-alert-to-ticket.py --url https://helpdesk.example.com \
#       --subject "DISK LOW: FS01 C: below 10%" --message "Free space 8.2 GB" --priority High
# or with a JSON file the tool writes out:
#   python monitoring-alert-to-ticket.py --url https://helpdesk.example.com --alert-file alert.json
#
#   alert.json:
#   { "subject": "DISK LOW: FS01 C: below 10%", "message": "Free space 8.2 GB",
#     "priority": "High", "requester_email": "monitoring@example.com" }
#
# PREVIEW FIRST: without --apply it only looks, and says what it WOULD do. Add
# --apply once you are happy, and put --apply in the monitoring tool's command.
#
# KEEP SUBJECTS STABLE. The match is on the subject, so put the changing details
# (the exact free space, the time) in the message, not the subject - otherwise
# every alert looks new and you get the duplicates this script exists to stop.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Turn a monitoring alert into a FreeITSM ticket, without duplicates.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The alert, as arguments...
parser.add_argument("--subject", default="")
parser.add_argument("--message", default="")
# A priority NAME as it appears in FreeITSM (e.g. High). Leave empty for the
# default. A name FreeITSM does not know is refused, not silently ignored.
parser.add_argument("--priority", default="")
# Who the ticket is "from". FreeITSM needs a requester on every ticket and creates
# a contact for an address it has not seen before - so use one mailbox for all
# monitoring, e.g. monitoring@yourcompany.com.
parser.add_argument("--requester-email", default="monitoring@example.com")
# ...or as a JSON file (its values win over the arguments above).
parser.add_argument("--alert-file", default="")
# Without --apply nothing is written: the script looks, then says what it would do.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()
BASE = args.url.rstrip("/") + "/api/v1"


def api(method, path, body=None):
    """Call the API and return the whole reply ({"data", "meta"}) so the search
    can page. Stops with FreeITSM's own message on failure
    (e.g. 422 "Unknown priority: Hihg")."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


# ---- 1. Read the alert ----------------------------------------------------------
alert = {"subject": args.subject, "message": args.message,
         "priority": args.priority, "requester_email": args.requester_email}
if args.alert_file:
    from_file = json.loads(Path(args.alert_file).read_text(encoding="utf-8"))
    alert.update({k: v for k, v in from_file.items() if v})
subject = (alert["subject"] or "").strip()
if not subject:
    sys.exit('The alert needs a subject: pass --subject or put "subject" in the --alert-file.')

mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to log it"
print(f"Alert: {subject}   [{mode}]")

# ---- 2. Look for an open ticket with the same subject ------------------------------
# q= searches subject AND ticket number with "contains", so "FS01 C:" would also
# match "FS01 C: below 5%". We therefore fetch the candidates and keep only an
# EXACT subject match (ignoring capitals and spaces at the ends). Paged, in case
# a very common word brings back more than 100. urlencode turns spaces, colons
# and % signs into a form that survives the trip in the address.
match = None
page = 1
while match is None:
    query = urllib.parse.urlencode({"state": "open", "sort": "id", "per_page": 100, "page": page, "q": subject})
    reply = api("GET", f"/tickets?{query}")
    for t in reply["data"]:
        # sort=id, oldest first: the first exact match is the ORIGINAL ticket,
        # even if someone has since logged a duplicate by hand.
        if t["subject"].strip().lower() == subject.lower():
            match = t
            break
    page += 1
    if page > reply["meta"]["total_pages"]:
        break

now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M") + " UTC"

# ---- 3a. Found: add a note --------------------------------------------------------
if match:
    print(f"Open ticket {match['ticket_number']} (#{match['id']}) already covers this alert.")
    # is_internal True: only analysts see it. Set it to False and the note is
    # shared with the requester - and may be emailed to them, which for an alert
    # mailbox is just noise.
    note = {"text": f"Alert fired again at {now}.\n{alert['message'] or ''}".strip(), "is_internal": True}
    if not args.apply:
        print(f"  would add a note: {note['text']}")
        sys.exit(0)
    ticket_id = match["id"]
    n = api("POST", f"/tickets/{ticket_id}/notes", note)["data"]
    print(f"  added note #{n['id']} to {match['ticket_number']}")
    sys.exit(0)

# ---- 3b. Not found: create a ticket -------------------------------------------------
# description is plain text - FreeITSM turns line breaks into the ticket body.
# Other fields you might add: department_id, ticket_type_id, assigned_team_id,
# company_id (a key limited to companies uses its first company otherwise).
body = {
    "subject": subject,
    "description": f"Raised automatically by monitoring at {now}.\n\n{alert['message'] or ''}".strip(),
    "requester_email": alert["requester_email"],
    "requester_name": "Monitoring",
}
if alert["priority"]:
    body["priority"] = alert["priority"]   # by name; priority_id works too

if not args.apply:
    print(f"  no open ticket has this subject - would create one "
          f"(priority: {alert['priority'] or 'default'}, requester: {alert['requester_email']})")
    sys.exit(0)
created = api("POST", "/tickets", body)["data"]
print(f"  created ticket {created['ticket_number']} (#{created['id']})")
