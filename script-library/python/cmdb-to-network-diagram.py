# @title       Draw a network diagram from the CMDB
# @summary     Starting from one configuration item, follows its relationships a few hops out and draws them as a Network Mapper diagram - one node per item in tidy rows, one line per relationship.
# @module      network-mapper
# @changes     yes
# @level       advanced
# @endpoints   GET /cmdb/objects, GET /cmdb/objects/{id}, GET /cmdb-relationship-types, GET /network-diagrams,
#              GET /network-diagrams/{id}, POST /network-diagrams, POST /network-diagrams/{id}/nodes,
#              POST /network-diagrams/{id}/connectors
# @permissions cmdb_objects.read, reference.read, network_diagrams.read, network_diagrams.create,
#              network_diagrams.update
#
# WHY: the CMDB already knows that the payroll app runs on SRV-01, which connects
# to the core switch. Drawing that by hand in Network Mapper means dragging every
# item on and joining every line yourself. This script does the first draft for
# you; you then open it in Network Mapper and tidy the layout.
#
# WHAT IT DOES:
#   1. finds the starting CI (by id, or by a name that matches exactly one CI),
#   2. walks its relationships - BOTH directions, "depends on" and "is depended on
#      by" alike - level by level, up to --depth hops (default 2),
#   3. lays the CIs out in rows: the starting CI alone at the top, everything one
#      hop away on the row below, two hops on the row below that, and so on,
#   4. creates the diagram, adds every CI as a node in one call, then draws one
#      connector per relationship, labelled with its verb ("connects to") and
#      bound to the CMDB relationship it stands for.
#
# SAFE TO RUN AGAIN: the diagram is found by its title first. When a current
# diagram with that title already exists, the script ADDS only what is missing -
# CIs not yet on it, and relationships not yet drawn - and never moves, restyles
# or deletes anything. Someone may have spent an hour tidying that layout, and a
# node that is no longer related in the CMDB may be on the diagram on purpose.
# New nodes on an existing diagram are sent WITHOUT coordinates, so FreeITSM
# auto-places them in a fresh column to the right of what is there, where they
# are easy to spot and drag into place. To redraw from scratch, give a new
# --title (or delete the old diagram in Network Mapper first).
#
# THINGS TO KNOW:
#   - Network diagrams belong to the whole install, not to one company, so the
#     title search sees every diagram. CIs, though, are per company: a key can
#     only read - and only place on a diagram - CIs in companies it can reach.
#   - Relationships only. Parent/child containment and "object reference"
#     properties are not drawn; add them in the editor, which lists them under
#     the diagram's suggestions.
#   - --max-objects (default 60) stops a walk from the core switch turning into a
#     diagram of the whole estate. The script says when it stopped early.
#
# Run it - first WITHOUT --apply to see what it would draw (standard library only):
#   python cmdb-to-network-diagram.py --url https://helpdesk.example.com --name "Core switch 01"
#   python cmdb-to-network-diagram.py --url https://helpdesk.example.com --object-id 42 --depth 3 --apply
#   ... --name "Core switch 01" --only-verbs "connects to" --title "Core network" --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# It needs the permissions in the @permissions lines above.

import argparse
import datetime
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from pathlib import Path

# CI names can hold any character. When the output is redirected to a file on
# Windows, Python writes in the old code page and would crash on those;
# errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Draw a Network Mapper diagram from CMDB relationships.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The CI to start from: its id (shown in the CMDB module)...
parser.add_argument("--object-id", type=int)
# ...or its name. Matching ignores upper/lower case.
parser.add_argument("--name")
# How many hops out from the starting CI to follow. 1 = its direct neighbours.
parser.add_argument("--depth", type=int, default=2, choices=range(1, 7), metavar="1-6")
# Stop adding CIs once the diagram has this many.
parser.add_argument("--max-objects", type=int, default=60)
# Follow only these relationship types, by verb as shown under CMDB -> Settings ->
# Relationship types (e.g. "connects to"). Repeat it for several. Left out: all types.
parser.add_argument("--only-verbs", action="append", default=[])
# The diagram's title. Left out: "CMDB map - <starting CI's name>".
parser.add_argument("--title", default="")
# Without --apply the script only reads the CMDB and prints what it would draw.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if not args.object_id and not args.name:
    sys.exit('Pass --object-id 42 or --name "Core switch 01".')
if not 2 <= args.max_objects <= 500:
    sys.exit("--max-objects must be between 2 and 500.")

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
    """Call the API; return the "data" part, or stop with FreeITSM's own message -
    for example 422 "Unknown cmdb_object_id" when a CI is in a company the key
    cannot reach."""
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


