# @title       Close stale tickets with a note
# @summary     Finds open tickets nobody has touched for a number of days, adds a note saying why, then closes them.
# @module      tickets
# @changes     yes
# @level       intermediate
# @endpoints   GET /statuses, GET /tickets, POST /tickets/{id}/notes, PATCH /tickets/{id}
# @permissions reference.read, tickets.read, ticket_notes.create, tickets.update
#
# WHAT IT SHOWS: filtering, then two writes per ticket in a deliberate order.
#
#   1. Ask FreeITSM which statuses count as CLOSED (GET /statuses) - never a
#      hard-coded id, because ids differ between installs.
#   2. Collect every open ticket whose last update is older than --days. ALL of
#      them first, before changing anything (see the TRAP in step 2).
#   3. For each: add a note explaining why, THEN change its status.
#
# The typical use is tickets parked waiting for the customer (Python 3.8 or
# later, standard library only):
#   python close-stale-tickets.py --url https://helpdesk.example.com --days 14 --waiting-status "Awaiting Response"
#   python close-stale-tickets.py --url https://helpdesk.example.com --days 14 --waiting-status "Awaiting Response" --apply
#
# PREVIEW FIRST: without --apply it lists the tickets it would close and writes
# nothing. Read that list before you add --apply - closing is easy to undo one
# ticket at a time, tedious for two hundred.
#
# "Untouched" means the ticket's updated_at: a note, a reply, a status or field
# change all move it on. Logging time does not.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Close FreeITSM tickets nobody has touched for a while.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How many days without an update makes a ticket stale. 0 is allowed - "anything
# not touched before now" - which is handy with --search to try the script on a
# few test tickets; the preview shows what it would catch.
parser.add_argument("--days", type=int, default=14)
# Only tickets in this status (its NAME, e.g. "Awaiting Response"). Leave it
# empty to consider EVERY open ticket - think twice about that on a busy desk.
parser.add_argument("--waiting-status", default="")
# Only tickets whose subject or number CONTAINS this text, e.g. "[Printer]".
parser.add_argument("--search", default="")
# The status to close with, by name (e.g. "Resolved"). Empty = the first status
# FreeITSM marks as closed, in your System -> Tickets order.
parser.add_argument("--close-status", default="")
# The note added before closing. {days} is replaced with --days.
parser.add_argument("--note-text", default="Closed automatically: no activity for {days} days. "
                    "If you still need help, reply to this ticket or contact the service desk.")
# Without --apply nothing is written: it lists what it would close.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if args.days < 0:
    sys.exit("--days must be 0 or more.")
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()
BASE = args.url.rstrip("/") + "/api/v1"


def api(method, path, body=None):
    """Call the API and return the whole reply ({"data", "meta"}) because the
    ticket list pages. Stops with FreeITSM's own message on failure - for example
    a closure refused because a required checklist on the ticket is not finished."""
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


def parse_utc(text):
    """FreeITSM dates look like "2026-11-19T09:30:00Z" (UTC). Python before 3.11
    cannot read the "Z", so swap it for the "+00:00" every version understands."""
    return datetime.fromisoformat(text.replace("Z", "+00:00"))


# ---- 1. Which status closes a ticket? ---------------------------------------------
# GET /statuses lists every ticket status with an is_closed flag. Closing means
# "a status whose is_closed is true" - FreeITSM then stamps closed_at, stops the
# SLA clock and takes the ticket out of the open queues.
statuses = api("GET", "/statuses")["data"]
if args.close_status:
    close = next((s for s in statuses if s["name"].lower() == args.close_status.lower()), None)
    if not close:
        sys.exit(f"No status called '{args.close_status}'. Statuses: {', '.join(s['name'] for s in statuses)}")
    if not close["is_closed"]:
        sys.exit(f"'{args.close_status}' is not a closed status, so it would not close anything.")
else:
    close = next((s for s in statuses if s["is_closed"]), None)
    if not close:
        sys.exit("This install has no closed status - add one under System -> Tickets.")

now = datetime.now(timezone.utc)
cutoff = now - timedelta(days=args.days)
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to close them"
in_status = f" in '{args.waiting_status}'" if args.waiting_status else ""
print(f"Open tickets{in_status} untouched since {cutoff:%Y-%m-%d %H:%M} UTC -> '{close['name']}'   [{mode}]")

# ---- 2. Collect the stale tickets ---------------------------------------------------
# There is no "updated before" filter, so we sort by updated_at, OLDEST first,
# and stop paging at the first ticket that is newer than the cutoff - every
# ticket after it is newer still.
#
# TRAP: collect them ALL before closing any. state=open pages are counted over
# the OPEN tickets; close ten from page 1 and what was page 2 slides up into
# page 1, so asking for page 2 next would silently skip a page of tickets.
#
# status= matches a status NAME exactly; q= is "subject or number contains".
# urlencode makes spaces and symbols safe to put in an address.
filters = {"state": "open", "sort": "updated_at", "per_page": 100}
if args.waiting_status:
    filters["status"] = args.waiting_status
if args.search:
    filters["q"] = args.search
stale = []
page = 1
done = False
while not done:
    reply = api("GET", "/tickets?" + urllib.parse.urlencode({**filters, "page": page}))
    for t in reply["data"]:
        last = parse_utc(t["updated_at"] or t["created_at"])
        if last >= cutoff:
            done = True
            break
        t["last_touched"] = last
        stale.append(t)
    page += 1
    if page > reply["meta"]["total_pages"]:
        break

if not stale:
    sys.exit("Nothing is stale. Done.")

# ---- 3. Note, then close ----------------------------------------------------------
# The note goes FIRST so anyone opening the ticket sees the reason directly
# above the closure in its history. is_internal False shares the note with the
# requester (FreeITSM may email it to them, depending on your notification
# settings); make it True to keep it among analysts.
#
# If FreeITSM refuses a closure (for example a required checklist is not done),
# the script stops there with FreeITSM's reason. That ticket keeps its note and
# stays open - deal with it by hand, then run the script again.
text = args.note_text.replace("{days}", str(args.days))
for t in stale:
    age = (now - t["last_touched"]).days
    line = f"  {t['ticket_number']}  '{t['subject']}'  [{(t['status'] or {}).get('name', 'no status')}, untouched {age} days]"
    if not args.apply:
        print("would close" + line)
        continue
    ticket_id = t["id"]
    api("POST", f"/tickets/{ticket_id}/notes", {"text": text, "is_internal": False})
    api("PATCH", f"/tickets/{ticket_id}", {"status_id": close["id"]})
    print("closed" + line)

print(f"\n{len(stale)} ticket(s) {'closed' if args.apply else 'would be closed'}. Done.")
