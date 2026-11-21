# @title       Change freeze clash check
# @summary     Finds open changes whose work or outage window falls inside a change freeze on the calendar, and can leave one comment on each asking for it to be moved.
# @module      changes
# @changes     yes
# @level       intermediate
# @endpoints   GET /calendar-categories, GET /calendar/events, GET /change-statuses, GET /changes, GET /changes/{id}/comments, POST /changes/{id}/comments
# @permissions reference.read, calendar_events.read, changes.read, change_comments.read, change_comments.create
#
# WHY: a change freeze (month end, Christmas, a big launch) is usually agreed in
# a meeting and put in the calendar - and then somebody schedules a change in the
# middle of it anyway, because nothing joins the two up. This script does: it
# reads the freezes from the calendar and every open change's schedule, and lists
# the ones that clash. Run it daily, or before each CAB meeting.
#
# HOW A FREEZE IS RECOGNISED: it is any calendar event in one category - "Change
# freeze" unless you pass -Category. Create that category once (Calendar ->
# Settings), then add each freeze as an event in it. The event's title is used in
# the messages, so call it something like "Year-end freeze".
#
# WHAT COUNTS AS A CLASH: the change's WORK window (work start to work end) or its
# OUTAGE window overlaps the freeze, even by a minute. A change with a work start
# but no end is treated as a moment at its start. Changes with no work start are
# not scheduled yet, so they cannot clash. Closed changes (Completed, Failed,
# Rejected, Cancelled) are left out.
#
# TIMES: both sides are LOCAL wall-clock times with no time zone. Calendar events
# are stored as server-local times and a change's windows exactly as typed, so the
# two are compared as they are - no conversion. An ALL-DAY freeze runs from
# midnight at the start of its first day to midnight at the END of its last day.
#
# WITH -Apply: each clashing change gets ONE comment naming the freeze(s). The
# comment carries a marker such as [freeze-clash event #12]; before writing, the
# script reads the change's comments and skips any freeze already mentioned. So
# it is safe to run every day - a change is told once per freeze, not every day.
# Comments on changes are always internal (never shown to requesters).
#
# Run it - first WITHOUT -Apply to see the clashes:
#   powershell -ExecutionPolicy Bypass -File .\change-freeze-clash-check.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\change-freeze-clash-check.ps1 -Url https://helpdesk.example.com -Days 120 -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# Keep that file out of source control and readable only by you. Comments are
# written as the analyst the key acts as.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com
    # or http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The calendar category that holds your freezes (matched ignoring capitals).
    [string]$Category = 'Change freeze',

    # How far ahead to look for freezes, counting from midnight today. A freeze
    # that has already started and is still running is included.
    [ValidateRange(1, 366)]
    [int]$Days = 90,

    # Without -Apply the script only prints the clashes.
    [switch]$Apply
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

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
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
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

# Every row of a list. Pages hold at most 100 rows (the server's cap) and
# meta.total_pages says when to stop.
function Get-AllPages([string]$Path, [string]$Query) {
    $all = @(); $page = 1
    do {
        $reply = Invoke-FreeItsmRaw 'GET' "$Path`?$Query&per_page=100&page=$page"
        $all += @($reply.data); $page++
    } while ($page -le $reply.meta.total_pages)
    return $all
}

# FreeITSM's local times come in two spellings - "2026-12-20 18:00:00" from the
# calendar and "2026-12-20T18:00:00" from a change. Both have no zone; both become
# a plain [datetime] (Kind Unspecified), so they compare directly.
function ConvertFrom-LocalTime([string]$Text) {
    [datetime]::ParseExact($Text.Replace('T', ' ').Substring(0, 19), 'yyyy-MM-dd HH:mm:ss', [Globalization.CultureInfo]::InvariantCulture)
}

