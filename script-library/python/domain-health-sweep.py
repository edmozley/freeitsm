# @title       Domain health sweep into tickets
# @summary     Checks every domain you monitor and logs one ticket per domain with serious findings - or adds a note to the ticket already open for it, so a nightly run never makes duplicates.
# @module      domains
# @changes     yes
# @level       intermediate
# @endpoints   GET /domains, POST /domains/{id}/check, GET /domains/{id}/findings,
#              GET /tickets, POST /tickets, POST /tickets/{id}/notes
# @permissions domains.read, domains.update, tickets.read, tickets.create, ticket_notes.create
#
# WHAT IT DOES: the Domains module grades every domain (SPF, DMARC, DNSSEC,
# certificates, expiry...) and lists what it found. This script turns the
# serious findings into work for the service desk:
#
#   1. reads every domain in the register (paged, 100 at a time),
#   2. runs the checks on each one again (POST /domains/{id}/check),
#   3. keeps the findings at the level you care about - "fail" by default,
#      add --level warn to include warnings too,
#   4. for each domain with any left, looks for an OPEN ticket with that
#      domain's stable subject ("Domain health: example.com"), then
#   5. adds a note to it listing today's findings - or, if there is none,
#      opens a new ticket.
#
# Step 4 is what makes it safe to run every night: one domain = one open ticket.
# When an analyst fixes the record and closes the ticket, the next night finds
# nothing and stays quiet. If the problem comes back, a fresh ticket opens -
# which is what you want: it is a new incident.
#
# PREVIEW IS REALLY READ-ONLY. Running a check is itself a write: POST
# /domains/{id}/check asks DNS and the domain's web servers again, then SAVES the
# new findings, grade and certificate dates on the domain (and logs a grade
# change in its history). So without --apply the script does NOT re-check - it
# reads the findings saved by the LAST check (the scheduled run, or someone
# pressing Check) with GET /domains/{id}/findings, and says what it would log.
# A domain that has never been checked shows "never checked" in the preview.
#
# Run it - first WITHOUT --apply (standard library only, Python 3.8 or later):
#   python domain-health-sweep.py --url https://helpdesk.example.com
#   python domain-health-sweep.py --url https://helpdesk.example.com --apply
# Then schedule the --apply line nightly (cron, Task Scheduler).
#
# Checks reach out to the internet from the FreeITSM SERVER (DNS, HTTPS), not
# from this computer, and take a few seconds per domain - a register of 200
# domains is a few minutes' run.
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
from datetime import datetime, timezone
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Check every domain and log a ticket for serious findings, without duplicates.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Which findings count as serious. Each finding has a level: pass, info, warn or
# fail. "fail" (the default) logs only failures; "warn" logs warnings AND failures.
parser.add_argument("--level", choices=["fail", "warn"], default="fail")
# The start of every ticket subject; the domain name is added after it. Keep it
# STABLE - it is how the next run finds the ticket again. Change it and the next
# run will not recognise yesterday's tickets.
parser.add_argument("--subject-prefix", default="Domain health:")
# A priority NAME as it appears in FreeITSM (e.g. High). Empty = your default.
parser.add_argument("--priority", default="")
# Who the tickets are "from". FreeITSM needs a requester on every ticket and
# creates a contact for an address it has not seen before - so use one mailbox
# for all automatic tickets.
parser.add_argument("--requester-email", default="monitoring@example.com")
# Without --apply nothing is written: no checks run, no tickets, no notes.
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
    meta for paging, and the findings need meta.checked_at. Stops with FreeITSM's
    own message on failure (e.g. 422 "Unknown priority: Hihg")."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        # A long timeout: a check waits on DNS and on each web server's certificate.
        with urllib.request.urlopen(request, timeout=180) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def find_open_ticket(subject):
    """The OPEN ticket with exactly this subject, or None.
    q= is a "contains" search on subject and ticket number, so "example.com" also
    finds "Domain health: mail.example.com". We page through the candidates and
    keep only an EXACT match (ignoring capitals). sort=id, oldest first, so the
    first match is the original ticket even if someone logged a copy by hand."""
    page = 1
    while True:
        query = urllib.parse.urlencode({"state": "open", "sort": "id", "per_page": 100, "page": page, "q": subject})
        reply = api("GET", f"/tickets?{query}")
        for t in reply["data"]:
            if t["subject"].strip().lower() == subject.lower():
                return t
        page += 1
        if page > reply["meta"]["total_pages"]:
            return None


