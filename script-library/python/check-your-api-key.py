# @title       Check your API key
# @summary     Connects to FreeITSM, proves the key works, and lists exactly what it is allowed to do.
# @module      general
# @changes     no
# @level       starter
# @endpoints   GET /ping
# @permissions none
#
# START HERE. Run this first with any new key: if it works, every other script in
# the library will be able to reach your FreeITSM, and you will see which
# permissions the key has - so you know which scripts it can run.
#
# Run it (Python 3.8 or later, nothing to install - standard library only):
#   python check-your-api-key.py --url https://helpdesk.example.com
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
import urllib.request
from pathlib import Path

# Names and titles can hold any character - an emoji, Chinese. When the output is
# redirected to a file on Windows, Python writes in the old code page and would
# crash on those; errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Check a FreeITSM API key.")
# Your FreeITSM address, without /api/v1 - e.g. https://helpdesk.example.com or
# http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
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

# Every REST call lives under /api/v1 on your server.
BASE = args.url.rstrip("/") + "/api/v1"


# ---- One helper for every call --------------------------------------------------
def api(method, path):
    """Call the API and return the "data" part of the reply.

    Every FreeITSM answer looks like {"data": ...} on success and
    {"error": {"code": "...", "message": "..."}} on failure. On failure we stop
    and print FreeITSM's own explanation, which is usually all you need to fix it.
    """
    request = urllib.request.Request(
        BASE + path, method=method,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read().decode("utf-8"))["data"]
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


# ---- Ask FreeITSM who we are ----------------------------------------------------
# /ping needs no permission at all: any valid key may call it. It answers with the
# key's name, the analyst it acts as, its permissions, its companies and expiry.
ping = api("GET", "/ping")
key = ping["key"]

print(f"Connected to {BASE}")
print(f"Key name    : {key['name']}")
print(f"Acts as     : {key['acts_as']}   (everything the key does is recorded as this analyst)")
print(f"Expires     : {key.get('expires_at') or 'never'}")
print(f"Server time : {ping['server_time']}   (UTC)")

# Companies: an empty list means the key is not limited to particular companies.
companies = key.get("companies") or []
print("Companies   : " + (", ".join(c["name"] for c in companies) if companies else "not limited"))

# Permissions come back as {"tickets": ["read", "create"], "assets": ["read"], ...}.
# Each script in the library lists the ones it needs in its @permissions line.
print("\nPermissions:")
permissions = key.get("permissions") or {}
if not permissions:
    print("  (none - this key can only call /ping)")
for resource in sorted(permissions):
    print(f"  {resource:<28} {', '.join(permissions[resource])}")
