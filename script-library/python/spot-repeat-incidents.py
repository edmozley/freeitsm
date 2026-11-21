# @title       Spot repeat incidents and open a problem
# @summary     Looks at recent tickets, finds groups that keep coming back - same configuration item, or the same word in the subject - and opens a problem for each group, linking the tickets and noting why.
# @module      problems
# @changes     yes
# @level       advanced
# @endpoints   GET /tickets, GET /problems, GET /problem-statuses, GET /problem-priorities, POST /problems, POST /problems/{id}/tickets, POST /problems/{id}/notes
# @permissions tickets.read, problems.read, reference.read, problems.create, problem_links.create, problem_notes.create
#
# WHY: problem management starts with noticing that the same thing keeps
# breaking. On a busy desk nobody sees that the fifth "printer offline" this
# fortnight is the same printer. This script looks for those patterns and turns
# each into a problem with the evidence already linked. (To link tickets to a
# problem you ALREADY have, by a keyword you choose, use "Link matching tickets
# to a problem" instead - this one FINDS the groups for you.)
#
# HOW THE GROUPS ARE FOUND - deliberately simple, so you can predict it:
#   1. Tickets created in the last --days days are read, with the records each
#      one is linked to (?include=related). Tickets already linked to a problem
#      are left out - they are explained already - EXCEPT ones linked to a
#      problem this script opened, so that group keeps its name on the next run.
#   2. SAME CONFIGURATION ITEM: tickets linked to the same CMDB item form a group.
#      This is the strongest signal. It needs tickets to be linked to CMDB items,
#      and the key to be allowed to read CMDB items (cmdb_objects read) - without
#      that the API leaves CMDB links out of the reply and this step finds nothing.
#   3. SAME WORD IN THE SUBJECT: for the rest, each subject is cut into words.
#      Short words (under --min-word-length letters), numbers and everyday words
#      ("cannot", "please", "working"...) are dropped - see STOP_WORDS below and
#      add your own. The word shared by the most tickets becomes a group, those
#      tickets are taken out, and it repeats. "Printer offline on floor 3" and
#      "Floor 3 printer jams" both land in "printer".
#   4. A group with at least --min-tickets tickets is proposed as a problem.
#   Groups are found within one company at a time, because a problem and the
#   tickets linked to it must belong to the same company.
#
# SAFE TO RUN AGAIN: each problem is titled "Repeat incidents: <what they share>".
# Before creating one the script searches the open problems for that exact title
# in that company; if it is there, it only links the new tickets and adds a note
# saying how many. Closing the problem ends it: its tickets are then explained,
# and a NEW group needs --min-tickets fresh tickets before another is proposed.
#
# Run it - first WITHOUT --apply to see the groups (standard library only):
#   python spot-repeat-incidents.py --url https://helpdesk.example.com
#   python spot-repeat-incidents.py --url https://helpdesk.example.com --days 30 --min-tickets 4 --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you. Problems, links
# and notes are recorded as the analyst the key acts as.

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path

# ---- Words that never make a group: edit this list for your desk ---------------------
# Lower case. Anything three letters or shorter is dropped anyway (--min-word-length).
STOP_WORDS = {
    "about", "after", "again", "also", "and", "any", "anyone", "because", "been", "before",
    "being", "cannot", "can't", "could", "does", "doesn't", "done", "error", "every", "from",
    "have", "having", "help", "how", "issue", "issues", "just", "keeps", "need", "needed",
    "needs", "not", "please", "problem", "problems", "request", "since", "some", "still",
    "that", "their", "them", "then", "there", "this", "today", "urgent", "very", "want",
    "when", "where", "which", "will", "with", "won't", "work", "working", "works", "would",
    "your", "fwd", "fw", "re",
}

PREFIX = "Repeat incidents: "

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Find repeat incidents in FreeITSM and open a problem for each group.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far back to look, in days.
parser.add_argument("--days", type=int, default=14)
# How many tickets make a pattern worth a problem.
parser.add_argument("--min-tickets", type=int, default=3)
# Words shorter than this are ignored ("pc", "mfa" - raise or lower to taste).
parser.add_argument("--min-word-length", type=int, default=4)
# Only tickets of this type, by name - for example Incident. Empty = every type.
parser.add_argument("--ticket-type", default="")
# Only tickets whose subject or number contains this text - to look at one
# service ("Outlook") or to try the script out on a few tickets first. Its own
# words are never used as a group (every ticket would share them).
parser.add_argument("--subject-contains", default="")
# The new problem's priority and status, by name. Empty = your defaults
# (Problems -> Settings).
parser.add_argument("--priority", default="")
parser.add_argument("--status", default="")
# A safety net: stop rather than read more tickets than this.
parser.add_argument("--max", type=int, default=2000)
# Without --apply the script only prints the groups it found.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

# Subjects can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if args.min_tickets < 2:
    sys.exit("--min-tickets must be 2 or more - one ticket is not a pattern.")

# ---- The key --------------------------------------------------------------------
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()

# Every REST call lives under /api/v1 on your server.
BASE = args.url.rstrip("/") + "/api/v1"


