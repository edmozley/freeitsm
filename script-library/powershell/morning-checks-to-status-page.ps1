# @title       Red morning checks to the status page
# @summary     Reads today's morning checks and declares an incident on the matching status-page service for each one that failed - or updates the incident already open - and can resolve them once the checks are green again.
# @module      service-status
# @changes     yes
# @level       intermediate
# @endpoints   GET /morning-checks/board, GET /morning-check-statuses, GET /service-status/services,
#              GET /service-impact-levels, GET /service-incident-statuses,
#              GET /service-status/incidents, POST /service-status/incidents, PATCH /service-status/incidents/{id}
# @permissions morning_check_results.read, reference.read, services.read, service_incidents.read,
#              service_incidents.create, service_incidents.update
#
# WHAT IT DOES: the morning checks say what is broken; the status page tells
# your users. This script joins the two, so a red check at 07:00 is on the
# status page before the phones start ringing:
#
#   1. reads the day's Morning Checks board (every active check and its result),
#   2. works out which status-page SERVICE each check belongs to - from the
#      $Map below (or a -MapFile), or else the service whose name appears in
#      the check's name ("Email server pings" -> "Email"),
#   3. for each service with a check in a failing status (Red by default):
#        - no open incident on it    -> declares one,
#        - the open incident is OURS -> updates it with today's failures,
#        - a PERSON declared it      -> leaves it alone (they are on it, and a
#                                       script should not rewrite their words),
#   4. with -Resolve: resolves OUR incident on a service whose checks are all
#      green again. An incident a person declared is never resolved by a script.
#
# "Ours" = the incident's title is exactly "<service> - morning checks failing".
# The title is how the next run recognises its own work, so do not rename these
# incidents by hand (or the next run will declare a new one beside it).
#
# Running it twice changes nothing the second time: FreeITSM only records a new
# update on an incident when its status, message or services actually change,
# and the message here is built from the board, which has not changed.
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\morning-checks-to-status-page.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\morning-checks-to-status-page.ps1 -Url https://helpdesk.example.com -Apply -Resolve
# Schedule it a few minutes after the checks are done (or after
# record-morning-check-results.ps1), and again later in the morning so
# incidents resolve when the checks are re-done green.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Optional: a JSON file with the same shape as $Map below, e.g.
    #   { "SQL server port open": "Finance system", "Internet line pings": ["Email", "Website"] }
    # Its entries are added to (and win over) the ones in $Map.
    [string]$MapFile = '',

    # Which morning check statuses mean "failed", comma-separated - YOUR labels
    # (Morning Checks -> Settings). Out of the box: Green, Amber and Red.
    [string]$FailStatus = 'Red',

    # Which status means "fine again" - used by -Resolve.
    [string]$OkStatus = 'Green',

    # The impact to show on the status page. Must be one of YOUR impact levels
    # (Service Status -> Settings) - out of the box: Major Outage, Partial Outage,
    # Degraded, Maintenance, Operational.
    [string]$Impact = 'Partial Outage',

    # The day's board to read, as yyyy-MM-dd. Defaults to today on THIS computer.
    [string]$Date = (Get-Date -Format 'yyyy-MM-dd'),

    # Also resolve OUR incidents whose checks are all green again.
    [switch]$Resolve,

    # Updates are INTERNAL unless you say otherwise, so a script can never publish
    # to the self-service portal by accident. Add -Public to let users see them.
    [switch]$Public,

    # Without -Apply nothing is written: it reads, then says what it would do.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- YOUR MAP: edit this, or pass -MapFile ----------------------------------------
