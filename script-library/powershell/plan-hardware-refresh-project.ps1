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
# WHICH ASSETS: an asset is due when it was bought more than -OlderThanYears
# ago (its purchase date), OR its warranty has already ended. Retired assets are
# left out (the API does that by default). An asset with neither date cannot be
# judged - it is counted and reported, so you can fill the dates in, but it is
# not added to the plan.
#
# WHAT IT BUILDS - a project (staged, so its Budget and RAID tabs are on) with:
#   - one BUDGET LINE per type + location, e.g. "Laptop - London": quantity x
#     unit cost, category hardware, the hostnames in its notes. The unit cost
#     comes from $UnitCosts below, or -UnitCost for a type not listed. It is
#     in the project's currency (your install's default).
#   - one RISK per group that has kit ALREADY out of warranty: a failure there is
#     a repair you pay for, or a person without a device, until it is replaced.
#   - with -Stages, three stages: order, deploy, dispose of the old kit.
# If new projects need approval on your install (Projects -> Settings), it starts
# as "proposed" and waits for that approval like any other proposal.
#
# SAFE TO RUN AGAIN: the project is found by its exact name first (-ProjectName)
# and reused. Budget lines, risks and stages are matched by title and only the
# missing ones are added. An existing budget line is NEVER changed - somebody may
# have edited the figure - but if today's count would give a different amount,
# the script says so, and you can update it by hand.
#
# Run it - first WITHOUT -Apply to see the plan:
#   powershell -ExecutionPolicy Bypass -File .\plan-hardware-refresh-project.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\plan-hardware-refresh-project.ps1 -Url https://helpdesk.example.com -OlderThanYears 4 -Types Laptop,Desktop -Stages -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# Keep that file out of source control and readable only by you. The key's
# analyst becomes the project manager, and must be allowed to create projects
# (Projects -> Settings). On a multi-company install the project goes in the
# key's default company - use a key scoped to the company whose kit it is.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com
    # or http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Bought this many years ago or more = due for replacement.
    [ValidateRange(1, 50)]
    [int]$OlderThanYears = 5,

    # The price of one replacement for a type not in $UnitCosts.
    [decimal]$UnitCost = 1000,

    # Only these asset types (e.g. -Types Laptop,Desktop). Empty = every type.
    [string[]]$Types = @(),

    # Only assets whose hostname, model, manufacturer or service tag contains this -
    # e.g. one model ("Latitude 7480"). Empty = every asset.
    [string]$Search = '',

    # The project's name - also how it is found again on the next run.
    [string]$ProjectName = "Hardware refresh $((Get-Date).Year + 1)",

    # Add three stages (order, deploy, dispose).
    [switch]$Stages,

    # Without -Apply the script only prints the plan.
    [switch]$Apply
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- YOUR PRICES: what one replacement costs, per asset type --------------------------
# Keys are asset type names exactly as in Assets -> Settings (capitals ignored).
# A type not listed here uses -UnitCost. Whole numbers or two decimal places.
$UnitCosts = @{
    'Laptop'  = 1100
    'Desktop' = 850
    'Monitor' = 180
}

$StageList = @(
    @{ Name = 'Order replacements'; Goal = 'Quotes approved and every replacement ordered.' }
    @{ Name = 'Deploy';             Goal = 'New kit built, handed over and the old kit collected.' }
    @{ Name = 'Dispose of old kit'; Goal = 'Old kit wiped, certificates of destruction filed, assets retired in FreeITSM.' }
)

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content: Get-Content tags the text with hidden
    # properties (PSPath, PSDrive...) that make ConvertTo-Json crawl - and hang.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}

