# @title       Announce approved changes as planned maintenance
# @summary     Puts a "planned maintenance" notice on the status page for each approved change scheduled in the next few days - one per change, never twice - and can resolve them once the work is over.
# @module      service-status
# @changes     yes
# @level       intermediate
# @endpoints   GET /change-statuses, GET /changes, GET /service-status/services, GET /service-impact-levels,
#              GET /service-incident-statuses, GET /service-status/incidents, POST /service-status/incidents,
#              PATCH /service-status/incidents/{id}
# @permissions reference.read, changes.read, services.read, service_incidents.read, service_incidents.create,
#              service_incidents.update
#
# WHAT IT DOES: users hate surprises. Once a change is approved and has a date,
# this script tells them, on the Service Status board:
#
#   1. finds the change statuses that mean "approved and going ahead" - by their
#      STAGE (approved, implementing), so renamed statuses still work,
#   2. lists the changes in those statuses whose work starts in the next -Days,
#   3. works out which status-page services each change touches: the services
#      whose name appears in the change's title ("Upgrade Email firmware" ->
#      Email), or else the -Service you give as the default,
#   4. looks for an announcement already made for that change - its title
#      starts with the change reference, e.g. "[CHG-0042]" - then
#   5. creates one if there is none, or refreshes its message if the change has
#      been moved to a new time since. A change is never announced twice.
#   6. with -ResolveFinished: resolves the announcements whose change has
#      finished (its work end has passed, or it was closed or cancelled).
#
# READ THIS FIRST - how FreeITSM shows it. A status-page incident has no start
# time of its own: from the moment it is created, the services on it show the
# impact you chose (Maintenance) until it is resolved. So an announcement made
# today for work on Saturday shows "Maintenance" from today. That is why the
# window is only 2 days ahead by default; use -Days 1 to announce the day before.
#
# The Service Status SCREEN has a proper "Planned maintenance" list that waits
# until the start time before it turns into an incident - but the REST API does
# not offer it (yet), so a script cannot use it. When it does, this script
# should move to it; until then, this is the closest a script can get.
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\announce-changes-as-maintenance.ps1 -Url https://helpdesk.example.com -Service "Network"
#   powershell -ExecutionPolicy Bypass -File .\announce-changes-as-maintenance.ps1 -Url https://helpdesk.example.com -Service "Network" -Apply -ResolveFinished
# Schedule it daily; each run only adds what is new.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far ahead to look, in days from now.
    [int]$Days = 2,

    # Which change stages count as "going ahead", comma-separated. Each change
    # status has a stage: draft, awaiting, approved, implementing, done or
    # stopped. Out of the box "Approved" is approved, and "Scheduled" and
    # "In Progress" are implementing.
    [string]$Stages = 'approved,implementing',

    # The service(s) to use when a change's title names none, comma-separated,
    # exactly as on the Service Status board. Empty = such changes are skipped.
    [string]$Service = '',

    # The impact to show. Must be one of YOUR impact levels - out of the box the
    # list includes Maintenance, which is meant for exactly this.
    [string]$Impact = 'Maintenance',

    # The incident status for a new announcement. Empty = FreeITSM's default
    # status (Investigating, out of the box) - which reads oddly for planned
    # work, so consider adding a status such as "Scheduled" under Service
    # Status -> Settings and passing its name here.
    [string]$IncidentStatus = '',

    # Also resolve announcements whose change is finished.
    [switch]$ResolveFinished,

    # Updates are INTERNAL unless you say otherwise, so a script can never publish
    # to the self-service portal by accident. Add -Public once you are happy -
    # that is the point of an announcement, but it is your call.
    [switch]$Public,

    # Without -Apply nothing is written: it reads, then says what it would do.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File], not Get-Content: Get-Content tags the text with hidden properties
    # that make ConvertTo-Json crawl in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the whole reply ({ data, meta }) - the lists need meta for paging. Sends
# a body as UTF-8 bytes so accented text survives Windows PowerShell 5.1, and
# stops with FreeITSM's own message on failure (e.g. 422 "Unknown or inactive
# impact level").
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return Invoke-RestMethod @params }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# Every row of a paged list (at most 100 a page; meta.total_pages says when to
# stop). $Query is the filter part of the address, already escaped.
function Get-All([string]$Path, [string]$Query) {
    $rows = @()
    $page = 1
    do {
        $reply = Invoke-FreeItsm 'GET' ($Path + "?$Query&per_page=100&page=$page")
        $rows += @($reply.data)
        $page++
    } while ($page -le $reply.meta.total_pages)
    return $rows
}