# ---- 1. Which relationship types to follow ----------------------------------------
# The verbs are renameable per install, so a typed verb is checked against YOUR
# list (GET /cmdb-relationship-types) - a typo stops the run here rather than
# silently drawing nothing.
follow_type_ids = set()
if args.only_verbs:
    types = api("GET", "/cmdb-relationship-types")
    for verb in args.only_verbs:
        v = verb.strip().lower()
        hits = [t for t in types if t["verb"].lower() == v or (t["inverse_verb"] or "").lower() == v]
        if not hits:
            sys.exit(f"No relationship type '{verb}'. Choose from: {', '.join(t['verb'] for t in types)}")
        follow_type_ids.update(t["id"] for t in hits)

# ---- 2. Find the starting CI ---------------------------------------------------------
# q= is a "contains" search, so take an exact match if there is one, otherwise
# insist on a single hit rather than guess.
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

# ---- 3. Walk the relationships, level by level ------------------------------------------
# GET /cmdb/objects/{id} returns relationships.outgoing (this CI -> other) and
# relationships.incoming (other -> this CI), each with the relationship's own id,
# its type and verb, and the other CI's id and name. Neighbours in a company the
# key cannot reach are left out by FreeITSM, so they never appear here.
#
# Every CI found is read once, including those on the last level: their
# relationships are not followed further, but a line BETWEEN two CIs already on
# the diagram is still worth drawing.
start = api("GET", f"/cmdb/objects/{object_id}")
objects = {start["id"]: {"id": start["id"], "name": start["name"], "class": start["class"]["name"],
                         "level": 0, "detail": start}}
edges = {}          # relationship id -> {id, from, to, verb}
queue = deque([start["id"]])
capped = False
title = args.title or f"CMDB map - {start['name']}"

while queue:
    me = objects[queue.popleft()]
    if me["detail"] is None:
        me["detail"] = api("GET", f"/cmdb/objects/{me['id']}")
    rels = me["detail"]["relationships"]
    # Outgoing: this CI is the "from" end. Incoming: the other CI is.
    links = [(r, me["id"], r["other_id"]) for r in rels["outgoing"]]
    links += [(r, r["other_id"], me["id"]) for r in rels["incoming"]]
    for r, frm, to in links:
        if follow_type_ids and r["type_id"] not in follow_type_ids:
            continue
        other = r["other_id"]
        if other not in objects:
            # Only CIs closer than --depth have their neighbours added.
            if me["level"] >= args.depth:
                continue
            if len(objects) >= args.max_objects:
                capped = True
                continue
            objects[other] = {"id": other, "name": r["other_name"], "class": r["other_class_name"],
                              "level": me["level"] + 1, "detail": None}
            queue.append(other)
        # Each relationship is seen from both ends; its id keeps it to one line.
        edges[r["id"]] = {"id": r["id"], "from": frm, "to": to, "verb": r["verb"]}

# ---- 4. Lay out the rows ------------------------------------------------------------------
# x/y are the node's TOP-LEFT corner in canvas pixels. A medium node is 56px and
# its name is drawn underneath, so 190px across and 170px down leaves room for
# the labels. Each row is centred under the widest one. Within a row, CIs sit
# in the order of the CI above that found them, which keeps lines from crossing
# more than they have to.
GAP_X, GAP_Y, LEFT, TOP = 190, 170, 80, 80
rows = {}
for o in objects.values():
    rows.setdefault(o["level"], []).append(o)
widest = max(len(r) for r in rows.values())
slot = {}           # CI id -> position in its row, used to order the next row


def leftmost_above(o):
    """Position of this CI's left-most neighbour on the row above (0 if none)."""
    above = []
    for e in edges.values():
        if o["id"] in (e["from"], e["to"]):
            n = e["to"] if e["from"] == o["id"] else e["from"]
            if objects[n]["level"] == o["level"] - 1:
                above.append(slot[n])
    return min(above) if above else 0


for level in sorted(rows):
    row = sorted(rows[level], key=lambda o: (leftmost_above(o), o["name"].lower()))
    offset = (widest - len(row)) * GAP_X / 2
    for i, o in enumerate(row):
        o["x"] = int(LEFT + offset + i * GAP_X)
        o["y"] = TOP + level * GAP_Y
        slot[o["id"]] = i
    rows[level] = row

