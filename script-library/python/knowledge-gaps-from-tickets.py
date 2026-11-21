# @title       What the knowledge base does not answer
# @summary     Groups the tickets closed in the last 30 days by the words in their subjects, searches the knowledge base for each group, and lists the busy topics with no article - your next articles to write.
# @module      knowledge
# @changes     no
# @level       intermediate
# @endpoints   GET /tickets, GET /knowledge/articles
# @permissions tickets.read, knowledge.read
#
# WHAT IT SHOWS: joining two modules on the client. FreeITSM has no "which
# tickets have no article" call - the two modules do not know about each other -
# but the API gives you both halves:
#
#   1. The tickets CLOSED in the last --days days (GET /tickets, paged).
#   2. The words their subjects have in common. Each word is counted once per
#      ticket, and everyday words ("the", "please", "issue") are skipped - see
#      STOP_WORDS below and add your own.
#   3. For every word shared by at least --min-tickets tickets, a keyword search
#      of the knowledge base (GET /knowledge/articles?q=word). No published
#      article mentioning the word = a gap: people keep asking, nothing answers.
#      For a gap it also looks for an unpublished DRAFT, so you can finish that
#      instead of starting again.
#
# The knowledge search matches the word anywhere in an article's title or text
# (it is a "contains" search, so "print" also finds "printer"). Treat the result
# as a shortlist to read, not a verdict: a word like "laptop" may be covered by
# an article that never answers this particular question.
#
# "Closed" means a status marked as closed in Tickets -> Settings -> Statuses.
# If your team uses a "Resolved" status that is NOT marked closed, those tickets
# have no closed date yet and are not counted until they close.
#
# Run it (Python 3.8 or later, standard library only):
#   python knowledge-gaps-from-tickets.py --url https://helpdesk.example.com
#   python knowledge-gaps-from-tickets.py --url https://helpdesk.example.com --days 90 --min-tickets 5
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass --key-file). A read-only key is
# enough: tickets.read and knowledge.read. The knowledge search sees only the
# articles the key's analyst may read, so use the key of someone who can see
# the whole knowledge base, or "no article" may only mean "none you can see".

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path

# Subjects can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Find busy ticket topics the knowledge base does not answer.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# How far back to look, by when the ticket was CLOSED.
parser.add_argument("--days", type=int, default=30)
# A word must appear in at least this many tickets to be a topic.
parser.add_argument("--min-tickets", type=int, default=3)
# Search the knowledge base for at most this many topics (busiest first) - each
# one is a call, and every call counts towards the key's rate limit.
parser.add_argument("--max-topics", type=int, default=25)
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

# Words too common to be a topic. All lower case; add the ones your own
# subjects are full of (a company name, "laptop" if every ticket says it...).
STOP_WORDS = {
    "the", "and", "for", "with", "not", "can", "cannot", "cant", "from", "this", "that", "have", "has",
    "are", "was", "were", "our", "your", "you", "its", "but", "all", "any", "some", "into", "out", "off",
    "how", "what", "when", "why", "who", "which", "does", "doesn", "don", "did", "will", "won", "just",
    "please", "help", "issue", "issues", "problem", "problems", "request", "requests", "urgent", "asap",
    "ticket", "need", "needs", "needed", "new", "unable", "working", "work", "works", "again",
    "still", "after", "before", "get", "getting", "set", "use", "using", "fwd", "query", "question",
    "support", "user", "users", "there", "their", "they", "them", "been", "being", "one", "two", "way",
    "every", "keeps", "also", "about", "more", "than", "then", "only", "very",
}


# ---- One helper for every call --------------------------------------------------
def api(path):
    """GET a path and return the whole reply ({"data", "meta"}) because lists
    page; stop with FreeITSM's own error message on failure, e.g. 403 "This key
    lacks ..."."""
    request = urllib.request.Request(
        BASE + path, headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"GET {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


# ---- 1. The tickets closed in the window ----------------------------------------
# state=closed keeps only tickets in a closed status NOW (a ticket closed and
# then reopened has left the list); closed_since filters on the closed date.
# "Z" = UTC, which is how FreeITSM stores every date. sort=id keeps the pages
# steady if tickets close while we read.
since = (datetime.now(timezone.utc) - timedelta(days=args.days)).strftime("%Y-%m-%dT%H:%M:%SZ")
tickets = []
page = 1
while True:
    query = urllib.parse.urlencode({"state": "closed", "closed_since": since, "sort": "id", "per_page": 100, "page": page})
    reply = api(f"/tickets?{query}")
    tickets += reply["data"]
    if page >= reply["meta"]["total_pages"]:
        break
    page += 1
print(f"{len(tickets)} ticket(s) closed since {since}")

# ---- 2. The words their subjects share -----------------------------------------------
# Split on anything that is not a letter or a digit ([^\W_] is "a letter or a
# digit" in any alphabet), lower case, drop short words, numbers and stop words.
# A set per ticket counts a word once even if the subject says it twice.
topics = {}          # word -> list of tickets
for t in tickets:
    for word in {w for w in re.findall(r"[^\W_]+", (t["subject"] or "").lower())
                 if len(w) >= 3 and not w.isdigit() and w not in STOP_WORDS}:
        topics.setdefault(word, []).append(t)
busy = sorted(((w, ts) for w, ts in topics.items() if len(ts) >= args.min_tickets),
              key=lambda item: (-len(item[1]), item[0]))[:args.max_topics]
print(f"{len(busy)} topic(s) shared by {args.min_tickets} or more tickets")

# ---- 3. Search the knowledge base for each topic -----------------------------------
# GET /knowledge/articles?q= searches PUBLISHED articles unless
# include_unpublished=true is added. per_page=3 is enough: meta.total says how
# many matched, and the first titles show what they are.
gaps, covered = [], []
for word, group in busy:
    found = api("/knowledge/articles?" + urllib.parse.urlencode({"q": word, "per_page": 3}))
    # Which ticket types the group is made of - "8 Incident, 2 Question" says
    # whether the article is a fix or a how-to. Ticket order is by id, so the
    # examples are the oldest first.
    types = Counter((t["ticket_type"] or {}).get("name", "no type") for t in group)
    row = {
        "topic": word,
        "tickets": len(group),
        "types": ", ".join(f"{n} {name}" for name, n in types.most_common()),
        "examples": [f"{t['ticket_number']} {t['subject']}" for t in group[:3]],
        "articles": found["meta"]["total"],
        "titles": [a["title"] for a in found["data"]],
        "draft": "",
    }
    if found["meta"]["total"] == 0:
        drafts = api("/knowledge/articles?" + urllib.parse.urlencode(
            {"q": word, "include_unpublished": "true", "per_page": 1}))
        if drafts["meta"]["total"]:
            row["draft"] = drafts["data"][0]["title"]
        gaps.append(row)
    else:
        covered.append(row)

# ---- 4. The report ----------------------------------------------------------------
print(f"\nWhat the knowledge base does not answer - tickets closed in the last {args.days} day(s)")
print(f"\nSuggested articles to write ({len(gaps)}) - busy topics with no published article:")
if not gaps:
    print("  none")
for g in gaps:
    print(f"\n  \"{g['topic']}\" - {g['tickets']} ticket(s): {g['types']}")
    for example in g["examples"]:
        print(f"      {example}")
    if g["draft"]:
        print(f"      A draft already mentions it: {g['draft']}")
print(f"\nAlready covered ({len(covered)}) - check the article answers what people asked:")
if not covered:
    print("  none")
for c in covered:
    print(f"  \"{c['topic']}\" - {c['tickets']} ticket(s), {c['articles']} article(s): {'; '.join(c['titles'])}")
