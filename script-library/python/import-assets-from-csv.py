# @title       Import assets from a CSV
# @summary     Creates or updates assets from a spreadsheet, matching on serial number (service tag) or hostname, with type, status and location given by name.
# @module      assets
# @changes     yes
# @level       intermediate
# @endpoints   GET /assets, POST /assets, PATCH /assets/{id}, GET /asset-types, GET /asset-statuses,
#              GET /asset-locations
# @permissions assets.read, assets.create, assets.update, reference.read
#
# WHAT IT DOES: for every row of the spreadsheet it finds the asset FreeITSM
# already has - by serial number first, then by hostname - and updates only the
# fields that differ, or creates the asset if there is none. Good for loading a
# supplier's delivery note, a stock-take, or kit the inventory agent cannot see
# (phones, monitors, docks).
#
# WHAT IT EXPECTS: a CSV file with a header row. "hostname" is required (it is
# the asset's name in FreeITSM); every other column is optional, and an EMPTY
# cell means "leave this as it is" - never "clear it".
#
#   hostname,service_tag,asset_tag,manufacturer,model,type,status,location,purchase_date,warranty_expiry
#   LT-0201,5CG1234XYZ,IT-00201,HP,EliteBook 840 G10,Laptop,Active,UK > London,2026-01-15,2029-01-14
#   MON-0042,CN0ABC123,IT-00342,Dell,P2723DE,Monitor,In Storage,,2025-06-01,2028-05-31
#
#   type / status   the NAME as shown in FreeITSM (Asset Management -> Settings),
#                   not an id - the script looks the ids up, so the same file works
#                   on any install. Compared ignoring case.
#   location        a location name, or its full path written with ">" between
#                   the levels when two places share a name ("UK > London > Floor 1")
#   dates           YYYY-MM-DD, the format the API accepts
#   asset_tag       the label stuck on the device. Set on new assets, and changed
#                   on existing ones when the file says something different (the
#                   API returns and changes asset tags since FreeITSM 5.7.0). A tag
#                   another asset in the company already has is refused (409).
#                   Leave the column out if your company has automatic tag numbering on.
#
# WHY MATCH ON SERIAL FIRST: a serial number belongs to one physical machine for
# its whole life, while hostnames get reused (a replacement laptop often takes the
# old one's name). Matching on the serial means a renamed machine is updated, not
# duplicated. Rows without a serial fall back to the hostname.
#
# HOW IT WORKS - two passes, so a mistake in row 90 is caught before row 1 is
# written:
#   1. Read the file, look up every type/status/location name and every existing
#      asset, and work out the plan. Any problem stops the run here.
#   2. Only with --apply: send the creates and updates.
#
# Run it - first WITHOUT --apply to see what it would do (standard library only):
#   python import-assets-from-csv.py --url https://helpdesk.example.com --csv assets.csv
#   python import-assets-from-csv.py --url https://helpdesk.example.com --csv assets.csv --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# It needs the permissions in the @permissions line above. New assets go into the
# key's default company; add "company_id" to the create body to choose another.

import argparse
import csv
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Import assets from a CSV into FreeITSM.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The spreadsheet to import.
parser.add_argument("--csv", default=str(Path(__file__).with_name("assets.csv")))
# Without --apply the script only reads and prints the plan.
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
    """Call the API; return the "data" part, or stop with FreeITSM's own message
    (e.g. 409 conflict "Another asset already uses this hostname")."""
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


def cell(row, column):
    """A CSV cell, trimmed; None when empty or when the column is not in the file."""
    return (row.get(column) or "").strip() or None


def plain_path(path):
    """FreeITSM writes location paths as "UK > London" with a special arrow
    character (U+203A) instead of ">". People type ">", so both sides are turned
    into the same plain form before comparing."""
    return " > ".join(part.strip() for part in path.replace("›", ">").split(">")).lower()