# Two windows clash when each starts before the other ends. A window with no
# length (a change with no end) is a moment: it clashes when it falls inside.
function Test-Overlap([datetime]$AStart, [datetime]$AEnd, [datetime]$BStart, [datetime]$BEnd) {
    if ($AEnd -le $AStart) { return ($BStart -le $AStart -and $AStart -lt $BEnd) }
    return ($AStart -lt $BEnd -and $AEnd -gt $BStart)
}

function Format-When([datetime]$Moment) { $Moment.ToString('ddd dd MMM yyyy HH:mm', [Globalization.CultureInfo]::InvariantCulture) }
function Format-Query([hashtable]$Filters) {
    ($Filters.GetEnumerator() | ForEach-Object { "$($_.Key)=$([uri]::EscapeDataString([string]$_.Value))" }) -join '&'
}

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to comment on the changes' }
Write-Host "Change freeze clash check -> $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. The freeze category -----------------------------------------------------------
# GET /calendar-categories lists every category with its id. We match the name
# ignoring capitals (-eq on strings already does), then filter events by that id.
$categories = @(Invoke-FreeItsm 'GET' '/calendar-categories')
$cat = $categories | Where-Object { $_.name.Trim() -eq $Category.Trim() } | Select-Object -First 1
if (-not $cat) {
    $names = ($categories | ForEach-Object { $_.name }) -join ', '
    if (-not $names) { $names = '(none)' }
    throw "No calendar category called '$Category'. Create it in Calendar -> Settings, or pass -Category with one of: $names"
}

# ---- 2. The freezes in the window -------------------------------------------------------
# GET /calendar/events needs a from + to window, as NAIVE local times (no "Z" -
# the calendar refuses one). It returns every event that overlaps the window, so
# a freeze that began last week and runs into today is included.
$today = (Get-Date).Date
$windowTo = $today.AddDays($Days)
$events = Get-AllPages '/calendar/events' (Format-Query @{
    from        = $today.ToString('yyyy-MM-dd HH:mm:ss')
    to          = $windowTo.ToString('yyyy-MM-dd HH:mm:ss')
    category_id = $cat.id
})
$freezes = @()
foreach ($e in $events) {
    $start = ConvertFrom-LocalTime $e.start_at
    $end = if ($e.end_at) { ConvertFrom-LocalTime $e.end_at } else { $start }
    if ($e.all_day) {
        # An all-day event is stored as midnight on its first and last day. The
        # freeze covers the whole of the last day too, so it ends at the NEXT midnight.
        $start = $start.Date
        $end = $end.Date.AddDays(1)
    }
    if ($end -lt $start) { $end = $start }
    $freezes += [pscustomobject]@{ Id = [int]$e.id; Title = $e.title; Start = $start; End = $end }
}
Write-Host "Freezes in '$($cat.name)' over the next $Days day(s): $($freezes.Count)"
foreach ($f in $freezes) { Write-Host "  #$($f.Id)  $($f.Title): $(Format-When $f.Start) to $(Format-When $f.End)" }
if ($freezes.Count -eq 0) { Write-Host 'Nothing to check.'; return }

# ---- 3. Which change statuses count as finished --------------------------------------------
# A status is finished when it is ticked "closed", or its stage is done or
# stopped. The stage is checked too because a status can be renamed - and on some
# installs Cancelled is not ticked closed - but its stage says what it means.
$finished = @{}
foreach ($s in @(Invoke-FreeItsm 'GET' '/change-statuses')) {
    if ($s.is_closed -or $s.stage -eq 'done' -or $s.stage -eq 'stopped') { $finished[[int]$s.id] = $true }
}

# ---- 4. The scheduled changes ------------------------------------------------------------------
# work_start_to keeps only changes that START before the last freeze ends - one
# starting later cannot clash. There is deliberately no lower limit: a long
# change that started before a freeze can still run into it. Like the times, the
# filter is wall-clock with no "Z".
$lastEnd = ($freezes | Measure-Object -Property End -Maximum).Maximum
$changes = Get-AllPages '/changes' (Format-Query @{ work_start_to = $lastEnd.ToString('yyyy-MM-ddTHH:mm:ss'); sort = 'work_start_at' })

