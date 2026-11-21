# @title       Migrate a knowledge base into FreeITSM
# @summary     Recreates another knowledge base - folders, articles, pictures, access lists and links - and is safe to stop and run again.
# @module      knowledge
# @changes     yes
# @level       advanced
# @endpoints   GET /knowledge/images/constraints, POST /knowledge/folders, POST /knowledge/articles,
#              POST /knowledge/articles/{id}/images, GET /knowledge/articles/{id}, PATCH /knowledge/articles/{id}
# @permissions knowledge_images.read, knowledge_folders.create, knowledge.create, knowledge_images.create, knowledge.read, knowledge.update
#
# WHAT IT EXPECTS: an export folder from your old system, in this simple shape
# (write a small exporter for your old tool, or reshape its export into this):
#
#   export/kb.json                 the index - see the example below
#   export/articles/kb-101.html    one HTML file per article
#   export/images/diagram.png      pictures, referenced from the HTML as ../images/diagram.png
#
#   kb.json:
#   { "articles": [
#       { "id": "KB-101", "title": "Reset your VPN token", "folder": "Networking/VPN",
#         "file": "articles/kb-101.html", "tags": ["vpn"], "audience": "internal",
#         "restricted_to_team": null },
#       { "id": "KB-103", "title": "Escalating a P1 outage", "folder": "Service Desk/Runbooks",
#         "file": "articles/kb-103.html", "tags": ["p1"], "audience": "internal",
#         "restricted_to_team": "Escalation Team" } ] }
#
#   Links between articles are written as <a href="kb://KB-101">; the script turns
#   them into links to the new FreeITSM articles.
#
# HOW IT WORKS - the order matters:
#   0. Every picture is checked against what THIS install accepts - its size
#      limit and formats, read from the API - before anything is written, so a
#      picture that would be refused is found now, not half way through.
#   1. Folders, parents first.
#   2. Each article as a DRAFT (only analysts can see it), already in its folder,
#      with its audience and access list - so a restricted article is never
#      visible to everyone, not even for a moment.
#   3. Its pictures, uploaded to that article; each <img src> is pointed at the
#      picture's new address.
#   4. Links rewritten to the new article ids, then each article is PUBLISHED.
#
# SAFE TO RUN AGAIN: every create carries a client_reference (your old system's
# id). Send the same request twice and FreeITSM returns what it made the first
# time instead of making another - so after a timeout or Ctrl+C, just run it again.
#
# Run it - first WITHOUT --apply to see what it would do (standard library only):
#   python knowledge-migration.py --url https://helpdesk.example.com --export ./export
#   python knowledge-migration.py --url https://helpdesk.example.com --export ./export --apply
#
# The key needs the permissions in the @permissions line above, and its analyst
# must be a Knowledge administrator to set access lists. Docs:
# https://github.com/edmozley/freeitsm/wiki/REST-API-Knowledge#-migrating-a-knowledge-base-in

import argparse
import base64
import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

parser = argparse.ArgumentParser(description="Migrate a knowledge base into FreeITSM.")
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
parser.add_argument("--export", default=str(Path(__file__).with_name("export")))
# Your old system's team names -> FreeITSM team ids, as JSON. Map EVERY team your
# export restricts articles to: FreeITSM refuses an access entry it cannot match
# rather than quietly dropping it, because a dropped rule could open an article to
# people who should not see it. Find team ids under System -> Teams.
parser.add_argument("--team-map", default='{"Escalation Team": 10}')
# Without --apply the script only reads the export and prints the plan.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
api_key = os.environ.get("FREEITSM_API_KEY") or Path(args.key_file).read_text(encoding="utf-8").strip()
BASE = args.url.rstrip("/") + "/api/v1"
team_map = json.loads(args.team_map)
export = Path(args.export)