# ---- The columns, and where each one lives in FreeITSM ---------------------------
# First value = the name POST/PATCH /assets takes. Second = how to read the current
# value from GET /assets, whose reply groups fields (hardware, lifecycle...). To
# import another column - order_number, purchase_cost, memory - add a line here.
COLUMNS = {
    "hostname":        ("hostname",        lambda a: a["hostname"]),
    "service_tag":     ("service_tag",     lambda a: a["hardware"]["service_tag"]),
    "manufacturer":    ("manufacturer",    lambda a: a["hardware"]["manufacturer"]),
    "model":           ("model",           lambda a: a["hardware"]["model"]),
    "type":            ("asset_type_id",   lambda a: (a["type"] or {}).get("id")),
    "status":          ("asset_status_id", lambda a: (a["status"] or {}).get("id")),
    "location":        ("location_id",     lambda a: (a["location"] or {}).get("id")),
    "purchase_date":   ("purchase_date",   lambda a: a["lifecycle"]["purchase_date"]),
    "warranty_expiry": ("warranty_expiry", lambda a: a["lifecycle"]["warranty_expiry"]),
}

# ---- Pass 1a: the lookup lists --------------------------------------------------
# Three small reference lists, read once. Each is a list of {id, name, ...}.
# These belong to the key's default company, the same lists the asset screen offers.
types = api("GET", "/asset-types")
statuses = api("GET", "/asset-statuses")
locations = api("GET", "/asset-locations")


def resolve_name(items, name, what, line):
    """Name -> id, ignoring case. Stops with the choices when the name is unknown,
    so a typo ("Laptp") is caught before anything is written."""
    hits = [i for i in items if i["name"].lower() == name.lower()]
    if len(hits) == 1:
        return hits[0]["id"]
    if hits:
        sys.exit(f"Line {line}: more than one {what} is called '{name}'.")
    sys.exit(f"Line {line}: no {what} called '{name}'. Choose one of: {', '.join(i['name'] for i in items)}")


def resolve_location(text, line):
    # A full path is exact; a bare name must belong to only one place.
    hits = [l for l in locations if plain_path(l["path"] or "") == plain_path(text)]
    if not hits:
        hits = [l for l in locations if l["name"].lower() == text.lower()]
    if len(hits) == 1:
        return hits[0]["id"]
    if hits:
        sys.exit(f"Line {line}: several locations are called '{text}' - write the full path, e.g. 'UK > London > {text}'.")
    sys.exit(f"Line {line}: no location '{text}'. Add it under Asset Management -> Settings -> Locations first.")


def find_asset(serial, hostname, line):
    """The asset a row means, or None. retired=include: a retired asset still owns
    its serial and hostname, and creating a second record for it would split its
    history - so we find it and update it instead."""
    if serial:
        hits = api("GET", "/assets?retired=include&service_tag=" + urllib.parse.quote(serial, safe=""))
        if len(hits) > 1:
            ids = ", ".join(str(h["id"]) for h in hits)
            sys.exit(f"Line {line}: {len(hits)} assets already share the serial {serial} (ids {ids}). Tidy those first.")
        if hits:
            return hits[0]
    hits = api("GET", "/assets?retired=include&hostname=" + urllib.parse.quote(hostname, safe=""))
    if len(hits) > 1:
        sys.exit(f"Line {line}: {len(hits)} assets are called {hostname} (in different companies). Use a key limited to one company.")
    if not hits:
        return None
    # Same name, DIFFERENT serial: most likely a replacement machine that took the
    # old one's name. Updating would overwrite the old machine's record, so stop.
    theirs = hits[0]["hardware"]["service_tag"]
    if serial and theirs and theirs != serial:
        sys.exit(f"Line {line}: {hostname} is asset #{hits[0]['id']} with serial {theirs}, but the file says {serial}. "
                 "Rename or retire the old one first.")
    return hits[0]


# ---- Pass 1b: read the file and work out the plan ---------------------------------
# utf-8-sig so the invisible marker Excel puts at the start of "CSV UTF-8" files
# does not end up glued to the first column's name.
csv_path = Path(args.csv)
if not csv_path.exists():
    sys.exit(f"CSV file not found: {csv_path}")
