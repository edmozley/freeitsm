# @title       Declare a service incident
# @summary     Puts a service into an incident on the status board - or, if it already has one open, posts an update or resolves it.
# @module      service-status
# @changes     yes
# @level       intermediate
# @endpoints   GET /service-status/services, GET /service-impact-levels, GET /service-incident-statuses,
#              GET /service-status/incidents, POST /service-status/incidents, PATCH /service-status/incidents/{id}
# @permissions services.read, reference.read, service_incidents.read, service_incidents.create, service_incidents.update
#
# WHAT IT DOES: you name a service as it appears on the Service Status board
# (for example "Email") and say how badly it is affected. The script:
#   1. finds the service by name,
#   2. checks the impact level (and status, if you give one) against the lists
#      configured in YOUR FreeITSM - they are renameable, so nothing is assumed,
#   3. looks for an incident that is already OPEN on that service, then
#   4. either creates a new incident, or updates the open one: a new message,
#      a new status (say "Identified", or "Resolved" to close it), a new impact.
#
# Step 3 is what makes it safe to call from a monitoring tool every few minutes:
# the first failure declares the incident, later calls only add updates to it,
# and nobody ends up with ten copies of "Email is down".
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\declare-service-incident.ps1 -Url https://helpdesk.example.com -Service "Email" -Impact "Major Outage" -Message "Users cannot send or receive mail."
#   powershell -ExecutionPolicy Bypass -File .\declare-service-incident.ps1 -Url https://helpdesk.example.com -Service "Email" -Impact "Major Outage" -Message "Users cannot send or receive mail." -Apply
# Later, on the same incident:
#   ... -Service "Email" -Status "Identified" -Message "A failed certificate renewal - being replaced now." -Apply
#   ... -Service "Email" -Status "Resolved" -Message "Mail is flowing again." -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The service's name exactly as it appears on the Service Status board.
    [Parameter(Mandatory = $true)][string]$Service,

    # How badly the service is affected. Must be one of YOUR impact levels - out of
    # the box: Major Outage, Partial Outage, Degraded, Maintenance, Operational.
    # Left out: a NEW incident uses Major Outage, an update keeps the impact it had.
    [string]$Impact = '',

    # The incident's title - only used when a NEW incident is created.
    [string]$Title = '',

    # The update to post: what is happening, in words your users understand.
    [string]$Message = '',

    # The incident status to set, e.g. Investigating, Identified, Monitoring or
    # Resolved. Leave it out on a new incident and FreeITSM uses Investigating.
    [string]$Status = '',

    # Updates are INTERNAL unless you say otherwise, so a script can never publish
    # to the self-service portal by accident. Add -Public to let users see this one.
    [switch]$Public,

    # Without -Apply the script only looks things up and prints what it would do.
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

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to make the change' }
Write-Host "Service incident for '$Service' on $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. Find the service ----------------------------------------------------------
# ?q= is a "contains" search, so "Mail" would also find "Voicemail". We ask for
# the short list, then insist on an exact (case-insensitive) match ourselves.
# The list only shows ACTIVE services - an inactive one is not on the board.
$candidates = @(Invoke-FreeItsm 'GET' "/service-status/services?q=$([uri]::EscapeDataString($Service))")
$svc = $candidates | Where-Object { $_.name -eq $Service } | Select-Object -First 1
if (-not $svc) {
    $names = ($candidates | ForEach-Object { $_.name }) -join ', '
    throw "No active service is called '$Service'." + $(if ($names) { " Close matches: $names" } else { '' })
}
Write-Host "Service     : $($svc.name) (#$($svc.id)) - currently $($svc.current_status.name)"

# ---- 2. Check the impact level and status against YOUR lists ------------------------
# Both lists can be renamed and extended under Service Status -> Settings, so we
# read them rather than trusting a name typed here. Doing it BEFORE any write
# means a typo stops the run with a helpful list instead of a half-made change.
$levels = @(Invoke-FreeItsm 'GET' '/service-impact-levels') | Where-Object { $_.is_active }
$impactName = if ($Impact) { $Impact } else { 'Major Outage' }
$level = $levels | Where-Object { $_.name -eq $impactName } | Select-Object -First 1
if (-not $level) { throw "'$impactName' is not an active impact level. Yours are: $(($levels | ForEach-Object { $_.name }) -join ', ')" }

