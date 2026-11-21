# @title       Send this PC's inventory
# @summary     Reads the computer it runs on - operating system, CPU, memory, disks, network adapters and installed software - finds its asset by serial number or hostname, and sends the lot to FreeITSM.
# @module      assets
# @changes     yes
# @level       intermediate
# @endpoints   GET /assets, GET /assets/{id}, PATCH /assets/{id}, POST /assets/{id}/inventory, GET /assets/{id}/disks
# @permissions assets.read, assets.update, asset_inventory.create, asset_inventory.read
#
# WHY: the FreeITSM inventory agent is the right tool for a fleet. This is for
# the odd machine it does not cover - a server you would rather not install an
# agent on, a lab PC, a one-off check - and a worked example of the REST
# inventory endpoint for anyone writing their own discovery tool.
#
# WHAT IT SENDS, AND WHERE:
#   1. The asset's own fields - manufacturer, model, serial, memory, CPU, BIOS,
#      operating system, build, domain - with PATCH /assets/{id}. Only fields
#      whose value differs are sent, and FreeITSM writes one history line per
#      field it changes. An empty value here never clears a field there.
#   2. The lists - disks, network adapters, installed software - with
#      POST /assets/{id}/inventory. That endpoint does NOT take the fields in 1;
#      it only takes these lists (and "connections").
#
# HOW THE INVENTORY ENDPOINT WORKS (wiki REST-API-Assets):
#   - "source" names YOUR tool (here "this-pc-script"). Rows it sends are stored
#     tagged "rest:this-pc-script", beside - never on top of - what the agent or
#     another tool reported, and the asset page shows where each row came from.
#     The first key that writes a source name owns it in that company: another
#     integration's key gets a 403 if it tries to send the same name.
#   - "source_device_ref" is this device's identity in your tool. It is linked to
#     ONE asset: sending the same ref against another asset is a 409 naming the
#     asset it is linked to. This script uses the BIOS serial number, which
#     survives a rename and a rebuild, or the hostname when the serial is blank
#     or a placeholder like "To be filled by O.E.M.".
#   - "observed_at" is when the facts were read. A report older than the last one
#     applied is ignored as stale, so a slow, late run never overwrites a newer one.
#   - Each list says "complete": true here, meaning "this is the whole list": rows
#     this source sent before that are missing now are REMOVED - software you
#     uninstalled drops off. A list the script could not read is left out of the
#     report altogether, which leaves the last good copy alone.
#   - The answer is per list: applied (with added/updated/removed counts),
#     replayed, stale or failed. One list failing does not stop the others (HTTP 207).
#     "updated" counts every row seen again - its observed time is refreshed -
#     not only rows whose values changed, so a second run shows all "updated".
#   - Software new to FreeITSM is added to the Software catalogue, as the agent's is.
#
# WHICH ASSET: the one whose serial number (service tag) matches this machine's
# BIOS serial; failing that, the one with this computer's name as its hostname.
# Retired assets count - a retired machine still owns its serial. If none
# matches, the script stops: create the asset first (Asset Management, or the
# "Import assets from a CSV" script), or name it with --asset-id.
#
# WHERE IT RUNS - standard library only, so how much it can read depends on the
# operating system:
#   Windows  everything. Python's standard library cannot read WMI, so ONE
#            PowerShell command (built into Windows) is run to read the same CIM
#            classes and registry keys the FreeITSM agent reads, and hands them
#            back as JSON. Nothing is installed and nothing is changed.
#   Linux    OS, CPU, memory, mounted disks, network adapters; software from
#            dpkg or rpm when present. Make/model/serial come from
#            /sys/class/dmi/id - the serial is readable only by root.
#   Others   OS, CPU and the root disk only.
# A list it cannot read is left out of the report, never sent empty.
#
# Run it on the machine itself - first WITHOUT --apply to see exactly what it would send:
#   python send-this-pc-inventory.py --url https://helpdesk.example.com
#   python send-this-pc-inventory.py --url https://helpdesk.example.com --apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass --key-file.
# It needs the permissions in the @permissions line above.

import argparse
import base64
import datetime
import json
import os
import platform
import re
import shutil
import socket
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

# Software names can hold any character. When the output is redirected to a
# file on Windows, Python writes in the old code page and would crash on those;
# errors="replace" prints a ? instead and carries on.
sys.stdout.reconfigure(errors="replace")

