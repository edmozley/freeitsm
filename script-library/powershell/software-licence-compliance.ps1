# @title       Software licence compliance
# @summary     Compares the seats you have bought with the machines the software is installed on, and flags anything over-deployed or due for renewal.
# @module      software
# @changes     no
# @level       intermediate
# @endpoints   GET /software/licences, GET /software/apps/{id}
# @permissions software_licences.read, software_inventory.read
#
# WHAT IT SHOWS: one line per licensed application - seats bought, machines it is
# installed on, and the difference - with the ones that are OVER (more installs
# than seats) at the top. Licences past or near their renewal date are listed
# underneath. Run it before a vendor audit, or monthly as a scheduled task.
#
# HOW IT WORKS - two endpoints, each doing what it is best at:
#   1. GET /software/licences, every page: which applications you hold licences
#      for, and each licence's renewal date and status.
#   2. GET /software/apps/{id} for each of those applications: its "compliance"
#      block, which FreeITSM works out with the same rules as the Software
#      screen - only Active licences count, add up their seats, count distinct
#      machines with it installed, and ignore system components (runtimes and
#      updates that Windows lists but nobody buys). Asking FreeITSM rather than
#      adding up ourselves means this report and the screen always agree.
#
# A licence with no seat count (a site licence, for example) makes FreeITSM
# report the application as "unmetered": covered whatever the number of installs.
#
# Software you have NOT recorded a licence for does not appear here - the report
# starts from licences. Add them under Software -> Licences to have them checked.
#
# EXIT CODE: 0 when everything is covered, 2 when anything is over-deployed - so a
# scheduled task or monitoring tool can raise an alert on the number alone.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\software-licence-compliance.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\software-licence-compliance.ps1 -Url https://helpdesk.example.com -OutFile .\licences.csv
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# It needs the two read permissions in the @permissions line - it changes nothing.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Optional: also save the compliance table as a CSV file (opens in Excel).
    [string]$OutFile
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the WHOLE reply: lists carry their paging details in "meta" beside
# "data". Stops with FreeITSM's own error message on failure.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- 1. Every licence, a page at a time -------------------------------------------
# No status filter: we want Expired and Cancelled ones too, to show an
# application whose only licence has lapsed. per_page=100 is the most allowed.
$licences = @()
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/software/licences?per_page=100&sort=renewal_date&page=$page"
    $licences += @($reply.data)
    $page++
} while ($page -le $reply.meta.total_pages)
Write-Host "Read $($licences.Count) licences from $Base" -ForegroundColor Cyan
if ($licences.Count -eq 0) { Write-Host 'No licences recorded - add them under Software -> Licences.'; exit 0 }

# ---- 2. The compliance numbers, one call per application ----------------------------
# Several licences can belong to one application (bought in batches), so group
# first and ask about each application once.
$byApp = $licences | Group-Object { $_.app.id }
$report = @()
foreach ($group in $byApp) {
    $app = (Invoke-FreeItsm 'GET' "/software/apps/$($group.Name)").data
    $c = $app.compliance   # { installs, licensed_seats, unmetered_licences, seats_available }
    $state =
        if ($c.unmetered_licences) { 'COVERED (unmetered licence)' }
        elseif ($c.seats_available -lt 0) { 'OVER' }
        elseif ($c.seats_available -eq 0) { 'FULL' }       # compliant, but the next install is not
        else { 'OK' }
    $report += [pscustomobject]@{
        State      = $state
        # How many installs are not covered; 0 when compliant. Unmetered = never short.
        Shortfall  = if ($c.unmetered_licences -or $c.seats_available -ge 0) { 0 } else { - $c.seats_available }
        Application = $app.name
        Publisher  = $app.publisher
        Seats      = if ($c.unmetered_licences) { 'unlimited' } else { $c.licensed_seats }
        Installs   = $c.installs
        AppId      = $app.id
    }
}
# Worst first: biggest shortfall at the top, then by name.
$report = @($report | Sort-Object @{ Expression = 'Shortfall'; Descending = $true }, Application)

Write-Host "`nLicences against installations:" -ForegroundColor Cyan
$report | Format-Table State, Shortfall, Seats, Installs, Application, Publisher -AutoSize | Out-String | Write-Host

$over = @($report | Where-Object { $_.State -eq 'OVER' })
if ($over.Count) {
    Write-Host "$($over.Count) application(s) installed on more machines than you have seats for." -ForegroundColor Red
    Write-Host "See which machines with GET /software/apps/{id}/machines, or on the Software screen." -ForegroundColor DarkGray
} else {
    Write-Host 'Every licensed application is within its seats.' -ForegroundColor Green
}

# ---- Renewals worth a look ------------------------------------------------------------
# FreeITSM works out renewal_status for each licence: "overdue" (date passed),
# "due_soon" (inside the licence's notice period, 30 days unless set), or "ok".
# Only Active licences matter here - an Expired one is already known to be over.
$renewals = @($licences | Where-Object { $_.status -eq 'Active' -and $_.renewal_status -in 'overdue', 'due_soon' })
if ($renewals.Count) {
    Write-Host "`nRenewals due:" -ForegroundColor Yellow
    foreach ($l in $renewals) {
        Write-Host ("  {0,-9} {1}  {2} - {3}" -f $l.renewal_status, $l.renewal_date, $l.app.name, $l.licence_type)
    }
}

if ($OutFile) {
    $report | Export-Csv -Path $OutFile -NoTypeInformation -Encoding UTF8
    Write-Host "`nSaved $($report.Count) rows to $OutFile" -ForegroundColor Green
}

# Exit code 2 = something is over-deployed (see the header).
if ($over.Count) { exit 2 }
exit 0
