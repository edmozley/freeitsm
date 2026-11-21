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
# "Import assets from a CSV" script), or name it with -AssetId.
#
# Run it on the PC itself - first WITHOUT -Apply to see exactly what it would send:
#   powershell -ExecutionPolicy Bypass -File .\send-this-pc-inventory.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\send-this-pc-inventory.ps1 -Url https://helpdesk.example.com -Apply
# Windows only: it reads CIM (WMI) and the registry. Use the Python version elsewhere.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# It needs the permissions in the @permissions line above.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The asset to report on, when matching by serial or hostname is not right.
    [int]$AssetId = 0,

    # Your tool's name in FreeITSM: lowercase letters, digits, dots, underscores,
    # hyphens. "agent", "intune", "api" and a few others are reserved.
    [string]$Source = 'this-pc-script',

    # Without -Apply the script reads this PC and prints what it would send.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content: Get-Content tags the text with hidden
    # properties that make ConvertTo-Json crawl in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the "data" part of the reply, or stops with FreeITSM's own message -
# for example 409 "This device is already linked to asset 12".
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # UTF-8 bytes so accented software names survive Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# A trimmed string cut to FreeITSM's column size, or $null when empty. Asset
# fields that are too long are refused rather than cut, so cut them here.
function Get-Text($Value, [int]$Max) {
    if ($null -eq $Value) { return $null }
    $v = ([string]$Value).Trim()
    if ($v -eq '') { return $null }
    if ($v.Length -gt $Max) { $v = $v.Substring(0, $Max) }
    return $v
}

# ---- 1. Read this PC -------------------------------------------------------------
# The same CIM classes and registry keys the FreeITSM inventory agent reads, so
# the values look the same on the asset page whichever one sent them.
$observedAt = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss'Z'")   # RFC 3339, UTC, whole seconds
$cs   = Get-CimInstance Win32_ComputerSystem
$os   = Get-CimInstance Win32_OperatingSystem
$bios = Get-CimInstance Win32_BIOS
$cpu  = Get-CimInstance Win32_Processor | Select-Object -First 1
$nt   = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue
$hostname = $env:COMPUTERNAME

# A placeholder serial would match every unbranded PC in the building, so it is
# treated as no serial at all.
$serial = Get-Text $bios.SerialNumber 50
$placeholders = @('to be filled by o.e.m.', 'default string', 'system serial number', 'not applicable', 'none', '0', 'n/a', '0123456789')
if ($serial -and $placeholders -contains $serial.ToLower()) { $serial = $null }

# Build "26100.4061": the base build plus the update revision, as Settings shows it.
$build = "$($os.BuildNumber)"
if ($nt -and $nt.UBR) { $build = "$($os.BuildNumber).$($nt.UBR)" }

# Field names exactly as PATCH /assets takes them; sizes are FreeITSM's columns.
# memory is in bytes and speed in Hz - the units the agent uses.
$facts = [ordered]@{
    manufacturer     = Get-Text $cs.Manufacturer 50
    model            = Get-Text $cs.Model 50
    service_tag      = $serial
    memory           = [long]$cs.TotalPhysicalMemory
    cpu_name         = Get-Text $cpu.Name 250
    speed            = [long]$cpu.MaxClockSpeed * 1000000
    bios_version     = Get-Text $bios.SMBIOSBIOSVersion 20
    operating_system = Get-Text ($os.Caption -replace '^Microsoft ', '') 50
    feature_release  = $(if ($nt) { Get-Text $nt.DisplayVersion 10 } else { $null })
    build_number     = Get-Text $build 50
    domain           = Get-Text $cs.Domain 100
}

# Each list is read on its own, so one that fails is simply left out of the
# report (FreeITSM keeps the last good copy) instead of stopping the whole run.
$sections = [ordered]@{}

# Disks: local fixed volumes (DriveType 3). The drive letter is the row's
# identity (external_id), so C: stays the same row from one report to the next.
try {
    $disks = @(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' -ErrorAction Stop | ForEach-Object {
        [ordered]@{
            external_id = $_.DeviceID
            drive       = $_.DeviceID
            label       = Get-Text $_.VolumeName 100
            file_system = Get-Text $_.FileSystem 20
            size_bytes  = [long]$_.Size
            free_bytes  = [long]$_.FreeSpace
        }
    })
    $sections.disks = [ordered]@{ complete = $true; items = $disks }
} catch { Write-Host "Disks not read: $($_.Exception.Message) - left out of the report." -ForegroundColor DarkYellow }