# check name (exactly as on the Morning Checks board) -> service name (exactly as
# on the Service Status board). An array maps one check to several services.
# Checks not listed here fall back to the name match described above; checks
# that match nothing are reported and left out.
$Map = @{
    # 'SQL server port open' = 'Finance system'
    # 'Internet line pings'  = @('Email', 'Website')
}
if ($MapFile) {
    # [IO.File], never Get-Content, for text that may end up in ConvertTo-Json.
    $fromFile = [IO.File]::ReadAllText($MapFile, [Text.Encoding]::UTF8) | ConvertFrom-Json
    foreach ($p in $fromFile.PSObject.Properties) { $Map[$p.Name] = $p.Value }
}

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Sends a request, returns the "data" part of the reply, and stops with FreeITSM's
# own message on failure - for example 422 "Unknown or inactive impact level".
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so accented text survives Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# The title this script gives its incidents - and how it finds them again.
function Get-OurTitle([string]$ServiceName) { return "$ServiceName - morning checks failing" }

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply' }
Write-Host "Morning checks for $Date -> status page on $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. Check every name typed above against YOUR lists -------------------------------
# Morning check statuses, impact levels and incident statuses can all be renamed
# in Settings, so we read them rather than trusting a name. Doing it BEFORE any
# write means a typo stops the run with a helpful list instead of a half-made change.
$mcStatuses = @(Invoke-FreeItsm 'GET' '/morning-check-statuses') | Where-Object { $_.is_active }
$failLabels = @($FailStatus -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
foreach ($wanted in ($failLabels + $OkStatus)) {
    # -contains ignores capitals, like every comparison in PowerShell.
    if (-not (@($mcStatuses | ForEach-Object { $_.label }) -contains $wanted)) {
        throw "'$wanted' is not an active morning check status. Yours are: $(($mcStatuses | ForEach-Object { $_.label }) -join ', ')"
    }
}

$levels = @(Invoke-FreeItsm 'GET' '/service-impact-levels') | Where-Object { $_.is_active }
$level = $levels | Where-Object { $_.name -eq $Impact } | Select-Object -First 1
if (-not $level) { throw "'$Impact' is not an active impact level. Yours are: $(($levels | ForEach-Object { $_.name }) -join ', ')" }

# Resolving needs a status flagged "resolved" - the first active one in your list
# (out of the box: Resolved).
$resolvedStatus = @(Invoke-FreeItsm 'GET' '/service-incident-statuses') | Where-Object { $_.is_active -and $_.is_resolved } | Select-Object -First 1
if ($Resolve -and -not $resolvedStatus) { throw '-Resolve needs an active incident status marked as resolved (Service Status -> Settings).' }

# The status board's services. Only ACTIVE services are listed - an inactive one
# is not on the board, so there is nothing to put an incident on.
$services = @(Invoke-FreeItsm 'GET' '/service-status/services')

# The services a check belongs to: from $Map first, otherwise every service whose
# whole name appears as words in the check's name (so "Mail" does not match
# "Voicemail check"), longest name first - "Email" before "Mail".
function Get-ServicesFor([string]$CheckName) {
    $mapKey = $Map.Keys | Where-Object { $_ -eq $CheckName } | Select-Object -First 1
    if ($mapKey) {
        $found = @()
        foreach ($n in @($Map[$mapKey])) {
            $s = $services | Where-Object { $_.name -eq $n } | Select-Object -First 1
            if (-not $s) { throw "The map sends '$CheckName' to '$n', but no active service has that name." }
            $found += $s
        }
        return $found
    }
    foreach ($s in ($services | Sort-Object { - $_.name.Length })) {
        if ($CheckName -match ('(?<!\w)' + [regex]::Escape($s.name) + '(?!\w)')) { return @($s) }   # -match ignores capitals
    }
    return @()
}

# ---- 2. Read the board and sort each check under its service(s) -------------------
$board = Invoke-FreeItsm 'GET' "/morning-checks/board?date=$Date"
$failing = @{}   # service id -> list of "check is Red (notes)" lines
$green = @{}     # service id -> $true while every result seen so far is OK
foreach ($check in @($board.checks)) {
    $result = $check.result
    if (-not $result) { continue }                      # not done yet - says nothing either way
    $label = if ($result.status) { $result.status.label } else { "$($result.orphan_label)" }
    $isFail = $failLabels -contains $label
    $targets = @(Get-ServicesFor $check.name)
    if ($targets.Count -eq 0) {
        if ($isFail) { Write-Host "  ?       '$($check.name)' failed but matches no service - add it to `$Map" -ForegroundColor Yellow }
        continue
    }
    foreach ($s in $targets) {
        if ($isFail) {
            $line = "$($check.name) is $label"
            $notes = "$($result.notes)".Trim()
            if ($notes) { $line += " ($notes)" }
            if (-not $failing.ContainsKey($s.id)) { $failing[$s.id] = @() }
            $failing[$s.id] += $line
            $green[$s.id] = $false
        } elseif ($label -eq $OkStatus) {
            if (-not $green.ContainsKey($s.id)) { $green[$s.id] = $true }
        } else {
            $green[$s.id] = $false                      # Amber, say: not failing, not fine either
        }
    }
}
if ($failing.Count -eq 0 -and -not $Resolve) { Write-Host 'No failed checks on a mapped service - nothing to do.' }

# ---- 3. One incident per failing service ------------------------------------------
foreach ($svc in $services) {
    $sid = $svc.id
    $isFailing = $failing.ContainsKey($sid)
    if (-not $isFailing -and -not ($Resolve -and $green[$sid])) { continue }
    # state=open means "its status is not a resolved one". Open first, newest first.
    $open = @(Invoke-FreeItsm 'GET' "/service-status/incidents?state=open&service_id=$sid&per_page=100")
    $title = Get-OurTitle $svc.name
    # -ceq: the title must match exactly, capitals included.
    $ours = $open | Where-Object { $_.title -ceq $title } | Select-Object -First 1
    $public = [bool]$Public

    if ($isFailing) {
        $message = "Morning checks on $Date found a problem: " + ($failing[$sid] -join '; ')
        if (-not $ours -and $open.Count -gt 0) {
            # A person (or another tool) has already declared an incident here.
            # Their words stay as they are - we only report it.
            Write-Host "  -       $($svc.name): already in incident #$($open[0].id) '$($open[0].title)' - left alone"
            continue
        }
        if (-not $ours) {
            Write-Host "  DECLARE $($svc.name) at '$($level.name)': $message" -ForegroundColor Yellow
            if ($Apply) {
                $inc = Invoke-FreeItsm 'POST' '/service-status/incidents' @{
                    title       = $title
                    # The affected service with its impact - this is what turns its
                    # dot on the board from green to amber or red.
                    services    = @(@{ service_id = $sid; impact_level_id = $level.id })
                    comment     = $message
                    is_internal = (-not $public)
                    # No status_id: FreeITSM uses your default (Investigating).
                }
                Write-Host "          declared incident #$($inc.id)" -ForegroundColor Green
            }
            continue
        }
        # Our incident is still open: post today's state as the latest update.
        # Only the comment is sent - leaving "services" out keeps the impact as it
        # is, in case an analyst has raised or lowered it by hand.
        if ("$($ours.comment)" -ceq $message) {
            Write-Host "  same    $($svc.name): incident #$($ours.id) already says this"
            continue
        }
        Write-Host "  UPDATE  $($svc.name): incident #$($ours.id) - $message" -ForegroundColor Yellow
        if ($Apply) {
            [void](Invoke-FreeItsm 'PATCH' "/service-status/incidents/$($ours.id)" @{ comment = $message; is_internal = (-not $public) })
        }
        continue
    }

    # ---- 4. Every check on this service is green again: resolve OUR incident -------
    if ($ours) {
        $message = "Morning checks on $Date are all $OkStatus again."
        Write-Host "  RESOLVE $($svc.name): incident #$($ours.id) - $message" -ForegroundColor Yellow
        if ($Apply) {
            $inc = Invoke-FreeItsm 'PATCH' "/service-status/incidents/$($ours.id)" @{
                status_id = $resolvedStatus.id; comment = $message; is_internal = (-not $public)
            }
            # A resolved status stamps resolved_at; the service's dot goes back to
            # normal once no open incident is left on it.
            Write-Host "          now $($inc.status.name)" -ForegroundColor Green
        }
    }
}