# ---- Settings from the command line ---------------------------------------------
parser = argparse.ArgumentParser(description="Send this computer's inventory to its FreeITSM asset.")
# Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
parser.add_argument("--url", default=os.environ.get("FREEITSM_URL"))
parser.add_argument("--key-file", default=str(Path(__file__).with_name("api-key.txt")))
# The asset to report on, when matching by serial or hostname is not right.
parser.add_argument("--asset-id", type=int)
# Your tool's name in FreeITSM: lowercase letters, digits, dots, underscores,
# hyphens. "agent", "intune", "api" and a few others are reserved.
parser.add_argument("--source", default="this-pc-script")
# Without --apply the script reads this machine and prints what it would send.
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
    """Call the API; return the "data" part, or stop with FreeITSM's own message -
    for example 409 "This device is already linked to asset 12". A 207 (some lists
    failed) is a success here; the per-list results are checked by the caller."""
    data = json.dumps(body).encode("utf-8") if body is not None else None
    headers = {"Authorization": f"Bearer {api_key}", "Accept": "application/json"}
    if data is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            return json.loads(response.read().decode("utf-8"))["data"]
    except urllib.error.HTTPError as e:
        sys.exit(f"{method} {path} failed: HTTP {e.code} {e.read().decode('utf-8', 'replace')}")
    except urllib.error.URLError as e:
        sys.exit(f"Could not reach {BASE}: {e.reason}")


def text(value, size):
    """A trimmed string cut to FreeITSM's column size, or None when empty. Asset
    fields that are too long are refused rather than cut, so cut them here."""
    if value is None:
        return None
    value = str(value).strip()
    return value[:size] if value else None


def run(command):
    """Output of a local command, or None if it is missing or fails."""
    try:
        return subprocess.run(command, capture_output=True, check=True, timeout=120).stdout.decode("utf-8", "replace")
    except (OSError, subprocess.SubprocessError):
        return None


def read(path):
    try:
        return Path(path).read_text(encoding="utf-8", errors="replace").strip()
    except OSError:
        return None


# ---- 1. Read this machine ----------------------------------------------------------
# facts  = asset fields, named exactly as PATCH /assets takes them; memory in
#          bytes and speed in Hz - the units the agent uses.
# lists  = disks / network_adapters / software item lists in the shape
#          POST /assets/{id}/inventory takes. A list missing here is left out.
observed_at = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")  # RFC 3339, whole seconds
hostname = socket.gethostname().split(".")[0]
facts, lists = {}, {}

# Read on Windows by PowerShell: the same CIM classes and registry keys as the agent.
# Each list is read on its own (try/catch), so one that fails is left out alone.
WINDOWS_READER = r"""
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$out = @{}
$cs = Get-CimInstance Win32_ComputerSystem; $os = Get-CimInstance Win32_OperatingSystem
$bios = Get-CimInstance Win32_BIOS; $cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$nt = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue
$out.system = @{ name = $env:COMPUTERNAME; manufacturer = $cs.Manufacturer; model = $cs.Model; memory = [long]$cs.TotalPhysicalMemory
    domain = $cs.Domain; serial = $bios.SerialNumber; bios = $bios.SMBIOSBIOSVersion; cpu = $cpu.Name; mhz = [long]$cpu.MaxClockSpeed
    os = $os.Caption; build = "$($os.BuildNumber)"; ubr = $(if ($nt) { $nt.UBR }); release = $(if ($nt) { $nt.DisplayVersion }) }
try { $out.disks = @(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object {
    @{ drive = $_.DeviceID; label = $_.VolumeName; fs = $_.FileSystem; size = [long]$_.Size; free = [long]$_.FreeSpace } }) } catch {}
try { $out.adapters = @(Get-CimInstance Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True' | ForEach-Object {
    @{ name = $_.Description; mac = $_.MACAddress; ips = @($_.IPAddress); masks = @($_.IPSubnet)
       gateways = @($_.DefaultIPGateway | Where-Object { $_ }); dhcp = [bool]$_.DHCPEnabled } }) } catch {}
try { $out.software = @(Get-ItemProperty -ErrorAction SilentlyContinue -Path `
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*' |
    Where-Object { $_.DisplayName } | ForEach-Object {
    @{ name = "$($_.DisplayName)"; publisher = "$($_.Publisher)"; version = "$($_.DisplayVersion)"; date = "$($_.InstallDate)"
       hidden = [bool](($_.SystemComponent -eq 1) -or $_.ParentKeyName) } }) } catch {}
$out | ConvertTo-Json -Depth 5 -Compress
"""


def first_ipv4(values):
    for i, v in enumerate(values or []):
        if re.fullmatch(r"\d+\.\d+\.\d+\.\d+", v or ""):
            return i, v
    return None, None