$clashes = @(); $checked = 0
foreach ($c in $changes) {
    $s = $c.schedule
    if (-not $s.work_start_at) { continue }
    if ($c.status -and $finished.ContainsKey([int]$c.status.id)) { continue }
    $checked++
    $windows = @()
    $ws = ConvertFrom-LocalTime $s.work_start_at
    $we = if ($s.work_end_at) { ConvertFrom-LocalTime $s.work_end_at } else { $ws }
    $windows += [pscustomobject]@{ Kind = 'work'; Start = $ws; End = $we }
    if ($s.outage_start_at) {
        $os = ConvertFrom-LocalTime $s.outage_start_at
        $oe = if ($s.outage_end_at) { ConvertFrom-LocalTime $s.outage_end_at } else { $os }
        $windows += [pscustomobject]@{ Kind = 'outage'; Start = $os; End = $oe }
    }
    $hit = @()
    foreach ($f in $freezes) {
        foreach ($w in $windows) {
            if (Test-Overlap $w.Start $w.End $f.Start $f.End) {
                $hit += [pscustomobject]@{ Freeze = $f; Kind = $w.Kind; Start = $w.Start; End = $w.End }
                break   # one reason per freeze is enough
            }
        }
    }
    if ($hit.Count -gt 0) { $clashes += [pscustomobject]@{ Change = $c; Hits = $hit } }
}

Write-Host "`nOpen scheduled changes checked: $checked; clashing: $($clashes.Count)`n"
foreach ($x in $clashes) {
    $status = if ($x.Change.status) { $x.Change.status.name } else { 'no status' }
    Write-Host "  $($x.Change.change_number)  $($x.Change.title)  ($status)" -ForegroundColor Yellow
    foreach ($h in $x.Hits) {
        Write-Host "      $($h.Kind) $(Format-When $h.Start) to $(Format-When $h.End)  clashes with  #$($h.Freeze.Id) $($h.Freeze.Title)"
    }
}

# ---- 5. With -Apply: one comment per change, once per freeze -------------------------------------
# The marker in each comment is how the script recognises its own earlier
# comment. GET /changes/{id}/comments returns every comment (newest first, no
# paging), so one call per clashing change is enough.
if (-not $Apply) {
    if ($clashes.Count -gt 0) { Write-Host "`nPreview only - add -Apply to comment on these changes." -ForegroundColor Cyan }
    return
}

$written = 0; $skipped = 0
foreach ($x in $clashes) {
    $c = $x.Change
    $existing = (@(Invoke-FreeItsm 'GET' "/changes/$($c.id)/comments") | ForEach-Object { $_.text }) -join ' '
    $new = @($x.Hits | Where-Object { -not $existing.Contains("[freeze-clash event #$($_.Freeze.Id)]") })
    if ($new.Count -eq 0) {
        Write-Host "  skip  $($c.change_number)  (already told about this freeze)" -ForegroundColor DarkGray
        $skipped++
        continue
    }
    $lines = @('This change is scheduled inside a change freeze:')
    foreach ($h in $new) {
        $lines += "- its $($h.Kind) window ($(Format-When $h.Start) to $(Format-When $h.End)) overlaps '$($h.Freeze.Title)' ($(Format-When $h.Freeze.Start) to $(Format-When $h.Freeze.End))."
    }
    $lines += 'Please reschedule it, or record the agreed exception here.'
    $lines += (($new | ForEach-Object { "[freeze-clash event #$($_.Freeze.Id)]" }) -join ' ')
    [void](Invoke-FreeItsm 'POST' "/changes/$($c.id)/comments" @{ text = ($lines -join "`n") })
    Write-Host "  commented  $($c.change_number)  $($c.title)" -ForegroundColor Green
    $written++
}
Write-Host "`nCommented on $written change(s); skipped $skipped already told." -ForegroundColor Cyan
