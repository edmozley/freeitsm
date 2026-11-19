# @title       Time logged per analyst this month
# @summary     Adds up the time each analyst logged on tickets this month (or any month you name) and prints the totals.
# @module      tickets
# @changes     no
# @level       intermediate
# @endpoints   GET /tickets, GET /tickets/{id}/time-entries
# @permissions tickets.read, ticket_time_entries.read
#
# WHAT IT SHOWS: combining two endpoints. Time is logged against a ticket, so
# there is no single "all time entries" list - you walk the tickets, ask each
# one for its time entries, and add them up yourself:
#
#   1. List the tickets that could hold time for the month (paged).
#   2. GET /tickets/{id}/time-entries for each.
#   3. Keep the entries dated inside the month and total them per analyst.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\time-logged-per-analyst.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\time-logged-per-analyst.ps1 -Url https://helpdesk.example.com -Month 2026-10
#
# A read-only key is enough: tickets.read and ticket_time_entries.read.
#
# If time tracking is switched off for the API (Tickets -> Settings -> Time
# tracking, which can differ per company), those tickets answer 404 "Time
# tracking is not enabled for this ticket's company" and the script stops there -
# give the key only the companies that track time, or switch it on. FreeITSM
# refuses rather than answering "no time", because "no time" would be untrue.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The month to total, as yyyy-MM. Empty = the current month. Months are
    # counted in UTC, the way FreeITSM stores times: an entry at 00:30 on the 1st
    # in a timezone ahead of UTC belongs to the previous month here.
    [string]$Month = '',

    # Which tickets to look at - see step 1 for why this is not just "this month".
    [int]$LookbackDays = 90,

    # Look at EVERY ticket instead: exact, but one call per ticket on the whole
    # install. Fine for a few thousand tickets, slow beyond that.
    [switch]$AllTickets
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

# Dates come back as "2026-11-19T09:30:00Z" (UTC). Windows PowerShell 5.1 leaves
# that as text, PowerShell 7 makes it a [datetime]; this turns either into UTC.
function ConvertTo-Utc($Value) {
    if ($Value -is [datetime]) { return $Value.ToUniversalTime() }
    return [DateTime]::Parse($Value, [Globalization.CultureInfo]::InvariantCulture,
        [Globalization.DateTimeStyles]::AdjustToUniversal -bor [Globalization.DateTimeStyles]::AssumeUniversal)
}

# ---- The month, as a UTC window [start, end) ------------------------------------
if ($Month) {
    $start = [DateTime]::ParseExact($Month, 'yyyy-MM', [Globalization.CultureInfo]::InvariantCulture,
        [Globalization.DateTimeStyles]::AssumeUniversal -bor [Globalization.DateTimeStyles]::AdjustToUniversal)
} else {
    $now = [DateTime]::UtcNow
    $start = New-Object DateTime($now.Year, $now.Month, 1, 0, 0, 0, [DateTimeKind]::Utc)
}
$end = $start.AddMonths(1)
Write-Host "Time logged in $($start.ToString('MMMM yyyy')) (UTC)" -ForegroundColor Cyan

# ---- 1. The tickets that could hold time for the month ---------------------------
# TRAP: logging time does NOT change a ticket's updated_at. So "tickets updated
# this month" would miss an old ticket that only had time logged on it. We look
# back -LookbackDays before the month starts as well - an analyst who logs time
# usually adds a note or reply now and then - or, with -AllTickets, at every
# ticket. Open and closed both count; tickets in the trash are left out.
$query = 'sort=id&per_page=100'
if (-not $AllTickets) {
    $query += '&updated_since=' + $start.AddDays(-$LookbackDays).ToString('yyyy-MM-ddTHH:mm:ssZ')
}
$tickets = New-Object System.Collections.Generic.List[object]
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/tickets?$query&page=$page"
    foreach ($t in @($reply.data)) { $tickets.Add($t) }
    $page++
} while ($page -le $reply.meta.total_pages)
Write-Host "Checking time on $($tickets.Count) ticket(s)..."

# ---- 2 + 3. Each ticket's time entries, totalled per analyst -----------------------
# Each entry looks like:
#   { "id": 12, "minutes": 45, "notes": "Rebuilt profile",
#     "analyst": { "id": 4, "name": "Sam Patel" }, "entry_at": "2026-11-03T10:15:00Z" }
# entry_at is when the work was done (the analyst can back-date it), which is
# what a monthly report wants - not when it was typed in.
$totals = @{}    # analyst name -> running totals
$i = 0
foreach ($t in $tickets) {
    $i++
    Write-Progress -Activity 'Reading time entries' -Status $t.ticket_number -PercentComplete (100 * $i / $tickets.Count)
    foreach ($e in @((Invoke-FreeItsm 'GET' "/tickets/$($t.id)/time-entries").data)) {
        $when = ConvertTo-Utc $e.entry_at
        if ($when -lt $start -or $when -ge $end) { continue }
        # An entry whose analyst has since been removed has analyst = null.
        $who = if ($e.analyst) { $e.analyst.name } else { '(removed analyst)' }
        if (-not $totals.ContainsKey($who)) {
            $totals[$who] = @{ Minutes = 0; Entries = 0; Tickets = New-Object 'System.Collections.Generic.HashSet[int]' }
        }
        $totals[$who].Minutes += $e.minutes
        $totals[$who].Entries++
        [void]$totals[$who].Tickets.Add($t.id)
    }
}
Write-Progress -Activity 'Reading time entries' -Completed

# ---- The report ---------------------------------------------------------------------
if ($totals.Count -eq 0) { Write-Host 'No time logged in that month.'; return }
$rows = foreach ($who in $totals.Keys) {
    $m = $totals[$who].Minutes
    [pscustomobject][ordered]@{
        Analyst = $who
        Hours   = '{0}:{1:00}' -f [Math]::Floor($m / 60), ($m % 60)   # 7:30 = seven and a half hours
        Minutes = $m
        Entries = $totals[$who].Entries
        Tickets = $totals[$who].Tickets.Count
    }
}
# Swap Format-Table for Export-Csv -NoTypeInformation to feed a timesheet or billing system.
$rows | Sort-Object Minutes -Descending | Format-Table -AutoSize | Out-String | Write-Host
$all = ($rows | Measure-Object Minutes -Sum).Sum
Write-Host ('Total: {0}:{1:00} across {2} analyst(s)' -f [Math]::Floor($all / 60), ($all % 60), $totals.Count) -ForegroundColor Green