def read_windows():
    # -EncodedCommand takes the script as base64 UTF-16, which sidesteps every
    # quoting rule between Python and PowerShell.
    encoded = base64.b64encode(WINDOWS_READER.encode("utf-16-le")).decode("ascii")
    raw = run(["powershell", "-NoProfile", "-NonInteractive", "-EncodedCommand", encoded])
    if not raw:
        sys.exit("Could not read this PC through PowerShell.")
    w = json.loads(raw)
    s = w["system"]
    facts.update({
        "name": s["name"], "manufacturer": s["manufacturer"], "model": s["model"], "serial": s["serial"],
        "memory": s["memory"], "cpu_name": s["cpu"], "speed": (s["mhz"] or 0) * 1000000, "bios_version": s["bios"],
        # "Microsoft Windows 11 Pro" -> "Windows 11 Pro", as the agent writes it.
        "operating_system": re.sub(r"^Microsoft ", "", s["os"] or ""), "feature_release": s["release"],
        # Build "26100.4061": the base build plus the update revision, as Settings shows it.
        "build_number": f"{s['build']}.{s['ubr']}" if s.get("ubr") else s["build"], "domain": s["domain"],
    })
    if "disks" in w:
        lists["disks"] = [{"external_id": d["drive"], "drive": d["drive"], "label": d["label"], "file_system": d["fs"],
                           "size_bytes": d["size"], "free_bytes": d["free"]} for d in w["disks"]]
    if "adapters" in w:
        lists["network_adapters"] = []
        for a in w["adapters"]:
            i, ip = first_ipv4(a["ips"])
            _, gateway = first_ipv4(a["gateways"])
            lists["network_adapters"].append({
                "external_id": (a["mac"] or a["name"] or "").upper(), "name": a["name"], "mac_address": a["mac"],
                "ip_address": ip, "subnet_mask": a["masks"][i] if ip and i < len(a["masks"]) else None,
                "gateway": gateway, "dhcp_enabled": a["dhcp"]})
    if "software" in w:
        lists["software"] = [{"display_name": p["name"], "publisher": p["publisher"], "display_version": p["version"],
                              "install_date": p["date"], "system_component": p["hidden"]} for p in w["software"]]