with csv_path.open(newline="", encoding="utf-8-sig") as f:
    rows = list(csv.DictReader(f))
mode = "APPLYING" if args.apply else "PREVIEW - nothing will be written; add --apply to import"
print(f"{len(rows)} rows in {csv_path} -> {BASE}   [{mode}]")

plan = []
for line, row in enumerate(rows, start=2):   # the header is line 1
    hostname = cell(row, "hostname")
    if not hostname:
        sys.exit(f"Line {line}: hostname is empty. Every asset needs a name.")

    # What the row asks for, already turned into ids where FreeITSM wants ids.
    # label keeps the text as typed, so the plan can say "status -> Active"
    # rather than "status -> 13".
    want, label = {}, {}
    for column in COLUMNS:
        value = cell(row, column)
        if value is None:
            continue
        label[column] = value
        if column == "type":
            value = resolve_name(types, value, "asset type", line)
        elif column == "status":
            value = resolve_name(statuses, value, "asset status", line)
        elif column == "location":
            value = resolve_location(value, line)
        elif column in ("purchase_date", "warranty_expiry") and not re.match(r"^\d{4}-\d{2}-\d{2}$", value):
            sys.exit(f"Line {line}: {column} '{value}' must be YYYY-MM-DD.")
        want[column] = value

    asset = find_asset(cell(row, "service_tag"), hostname, line)
    if asset is None:
        body = {COLUMNS[c][0]: v for c, v in want.items()}
        if cell(row, "asset_tag"):
            body["asset_tag"] = cell(row, "asset_tag")
        plan.append({"action": "create", "name": hostname, "body": body})
        continue

    # Compare each wanted value with what FreeITSM has; keep only the differences.
    # str() on both sides so an id 4 and "4" compare equal, and None becomes "None"
    # which never equals a real value.
    patch, changes = {}, []
    for column, wanted in want.items():
        field, have = COLUMNS[column][0], COLUMNS[column][1](asset)
        if str(have) != str(wanted):
            patch[field] = wanted
            changes.append(f"{column} -> '{label[column]}'")
    # The tag is not in COLUMNS because it is not looked up or validated here:
    # FreeITSM checks it is free in the asset's company. Sent only when it differs.
    tag = cell(row, "asset_tag")
    if tag and str(asset.get("asset_tag") or "") != tag:
        patch["asset_tag"] = tag
        changes.append(f"asset_tag -> '{tag}'")
    plan.append({"action": "update" if patch else "unchanged", "name": hostname,
                 "id": asset["id"], "body": patch, "changes": changes})

# ---- Pass 2: show the plan, and carry it out with --apply ---------------------------
count = {"create": 0, "update": 0, "unchanged": 0}
for p in plan:
    count[p["action"]] += 1
    if p["action"] == "unchanged":
        print(f"  unchanged     {p['name']} (#{p['id']})")
    elif p["action"] == "create":
        if not args.apply:
            print(f"  would create  {p['name']}")
            continue
        new = api("POST", "/assets", p["body"])
        print(f"  created       {p['name']} -> asset #{new['id']}")
        # FreeITSM names any field it did not recognise rather than silently
        # dropping it - usually a typo in a column you added to COLUMNS.
        if new.get("ignored"):
            print(f"                ignored by FreeITSM: {', '.join(new['ignored'])}")
    else:
        if not args.apply:
            print(f"  would update  {p['name']} (#{p['id']}): {'; '.join(p['changes'])}")
            continue
        # PATCH changes only the fields sent, and FreeITSM writes one history line
        # per changed field - visible on the asset's History tab.
        updated = api("PATCH", f"/assets/{p['id']}", p["body"])
        print(f"  updated       {p['name']} (#{p['id']}): {'; '.join(p['changes'])}")
        if updated.get("ignored"):
            print(f"                ignored by FreeITSM: {', '.join(updated['ignored'])}")

verb = "" if args.apply else "would be "
print(f"\nDone: {count['create']} {verb}created, {count['update']} {verb}updated, {count['unchanged']} unchanged.")
