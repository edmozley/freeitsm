# @title       Plan a hardware refresh from ageing assets
# @summary     Finds assets older than a number of years or out of warranty, groups them by type and location, and turns them into a project with a budget line per group, a risk for kit already out of warranty, and optional stages.
# @module      projects
# @changes     yes
# @level       advanced
# @endpoints   GET /asset-types, GET /assets, GET /projects, POST /projects, GET /projects/{id}/budget, POST /projects/{id}/budget-lines, GET /projects/{id}/raid, POST /projects/{id}/raid, GET /projects/{id}/stages, POST /projects/{id}/stages
# @permissions reference.read, assets.read, projects.read, projects.create, projects.update
#
# WHY: a refresh budget is usually built in a spreadsheet from an asset export,
# then retyped into a project. This script does both halves in one go: it picks
# out the kit due for replacement, counts it per type and site, prices it, and
# builds the project the work will be run from - so the plan and the evidence
# start in the same place.
#
# WHICH ASSETS: an asset is due when it was bought more than --older-than-years
# ago (its purchase date), OR its warranty has already ended. Retired assets are
# left out (the API does that by default). An asset with neither date cannot be
# judged - it is counted and reported, so you can fill the dates in, but it is
# not added to the plan.
#
# WHAT IT BUILDS - a project (staged, so its Budget and RAID tabs are on) with:
#   - one BUDGET LINE per type + location, e.g. "Laptop - London": quantity x
#     unit cost, category hardware, the hostnames in its notes. The unit cost
#     comes from UNIT_COSTS below, or --unit-cost for a type not listed. It is
#     in the project's currency (your install's default).
#   - one RISK per group that has kit ALREADY out of warranty: a failure there is
#     a repair you pay for, or a person without a device, until it is replaced.
#   - with --stages, three stages: order, deploy, dispose of the old kit.
# If new projects need approval on your install (Projects -> Settings), it starts
# as "proposed" and waits for that approval like any other proposal.
#
# SAFE TO RUN AGAIN: the project is found by its exact name first (--project-name)
# and reused. Budget lines, risks and stages are matched by title and only the
# missing ones are added. An existing budget line is NEVER changed - somebody may
# have edited the figure - but if today's count would give a different amount,
# the script says so, and you can update it by hand.
#
# Run it - first WITHOUT --apply to see the plan (standard library only):
#   python plan-hardware-refresh-project.py --url https://helpdesk.example.com
#   python plan-hardware-refresh-project.py --url https://helpdesk.example.com --older-than-years 4 --types Laptop,Desktop --stages --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# Keep that file out of source control and readable only by you. The key's
# analyst becomes the project manager, and must be allowed to create projects
# (Projects -> Settings). On a multi-company install the project goes in the
# key's default company - use a key scoped to the company whose kit it is.

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict
from datetime import date
from pathlib import Path

# ---- YOUR PRICES: what one replacement costs, per asset type --------------------------
# Keys are asset type names exactly as in Assets -> Settings (capitals ignored).
# A type not listed here uses --unit-cost. Whole numbers or two decimal places.
UNIT_COSTS = {
    "Laptop": 1100,
    "Desktop": 850,
    "Monitor": 180,
}