class Conflict(Exception):
    """FreeITSM said 409 conflict - for a link, "already linked"."""


# ---- Helpers --------------------------------------------------------------------
def api_raw(method, path, body=None):
    """Call the API and return FreeITSM's WHOLE reply ({"data": ..., "meta": ...}).
    On failure stop with FreeITSM's own message - except 409 conflict, raised as
    Conflict so the caller can decide it is harmless."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")
        if e.code == 409:
            raise Conflict(detail)
        sys.exit(f"{method} {path} failed: HTTP {e.code} {detail}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def api(method, path, body=None):
    """The usual case: just the "data" part."""
    return api_raw(method, path, body)["data"]


def words_of(text):
    """The significant words of a subject, each once, lower case."""
    found = re.findall(r"[^\W_]+(?:['-][^\W_]+)*", (text or "").lower())
    return {w for w in found
            if len(w) >= args.min_word_length and not w.isdigit() and w not in STOP_WORDS}


def by_name(rows, name, what):
    """Find a reference row by name (ignoring capitals), or stop with the choices."""
    row = next((r for r in rows if r["name"].strip().lower() == name.strip().lower()), None)
    if row is None:
        sys.exit(f"No {what} called '{name}'. Choose one of: " + ", ".join(r["name"] for r in rows))
    return row


mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to open the problems"
print(f"Spot repeat incidents -> {BASE}   [{mode}]")

# ---- 1. The new problem's priority and status ---------------------------------------
# Checked first, so a typo stops the script before it has read a thing. Sent by
# id; left out entirely when not given, so FreeITSM applies your defaults.
problem_fields = {}
if args.priority:
    problem_fields["priority_id"] = by_name(api("GET", "/problem-priorities"), args.priority, "problem priority")["id"]
if args.status:
    status = by_name(api("GET", "/problem-statuses"), args.status, "problem status")
    if status["is_closed"]:
        sys.exit(f"'{status['name']}' is a closed status - a new problem should start open.")
    problem_fields["status_id"] = status["id"]

# ---- 2. The problems this script opened before ------------------------------------------
# q= is a "contains" search over the title and PRB number; state=open leaves
# closed ones out on purpose (see SAFE TO RUN AGAIN).
ours = []
page = 1
while True:
    reply = api_raw("GET", "/problems?" + urllib.parse.urlencode(
        {"q": PREFIX.strip(), "state": "open", "per_page": 100, "page": page}))
    ours += [p for p in reply["data"] if p["title"].startswith(PREFIX)]
    if page >= reply["meta"]["total_pages"]:
        break
    page += 1
our_ids = {p["id"] for p in ours}


def find_ours(company_id, title):
    """Our open problem with this exact title in this company. A ticket with no
    company (company: null - usual on a single-company install) belongs to the
    default company, which is where a problem created without company_id lands;
    so a null on either side matches any company."""
    for p in ours:
        pid = (p["company"] or {}).get("id")
        if p["title"] == title and (company_id is None or pid is None or pid == company_id):
            return p
    return None

# ---- 3. The recent tickets, with what they are linked to --------------------------------------
# created_since is an exact moment (UTC, with Z). include=related adds each
# ticket's links - problems, CMDB items, assets... - in the same reply, so there
# is no need for a second call per ticket.
since = (datetime.now(timezone.utc) - timedelta(days=args.days)).strftime("%Y-%m-%dT%H:%M:%SZ")
filters = {"created_since": since, "include": "related", "sort": "created_at", "per_page": 100}
if args.subject_contains:
    filters["q"] = args.subject_contains
first = api_raw("GET", "/tickets?" + urllib.parse.urlencode({**filters, "page": 1}))
if first["meta"]["total"] > args.max:
    sys.exit(f"{first['meta']['total']} tickets in the last {args.days} days - more than --max {args.max}. "
             "Use fewer --days, or raise --max if you mean it.")
tickets = list(first["data"])
for page in range(2, first["meta"]["total_pages"] + 1):
    tickets += api("GET", "/tickets?" + urllib.parse.urlencode({**filters, "page": page}))

# Tickets from before companies were switched on say "company": null. They
# belong to the default company - FreeITSM treats null as the default everywhere,
# including the problem-to-ticket "same company" rule - but the reply does not
# say which company that is. The ticket list's own company_id filter does know:
# asking for the default company also returns the null ones. So for each company
# we saw, one small call (per_page=1, we only want meta.total) tells us whether
# its count includes the nulls. Without this, the same company would be split in
# two and a group could be missed.
nulls = [t for t in tickets if not t["company"]]
seen = {t["company"]["id"]: t["company"] for t in tickets if t["company"]}
if nulls and seen:
    count_filters = {k: v for k, v in filters.items() if k != "include"}
    for cid, company in seen.items():
        own = sum(1 for t in tickets if t["company"] and t["company"]["id"] == cid)
        total = api_raw("GET", "/tickets?" + urllib.parse.urlencode(
            {**count_filters, "company_id": cid, "per_page": 1}))["meta"]["total"]
        if total == own + len(nulls):
            for t in nulls:
                t["company"] = company
            break

ignore_words = words_of(args.subject_contains)
candidates = []
for t in tickets:
    if args.ticket_type and (t["ticket_type"] or {}).get("name", "").lower() != args.ticket_type.lower():
        continue
    links = t["related"]["links"]
    problem_ids = {l["id"] for l in links if l["type"] == "problem"}
    if problem_ids - our_ids:
        continue   # already explained by a problem somebody else opened
    t["_linked_to"] = problem_ids
    t["_cis"] = {(l["id"], l["name"]) for l in links if l["type"] == "cmdb_object"}
    t["_words"] = words_of(t["subject"]) - ignore_words
    candidates.append(t)
print(f"Tickets since {since[:10]}: {len(tickets)}; still unexplained: {len(candidates)}")

# ---- 4. Find the groups, one company at a time ---------------------------------------------------
by_company = defaultdict(list)
for t in candidates:
    by_company[(t["company"] or {}).get("id")].append(t)

groups = []   # (company id, company name, title, why, [tickets])
for company_id, pool in by_company.items():
    company_name = (pool[0]["company"] or {}).get("name")
    # 4a. Same configuration item. A ticket linked to two items can count in both.
    by_ci = defaultdict(list)
    for t in pool:
        for ci in t["_cis"]:
            by_ci[ci].append(t)
    grouped = set()
    for (ci_id, ci_name), members in sorted(by_ci.items(), key=lambda kv: (-len(kv[1]), kv[0][1] or "")):
        if len(members) >= args.min_tickets:
            groups.append((company_id, company_name, f"{PREFIX}{ci_name}",
                           f"{len(members)} tickets linked to the configuration item '{ci_name}'", members))
            grouped.update(t["id"] for t in members)
    # 4b. Same subject word, for the tickets no CI group took. Greedy: the most
    # shared word wins (ties: alphabetical, so every run gives the same answer),
    # its tickets leave the pool, repeat until no word reaches --min-tickets.
    rest = [t for t in pool if t["id"] not in grouped]
    while True:
        count = defaultdict(list)
        for t in rest:
            for w in t["_words"]:
                count[w].append(t)
        best = sorted(((w, m) for w, m in count.items() if len(m) >= args.min_tickets),
                      key=lambda wm: (-len(wm[1]), wm[0]))
        if not best:
            break
        word, members = best[0]
        groups.append((company_id, company_name, f"{PREFIX}'{word}' in the subject",
                       f"{len(members)} tickets with the word '{word}' in the subject", members))
        taken = {t["id"] for t in members}
        rest = [t for t in rest if t["id"] not in taken]

if not groups:
    print(f"No group reaches {args.min_tickets} tickets. Nothing to do.")
    sys.exit(0)

# ---- 5. One problem per group: find it or open it, then link and note ---------------------------
opened = linked = 0
for company_id, company_name, title, why, members in groups:
    existing = find_ours(company_id, title)
    new_members = [t for t in members if not (existing and existing["id"] in t["_linked_to"])]
    where = f"  [{company_name}]" if len(by_company) > 1 and company_name else ""
    label = existing["problem_number"] if existing else "new problem"
    print(f"\n{title}  ({label}){where}")
    print(f"  why: {why}")
    for t in members:
        mark = "linked" if t not in new_members else ("link" if args.apply else "would link")
        print(f"    {mark:<11} {t['ticket_number']}  {t['subject']}")
    if not new_members:
        print("  nothing new - every ticket is already linked")
        continue
    if not args.apply:
        continue

    if existing is None:
        # The description says how the group was found, so whoever picks the
        # problem up knows it came from a script and on what evidence.
        body = {
            "title": title[:255],
            "description": f"Opened by the spot-repeat-incidents script: {why} in the last {args.days} days.",
            **problem_fields,
        }
        if company_id is not None:
            body["company_id"] = company_id
        existing = api("POST", "/problems", body)
        opened += 1
        print(f"  opened {existing['problem_number']}")

    # Link each ticket. 409 conflict = linked by somebody else meanwhile: fine.
    done = []
    for t in new_members:
        try:
            api("POST", f"/problems/{existing['id']}/tickets", {"ticket_id": t["id"]})
            done.append(t)
        except Conflict:
            pass
    linked += len(done)

    # One note per run that changed something: what was linked, and why.
    # Problem notes are an append-only journal, so this is the problem's history.
    if done:
        lines = [f"{len(done)} ticket(s) linked by the spot-repeat-incidents script - {why} "
                 f"(last {args.days} days, at least {args.min_tickets} needed):"]
        lines += [f"- {t['ticket_number']}  {t['subject']}" for t in done]
        api("POST", f"/problems/{existing['id']}/notes", {"note": "\n".join(lines)})
        print(f"  linked {len(done)} ticket(s) and added a note")

if args.apply:
    print(f"\nOpened {opened} problem(s); linked {linked} ticket(s).")
else:
    print("\nPreview only - add --apply to open these problems and link the tickets.")