# ---- 5. Show what was found ------------------------------------------------------------------
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to draw it"
print(f"Diagram '{title}' -> {BASE}   [{mode}]")
for level in sorted(rows):
    print(f"  Row {level}")
    for o in rows[level]:
        print(f"    #{o['id']} {o['name']} ({o['class']})")
print(f"  Relationships ({len(edges)})")
for e in sorted(edges.values(), key=lambda e: e["id"]):
    print(f"    {objects[e['from']]['name']} {e['verb']} {objects[e['to']]['name']}")
if capped:
    print(f"  Stopped at --max-objects {args.max_objects} - there is more beyond these. Raise it or lower --depth.")

# ---- 6. Is there a diagram already? ----------------------------------------------------------
# GET /network-diagrams lists only CURRENT versions (the editable leaf of each
# version chain); q= is a "contains" search on the title, so compare exactly here.
same = [d for d in api("GET", "/network-diagrams?" + urllib.parse.urlencode({"per_page": 100, "q": title}))
        if d["title"].lower() == title.lower()]
if len(same) > 1:
    sys.exit(f"{len(same)} diagrams are called '{title}' (ids {', '.join(str(d['id']) for d in same)}). Pass a different --title.")

node_of = {}        # CI id -> node id on the diagram
drawn_rel = set()   # relationship ids already drawn
drawn_pair = set()  # (node, node) pairs already joined - FreeITSM refuses a second line (409)
diagram_id = 0
if same:
    diagram_id = same[0]["id"]
    existing = api("GET", f"/network-diagrams/{diagram_id}")
    for n in existing["nodes"]:
        node_of.setdefault(n["object"]["id"], n["id"])
    for c in existing["connectors"]:
        if c["relationship"]:
            drawn_rel.add(c["relationship"]["id"])
        drawn_pair.add((c["from"]["node_id"], c["to"]["node_id"]))
        drawn_pair.add((c["to"]["node_id"], c["from"]["node_id"]))
    print(f"\nDiagram #{diagram_id} '{title}' already exists ({existing['node_count']} nodes) - only what is missing is added.")

new_objects = [o for o in objects.values() if o["id"] not in node_of]


def is_new_edge(e):
    """Not drawn yet: its relationship is not on the diagram, and - when both ends
    are already there - those two nodes are not joined already."""
    if e["id"] in drawn_rel:
        return False
    if e["from"] in node_of and e["to"] in node_of:
        return (node_of[e["from"]], node_of[e["to"]]) not in drawn_pair
    return True


new_edges = [e for e in sorted(edges.values(), key=lambda e: e["id"]) if is_new_edge(e)]

if not args.apply:
    what = f"add to diagram #{diagram_id}" if diagram_id else "create the diagram with"
    print(f"\nWould {what} {len(new_objects)} node(s) and {len(new_edges)} connector(s).")
    sys.exit(0)

# ---- 7. Draw it ---------------------------------------------------------------------------------
if not diagram_id:
    created = api("POST", "/network-diagrams", {
        "title": title,
        "description": f"Drawn from the CMDB, starting at {start['name']}, {args.depth} hop(s) out, "
                       f"on {datetime.date.today().isoformat()}.",
    })
    diagram_id = created["id"]
    print(f"Created diagram #{diagram_id}")

# All new nodes in ONE call: {"nodes": [...]} answers with the list of new nodes,
# each carrying its object - so the node ids are matched back by object id, not
# by trusting the order. On an existing diagram x/y are left out (see the top).
if new_objects:
    nodes = []
    for o in new_objects:
        n = {"cmdb_object_id": o["id"], "size": "large" if o["level"] == 0 else "medium"}
        if not same:
            n["x"], n["y"] = o["x"], o["y"]
        nodes.append(n)
    added = api("POST", f"/network-diagrams/{diagram_id}/nodes", {"nodes": nodes})
    for n in added:
        node_of[n["object"]["id"]] = n["id"]
    print(f"Added {len(added)} node(s)")

# One connector per relationship. cmdb_relationship_id ties the line to the CMDB
# relationship, so the diagram knows what it means (GET /network-diagrams/{id}
# shows its verbs); the label is what the editor draws on the line.
for e in new_edges:
    api("POST", f"/network-diagrams/{diagram_id}/connectors", {
        "from_node_id": node_of[e["from"]],
        "to_node_id": node_of[e["to"]],
        "cmdb_relationship_id": e["id"],
        "label": e["verb"],
    })
print(f"Added {len(new_edges)} connector(s)")
print(f"\nDone. Open it in Network Mapper to tidy the layout: {args.url.rstrip('/')}/network-mapper/diagram.php?id={diagram_id}&fit=1")