# ---- Settings from the command line ---------------------------------------------
next_year = date.today().year + 1
parser = argparse.ArgumentParser(description="Plan a hardware refresh project from ageing FreeITSM assets.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# Bought this many years ago or more = due for replacement.
parser.add_argument("--older-than-years", type=int, default=5)
# The price of one replacement for a type not in UNIT_COSTS.
parser.add_argument("--unit-cost", type=float, default=1000)
# Only these asset types, comma separated (e.g. Laptop,Desktop). Empty = every type.
parser.add_argument("--types", default="")
# Only assets whose hostname, model, manufacturer or service tag contains this -
# e.g. one model ("Latitude 7480"). Empty = every asset.
parser.add_argument("--search", default="")
# The project's name - also how it is found again on the next run.
parser.add_argument("--project-name", default=f"Hardware refresh {next_year}")
# Add three stages (order, deploy, dispose).
parser.add_argument("--stages", action="store_true")
# Without --apply the script only prints the plan.
parser.add_argument("--apply", action="store_true")
args = parser.parse_args()

# Hostnames and names can hold any character. When the output is redirected to
# a file on Windows, Python writes in the old code page and would crash on those;
# errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

if not args.url:
    sys.exit("Pass --url https://your-freeitsm (or set FREEITSM_URL).")
if args.older_than_years < 1:
    sys.exit("--older-than-years must be 1 or more.")

# ---- The key --------------------------------------------------------------------
api_key = os.environ.get("FREEITSM_API_KEY")
if not api_key:
    key_path = Path(args.key_file)
    if not key_path.exists():
        sys.exit(f"No key: set FREEITSM_API_KEY or put the key in {key_path}")
    api_key = key_path.read_text(encoding="utf-8").strip()

# Every REST call lives under /api/v1 on your server.
BASE = args.url.rstrip("/") + "/api/v1"


# ---- Helpers --------------------------------------------------------------------
def api_raw(method, path, body=None):
    """Call the API and return FreeITSM's WHOLE reply ({"data": ..., "meta": ...});
    on failure stop with FreeITSM's own message ({"error": {"code", "message"}})."""
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


def api(method, path, body=None):
    """The usual case: just the "data" part."""
    return api_raw(method, path, body)["data"]


def all_pages(path, filters):
    """Every row of a list (at most 100 a page; meta.total_pages says when to stop)."""
    rows, page = [], 1
    while True:
        reply = api_raw("GET", path + "?" + urllib.parse.urlencode({**filters, "per_page": 100, "page": page}))
        rows += reply["data"]
        if page >= reply["meta"]["total_pages"]:
            return rows
        page += 1


def years_ago(day, years):
    """The same date `years` years earlier; 29 February becomes the 28th."""
    try:
        return day.replace(year=day.year - years)
    except ValueError:
        return day.replace(year=day.year - years, day=28)


def money(amount):
    """Budget amounts are sent as text with two decimals ("2200.00"): the API
    accepts at most two, and a float such as 0.1 + 0.2 would have more."""
    return f"{amount:.2f}"


today = date.today()
cutoff = years_ago(today, args.older_than_years)
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to build the project"
print(f"Hardware refresh plan -> {BASE}   [{mode}]")
print(f"Due: bought on or before {cutoff} ({args.older_than_years}+ years), or warranty ended before {today}")

# ---- 1. Asset types: check --types, and price each type ----------------------------------
# GET /asset-types gives the names exactly as they are set up, so a typo in
# --types or in UNIT_COSTS is caught here instead of quietly matching nothing.
types = api("GET", "/asset-types")
type_names = {t["name"].lower(): t["name"] for t in types}
wanted = set()
for name in filter(None, (n.strip() for n in args.types.split(","))):
    if name.lower() not in type_names:
        sys.exit(f"No asset type called '{name}'. Choose from: " + ", ".join(sorted(type_names.values())))
    wanted.add(name.lower())
prices = {k.lower(): v for k, v in UNIT_COSTS.items()}
for k in prices:
    if k not in type_names:
        print(f"  note: UNIT_COSTS lists '{k}', which is not an asset type here - it is never used")

# ---- 2. The assets, and which are due -------------------------------------------------------
# GET /assets leaves retired assets out unless asked. Each asset carries its
# type, location (with the full path, e.g. "London > Floor 2") and lifecycle
# dates as YYYY-MM-DD. The date logic is done here, on the client, because the
# API's own filters cover warranty OR age, not both at once.
filters = {"sort": "hostname"}
if args.search:
    filters["q"] = args.search
assets = all_pages("/assets", filters)

groups = defaultdict(list)   # (type name, location) -> [(asset, reasons, out_of_warranty)]
undated = 0
for a in assets:
    type_name = a["type"]["name"] if a["type"] else "No type"
    if wanted and type_name.lower() not in wanted:
        continue
    life = a["lifecycle"]
    bought = date.fromisoformat(life["purchase_date"][:10]) if life["purchase_date"] else None
    warranty = date.fromisoformat(life["warranty_expiry"][:10]) if life["warranty_expiry"] else None
    if bought is None and warranty is None:
        undated += 1
        continue
    reasons = []
    if bought and bought <= cutoff:
        reasons.append(f"bought {bought}")
    expired = bool(warranty and warranty < today)
    if expired:
        reasons.append(f"warranty ended {warranty}")
    if not reasons:
        continue
    loc = a["location"]
    location = (loc.get("path") or loc["name"]) if loc else "No location"
    groups[(type_name, location)].append((a, reasons, expired))

total = sum(len(m) for m in groups.values())
print(f"Assets read: {len(assets)}; due for replacement: {total}; with no purchase or warranty date: {undated}")
if not groups:
    print("Nothing is due. Nothing to plan.")
    sys.exit(0)

# ---- 3. The plan, worked out before anything is written -------------------------------------------
plan = []
for (type_name, location), members in sorted(groups.items()):
    unit = prices.get(type_name.lower(), args.unit_cost)
    title = f"{type_name} - {location}"[:200]
    names = sorted(a["hostname"] or f"asset #{a['id']}" for a, _, _ in members)
    # A line's notes hold at most 500 characters, so a long list ends "and 12 more".
    notes = f"{len(members)} x {money(unit)}. Replaces: "
    for i, n in enumerate(names):
        tail = f" and {len(names) - i} more" if i else f"{len(names)} assets"
        if len(notes) + len(n) + 2 + len(" and 9999 more") > 500:
            notes += tail
            break
        notes += (", " if i else "") + n
    lapsed = [(a, r) for a, r, expired in members if expired]
    plan.append({"title": title, "count": len(members), "unit": unit, "planned": round(unit * len(members), 2),
                 "notes": notes, "members": members, "lapsed": lapsed})

for p in plan:
    print(f"\n  {p['title']}: {p['count']} x {money(p['unit'])} = {money(p['planned'])}")
    for a, reasons, _ in p["members"]:
        print(f"      {a['hostname'] or ('asset #' + str(a['id']))}  ({'; '.join(reasons)})")
print(f"\n  Total: {money(sum(p['planned'] for p in plan))}")

STAGES = [
    ("Order replacements", "Quotes approved and every replacement ordered."),
    ("Deploy", "New kit built, handed over and the old kit collected."),
    ("Dispose of old kit", "Old kit wiped, certificates of destruction filed, assets retired in FreeITSM."),
]

# ---- 4. The project: find it by name, or create it ---------------------------------------------------
# q= is a "contains" search over name, summary and goal, so we insist on the
# exact name afterwards. Every status is searched, so a project already closed
# with this name is noticed rather than duplicated.
found = [p for p in all_pages("/projects", {"q": args.project_name}) if p["name"] == args.project_name]
project = found[0] if found else None
if project and project["status"] in ("closed", "cancelled"):
    sys.exit(f"Project '{args.project_name}' exists but is {project['status']}. Pass a different --project-name.")

if not args.apply:
    if project:
        print(f"\nWould add what is missing to {project['code']} '{project['name']}'.")
    else:
        print(f"\nWould create project '{args.project_name}' with {len(plan)} budget line(s), "
              f"{sum(1 for p in plan if p['lapsed'])} risk(s)" + (f" and {len(STAGES)} stages." if args.stages else "."))
    print("Preview only - add --apply to build it.")
    sys.exit(0)

if project:
    print(f"\nFound {project['code']} '{project['name']}' ({project['status']}) - adding only what is missing.")
else:
    project = api("POST", "/projects", {
        "name": args.project_name,
        # 'staged' switches on the Budget, RAID and stage gate tabs.
        "methodology": "staged",
        "goal": "Replace end-of-life and out-of-warranty hardware.",
        "summary": f"Planned by the plan-hardware-refresh-project script on {today}: {total} asset(s) bought "
                   f"{args.older_than_years}+ years ago or out of warranty, in {len(plan)} group(s).",
    })
    print(f"\nCreated {project['code']} '{project['name']}' (status: {project['status']}).")
pid = project["id"]

# ---- 5. Budget lines: one per group, only the missing ones --------------------------------------------
# GET /projects/{id}/budget returns the lines (and totals); we match on title.
lines = {l["title"]: l for l in api("GET", f"/projects/{pid}/budget")["lines"]}
added = 0
for p in plan:
    have = lines.get(p["title"])
    if have:
        note = "" if have["planned"] is not None and abs(float(have["planned"]) - p["planned"]) < 0.005 \
            else f"  - today's count gives {money(p['planned'])}; update it by hand if that is right"
        print(f"  budget line already there: {p['title']} (planned {have['planned']}){note}")
        continue
    api("POST", f"/projects/{pid}/budget-lines", {
        "title": p["title"], "category": "hardware", "planned": money(p["planned"]), "notes": p["notes"],
    })
    print(f"  added budget line: {p['title']}  {money(p['planned'])}")
    added += 1

# ---- 6. A risk per group with kit already out of warranty ---------------------------------------------
# Probability and impact are 1-5. 4 x 3: likely that something fails before it
# is replaced, and a failure costs a repair or a person's working day.
risks = {r["title"] for r in api("GET", f"/projects/{pid}/raid?type=risk")}
for p in plan:
    if not p["lapsed"]:
        continue
    title = f"Out of warranty until replaced: {p['title']}"[:255]
    if title in risks:
        print(f"  risk already there: {title}")
        continue
    api("POST", f"/projects/{pid}/raid", {
        "type": "risk", "title": title, "probability": 4, "impact": 3, "response": "reduce",
        "description": f"{len(p['lapsed'])} asset(s) in this group are already out of warranty, so a failure "
                       "before replacement is a paid repair or a person without a device:\n"
                       + "\n".join(f"- {a['hostname'] or ('asset #' + str(a['id']))}: {'; '.join(r)}" for a, r in p["lapsed"]),
        "response_plan": "Replace these first, and keep a spare or two in stock until they are.",
    })
    print(f"  added risk: {title}")

# ---- 7. Stages, if asked for -------------------------------------------------------------------------------
# A new stage starts 'planned'; the project manager starts each one in the app.
if args.stages:
    have_stages = {s["name"] for s in api("GET", f"/projects/{pid}/stages")}
    for name, goal in STAGES:
        if name in have_stages:
            print(f"  stage already there: {name}")
            continue
        api("POST", f"/projects/{pid}/stages", {"name": name, "goal": goal})
        print(f"  added stage: {name}")

print(f"\nDone: {project['code']} - {added} budget line(s) added.")
