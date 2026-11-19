# @title       Impact before a change
# @summary     For one configuration item, prints everything that depends on it - directly and further down the chain - so you know who to warn before you touch it.
# @module      cmdb
# @changes     no
# @level       starter
# @endpoints   GET /cmdb/objects, GET /cmdb/objects/{id}, GET /cmdb/objects/{id}/impact
# @permissions cmdb_objects.read
#
# WHY: "I'm only rebooting the core switch" is how outages start. Before a change,
# ask the CMDB what sits on top of the thing you are changing. This uses the same
# impact engine as the Impact panel in the CMDB module, so the answer matches what
# analysts see on screen.
#
# HOW FREEITSM WORKS IT OUT: starting from your CI, it follows every edge along
# which a failure spreads - children (contained in it), relationships whose type
# says impact flows that way (e.g. "A depends on B": B failing hits A), and
# object-reference properties marked as spreading impact - hop after hop. A
# relationship type whose impact direction is "none" (e.g. "connects to") is
# NOT followed; set the direction under CMDB -> Settings ->
# Relationship types if that is wrong for you.
#
# Run it - by id, or by name (a name must match one CI). Standard library only:
#   python cmdb-impact-before-change.py --url https://helpdesk.example.com --object-id 42
#   python cmdb-impact-before-change.py --url https://helpdesk.example.com --name "Core switch 01"
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from itertools import groupby
from pathlib import Path

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Show what depends on a CMDB item before you change it.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The CI to check: its id (shown in the CMDB module's address bar and details)...
parser.add_argument("--object-id", type=int)
# ...or its name. Matching ignores upper/lower case.
parser.add_argument("--name")
args = parser.parse_args()

# Titles can hold any character - an emoji, Chinese. When the output is redirected
# to a file on Windows, Python writes in the old code page and would crash on
# those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if not args.object_id and not args.name:
    sys.exit('Pass --object-id 42 or --name "Core switch 01".')

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
    """Call the API and return the "data" part of the reply.

    On failure FreeITSM answers {"error": {"code": "...", "message": "..."}}; we stop
    and print that message. A CI in a company the key cannot reach answers 404
    "not found", exactly as if it did not exist.
    """
    request = urllib.request.Request(
        BASE + path, method=method,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            return json.loads(response.read().decode("utf-8"))["data"]
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


# ---- 1. Find the CI ---------------------------------------------------------------
# q= is a "contains" search on the name, so "switch" finds "Core switch 01" and
# "Edge switch 02". We take an exact match if there is one, otherwise insist on a
# single hit - guessing between two CIs is the wrong kind of help before a change.
object_id = args.object_id
if not object_id:
    hits = api("GET", "/cmdb/objects?" + urllib.parse.urlencode({"per_page": 100, "q": args.name}))
    exact = [h for h in hits if h["name"].lower() == args.name.lower()]
    if len(exact) == 1:
        hits = exact
    if not hits:
        sys.exit(f"No CI has a name containing '{args.name}'.")
    if len(hits) > 1:
        print(f"More than one CI matches '{args.name}' - run again with --object-id:")
        for h in hits:
            print(f"  {h['id']:<7} {h['name']}  ({h['class']['name']})")
        sys.exit(1)
    object_id = hits[0]["id"]

ci = api("GET", f"/cmdb/objects/{object_id}")
print(f"Impact of a change to #{ci['id']} {ci['name']} ({ci['class']['name']})")

# ---- 2. Ask for the impact ----------------------------------------------------------
# One call returns two views:
#   incoming_relationships / referenced_by_property / descendants
#       - what is attached DIRECTLY, one hop away
#   blast_radius
#       - EVERYTHING ultimately affected, each with its shortest hop count (depth),
#         how it was reached (via_name + via_label) and a grade
impact = api("GET", f"/cmdb/objects/{object_id}/impact")

print("\nDirectly attached")
direct = []
# relationship is the INVERSE verb, so it reads from this CI's side:
# "this is depended on by Payroll app".
direct += [f"this {r['relationship']} {r['name']} ({r['class_name']})" for r in impact["incoming_relationships"]]
direct += [f"{p['name']} ({p['class_name']}) points at it in '{p['property']}'" for p in impact["referenced_by_property"]]
direct += [f"{d['name']} ({d['class_name']}) is inside it, {d['depth']} level(s) down" for d in impact["descendants"]]
for line in direct or ["nothing"]:
    print("  " + line)

# ---- 3. The blast radius, nearest first ------------------------------------------------
# Grades: "affected" (goes down with it), "may_be_interrupted" or
# "redundancy_reduced" (a cluster or failover pair should carry it), "unknown"
# (FreeITSM lacks a fact it needs - see unknown_via). Without redundancy rules
# set up in the CMDB, everything reached is simply "affected".
blast = impact["blast_radius"]
print(f"\nEverything affected ({len(blast)})")
if not blast:
    print("  nothing - no CI depends on this one through an impact-bearing link")
# The server already sorts by depth, so groupby gives one block per hop count.
for depth, nodes in groupby(blast, key=lambda n: n["depth"]):
    print(f"  {depth} hop(s) away:")
    for n in nodes:
        # via_kind says which kind of edge reached it: child, relationship or property.
        if n["via_kind"] == "child":
            via = f"inside {n['via_name']}"
        elif n["via_kind"] == "property":
            via = f"its '{n['via_label']}' is {n['via_name']}"
        else:
            via = f"{n['via_label']} {n['via_name']}"
        unknown = f"  (unknown via: {', '.join(n['unknown_via'])})" if n.get("unknown_via") else ""
        print(f"    {n['grade']:<20} {n['name']} ({n['class_name']}) - {via}{unknown}")

# The walk stops at 1000 CIs or 20 hops. When it does, say so loudly: a capped list
# presented as complete is how somebody gets missed.
if impact["blast_radius_truncated"]:
    print("\nWARNING: the list was cut short (over 1000 CIs or 20 hops). The real impact is larger.")
for limit in impact["blast_radius_grades"]["limits"]:
    if isinstance(limit, dict) and limit.get("code") == "assumed_available":
        print(f"Note: assumed available because nothing records their state: {', '.join(limit['names'])}")
