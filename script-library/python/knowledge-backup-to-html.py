# @title       Back up the knowledge base to HTML
# @summary     Saves every knowledge article as its own HTML page in a folder, with an index.html that links them all - readable in any browser, even when FreeITSM is down.
# @module      knowledge
# @changes     no
# @level       intermediate
# @endpoints   GET /knowledge/articles, GET /knowledge/articles/{id}
# @permissions knowledge.read
#
# WHY: the runbook that tells you how to bring the helpdesk back is no use if it
# lives only inside the helpdesk. Run this nightly and copy the folder somewhere
# that does not depend on FreeITSM - a file share, a USB stick in the safe.
#
# WHAT YOU GET:
#   knowledge-backup/index.html              a table of every article, linking to...
#   knowledge-backup/191-escalating-a-p1-outage.html   ...one page per article
#
# Links between articles (the ?article=123 links the editor writes) are pointed at
# the backup copies, so you can click from one article to another offline.
#
# NOT INCLUDED: pictures. An article's pictures are served by FreeITSM and need
# the knowledge_images.read permission; offline they show as broken images. Add
# GET /knowledge/articles/{id}/images to fetch them if you need them.
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python knowledge-backup-to-html.py --url https://helpdesk.example.com
#   python knowledge-backup-to-html.py --url https://helpdesk.example.com --out-folder /srv/kb-backup --include-drafts
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you. The backup is
# only as private as the folder you put it in: the key sees internal articles.

import argparse
import html
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Back up the FreeITSM knowledge base to HTML files.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Where the HTML files go. Created if missing; files from an earlier run with the
# same names are overwritten, so the folder always matches today.
parser.add_argument("--out-folder", default=str(Path(__file__).with_name("knowledge-backup")))
# Published articles only, unless you add this.
parser.add_argument("--include-drafts", action="store_true")
args = parser.parse_args()

# Titles can hold any character - an emoji, Chinese. When the output is redirected
# to a file on Windows, Python writes in the old code page and would crash on
# those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")

# ---- The key --------------------------------------------------------------------
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()

# Every REST call lives under /api/v1 on your server.
BASE = args.url.rstrip("/") + "/api/v1"


# ---- One helper for every call --------------------------------------------------
def api(method, path):
    """Return FreeITSM's WHOLE reply - {"data": ..., "meta": {...}} - because a list
    says in "meta" how many pages there are. On failure FreeITSM answers
    {"error": {"code": "...", "message": "..."}}; we stop and print that message."""
    request = urllib.request.Request(
        BASE + path, method=method,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def esc(text):
    """Text we put into OUR page (titles, tags, names) must be escaped, or a title
    like "Fixing <Outlook> & Teams" would break the page or, worse, inject markup.
    The article BODY is different: it is already HTML, stored by FreeITSM's editor,
    so it goes in as it is - the same HTML the app itself shows."""
    return html.escape("" if text is None else str(text), quote=True)


def safe_file_name(article):
    """The article id (always unique) plus a readable slug of the title made of
    a-z, 0-9 and hyphens only - so no slashes, colons, dots or reserved Windows
    names can sneak in, whatever the title says."""
    slug = re.sub(r"[^a-z0-9]+", "-", article["title"].lower()).strip("-")
    slug = slug[:60].strip("-") or "article"
    return f"{article['id']}-{slug}.html"


# A little CSS so the backup is pleasant to read. Change it to taste.
STYLE = ("body{font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:2em auto;padding:0 1em;line-height:1.5;color:#222}"
         "table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:4px 8px;text-align:left;vertical-align:top}"
         ".meta{color:#555;font-size:.9em}img{max-width:100%}")

# ---- 1. List every article --------------------------------------------------------
# The list gives ids, titles and details but only a 300-character preview of the
# body; the full body needs one GET per article (step 2). Archived (deleted)
# articles are never in this list. Pages hold at most 100 rows (the server's cap);
# meta.total_pages says when to stop.
filters = {"sort": "title", "per_page": 100}
if args.include_drafts:
    filters["include_unpublished"] = "true"
articles, page = [], 1
while True:
    reply = api("GET", "/knowledge/articles?" + urllib.parse.urlencode({**filters, "page": page}))
    articles.extend(reply["data"])
    if page >= reply["meta"]["total_pages"]:
        break
    page += 1

out = Path(args.out_folder)
print(f"{len(articles)} article(s) to back up from {BASE} into {out}")

# Work out every file name FIRST, so that while writing article A we already know
# the file name of article B it links to.
file_for = {a["id"]: safe_file_name(a) for a in articles}
out.mkdir(parents=True, exist_ok=True)


def relink(match):
    """?article=123 -> 123-some-title.html when 123 is in this backup. A link to an
    article the key cannot see (or a draft you left out) is left as it was."""
    target = int(match.group(1))
    return f'href="{file_for[target]}"' if target in file_for else match.group(0)


# ---- 2. One page per article ---------------------------------------------------------
for a in articles:
    # count_view is NOT sent, so a backup does not inflate the article's view count.
    full = api("GET", f"/knowledge/articles/{a['id']}")["data"]
    body = re.sub(r'href="\?article=(\d+)"', relink, full.get("body_html") or "")
    owner = full["owner"]["name"] if full.get("owner") else "(none)"
    author = full["author"]["name"] if full.get("author") else "(unknown)"
    state = "published" if full["is_published"] else "DRAFT"
    page_html = f"""<!doctype html>
<html><head><meta charset="utf-8"><title>{esc(full['title'])}</title><style>{STYLE}</style></head>
<body>
<p><a href="index.html">&larr; All articles</a></p>
<h1>{esc(full['title'])}</h1>
<p class="meta">Article #{full['id']}, version {full['version']}, {state}, audience {esc(full['audience'])}<br>
Owner: {esc(owner)} &middot; Author: {esc(author)} &middot; Last changed: {esc(full['modified_at'])} &middot; Next review: {esc(full.get('next_review_date') or '(not set)')}<br>
Tags: {esc(', '.join(full.get('tags') or []))}</p>
<hr>
<article>
{body}
</article>
</body></html>
"""
    # UTF-8, which is what the <meta charset="utf-8"> line promises the browser.
    # newline="\n" keeps the files identical whichever system writes them.
    with open(out / file_for[a["id"]], "w", encoding="utf-8", newline="\n") as f:
        f.write(page_html)
    print(f"  #{a['id']} -> {file_for[a['id']]}")

# ---- 3. The index ----------------------------------------------------------------------
rows = []
for a in articles:
    owner = a["owner"]["name"] if a.get("owner") else ""
    state = "" if a["is_published"] else " (draft)"
    rows.append(f'<tr><td><a href="{file_for[a["id"]]}">{esc(a["title"])}</a>{state}</td>'
                f'<td>{esc(", ".join(a.get("tags") or []))}</td><td>{esc(owner)}</td><td>{esc(a["modified_at"])}</td></tr>')
stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M")
index_html = f"""<!doctype html>
<html><head><meta charset="utf-8"><title>Knowledge base backup</title><style>{STYLE}</style></head>
<body>
<h1>Knowledge base backup</h1>
<p class="meta">{len(articles)} article(s) from {esc(args.url)}, taken {stamp} UTC.</p>
<table><tr><th>Title</th><th>Tags</th><th>Owner</th><th>Last changed (UTC)</th></tr>
{chr(10).join(rows)}
</table>
</body></html>
"""
with open(out / "index.html", "w", encoding="utf-8", newline="\n") as f:
    f.write(index_html)
print(f"\nDone. Open {out / 'index.html'} in a browser.")