$statusRow = $null
if ($Status) {
    $statuses = @(Invoke-FreeItsm 'GET' '/service-incident-statuses') | Where-Object { $_.is_active }
    $statusRow = $statuses | Where-Object { $_.name -eq $Status } | Select-Object -First 1
    if (-not $statusRow) { throw "'$Status' is not an active incident status. Yours are: $(($statuses | ForEach-Object { $_.name }) -join ', ')" }
}

# ---- 3. Is there already an open incident on this service? ------------------------
# state=open means "its status is not a resolved one". The list is ordered open
# first, most recently updated first, so the first row is the live incident.
$open = @(Invoke-FreeItsm 'GET' "/service-status/incidents?state=open&service_id=$($svc.id)&per_page=100")

if ($open.Count -eq 0) {
    # ---- 4a. Declare a new incident ------------------------------------------------
    if ($statusRow -and $statusRow.is_resolved) {
        # Nothing is open, so there is nothing to resolve - say so rather than
        # creating an incident that is closed the moment it exists.
        Write-Host "Nothing is open on $($svc.name), so there is nothing to mark '$Status'." -ForegroundColor Yellow
        return
    }
    $newTitle = if ($Title) { $Title } else { "$($svc.name) - $($level.name)" }
    $body = @{
        title       = $newTitle
        # The affected services, each with its own impact. This is what turns the
        # service's dot on the board from green to red.
        services    = @(@{ service_id = $svc.id; impact_level_id = $level.id })
        is_internal = (-not $Public)
    }
    if ($Message)   { $body.comment = $Message }
    if ($statusRow) { $body.status_id = $statusRow.id }

    if (-not $Apply) {
        Write-Host "Would DECLARE a new incident '$newTitle' - $($svc.name) at '$($level.name)'" -ForegroundColor Yellow
        if ($Message) { Write-Host "  with the update: $Message" }
        return
    }
    $inc = Invoke-FreeItsm 'POST' '/service-status/incidents' $body
    Write-Host "Declared incident #$($inc.id) '$($inc.title)' - status $($inc.status.name)" -ForegroundColor Green
    return
}

# ---- 4b. Update the open incident -----------------------------------------------
$inc = $open[0]
Write-Host "Open incident: #$($inc.id) '$($inc.title)' - status $($inc.status.name)"
if ($open.Count -gt 1) { Write-Host "  ($($open.Count) incidents are open on this service; updating the most recent.)" -ForegroundColor Yellow }

# A PATCH only changes the fields you send. Two rules the API applies:
#   - "services" REPLACES the whole list of affected services. One incident can
#     cover several services, so we send every service it already has, changing
#     only the impact on ours (and only if you passed -Impact) - otherwise the
#     others would drop off it.
#   - FreeITSM records a new update (the timeline entry users see) only when the
#     status, message or services actually change, so running the same command
#     twice does not post the same message twice.
$services = @(foreach ($s in @($inc.services)) {
    if ($s.service_id -eq $svc.id -and $Impact) { @{ service_id = $s.service_id; impact_level_id = $level.id } }
    else { @{ service_id = $s.service_id; impact_level_id = $s.impact.id } }
})
$patch = @{ services = $services; is_internal = (-not $Public) }
if ($Message)   { $patch.comment = $Message }
if ($statusRow) { $patch.status_id = $statusRow.id }

if (-not $Apply) {
    $what = @()
    if ($Impact)    { $what += "impact '$($level.name)'" }
    if ($statusRow) { $what += "status '$($statusRow.name)'" }
    if ($Message)   { $what += "update '$Message'" }
    Write-Host "Would UPDATE incident #$($inc.id): $($what -join ', ')" -ForegroundColor Yellow
    return
}
$inc = Invoke-FreeItsm 'PATCH' "/service-status/incidents/$($inc.id)" $patch
# A resolved status also stamps resolved_at, and the service goes back to its
# normal colour on the board once no open incident is left on it.
$note = if ($inc.resolved_at) { " - resolved at $($inc.resolved_at)" } else { '' }
Write-Host "Updated incident #$($inc.id) - status $($inc.status.name)$note" -ForegroundColor Green
