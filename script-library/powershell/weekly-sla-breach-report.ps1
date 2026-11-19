# @title       Weekly SLA breach report
# @summary     Checks the SLA of every ticket logged in the last 7 days and summarises which missed their response or resolution target.
# @module      tickets
# @changes     no
# @level       intermediate
# @endpoints   GET /tickets, GET /tickets/{id}/sla
# @permissions tickets.read, ticket_sla.read
#
# WHAT IT SHOWS: one call per ticket, then a summary. The ticket list does not
# carry SLA figures - they are worked out when you ask, from the ticket's
# priority, its SLA calendar (working hours, bank holidays) and every status it
# has been in (statuses like "On Hold" pause the clock). So the script:
#
#   1. Lists the tickets logged in the last -Days days (paged).
#   2. Asks GET /tickets/{id}/sla for each one.
#   3. Counts breaches and prints the tickets that missed a target.
#
# One call per ticket is fine for a week of tickets. For thousands, run it out
# of hours: every call counts towards the key's rate limit (System -> API).
#
# Run it - every Monday morning from Task Scheduler, say:
#   powershell -ExecutionPolicy Bypass -File .\weekly-sla-breach-report.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\weekly-sla-breach-report.ps1 -Url https://helpdesk.example.com -Days 30
#
# A read-only key is enough: tickets.read and ticket_sla.read.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far back to look, by when the ticket was LOGGED.
    [int]$Days = 7
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the whole reply ({ data, meta }) because the ticket list pages; stops
# with FreeITSM's own error message on failure.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- 1. The tickets logged in the window -----------------------------------------
# created_since takes an ISO 8601 date and time; the "Z" means UTC, which is how
# FreeITSM stores every date. No state= filter: a ticket closed late is exactly
# what this report is for, so open AND closed tickets count. sort=id keeps the
# pages steady if new tickets arrive while we read (see export-open-tickets-csv).
$since = [DateTime]::UtcNow.AddDays(-$Days).ToString('yyyy-MM-ddTHH:mm:ssZ')
$tickets = New-Object System.Collections.Generic.List[object]
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/tickets?created_since=$since&sort=id&per_page=100&page=$page"
    foreach ($t in @($reply.data)) { $tickets.Add($t) }
    $page++
} while ($page -le $reply.meta.total_pages)
Write-Host "$($tickets.Count) ticket(s) logged since $since" -ForegroundColor Cyan

# ---- 2. Ask for each ticket's SLA ------------------------------------------------
# The answer looks like:
#   { "enabled": true, "reason_disabled": null,
#     "response":   { "target_minutes": 60,  "elapsed_minutes": 75,  "breached": true,  "percent": 100, ... },
#     "resolution": { "target_minutes": 480, "elapsed_minutes": 120, "breached": false, ... } }
# Minutes are WORKING minutes on the SLA calendar, not wall-clock minutes.
# "enabled": false comes with a reason - no priority, a priority with no target,
# SLAs not switched on yet - and response/resolution are then null. A priority
# may have only one of the two targets, so either can be null on its own too.
$measured = 0
$notMeasured = @{}          # reason -> how many
$responseBreaches = 0
$resolutionBreaches = 0
$breachedRows = New-Object System.Collections.Generic.List[object]
$i = 0
foreach ($t in $tickets) {
    $i++
    Write-Progress -Activity 'Checking SLAs' -Status $t.ticket_number -PercentComplete (100 * $i / $tickets.Count)
    $sla = (Invoke-FreeItsm 'GET' "/tickets/$($t.id)/sla").data
    if (-not $sla.enabled) {
        $reason = if ($sla.reason_disabled) { $sla.reason_disabled } else { 'not measured' }
        $notMeasured[$reason] = 1 + [int]$notMeasured[$reason]
        continue
    }
    $measured++
    $missed = @()
    if ($sla.response -and $sla.response.breached) {
        $responseBreaches++
        $missed += "response $($sla.response.elapsed_minutes)/$($sla.response.target_minutes) min"
    }
    if ($sla.resolution -and $sla.resolution.breached) {
        $resolutionBreaches++
        $missed += "resolution $($sla.resolution.elapsed_minutes)/$($sla.resolution.target_minutes) min"
    }
    if ($missed.Count -gt 0) {
        $breachedRows.Add([pscustomobject]@{
            Ticket   = $t.ticket_number
            Priority = if ($t.priority) { $t.priority.name } else { '' }
            Status   = if ($t.status) { $t.status.name } else { '' }
            Analyst  = if ($t.assigned_analyst) { $t.assigned_analyst.name } else { '(unassigned)' }
            Missed   = $missed -join ', '
            Subject  = $t.subject
        })
    }
}
Write-Progress -Activity 'Checking SLAs' -Completed

# ---- 3. The summary -----------------------------------------------------------------
# Paste it into an email, or swap Format-Table for Export-Csv to keep a history.
Write-Host "`nSLA report - tickets logged in the last $Days day(s)" -ForegroundColor Yellow
Write-Host ("  Tickets                {0}" -f $tickets.Count)
Write-Host ("  Measured against SLA   {0}" -f $measured)
Write-Host ("  Response breaches      {0}" -f $responseBreaches)
Write-Host ("  Resolution breaches    {0}" -f $resolutionBreaches)
if ($measured -gt 0) {
    $ok = $measured - $breachedRows.Count
    Write-Host ("  Met every target       {0} ({1}%)" -f $ok, [Math]::Round(100 * $ok / $measured))
}
if ($notMeasured.Count -gt 0) {
    Write-Host "`n  Not measured:"
    foreach ($r in $notMeasured.GetEnumerator() | Sort-Object Value -Descending) { Write-Host ("    {0,4}  {1}" -f $r.Value, $r.Key) }
}
if ($breachedRows.Count -gt 0) {
    Write-Host "`nTickets that missed a target (working minutes taken / allowed):" -ForegroundColor Yellow
    $breachedRows | Format-Table -AutoSize -Wrap | Out-String -Width 200 | Write-Host
}