serious_levels = {"fail"} if args.level == "fail" else {"fail", "warn"}
now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M") + " UTC"
mode = "APPLYING" if args.apply else "PREVIEW - no checks run, nothing is written; add --apply"
print(f"Domain health sweep on {BASE}   [{mode}]")
print(f"Serious = level {' or '.join(sorted(serious_levels))}\n")

# ---- 1. Every domain in the register ------------------------------------------------
# GET /domains is paged (at most 100 a page); meta.total_pages says when to stop.
# The key's company scope applies: a key limited to one company sees its domains.
domains = []
page = 1
while True:
    reply = api("GET", f"/domains?sort=domain_name&per_page=100&page={page}")
    domains.extend(reply["data"])
    page += 1
    if page > reply["meta"]["total_pages"]:
        break

created = noted = clean = skipped = 0
for d in domains:
    name = d["domain_name"]
    # Someone turned monitoring off for this domain (a name being let go, say) -
    # respect that, just as the scheduled checks do.
    if not d["monitoring_enabled"]:
        print(f"  skip   {name}: monitoring is off for this domain")
        skipped += 1
        continue

    # ---- 2. Its findings: fresh (apply) or the last saved ones (preview) --------
    if args.apply:
        # Re-runs every check and saves the result; the reply is the domain with
        # its new grade and a "findings" list.
        checked = api("POST", f"/domains/{d['id']}/check")["data"]
        findings, grade = checked["findings"], checked["security_grade"]
    else:
        reply = api("GET", f"/domains/{d['id']}/findings")
        findings, grade = reply["data"], d["security_grade"]
        if reply["meta"]["checked_at"] is None:
            print(f"  ?      {name}: never checked - --apply would check it now")
            continue

    # ---- 3. Keep only the serious ones ----------------------------------------------
    # Each finding has its English title and advice filled in by the API, so the
    # ticket is readable without opening the Domains screen.
    serious = [f for f in findings if f["level"] in serious_levels]
    if not serious:
        print(f"  ok     {name}: grade {grade or '-'}, nothing serious")
        clean += 1
        continue
    lines = "\n".join(f"- [{f['level'].upper()}] {f['title']}" + (f"\n  {f['advice']}" if f["advice"] else "")
                      for f in serious)

    # ---- 4. Look first: is a ticket already open for this domain? -----------------
    subject = f"{args.subject_prefix} {name}"
    ticket = find_open_ticket(subject)

    if ticket:
        # ---- 5a. Yes: add today's findings as a note ---------------------------------
        # is_internal True: only analysts see it. A note shared with the requester
        # may be emailed to them - noise for an automatic mailbox.
        note = {"text": f"Domain checks at {now}: grade {grade or '-'}, {len(serious)} serious finding(s).\n{lines}",
                "is_internal": True}
        print(f"  NOTE   {name}: {len(serious)} serious - open ticket {ticket['ticket_number']} already covers it")
        if args.apply:
            ticket_id = ticket["id"]
            n = api("POST", f"/tickets/{ticket_id}/notes", note)["data"]
            print(f"         added note #{n['id']}")
        noted += 1
        continue

    # ---- 5b. No: open a ticket -------------------------------------------------------
    # description is plain text - FreeITSM turns line breaks into the ticket body.
    # Fields you might add: department_id, ticket_type_id, assigned_team_id, or
    # company_id (d["company"]["id"]) to log it under the domain's own company.
    body = {
        "subject": subject,
        "description": f"The domain checks at {now} graded {name} {grade or '-'} "
                       f"with {len(serious)} serious finding(s):\n\n{lines}\n\n"
                       f"Raised automatically by the domain health sweep. Fix the records, "
                       f"press Check on the domain, then close this ticket.",
        "requester_email": args.requester_email,
        "requester_name": "Domain health sweep",
    }
    if args.priority:
        body["priority"] = args.priority   # by name; priority_id works too
    print(f"  NEW    {name}: {len(serious)} serious - no open ticket, {'creating' if args.apply else 'would create'} '{subject}'")
    for f in serious:
        print(f"           [{f['level'].upper()}] {f['title']}")
    if args.apply:
        t = api("POST", "/tickets", body)["data"]
        print(f"         created ticket {t['ticket_number']} (#{t['id']})")
    created += 1

verb = "" if args.apply else "would be "
print(f"\n{len(domains)} domain(s): {clean} clean, {skipped} not monitored, "
      f"{created} ticket(s) {verb}created, {noted} note(s) {verb}added.")
