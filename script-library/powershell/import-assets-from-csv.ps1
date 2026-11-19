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
#   asset_tag       the label stuck on the device. Only used when CREATING: the
#                   REST API does not return or search asset tags, so it cannot be
#                   used to find an asset again, or be changed later from here.
#                   Leave it out if your company has automatic tag numbering on.
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
#   2. Only with -Apply: send the creates and updates.
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\import-assets-from-csv.ps1 -Url https://helpdesk.example.com -CsvFile .\assets.csv
#   powershell -ExecutionPolicy Bypass -File .\import-assets-from-csv.ps1 -Url https://helpdesk.example.com -CsvFile .\assets.csv -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# It needs the permissions in the @permissions line above. New assets go into the
# key's default company; add company_id to the create body to choose another.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The spreadsheet to import.
    [string]$CsvFile = (Join-Path $PSScriptRoot 'assets.csv'),

    # Without -Apply the script only reads and prints the plan.
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
# Returns the "data" part of the reply, or stops with FreeITSM's own message
# (e.g. 409 conflict "Another asset already uses this hostname").
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # UTF-8 bytes so accented model names survive Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# A CSV cell, trimmed; $null when empty or when the column is not in the file.
function Get-Cell($Row, [string]$Column) {
    $prop = $Row.PSObject.Properties[$Column]
    if ($null -eq $prop -or $null -eq $prop.Value) { return $null }
    $v = ([string]$prop.Value).Trim()
    if ($v -eq '') { return $null }
    return $v
}

# FreeITSM writes location paths as "UK > London" with a special arrow
# character (U+203A) instead of ">". People type ">", so both sides are turned
# into the same plain form before comparing. [char] keeps this file plain ASCII,
# which Windows PowerShell 5.1 needs to read it correctly.
function ConvertTo-PlainPath([string]$Path) {
    return (($Path -replace [string][char]0x203A, '>') -split '\s*>\s*' -join ' > ').ToLower()
}

# ---- The columns, and where each one lives in FreeITSM -----------------------------
# Field = the name POST/PATCH /assets takes. Have = how to read the current value
# from GET /assets, whose reply groups fields (hardware, lifecycle...). To import
# another column - order_number, purchase_cost, memory - add a line here.
$Columns = [ordered]@{
    hostname        = @{ Field = 'hostname';        Have = { param($a) $a.hostname } }
    service_tag     = @{ Field = 'service_tag';     Have = { param($a) $a.hardware.service_tag } }
    manufacturer    = @{ Field = 'manufacturer';    Have = { param($a) $a.hardware.manufacturer } }
    model           = @{ Field = 'model';           Have = { param($a) $a.hardware.model } }
    type            = @{ Field = 'asset_type_id';   Have = { param($a) $a.type.id } }
    status          = @{ Field = 'asset_status_id'; Have = { param($a) $a.status.id } }
    location        = @{ Field = 'location_id';     Have = { param($a) $a.location.id } }
    purchase_date   = @{ Field = 'purchase_date';   Have = { param($a) $a.lifecycle.purchase_date } }
    warranty_expiry = @{ Field = 'warranty_expiry'; Have = { param($a) $a.lifecycle.warranty_expiry } }
}

# ---- Pass 1a: the lookup lists -------------------------------------------------------
# Three small reference lists, read once. Each is a list of {id, name, ...}.
# These belong to the key's default company, the same lists the asset screen offers.
$types     = @(Invoke-FreeItsm 'GET' '/asset-types')
$statuses  = @(Invoke-FreeItsm 'GET' '/asset-statuses')
$locations = @(Invoke-FreeItsm 'GET' '/asset-locations')

# Name -> id, ignoring case. Stops with the choices when the name is unknown, so a
# typo ("Laptp") is caught before anything is written.
function Resolve-Name($List, [string]$Name, [string]$What, [int]$Line) {
    $hit = @($List | Where-Object { $_.name -eq $Name })   # -eq ignores case in PowerShell
    if ($hit.Count -eq 1) { return [int]$hit[0].id }
    if ($hit.Count -gt 1) { throw "Line ${Line}: more than one $What is called '$Name'." }
    throw "Line ${Line}: no $What called '$Name'. Choose one of: $((@($List | ForEach-Object { $_.name })) -join ', ')"
}
function Resolve-Location([string]$Text, [int]$Line) {
    $plain = ConvertTo-PlainPath $Text
    # A full path is exact; a bare name must belong to only one place.
    $hit = @($locations | Where-Object { (ConvertTo-PlainPath $_.path) -eq $plain })
    if ($hit.Count -eq 0) { $hit = @($locations | Where-Object { $_.name -eq $Text }) }
    if ($hit.Count -eq 1) { return [int]$hit[0].id }
    if ($hit.Count -gt 1) { throw "Line ${Line}: several locations are called '$Text' - write the full path, e.g. 'UK > London > $Text'." }
    throw "Line ${Line}: no location '$Text'. Add it under Asset Management -> Settings -> Locations first."
}