# Network adapters with an IP address. Identity = the MAC address. FreeITSM holds
# one IPv4 address per adapter, so the first IPv4 address and its mask are sent.
try {
    $seen = @{}
    $adapters = @()
    foreach ($a in @(Get-CimInstance Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True' -ErrorAction Stop)) {
        $id = if ($a.MACAddress) { $a.MACAddress.ToUpper() } else { Get-Text $a.Description 120 }
        if (-not $id -or $seen.ContainsKey($id)) { continue }   # two rows with one identity are refused
        $seen[$id] = $true
        $ips = @($a.IPAddress); $masks = @($a.IPSubnet)
        $ip = $null; $mask = $null
        for ($i = 0; $i -lt $ips.Count; $i++) {
            if ($ips[$i] -match '^\d+\.\d+\.\d+\.\d+$') { $ip = $ips[$i]; if ($i -lt $masks.Count) { $mask = $masks[$i] }; break }
        }
        $gateway = @($a.DefaultIPGateway | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' }) | Select-Object -First 1
        $adapters += [ordered]@{
            external_id  = $id
            name         = Get-Text $a.Description 255
            mac_address  = $a.MACAddress
            ip_address   = $ip
            subnet_mask  = $mask
            gateway      = $gateway
            dhcp_enabled = [bool]$a.DHCPEnabled
        }
    }
    $sections.network_adapters = [ordered]@{ complete = $true; items = $adapters }
} catch { Write-Host "Network adapters not read: $($_.Exception.Message) - left out of the report." -ForegroundColor DarkYellow }

# Installed software: Programs and Features, from the registry (64-bit and 32-bit
# views - the same keys the agent reads; per-user installs are not included).
# FreeITSM knows a program by name + publisher and refuses a list that names the
# same pair twice, so duplicates (the 32 and 64-bit entries of one program) are
# dropped here. Hidden entries - system components and updates that belong to
# another program - are sent flagged, as the agent does.
try {
    $seen = @{}
    $software = @()
    $keys = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*',
            'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*'
    foreach ($p in @(Get-ItemProperty -Path $keys -ErrorAction SilentlyContinue)) {
        $name = Get-Text $p.DisplayName 512
        if (-not $name) { continue }
        $publisher = Get-Text $p.Publisher 512
        $key = $name.ToLower() + '|' + ([string]$publisher).ToLower()
        if ($seen.ContainsKey($key)) { continue }
        $seen[$key] = $true
        $software += [ordered]@{
            display_name     = $name
            publisher        = $publisher
            display_version  = Get-Text $p.DisplayVersion 100
            install_date     = Get-Text $p.InstallDate 50
            system_component = [bool](($p.SystemComponent -eq 1) -or $p.ParentKeyName)
        }
    }
    $sections.software = [ordered]@{ complete = $true; items = $software }
} catch { Write-Host "Software not read: $($_.Exception.Message) - left out of the report." -ForegroundColor DarkYellow }

if (-not $sections.Count) { throw 'None of the lists could be read, so there is nothing to send.' }

# ---- 2. Find the asset ----------------------------------------------------------------
if ($AssetId) {
    $asset = Invoke-FreeItsm 'GET' "/assets/$AssetId"
} else {
    $asset = $null
    if ($serial) {
        $hits = @(Invoke-FreeItsm 'GET' ('/assets?retired=include&service_tag=' + [uri]::EscapeDataString($serial)))
        if ($hits.Count -gt 1) { throw "$($hits.Count) assets share the serial $serial (ids $(($hits | ForEach-Object { $_.id }) -join ', ')). Tidy those, or pass -AssetId." }
        if ($hits.Count -eq 1) { $asset = $hits[0] }
    }
    if (-not $asset) {
        $hits = @(Invoke-FreeItsm 'GET' ('/assets?retired=include&hostname=' + [uri]::EscapeDataString($hostname)))
        if ($hits.Count -gt 1) { throw "$($hits.Count) assets are called $hostname. Pass -AssetId." }
        if ($hits.Count -eq 1) {
            # Same name, DIFFERENT serial: most likely a new PC that took an old
            # one's name. Reporting onto it would overwrite the old machine's record.
            $theirs = $hits[0].hardware.service_tag
            if ($serial -and $theirs -and $theirs -ne $serial) {
                throw "$hostname is asset #$($hits[0].id) with serial $theirs, but this PC's serial is $serial. Pass -AssetId if it really is this machine."
            }
            $asset = $hits[0]
        }
    }
    if (-not $asset) { throw "No asset has serial '$serial' or hostname '$hostname'. Create it first, or pass -AssetId." }
}

# ---- 3. Work out what to send -------------------------------------------------------------
# GET /assets groups the fields (hardware, os, network); PATCH takes them flat.
$have = @{
    manufacturer = $asset.hardware.manufacturer; model = $asset.hardware.model; service_tag = $asset.hardware.service_tag
    memory = $asset.hardware.memory; cpu_name = $asset.hardware.cpu_name; speed = $asset.hardware.speed
    bios_version = $asset.hardware.bios_version; operating_system = $asset.os.operating_system
    feature_release = $asset.os.feature_release; build_number = $asset.os.build_number; domain = $asset.network.domain
}
$patch = [ordered]@{}
foreach ($f in $facts.Keys) {
    $v = $facts[$f]
    if ($null -eq $v -or "$v" -eq '' -or "$v" -eq '0') { continue }   # unknown here: leave FreeITSM's value alone
    if ("$($have[$f])" -cne "$v") { $patch[$f] = $v }
}
$deviceRef = if ($serial) { $serial } else { $hostname.ToLower() }
$report = [ordered]@{
    schema_version    = 1
    source            = $Source
    source_device_ref = $deviceRef
    observed_at       = $observedAt
    sections          = $sections
}

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be sent; add -Apply to send it' }
Write-Host "$hostname -> asset #$($asset.id) $($asset.hostname) at $Base   [$mode]" -ForegroundColor Cyan

if (-not $Apply) {
    # The exact bodies that -Apply would send. The lists are printed one item per
    # line, because Windows PowerShell's own JSON layout runs to a thousand lines.
    Write-Host "`nPATCH /assets/$($asset.id)" -ForegroundColor Yellow
    if ($patch.Count) { $patch | ConvertTo-Json } else { Write-Host '  (nothing - every field already matches)' }
    Write-Host "`nPOST /assets/$($asset.id)/inventory" -ForegroundColor Yellow
    Write-Host "  schema_version 1, source $Source, source_device_ref $deviceRef, observed_at $observedAt"
    foreach ($s in $sections.Keys) {
        Write-Host "  sections.$s (complete: the whole list)" -ForegroundColor Yellow
        foreach ($item in @($sections[$s].items)) { Write-Host ('    ' + ($item | ConvertTo-Json -Compress)) }
    }
}

# A short summary, last, so it is on screen after a long payload.
Write-Host "`nAsset fields that differ: $(if ($patch.Count) { ($patch.Keys | ForEach-Object { $_ }) -join ', ' } else { 'none' })"
foreach ($s in $sections.Keys) { Write-Host ("  {0,-17} {1} item(s)" -f $s, @($sections[$s].items).Count) }
Write-Host "  source $Source, device $deviceRef, observed $observedAt"
if (-not $Apply) { exit 0 }

# ---- 4. Send it ----------------------------------------------------------------------------------
if ($patch.Count) {
    $updated = Invoke-FreeItsm 'PATCH' "/assets/$($asset.id)" $patch
    Write-Host "Updated asset fields: $(($patch.Keys | ForEach-Object { $_ }) -join ', ')" -ForegroundColor Green
    # FreeITSM names any field it did not recognise rather than silently dropping it.
    if ($updated.ignored) { Write-Host "  ignored by FreeITSM: $($updated.ignored -join ', ')" -ForegroundColor Red }
}

# 200 = every list applied (or replayed / stale); 207 = some failed, and each
# failed one says why. Invoke-RestMethod treats both as success, so the
# per-list results are checked here. Sending the same report again retries
# only the lists that failed.
$result = Invoke-FreeItsm 'POST' "/assets/$($asset.id)/inventory" $report
foreach ($p in $result.sections.PSObject.Properties) {
    $r = $p.Value
    switch ($r.result) {
        'applied'  { Write-Host ("  {0,-17} applied: {1} added, {2} updated, {3} removed" -f $p.Name, $r.added, $r.updated, $r.removed) -ForegroundColor Green }
        'failed'   { Write-Host ("  {0,-17} FAILED: {1}" -f $p.Name, $r.error.message) -ForegroundColor Red }
        'stale'    { Write-Host ("  {0,-17} stale - a newer report (observed {1}) is already applied" -f $p.Name, $r.newer_observed_at) -ForegroundColor DarkYellow }
        default    { Write-Host ("  {0,-17} {1}" -f $p.Name, $r.result) }
    }
}
if ($result.asset_id -ne $asset.id) { Write-Host "Note: asset #$($asset.id) was merged; the report went to #$($result.asset_id)." -ForegroundColor DarkYellow }

# ---- 5. Read it back ------------------------------------------------------------------------------
# GET /assets/{id}/disks lists every source's volumes; ours are tagged rest:<source>.
Write-Host "`nVolumes FreeITSM now holds from this script:" -ForegroundColor Cyan
foreach ($d in @(Invoke-FreeItsm 'GET' "/assets/$($result.asset_id)/disks" | Where-Object { $_.source -eq "rest:$Source" })) {
    $gb = if ($d.size_bytes) { [math]::Round($d.size_bytes / 1GB, 1) } else { '?' }
    Write-Host ("  {0,-4} {1,-16} {2} GB, {3}% used" -f $d.drive, $d.label, $gb, $d.used_percent)
}