def read_linux():
    dmi = "/sys/class/dmi/id/"
    os_release = dict(re.findall(r'^(\w+)="?([^"\n]*)"?$', read("/etc/os-release") or "", re.M))
    cpu = re.search(r"^model name\s*:\s*(.+)$", read("/proc/cpuinfo") or "", re.M)
    mem = re.search(r"^MemTotal:\s*(\d+) kB", read("/proc/meminfo") or "", re.M)
    facts.update({
        "name": hostname, "manufacturer": read(dmi + "sys_vendor"), "model": read(dmi + "product_name"),
        "serial": read(dmi + "product_serial"), "bios_version": read(dmi + "bios_version"),
        "memory": int(mem.group(1)) * 1024 if mem else None, "cpu_name": cpu.group(1) if cpu else platform.processor(),
        "operating_system": os_release.get("PRETTY_NAME") or f"Linux {platform.release()}",
        "build_number": platform.release(),
    })
    # Disks: real filesystems from /proc/mounts. The mount point is the identity;
    # "drive" holds 10 characters, so the device name goes there.
    real = {"ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "vfat", "exfat", "ntfs", "ntfs3", "f2fs", "reiserfs", "jfs"}
    # An unreadable /proc/mounts means "could not read", not "no disks": the list
    # is then left out, because an empty COMPLETE list would delete the last good one.
    mounts = read("/proc/mounts")
    disks, seen = [], set()
    for line in (mounts or "").splitlines():
        device, mount, fs = (line.split() + ["", "", ""])[:3]
        mount = mount.replace("\\040", " ")
        if fs not in real or mount in seen or len(mount) > 128:
            continue
        seen.add(mount)
        try:
            usage = shutil.disk_usage(mount)
        except OSError:
            continue
        disks.append({"external_id": mount, "drive": os.path.basename(device)[:10], "label": mount,
                      "file_system": fs, "size_bytes": usage.total, "free_bytes": usage.free})
    if mounts:
        lists["disks"] = disks
    # Network adapters: every interface with a MAC in /sys/class/net, its first
    # IPv4 address from "ip -o -4 addr", and the default gateway from the kernel's
    # routing table (hex, least significant byte first).
    ips = {}
    for m in re.finditer(r"^\d+:\s+(\S+)\s+inet\s+([\d.]+)/(\d+)", run(["ip", "-o", "-4", "addr", "show"]) or "", re.M):
        bits = (0xFFFFFFFF << (32 - int(m.group(3)))) & 0xFFFFFFFF
        ips.setdefault(m.group(1), (m.group(2), socket.inet_ntoa(bits.to_bytes(4, "big"))))
    gateways = {}
    for line in (read("/proc/net/route") or "").splitlines()[1:]:
        f = line.split()
        if len(f) > 2 and f[1] == "00000000":
            gateways[f[0]] = socket.inet_ntoa(bytes.fromhex(f[2])[::-1])
    if os.path.isdir("/sys/class/net"):
        adapters = []
        for nic in sorted(os.listdir("/sys/class/net")):
            mac = (read(f"/sys/class/net/{nic}/address") or "").upper()
            if nic == "lo" or not mac or mac == "00:00:00:00:00:00":
                continue
            ip, mask = ips.get(nic, (None, None))
            adapters.append({"external_id": mac, "name": nic, "mac_address": mac, "ip_address": ip,
                             "subnet_mask": mask, "gateway": gateways.get(nic)})
        lists["network_adapters"] = adapters
    # Software: Debian/Ubuntu packages, else Red Hat family. Neither: left out.
    out = run(["dpkg-query", "-W", "-f", "${Package}\t${Version}\t${Maintainer}\n"]) if shutil.which("dpkg-query") else None
    if out is None and shutil.which("rpm"):
        out = run(["rpm", "-qa", "--qf", "%{NAME}\t%{VERSION}-%{RELEASE}\t%{VENDOR}\n"])
    if out is not None:
        lists["software"] = [{"display_name": f[0], "display_version": f[1], "publisher": f[2] if len(f) > 2 else None}
                             for f in (line.split("\t") for line in out.splitlines()) if f[0]]


def read_other():
    facts.update({"name": hostname, "operating_system": f"{platform.system()} {platform.release()}",
                  "cpu_name": platform.processor(), "build_number": platform.version()})
    usage = shutil.disk_usage("/")
    lists["disks"] = [{"external_id": "/", "drive": "/", "label": "/", "size_bytes": usage.total, "free_bytes": usage.free}]


system = platform.system()
if system == "Windows":
    read_windows()
elif system == "Linux":
    read_linux()
else:
    read_other()
hostname = facts.pop("name") or hostname

# A placeholder serial would match every unbranded PC in the building, so it is
# treated as no serial at all.
serial = text(facts.pop("serial", None), 50)
if serial and serial.lower() in ("to be filled by o.e.m.", "default string", "system serial number",
                                 "not applicable", "none", "0", "n/a", "0123456789"):
    serial = None
facts["service_tag"] = serial

# Column sizes, so a long model name is cut rather than refused.
SIZES = {"manufacturer": 50, "model": 50, "service_tag": 50, "cpu_name": 250, "bios_version": 20,
         "operating_system": 50, "feature_release": 10, "build_number": 50, "domain": 100}
for field, size in SIZES.items():
    facts[field] = text(facts.get(field), size)


def tidy(name, items):
    """Make one list acceptable to FreeITSM: strings cut to their columns, empty
    strings as None, and no two items with the same identity - FreeITSM refuses
    a list that names one row twice (software: name + publisher; others: external_id)."""
    sizes = {"display_name": 512, "publisher": 512, "display_version": 100, "install_date": 50, "external_id": 128,
             "drive": 10, "label": 100, "file_system": 20, "name": 255, "mac_address": 17}
    out, seen = [], set()
    for item in items:
        item = {k: (text(v, sizes[k]) if k in sizes else v) for k, v in item.items()}
        item = {k: v for k, v in item.items() if v is not None}
        key = (item.get("display_name", "").lower(), (item.get("publisher") or "").lower()) if name == "software" \
            else item.get("external_id")
        if not key or key in seen or (name == "software" and not item.get("display_name")):
            continue
        seen.add(key)
        out.append(item)
    return out


sections = {name: {"complete": True, "items": tidy(name, items)} for name, items in lists.items()}
if not sections:
    sys.exit("None of the lists could be read, so there is nothing to send.")

# ---- 2. Find the asset --------------------------------------------------------------
if args.asset_id:
    asset = api("GET", f"/assets/{args.asset_id}")
else:
    asset = None
    if serial:
        hits = api("GET", "/assets?retired=include&service_tag=" + urllib.parse.quote(serial, safe=""))
        if len(hits) > 1:
            sys.exit(f"{len(hits)} assets share the serial {serial} (ids {', '.join(str(h['id']) for h in hits)}). "
                     "Tidy those, or pass --asset-id.")
        asset = hits[0] if hits else None
    if asset is None:
        hits = api("GET", "/assets?retired=include&hostname=" + urllib.parse.quote(hostname, safe=""))
        if len(hits) > 1:
            sys.exit(f"{len(hits)} assets are called {hostname}. Pass --asset-id.")
        if hits:
            # Same name, DIFFERENT serial: most likely a new PC that took an old
            # one's name. Reporting onto it would overwrite the old machine's record.
            theirs = hits[0]["hardware"]["service_tag"]
            if serial and theirs and theirs != serial:
                sys.exit(f"{hostname} is asset #{hits[0]['id']} with serial {theirs}, but this machine's serial is {serial}. "
                         "Pass --asset-id if it really is this machine.")
            asset = hits[0]
    if asset is None:
        sys.exit(f"No asset has serial '{serial}' or hostname '{hostname}'. Create it first, or pass --asset-id.")

# ---- 3. Work out what to send ----------------------------------------------------------
# GET /assets groups the fields (hardware, os, network); PATCH takes them flat.
have = {**asset["hardware"], **asset["os"], **asset["network"]}
patch = {f: v for f, v in facts.items()
         if v not in (None, "", 0) and str(have.get(f)) != str(v)}   # unknown here: leave FreeITSM's value alone
device_ref = serial or hostname.lower()
report = {"schema_version": 1, "source": args.source, "source_device_ref": device_ref,
          "observed_at": observed_at, "sections": sections}

mode = "APPLYING" if args.apply else "PREVIEW - nothing will be sent; add --apply to send it"
print(f"{hostname} -> asset #{asset['id']} {asset['hostname']} at {BASE}   [{mode}]")

if not args.apply:
    # The exact bodies that --apply would send, the lists one item per line.
    print(f"\nPATCH /assets/{asset['id']}")
    print(json.dumps(patch, indent=2) if patch else "  (nothing - every field already matches)")
    print(f"\nPOST /assets/{asset['id']}/inventory")
    print(f"  schema_version 1, source {args.source}, source_device_ref {device_ref}, observed_at {observed_at}")
    for name, section in sections.items():
        print(f"  sections.{name} (complete: the whole list)")
        for item in section["items"]:
            print("    " + json.dumps(item, ensure_ascii=False))

# A short summary, last, so it is on screen after a long payload.
print(f"\nAsset fields that differ: {', '.join(patch) or 'none'}")
for name, section in sections.items():
    print(f"  {name:<17} {len(section['items'])} item(s)")
print(f"  source {args.source}, device {device_ref}, observed {observed_at}")
if not args.apply:
    sys.exit(0)

# ---- 4. Send it -----------------------------------------------------------------------------
if patch:
    updated = api("PATCH", f"/assets/{asset['id']}", patch)
    print(f"Updated asset fields: {', '.join(patch)}")
    # FreeITSM names any field it did not recognise rather than silently dropping it.
    if updated.get("ignored"):
        print(f"  ignored by FreeITSM: {', '.join(updated['ignored'])}")

# 200 = every list applied (or replayed / stale); 207 = some failed, and each
# failed one says why. Sending the same report again retries only those.
result = api("POST", f"/assets/{asset['id']}/inventory", report)
for name, r in result["sections"].items():
    if r["result"] == "applied":
        print(f"  {name:<17} applied: {r['added']} added, {r['updated']} updated, {r['removed']} removed")
    elif r["result"] == "failed":
        print(f"  {name:<17} FAILED: {r['error']['message']}")
    elif r["result"] == "stale":
        print(f"  {name:<17} stale - a newer report (observed {r['newer_observed_at']}) is already applied")
    else:
        print(f"  {name:<17} {r['result']}")
if result["asset_id"] != asset["id"]:
    print(f"Note: asset #{asset['id']} was merged; the report went to #{result['asset_id']}.")

# ---- 5. Read it back --------------------------------------------------------------------------
# GET /assets/{id}/disks lists every source's volumes; ours are tagged rest:<source>.
print("\nVolumes FreeITSM now holds from this script:")
for d in api("GET", f"/assets/{result['asset_id']}/disks"):
    if d["source"] == f"rest:{args.source}":
        size = f"{d['size_bytes'] / 1024 ** 3:.1f}" if d["size_bytes"] else "?"
        print(f"  {d['drive']:<4} {d['label'] or '':<16} {size} GB, {d['used_percent']}% used")