# Every REST call lives under /api/v1 on your server.
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- Helpers --------------------------------------------------------------------
# Sends a request and returns FreeITSM's WHOLE reply ({ "data": ..., "meta": ... });
# on failure it stops with FreeITSM's own message ({ "error": { "message": ... } }).
function Invoke-FreeItsmRaw([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" }; TimeoutSec = 60 }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so non-English text survives Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return Invoke-RestMethod @params }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) { (Invoke-FreeItsmRaw $Method $Path $Body).data }

# Every row of a list (at most 100 a page; meta.total_pages says when to stop).
function Get-AllPages([string]$Path, [string]$Query) {
    $all = @(); $page = 1
    do {
        $reply = Invoke-FreeItsmRaw 'GET' "$Path`?$Query&per_page=100&page=$page"
        $all += @($reply.data); $page++
    } while ($page -le $reply.meta.total_pages)
    return $all
}

# Budget amounts are sent as text with two decimals ("2200.00"): the API accepts
# at most two. InvariantCulture keeps the decimal point a point on every PC.
function Format-Money([decimal]$Amount) { $Amount.ToString('0.00', [Globalization.CultureInfo]::InvariantCulture) }
function ConvertFrom-IsoDate([string]$Text) {
    [datetime]::ParseExact($Text.Substring(0, 10), 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
}
function Get-AssetName($Asset) { if ($Asset.hostname) { $Asset.hostname } else { "asset #$($Asset.id)" } }

# AddYears(-5) on 29 February gives 28 February, as wanted.
$today = (Get-Date).Date
$cutoff = $today.AddYears(-$OlderThanYears)
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to build the project' }
Write-Host "Hardware refresh plan -> $Base   [$mode]" -ForegroundColor Cyan
Write-Host "Due: bought on or before $($cutoff.ToString('yyyy-MM-dd')) ($OlderThanYears+ years), or warranty ended before $($today.ToString('yyyy-MM-dd'))"

# ---- 1. Asset types: check -Types, and price each type ----------------------------------
# GET /asset-types gives the names exactly as they are set up, so a typo in
# -Types or in $UnitCosts is caught here instead of quietly matching nothing.
$typeNames = @{}
foreach ($t in @(Invoke-FreeItsm 'GET' '/asset-types')) { $typeNames[$t.name.ToLowerInvariant()] = $t.name }
$wanted = @{}
# "-Types Laptop,Desktop" arrives as two values; one "Laptop,Desktop" string is split too.
foreach ($name in @($Types | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })) {
    if (-not $typeNames.ContainsKey($name.ToLowerInvariant())) {
        throw "No asset type called '$name'. Choose from: $(($typeNames.Values | Sort-Object) -join ', ')"
    }
    $wanted[$name.ToLowerInvariant()] = $true
}
$prices = @{}
foreach ($k in $UnitCosts.Keys) {
    $prices[$k.ToLowerInvariant()] = [decimal]$UnitCosts[$k]
    if (-not $typeNames.ContainsKey($k.ToLowerInvariant())) { Write-Host "  note: `$UnitCosts lists '$k', which is not an asset type here - it is never used" -ForegroundColor DarkYellow }
}

# ---- 2. The assets, and which are due -------------------------------------------------------
# GET /assets leaves retired assets out unless asked. Each asset carries its
# type, location (with the full path, e.g. "London > Floor 2") and lifecycle
# dates as YYYY-MM-DD. The date logic is done here, on the client, because the
# API's own filters cover warranty OR age, not both at once.
$query = 'sort=hostname'
if ($Search) { $query += '&q=' + [uri]::EscapeDataString($Search) }
$assets = @(Get-AllPages '/assets' $query)

$groups = @{}   # "type|location" -> list of @{ Asset; Reasons; Expired }
$undated = 0
foreach ($a in $assets) {
    $typeName = if ($a.type) { $a.type.name } else { 'No type' }
    if ($wanted.Count -gt 0 -and -not $wanted.ContainsKey($typeName.ToLowerInvariant())) { continue }
    $life = $a.lifecycle
    $bought = if ($life.purchase_date) { ConvertFrom-IsoDate $life.purchase_date } else { $null }
    $warranty = if ($life.warranty_expiry) { ConvertFrom-IsoDate $life.warranty_expiry } else { $null }
    if (-not $bought -and -not $warranty) { $undated++; continue }
    $reasons = @()
    if ($bought -and $bought -le $cutoff) { $reasons += "bought $($bought.ToString('yyyy-MM-dd'))" }
    $expired = [bool]($warranty -and $warranty -lt $today)
    if ($expired) { $reasons += "warranty ended $($warranty.ToString('yyyy-MM-dd'))" }
    if ($reasons.Count -eq 0) { continue }
    $location = if ($a.location) { if ($a.location.path) { $a.location.path } else { $a.location.name } } else { 'No location' }
    $key = "$typeName|$location"
    if (-not $groups.ContainsKey($key)) { $groups[$key] = [pscustomobject]@{ Type = $typeName; Location = $location; Members = @() } }
    $groups[$key].Members += [pscustomobject]@{ Asset = $a; Reasons = $reasons; Expired = $expired }
}

$total = 0; foreach ($g in $groups.Values) { $total += $g.Members.Count }
Write-Host "Assets read: $($assets.Count); due for replacement: $total; with no purchase or warranty date: $undated"
if ($groups.Count -eq 0) { Write-Host 'Nothing is due. Nothing to plan.'; return }

# ---- 3. The plan, worked out before anything is written -------------------------------------------
$plan = @()
foreach ($g in ($groups.Values | Sort-Object Type, Location)) {
    $unit = if ($prices.ContainsKey($g.Type.ToLowerInvariant())) { $prices[$g.Type.ToLowerInvariant()] } else { $UnitCost }
    $title = "$($g.Type) - $($g.Location)"
    if ($title.Length -gt 200) { $title = $title.Substring(0, 200) }
    $names = @($g.Members | ForEach-Object { Get-AssetName $_.Asset } | Sort-Object)
    # A line's notes hold at most 500 characters, so a long list ends "and 12 more".
    $notes = "$($names.Count) x $(Format-Money $unit). Replaces: "
    for ($i = 0; $i -lt $names.Count; $i++) {
        if ($notes.Length + $names[$i].Length + 2 + ' and 9999 more'.Length -gt 500) {
            $notes += if ($i -gt 0) { " and $($names.Count - $i) more" } else { "$($names.Count) assets" }
            break
        }
        $notes += $(if ($i -gt 0) { ', ' } else { '' }) + $names[$i]
    }
    $plan += [pscustomobject]@{
        Title = $title; Count = $g.Members.Count; Unit = $unit; Planned = $unit * $g.Members.Count
        Notes = $notes; Members = $g.Members; Lapsed = @($g.Members | Where-Object { $_.Expired })
    }
}

foreach ($p in $plan) {
    Write-Host "`n  $($p.Title): $($p.Count) x $(Format-Money $p.Unit) = $(Format-Money $p.Planned)" -ForegroundColor Yellow
    foreach ($m in $p.Members) { Write-Host "      $(Get-AssetName $m.Asset)  ($($m.Reasons -join '; '))" }
}
$sum = [decimal]0; foreach ($p in $plan) { $sum += $p.Planned }
Write-Host "`n  Total: $(Format-Money $sum)"

# ---- 4. The project: find it by name, or create it ---------------------------------------------------
# q= is a "contains" search over name, summary and goal, so we insist on the
# exact name afterwards. Every status is searched, so a project already closed
# with this name is noticed rather than duplicated.
$project = @(Get-AllPages '/projects' ('q=' + [uri]::EscapeDataString($ProjectName))) | Where-Object { $_.name -ceq $ProjectName } | Select-Object -First 1
if ($project -and ($project.status -eq 'closed' -or $project.status -eq 'cancelled')) {
    throw "Project '$ProjectName' exists but is $($project.status). Pass a different -ProjectName."
}
$riskCount = @($plan | Where-Object { $_.Lapsed.Count -gt 0 }).Count

if (-not $Apply) {
    if ($project) { Write-Host "`nWould add what is missing to $($project.code) '$($project.name)'." }
    else {
        $extra = if ($Stages) { " and $($StageList.Count) stages." } else { '.' }
        Write-Host "`nWould create project '$ProjectName' with $($plan.Count) budget line(s), $riskCount risk(s)$extra"
    }
    Write-Host 'Preview only - add -Apply to build it.' -ForegroundColor Cyan
    return
}

if ($project) {
    Write-Host "`nFound $($project.code) '$($project.name)' ($($project.status)) - adding only what is missing." -ForegroundColor DarkGray
} else {
    $project = Invoke-FreeItsm 'POST' '/projects' @{
        name        = $ProjectName
        # 'staged' switches on the Budget, RAID and stage gate tabs.
        methodology = 'staged'
        goal        = 'Replace end-of-life and out-of-warranty hardware.'
        summary     = "Planned by the plan-hardware-refresh-project script on $($today.ToString('yyyy-MM-dd')): $total asset(s) bought $OlderThanYears+ years ago or out of warranty, in $($plan.Count) group(s)."
    }
    Write-Host "`nCreated $($project.code) '$($project.name)' (status: $($project.status))." -ForegroundColor Green
}
$projectId = $project.id

# ---- 5. Budget lines: one per group, only the missing ones --------------------------------------------
# GET /projects/{id}/budget returns the lines (and totals); we match on title.
$lines = @{}
foreach ($l in @((Invoke-FreeItsm 'GET' "/projects/$projectId/budget").lines)) { $lines[$l.title] = $l }
$added = 0
foreach ($p in $plan) {
    if ($lines.ContainsKey($p.Title)) {
        $have = $lines[$p.Title]
        $note = ''
        if ($null -eq $have.planned -or [math]::Abs([decimal]$have.planned - $p.Planned) -ge 0.005) {
            $note = "  - today's count gives $(Format-Money $p.Planned); update it by hand if that is right"
        }
        Write-Host "  budget line already there: $($p.Title) (planned $($have.planned))$note" -ForegroundColor DarkGray
        continue
    }
    [void](Invoke-FreeItsm 'POST' "/projects/$projectId/budget-lines" @{
        title = $p.Title; category = 'hardware'; planned = (Format-Money $p.Planned); notes = $p.Notes
    })
    Write-Host "  added budget line: $($p.Title)  $(Format-Money $p.Planned)" -ForegroundColor Green
    $added++
}

# ---- 6. A risk per group with kit already out of warranty ---------------------------------------------
# Probability and impact are 1-5. 4 x 3: likely that something fails before it
# is replaced, and a failure costs a repair or a person's working day.
$risks = @{}
foreach ($r in @(Invoke-FreeItsm 'GET' "/projects/$projectId/raid?type=risk")) { $risks[$r.title] = $true }
foreach ($p in $plan) {
    if ($p.Lapsed.Count -eq 0) { continue }
    $title = "Out of warranty until replaced: $($p.Title)"
    if ($title.Length -gt 255) { $title = $title.Substring(0, 255) }
    if ($risks.ContainsKey($title)) { Write-Host "  risk already there: $title" -ForegroundColor DarkGray; continue }
    $listing = ($p.Lapsed | ForEach-Object { "- $(Get-AssetName $_.Asset): $($_.Reasons -join '; ')" }) -join "`n"
    [void](Invoke-FreeItsm 'POST' "/projects/$projectId/raid" @{
        type = 'risk'; title = $title; probability = 4; impact = 3; response = 'reduce'
        description   = "$($p.Lapsed.Count) asset(s) in this group are already out of warranty, so a failure before replacement is a paid repair or a person without a device:`n$listing"
        response_plan = 'Replace these first, and keep a spare or two in stock until they are.'
    })
    Write-Host "  added risk: $title" -ForegroundColor Green
}

# ---- 7. Stages, if asked for -------------------------------------------------------------------------------
# A new stage starts 'planned'; the project manager starts each one in the app.
if ($Stages) {
    $haveStages = @{}
    foreach ($s in @(Invoke-FreeItsm 'GET' "/projects/$projectId/stages")) { $haveStages[$s.name] = $true }
    foreach ($s in $StageList) {
        if ($haveStages.ContainsKey($s.Name)) { Write-Host "  stage already there: $($s.Name)" -ForegroundColor DarkGray; continue }
        [void](Invoke-FreeItsm 'POST' "/projects/$projectId/stages" @{ name = $s.Name; goal = $s.Goal })
        Write-Host "  added stage: $($s.Name)" -ForegroundColor Green
    }
}

Write-Host "`nDone: $($project.code) - $added budget line(s) added." -ForegroundColor Cyan