# Finds the asset a row means, or $null. retired=include: a retired asset still
# owns its serial and hostname, and creating a second record for it would split
# its history - so we find it and update it instead.
function Find-Asset([string]$Serial, [string]$Hostname, [int]$Line) {
    if ($Serial) {
        $hits = @(Invoke-FreeItsm 'GET' ('/assets?retired=include&service_tag=' + [uri]::EscapeDataString($Serial)))
        if ($hits.Count -gt 1) { throw "Line ${Line}: $($hits.Count) assets already share the serial $Serial (ids $(($hits | ForEach-Object { $_.id }) -join ', ')). Tidy those first." }
        if ($hits.Count -eq 1) { return $hits[0] }
    }
    $hits = @(Invoke-FreeItsm 'GET' ('/assets?retired=include&hostname=' + [uri]::EscapeDataString($Hostname)))
    if ($hits.Count -gt 1) { throw "Line ${Line}: $($hits.Count) assets are called $Hostname (in different companies). Use a key limited to one company." }
    if ($hits.Count -eq 0) { return $null }
    # Same name, DIFFERENT serial: most likely a replacement machine that took the
    # old one's name. Updating would overwrite the old machine's record, so stop.
    $theirs = $hits[0].hardware.service_tag
    if ($Serial -and $theirs -and $theirs -ne $Serial) {
        throw "Line ${Line}: $Hostname is asset #$($hits[0].id) with serial $theirs, but the file says $Serial. Rename or retire the old one first."
    }
    return $hits[0]
}

# ---- Pass 1b: read the file and work out the plan --------------------------------------
if (-not (Test-Path $CsvFile)) { throw "CSV file not found: $CsvFile" }
$rows = @(Import-Csv -Path $CsvFile -Encoding UTF8)
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to import' }
Write-Host "$($rows.Count) rows in $CsvFile -> $Base   [$mode]" -ForegroundColor Cyan

$plan = @()
$line = 1
foreach ($row in $rows) {
    $line++
    $hostname = Get-Cell $row 'hostname'
    if (-not $hostname) { throw "Line ${line}: hostname is empty. Every asset needs a name." }

    # What the row asks for, already turned into API field names and ids.
    # $label keeps the text as typed, so the plan can say "status -> Active"
    # rather than "status -> 13".
    $want = [ordered]@{}; $label = @{}
    foreach ($col in $Columns.Keys) {
        $v = Get-Cell $row $col
        if ($null -eq $v) { continue }
        $label[$col] = $v
        switch ($col) {
            'type'     { $v = Resolve-Name $types $v 'asset type' $line }
            'status'   { $v = Resolve-Name $statuses $v 'asset status' $line }
            'location' { $v = Resolve-Location $v $line }
            { $_ -in 'purchase_date', 'warranty_expiry' } {
                if ($v -notmatch '^\d{4}-\d{2}-\d{2}$') { throw "Line ${line}: $col '$v' must be YYYY-MM-DD." }
            }
        }
        $want[$col] = $v
    }

    $asset = Find-Asset (Get-Cell $row 'service_tag') $hostname $line
    if ($null -eq $asset) {
        $body = [ordered]@{}
        foreach ($col in $want.Keys) { $body[$Columns[$col].Field] = $want[$col] }
        $tag = Get-Cell $row 'asset_tag'
        if ($tag) { $body['asset_tag'] = $tag }
        $plan += @{ Action = 'create'; Name = $hostname; Body = $body; Line = $line }
        continue
    }

    # Compare each wanted value with what FreeITSM has; keep only the differences.
    $patch = [ordered]@{}; $changes = @()
    foreach ($col in $want.Keys) {
        $have = & $Columns[$col].Have $asset
        if ([string]$have -cne [string]$want[$col]) {
            $patch[$Columns[$col].Field] = $want[$col]
            $changes += "$col -> '$($label[$col])'"
        }
    }
    $action = if ($patch.Count) { 'update' } else { 'unchanged' }
    $plan += @{ Action = $action; Name = $hostname; Id = $asset.id; Body = $patch; Changes = $changes; Line = $line }
}

# ---- Pass 2: show the plan, and carry it out with -Apply ----------------------------------
$count = @{ create = 0; update = 0; unchanged = 0 }
foreach ($p in $plan) {
    $count[$p.Action]++
    switch ($p.Action) {
        'unchanged' { Write-Host "  unchanged     $($p.Name) (#$($p.Id))" -ForegroundColor DarkGray }
        'create' {
            if (-not $Apply) { Write-Host "  would create  $($p.Name)" -ForegroundColor Yellow; break }
            $new = Invoke-FreeItsm 'POST' '/assets' $p.Body
            Write-Host "  created       $($p.Name) -> asset #$($new.id)" -ForegroundColor Green
            # FreeITSM names any field it did not recognise rather than silently
            # dropping it - usually a typo in a column you added to $Columns.
            if ($new.ignored) { Write-Host "                ignored by FreeITSM: $($new.ignored -join ', ')" -ForegroundColor Red }
        }
        'update' {
            if (-not $Apply) { Write-Host "  would update  $($p.Name) (#$($p.Id)): $($p.Changes -join '; ')" -ForegroundColor Yellow; break }
            # PATCH changes only the fields sent, and FreeITSM writes one history
            # line per changed field - visible on the asset's History tab.
            $upd = Invoke-FreeItsm 'PATCH' "/assets/$($p.Id)" $p.Body
            Write-Host "  updated       $($p.Name) (#$($p.Id)): $($p.Changes -join '; ')" -ForegroundColor Green
            if ($upd.ignored) { Write-Host "                ignored by FreeITSM: $($upd.ignored -join ', ')" -ForegroundColor Red }
        }
    }
}
$verb = if ($Apply) { '' } else { 'would be ' }
Write-Host "`nDone: $($count.create) ${verb}created, $($count.update) ${verb}updated, $($count.unchanged) unchanged." -ForegroundColor Cyan