# '2026-11-25T22:00:00' -> '2026-11-25 22:00'. Change times are WALL-CLOCK times -
# exactly as the change says, with no time zone - so they are shown as is.
function Format-When($When) {
    if (-not $When) { return '?' }
    $s = (ConvertTo-IsoText $When).Replace('T', ' ')
    if ($s.Length -gt 16) { $s = $s.Substring(0, 16) }
    return $s
}

# Windows PowerShell 5.1 keeps a date in a JSON reply as text; PowerShell 7 turns
# it into a DateTime. This gives the same 'yyyy-MM-ddTHH:mm:ss' text in both, so
# dates sort and compare the same way whichever one runs the script.
function ConvertTo-IsoText($When) {
    if ($When -is [DateTime]) { return $When.ToString('yyyy-MM-ddTHH:mm:ss') }
    return "$When"
}

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply' }
Write-Host "Planned maintenance from changes on $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. Check every name typed above against YOUR lists -------------------------------
# All of these can be renamed in Settings, so we read them rather than trusting
# a name. Doing it BEFORE any write means a typo stops the run with a helpful list.
$stageList = @($Stages -split ',' | ForEach-Object { $_.Trim().ToLower() } | Where-Object { $_ })
$statuses = @((Invoke-FreeItsm 'GET' '/change-statuses').data | Where-Object {
    $_.is_active -and -not $_.is_closed -and ($stageList -contains "$($_.stage)")
})
if ($statuses.Count -eq 0) { throw "No active, open change status has a stage in: $($stageList -join ', ')" }
Write-Host "Change statuses: $(($statuses | ForEach-Object { $_.name }) -join ', ')"

$levels = @((Invoke-FreeItsm 'GET' '/service-impact-levels').data | Where-Object { $_.is_active })
$level = $levels | Where-Object { $_.name -eq $Impact } | Select-Object -First 1
if (-not $level) { throw "'$Impact' is not an active impact level. Yours are: $(($levels | ForEach-Object { $_.name }) -join ', ')" }

$incidentStatuses = @((Invoke-FreeItsm 'GET' '/service-incident-statuses').data | Where-Object { $_.is_active })
$newStatus = $null
if ($IncidentStatus) {
    $newStatus = $incidentStatuses | Where-Object { $_.name -eq $IncidentStatus } | Select-Object -First 1
    if (-not $newStatus) { throw "'$IncidentStatus' is not an active incident status. Yours are: $(($incidentStatuses | ForEach-Object { $_.name }) -join ', ')" }
}
$resolvedStatus = $incidentStatuses | Where-Object { $_.is_resolved } | Select-Object -First 1
if ($ResolveFinished -and -not $resolvedStatus) { throw '-ResolveFinished needs an active incident status marked as resolved (Service Status -> Settings).' }

# Only ACTIVE services are listed - an inactive one is not on the board.
$services = @((Invoke-FreeItsm 'GET' '/service-status/services').data)
$defaultServices = @()
foreach ($name in @($Service -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })) {
    $s = $services | Where-Object { $_.name -eq $name } | Select-Object -First 1
    if (-not $s) { throw "No active service is called '$name'. Yours are: $(($services | ForEach-Object { $_.name }) -join ', ')" }
    $defaultServices += $s
}

# Every service whose whole name appears as words in the change's title (so
# "Mail" does not match "Voicemail"), or else the -Service default.
function Get-ServicesFor($Change) {
    $found = @($services | Where-Object { $Change.title -match ('(?<!\w)' + [regex]::Escape($_.name) + '(?!\w)') })
    if ($found.Count -gt 0) { return $found }
    return $defaultServices
}

# ---- 2. The changes going ahead in the window ------------------------------------------
# work_start_from / work_start_to filter on the change's work start. They are
# wall-clock times too - no Z, no offset - so we send this computer's local time.
# status_id takes one status, so we ask once per status.
$now = Get-Date
$nowText = $now.ToString('yyyy-MM-ddTHH:mm:ss')
$window = "work_start_from=$nowText&work_start_to=$($now.AddDays($Days).ToString('yyyy-MM-ddTHH:mm:ss'))&sort=work_start_at"
$changes = @()
foreach ($st in $statuses) { $changes += @(Get-All '/changes' "$window&status_id=$($st.id)") }
$changes = @($changes | Sort-Object { ConvertTo-IsoText $_.schedule.work_start_at })
Write-Host "$($changes.Count) change(s) start in the next $Days day(s).`n"