def api(method, path, body=None):
    """Call the API; return the "data" part, or stop with FreeITSM's own message
    (e.g. 409 reference_conflict, 422 invalid_access)."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))["data"]
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def outcome(result):
    return "(already there - reused)" if result.get("replayed") else "(created)"


index = json.loads((export / "kb.json").read_text(encoding="utf-8"))
articles = index["articles"]
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to migrate"
print(f"{len(articles)} articles from {export} -> {BASE}   [{mode}]")

# Check the team mapping BEFORE writing anything, so a missing team stops the run
# at the start instead of half way through.
for a in articles:
    if a.get("restricted_to_team") and a["restricted_to_team"] not in team_map:
        sys.exit(f"Article {a['id']} is restricted to '{a['restricted_to_team']}', which is not in --team-map.")

# ---- 0. Will FreeITSM take every picture? -----------------------------------------
# The largest picture is an administrator's choice (Knowledge -> Settings -> Images,
# 10 MB unless changed) and only some formats are accepted - never SVG, which can
# carry a script. So ask the install rather than guessing, and check every picture
# NOW: a refused picture otherwise stops the run half way, with drafts already made.
limits = api("GET", "/knowledge/images/constraints")
accepted = {f["extension"] for f in limits["formats"]}
limit_mb = limits["max_bytes"] / 1048576
print(f"\n0. Pictures - this install takes up to {limit_mb:g} MB each: {', '.join(sorted(accepted))}")
if not limits["within_server_limits"]:
    # FreeITSM's limit is fine but the server's PHP is set too low to carry it.
    print("  ! This server's PHP settings are too low for pictures that big - see the warning")
    print("    under Knowledge -> Settings -> Images, or keep the pictures smaller.")

refused = []
for a in articles:
    article_file = export / a["file"]
    for rel in re.findall(r'src="((?!https?:|data:)[^"]+)"', article_file.read_text(encoding="utf-8")):
        file = (article_file.parent / rel).resolve()
        ext = file.suffix.lower().lstrip(".")
        if not file.is_file():
            refused.append(f"{a['id']}: {rel} - the file is not in the export")
        elif ext not in accepted:
            refused.append(f"{a['id']}: {rel} - .{ext} pictures are not accepted (convert it to PNG or JPEG)")
        elif file.stat().st_size > limits["max_bytes"]:
            refused.append(f"{a['id']}: {rel} - {file.stat().st_size / 1048576:.1f} MB, over the {limit_mb:g} MB limit")

if refused:
    print(f"  {len(refused)} picture(s) would be refused:")
    for line in refused:
        print(f"    - {line}")
    print("  Shrink or convert them, or raise the limit under Knowledge -> Settings -> Images.")
    if args.apply:
        sys.exit("Nothing was written. Fix the pictures above and run again.")
else:
    print("  every picture fits")

# ---- 1. Folders -----------------------------------------------------------------
# "Networking/VPN" needs "Networking" first. The cache stops us asking twice.
folder_ids = {}


def folder_id(path):
    if path in folder_ids:
        return folder_ids[path]
    parts = path.split("/")
    parent = folder_id("/".join(parts[:-1])) if len(parts) > 1 else None
    if not args.apply:
        print(f"  would create folder {path}")
        folder_ids[path] = f"({path})"
        return folder_ids[path]
    f = api("POST", "/knowledge/folders", {"name": parts[-1], "parent_id": parent, "client_reference": f"folder:{path}"})
    print(f"  folder  {path} -> #{f['id']} {outcome(f)}")
    folder_ids[path] = f["id"]
    return f["id"]


print("\n1. Folders")
for a in articles:
    folder_id(a["folder"])

# ---- 2 + 3. Articles as drafts, then their pictures -------------------------------
print("\n2. Articles (as drafts) and 3. their pictures")
new = {}
for a in articles:
    article_file = export / a["file"]
    html = article_file.read_text(encoding="utf-8")
    # Local pictures only - leave http:, https: and data: images alone. Paths are
    # relative to the ARTICLE's folder, the way a browser previewing the export reads them.
    pictures = re.findall(r'src="((?!https?:|data:)[^"]+)"', html)

    if not args.apply:
        note = f" restricted to {a['restricted_to_team']}" if a.get("restricted_to_team") else ""
        print(f"  would create draft '{a['title']}' in {a['folder']}{note}, with {len(pictures)} picture(s)")
        continue

    body = {
        "title": a["title"],
        "body_html": html,
        "tags": a.get("tags", []),
        "audience": a.get("audience", "internal"),
        "folder_id": folder_ids[a["folder"]],
        "is_published": False,       # a draft until its pictures and links are in place
        "processing": "suppress",    # no AI or workflow calls for each imported article
        "client_reference": f"article:{a['id']}",
    }
    if a.get("restricted_to_team"):
        # Saved in the same call as the article: never briefly open to everyone.
        body["access"] = {"is_restricted": True, "inherits": False,
                          "entries": [{"principal_type": "team", "principal_id": team_map[a["restricted_to_team"]]}]}
    art = api("POST", "/knowledge/articles", body)
    print(f"  article {a['id']} '{a['title']}' -> #{art['id']} {outcome(art)}")

    image_ids = []
    for rel in pictures:
        file = (article_file.parent / rel).resolve()
        img = api("POST", f"/knowledge/articles/{art['id']}/images", {
            "filename": file.name,
            "content_base64": base64.b64encode(file.read_bytes()).decode("ascii"),
            "client_reference": f"image:{a['id']}:{rel}"})
        print(f"    picture {rel} -> image #{img['id']} {outcome(img)}")
        # The returned url checks the article's permissions on every view.
        html = html.replace(f'src="{rel}"', f'src="{img["url"]}"')
        image_ids.append(img["id"])
    new[a["id"]] = {"id": art["id"], "html": html, "images": image_ids}

# ---- 4. Links, then publish ---------------------------------------------------------
if args.apply:
    print("\n4. Links and publishing")
    for a in articles:
        n = new[a["id"]]

        def relink(m):
            # kb://KB-101 -> ?article=<new id>, the same link the editor's link picker writes.
            target = m.group(1)
            if target not in new:
                sys.exit(f"Article {a['id']} links to {target}, which is not in the export.")
            return f'href="?article={new[target]["id"]}"'

        html = re.sub(r'href="kb://([^"]+)"', relink, n["html"])
        # Read it first: the revision proves nobody edited it since, and we skip
        # articles a previous run already finished.
        current = api("GET", f"/knowledge/articles/{n['id']}")
        if current["is_published"] and current["body_html"] == html:
            print(f"  #{n['id']} already published - nothing to do")
            continue
        patch = {"body_html": html, "is_published": True, "expected_revision": current["revision"]}
        # Refuse to publish if a picture went missing or belongs to another article.
        if n["images"]:
            patch["required_image_ids"] = n["images"]
        api("PATCH", f"/knowledge/articles/{n['id']}", patch)
        print(f"  #{n['id']} '{a['title']}' published")
print("\nDone.")