# All announcements this script has made - open and resolved. q= is a "contains"
# search on the title; every one of ours starts "[CHG-". Read once, used below.
$announced = @(Get-All '/service-status/incidents' ('q=' + [uri]::EscapeDataString('[CHG-')))

$made = 0; $refreshed = 0; $resolved = 0
$public = [bool]$Public
foreach ($change in $changes) {
    $sched = $change.schedule
    # The outage window is what users feel; fall back to the work window.
    $start = if ($sched.outage_start_at) { $sched.outage_start_at } else { $sched.work_start_at }
    $end   = if ($sched.outage_end_at)   { $sched.outage_end_at }   else { $sched.work_end_at }
    $prefix = "[$($change.change_number)]"
    $message = "Planned maintenance from $(Format-When $start) to $(Format-When $end) for change $($change.change_number): $($change.title)."
    $title = "$prefix Planned maintenance: $($change.title)"

    # ---- 4. Already announced? -------------------------------------------------------
    $existing = $announced | Where-Object { $_.title.StartsWith("$prefix ") } | Select-Object -First 1
    if ($existing) {
        if ($existing.status -and $existing.status.is_resolved) {
            Write-Host "  done    $($change.change_number): announcement #$($existing.id) is already resolved - left alone"
        } elseif ("$($existing.comment)" -ceq $message) {
            Write-Host "  same    $($change.change_number): already announced as #$($existing.id)"
        } else {
            # The change was moved (or retitled) since it was announced: refresh the
            # message. FreeITSM logs this as a new update on the announcement.
            Write-Host "  UPDATE  $($change.change_number): #$($existing.id) - $message" -ForegroundColor Yellow
            if ($Apply) {
                [void](Invoke-FreeItsm 'PATCH' "/service-status/incidents/$($existing.id)" @{ comment = $message; is_internal = (-not $public) })
            }
            $refreshed++
        }
        continue
    }

    # ---- 5. Announce it --------------------------------------------------------------
    $affected = @(Get-ServicesFor $change)
    if ($affected.Count -eq 0) {
        Write-Host "  ?       $($change.change_number) '$($change.title)': names no service - pass -Service to give a default" -ForegroundColor Yellow
        continue
    }
    Write-Host "  NEW     $($change.change_number) on $(($affected | ForEach-Object { $_.name }) -join ', '): $message" -ForegroundColor Yellow
    $body = @{
        title       = $title
        # Each affected service at the maintenance impact - what turns its dot on
        # the board to the Maintenance colour.
        services    = @($affected | ForEach-Object { @{ service_id = $_.id; impact_level_id = $level.id } })
        comment     = $message
        is_internal = (-not $public)
    }
    if ($newStatus) { $body.status_id = $newStatus.id }
    if ($Apply) {
        $inc = (Invoke-FreeItsm 'POST' '/service-status/incidents' $body).data
        Write-Host "          announced as incident #$($inc.id)" -ForegroundColor Green
    }
    $made++
}

# ---- 6. Resolve the announcements whose change is over --------------------------------
if ($ResolveFinished) {
    foreach ($inc in $announced) {
        if ($inc.status -and $inc.status.is_resolved) { continue }
        if ($inc.title -notmatch '^\[(CHG-\d+)\]') { continue }
        $ref = $Matches[1]
        # q=CHG-0042 finds a change by its reference (any status, open or closed).
        $change = @((Invoke-FreeItsm 'GET' "/changes?q=$ref").data) | Where-Object { $_.change_number -eq $ref } | Select-Object -First 1
        if (-not $change) { continue }                 # deleted, or outside this key's companies - leave it to a person
        $end = $change.schedule.work_end_at
        $closed = [bool]($change.status -and $change.status.is_closed)
        # ISO text compares in time order.
        $endText = ConvertTo-IsoText $end
        $over = $closed -or ($endText -and [string]::CompareOrdinal($endText, $nowText) -le 0)
        if (-not $over) { continue }
        $why = if ($closed) { "is $($change.status.name)" } else { "ended $(Format-When $endText)" }
        Write-Host "  RESOLVE #$($inc.id): $ref $why" -ForegroundColor Yellow
        if ($Apply) {
            [void](Invoke-FreeItsm 'PATCH' "/service-status/incidents/$($inc.id)" @{
                status_id = $resolvedStatus.id
                comment = "The planned maintenance for $ref is complete."
                is_internal = (-not $public)
            })
        }
        $resolved++
    }
}

$verb = if ($Apply) { '' } else { 'would be ' }
Write-Host "`n$made announcement(s) ${verb}made, $refreshed ${verb}refreshed, $resolved ${verb}resolved."
