# @title       Monday morning briefing
# @summary     Writes one self-contained HTML page for the start of the week: open tickets by priority with those breaching or close to breaching SLA, changes in the next 7 days, open service incidents, this morning's checks, background job health and contracts ending soon.
# @module      tickets
# @changes     no
# @level       advanced
# @endpoints   GET /tickets, GET /tickets/{id}/sla, GET /changes, GET /service-status/incidents, GET /morning-checks/board, GET /jobs/health, GET /contracts
# @permissions tickets.read, ticket_sla.read, changes.read, service_incidents.read, morning_check_results.read, job_health.read, contracts.read
#
# WHAT IT SHOWS: one report from seven modules. Each section is one or two
# calls, and the script turns the answers into a single HTML file you can open,
# email or put on a shared drive - no internet needed to view it (no external
# styles, scripts or fonts). Every value from FreeITSM is HTML-escaped before it
# goes into the page, so a ticket subject like "<script>" shows as text.
#
#   1. Open tickets       GET /tickets?state=open (paged), counted by priority.
#   2. SLA                GET /tickets/{id}/sla for open tickets that HAVE a
#                         priority (no priority = no SLA), oldest first, at most
#                         -MaxSlaChecks of them - one call each, and every call
#                         counts towards the key's rate limit.
#   3. Changes            GET /changes?state=open with work starting in the next
#                         -ChangeDays days.
#   4. Service incidents  GET /service-status/incidents?state=open.
#   5. Morning checks     GET /morning-checks/board - today's checks and results.
#   6. Background jobs    GET /jobs/health - SLA emails, webhooks, mail import...
#   7. Contracts          GET /contracts?expiring_within_days=-ContractDays.
#
# "At risk" means a target that is not yet met or missed but has used
# -AtRiskPercent of its time or more (80 by default - the same as FreeITSM's own
# default warning threshold in Tickets -> Settings -> SLA). SLA minutes are
# WORKING minutes on the ticket's SLA calendar, and paused statuses ("On Hold")
# stop the clock - FreeITSM works all of that out; the script only reads it.
#
# Run it - every Monday at 07:30 from Task Scheduler, say:
#   powershell -ExecutionPolicy Bypass -File .\monday-briefing.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\monday-briefing.ps1 -Url https://helpdesk.example.com -OutFile \\server\share\briefing.html
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass -KeyFile). A read-only key is
# enough, with the seven permissions above. Job health is install-wide, so it
# also needs a key (and an analyst) that reaches every company - an
# administrator's key, in practice. Everything else shows only what the key's
# companies may see.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Where to write the page. Defaults to monday-briefing-<date>.html here.
    [string]$OutFile = '',

    # Check the SLA of at most this many open tickets (oldest first).
    [int]$MaxSlaChecks = 200,

    # A target this far used (percent) or more counts as "at risk".
    [int]$AtRiskPercent = 80,

    # Changes whose work starts within this many days from today.
    [int]$ChangeDays = 7,

    # Contracts ending within this many days from today.
    [int]$ContractDays = 60
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }
if (-not $OutFile) { $OutFile = 'monday-briefing-' + (Get-Date).ToString('yyyy-MM-dd') + '.html' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File], not Get-Content: Get-Content tags the text with hidden properties
    # (PSPath, PSDrive...) that cause trouble later in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$AppUrl = $Url.TrimEnd('/') + '/'
$Base = $AppUrl + 'api/v1'

# ---- Calling the API ------------------------------------------------------------
# Returns the whole reply ({ data, meta }); stops with FreeITSM's own error
# message on failure, e.g. 403 "This key lacks changes.read".
function Invoke-FreeItsm([string]$Path) {
    try {
        return Invoke-RestMethod -Method GET -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "GET $Path failed: $detail"
    }
}

# Every page of a paged list: { "data": [...], "meta": { "total_pages": 3 } }.
# 100 is the most a page can hold.
function Get-FreeItsmAll([string]$Path) {
    $all = New-Object System.Collections.Generic.List[object]
    $page = 1
    do {
        $sep = if ($Path.Contains('?')) { '&' } else { '?' }
        $reply = Invoke-FreeItsm "$Path${sep}per_page=100&page=$page"
        foreach ($row in @($reply.data)) { $all.Add($row) }
        $page++
    } while ($page -le $reply.meta.total_pages)
    return , $all
}

# ---- Small helpers for the page ---------------------------------------------------
# E: escape any text for HTML. Every value from the API goes through it.
function E($text) { [System.Net.WebUtility]::HtmlEncode([string]$text) }
# A colour from FreeITSM goes into a style attribute, so only a real #hex colour
# is used; anything else falls back to grey.
function Safe-Colour($c) { if ([string]$c -match '^#[0-9A-Fa-f]{3,8}$') { $c } else { '#9ca3af' } }
# 135 working minutes -> "2h 15m"; negative = over by that much.
function Format-Minutes($m) {
    $m = [int]$m
    $sign = if ($m -lt 0) { '-' } else { '' }
    $m = [Math]::Abs($m)
    if ($m -ge 60) { return '{0}{1}h {2}m' -f $sign, [Math]::Floor($m / 60), ($m % 60) }
    return "$sign${m}m"
}
# An ISO date and time in UTC ("...Z") -> this computer's local time.
function Format-When($iso) {
    if (-not $iso) { return '' }
    return ([datetime]::Parse([string]$iso)).ToString('ddd d MMM HH:mm')
}
function Name-Of($ref, [string]$empty = '') { if ($ref) { $ref.name } else { $empty } }
# A <table> from a list of rows: $columns are the headings, $cells a script
# block that turns one row into an array of ALREADY-ESCAPED cell HTML.
function Html-Table($rows, [string[]]$columns, [scriptblock]$cells, [string]$empty) {
    # Measure-Object, not @($rows).Count: @() on a generic List throws
    # "Argument types do not match" in Windows PowerShell 5.1.
    if (($rows | Measure-Object).Count -eq 0) { return "<p class=""none"">$(E $empty)</p>" }
    $sb = New-Object System.Text.StringBuilder
    [void]$sb.Append('<table><thead><tr>')
    foreach ($c in $columns) { [void]$sb.Append("<th>$(E $c)</th>") }
    [void]$sb.Append('</tr></thead><tbody>')
    foreach ($r in $rows) {
        [void]$sb.Append('<tr>')
        foreach ($cell in (& $cells $r)) { [void]$sb.Append("<td>$cell</td>") }
        [void]$sb.Append('</tr>')
    }
    [void]$sb.Append('</tbody></table>')
    return $sb.ToString()
}

# ---- 1. Open tickets, by priority ---------------------------------------------------
# sort=id gives oldest first and keeps the pages steady while tickets arrive.
Write-Host 'Reading open tickets...' -ForegroundColor Cyan
$open = Get-FreeItsmAll '/tickets?state=open&sort=id'
$byPriority = @($open | Group-Object { Name-Of $_.priority '(no priority)' } | Sort-Object Count -Descending)

# ---- 2. SLA for the open tickets ------------------------------------------------------
# GET /tickets/{id}/sla answers { enabled, reason_disabled, response, resolution };
# each target is { target_minutes, elapsed_minutes, remaining_minutes, percent,
# breached, achieved_at } or null when the priority has no such target. A target
# with achieved_at set is finished (responded to / resolved): it can still have
# been breached, but it can no longer be "at risk".
$candidates = @($open | Where-Object { $_.priority })
$toCheck = @($candidates | Select-Object -First $MaxSlaChecks)
$breached = New-Object System.Collections.Generic.List[object]
$atRisk = New-Object System.Collections.Generic.List[object]
$notMeasured = @{}       # reason -> how many, e.g. "Priority has no SLA target configured"
$i = 0
foreach ($t in $toCheck) {
    $i++
    Write-Progress -Activity 'Checking SLAs' -Status $t.ticket_number -PercentComplete (100 * $i / $toCheck.Count)
    $sla = (Invoke-FreeItsm "/tickets/$($t.id)/sla").data
    if (-not $sla.enabled) {
        $reason = if ($sla.reason_disabled) { $sla.reason_disabled } else { 'not measured' }
        $notMeasured[$reason] = 1 + [int]$notMeasured[$reason]
        continue
    }
    $missed = @(); $close = @()
    foreach ($kind in 'response', 'resolution') {
        $target = $sla.$kind
        if (-not $target) { continue }
        if ($target.breached) {
            $missed += "$kind over by $(Format-Minutes (-$target.remaining_minutes))"
        } elseif (-not $target.achieved_at -and $target.percent -ge $AtRiskPercent) {
            $close += "$kind $(Format-Minutes $target.remaining_minutes) left ($([Math]::Round($target.percent))%)"
        }
    }
    # A ticket with a missed target goes in the breached list only, but its
    # detail still says if the other target is close.
    $row = [pscustomobject]@{ Ticket = $t; Detail = (@($missed) + @($close)) -join ', ' }
    if ($missed.Count -gt 0) { $breached.Add($row) }
    elseif ($close.Count -gt 0) { $atRisk.Add($row) }
}
Write-Progress -Activity 'Checking SLAs' -Completed
$unchecked = $candidates.Count - $toCheck.Count

# ---- 3. Changes starting in the next -ChangeDays days ---------------------------------
# work_start_from / work_start_to are WALL-CLOCK times - the time as it shows on
# the change, no "Z" - so plain dates are right here. _to is "before", so the
# day after the window ends.
$today = (Get-Date).Date
$from = $today.ToString('yyyy-MM-dd')
$to = $today.AddDays($ChangeDays + 1).ToString('yyyy-MM-dd')
Write-Host 'Reading changes, incidents, checks, jobs and contracts...' -ForegroundColor Cyan
$changes = Get-FreeItsmAll "/changes?state=open&work_start_from=$from&work_start_to=$to&sort=work_start_at"

# ---- 4. Open service incidents -------------------------------------------------------
$incidents = Get-FreeItsmAll '/service-status/incidents?state=open'

# ---- 5. Today's morning checks --------------------------------------------------------
# One reply, not paged: { date, checks: [ { name, due, result: { status: { label,
# colour }, notes } or null } ] }. A check that is not due today is still listed;
# the briefing shows the due ones and any that were recorded anyway.
$board = (Invoke-FreeItsm '/morning-checks/board').data
$checks = @($board.checks | Where-Object { $_.due -or $_.result })
$checksNotDone = @($checks | Where-Object { -not $_.result }).Count

# ---- 6. Background job health ----------------------------------------------------------
# status per job: healthy, never_seen, overdue, failing, partial, stuck, not_needed
# or not_used (and unknown). The last two mean "this install does not use it",
# so they are left out.
$jobs = (Invoke-FreeItsm '/jobs/health').data
$jobRows = @($jobs.jobs | Where-Object { $_.status -ne 'healthy' -and $_.status -ne 'not_needed' -and $_.status -ne 'not_used' })

# ---- 7. Contracts ending soon ----------------------------------------------------------
# FreeITSM works the window out against today in its own time zone, and only
# counts ACTIVE contracts.
$contracts = Get-FreeItsmAll "/contracts?expiring_within_days=$ContractDays&sort=contract_end"

# ---- Build the page ------------------------------------------------------------------
function Ticket-Link($t) { "<a href=""$(E ($AppUrl + 'tickets/?ticket_id=' + $t.id))"">$(E $t.ticket_number)</a>" }
$tiles = @(
    @('Open tickets', $open.Count, ''),
    @('SLA breached', $breached.Count, $(if ($breached.Count) { 'bad' } else { '' })),
    @('SLA at risk', $atRisk.Count, $(if ($atRisk.Count) { 'warn' } else { '' })),
    @("Changes, next $ChangeDays days", $changes.Count, ''),
    @('Open incidents', $incidents.Count, $(if ($incidents.Count) { 'bad' } else { '' })),
    @('Checks not done', $checksNotDone, $(if ($checksNotDone) { 'warn' } else { '' })),
    @('Jobs needing attention', $jobRows.Count, $(if ($jobRows.Count) { 'bad' } else { '' })),
    @("Contracts ending, $ContractDays days", $contracts.Count, '')
)
$html = New-Object System.Text.StringBuilder
[void]$html.Append(@"
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monday briefing - $(E $today.ToString('d MMMM yyyy'))</title>
<style>
body { font-family: Segoe UI, Arial, sans-serif; color: #1f2937; background: #f3f4f6; margin: 0; padding: 16px; }
main { max-width: 1100px; margin: 0 auto; }
h1 { font-size: 22px; margin: 0 0 4px; } h2 { font-size: 17px; margin: 0 0 10px; }
.sub { color: #6b7280; margin: 0 0 16px; }
section { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; margin: 0 0 14px; overflow-x: auto; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 0 0 14px; }
.tile { background: #fff; border: 1px solid #e5e7eb; border-left: 4px solid #9ca3af; border-radius: 8px; padding: 10px 12px; }
.tile b { display: block; font-size: 24px; } .tile span { color: #6b7280; font-size: 13px; }
.tile.bad { border-left-color: #dc2626; } .tile.warn { border-left-color: #f59e0b; }
table { border-collapse: collapse; width: 100%; font-size: 14px; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
th { color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; }
.none { color: #6b7280; margin: 0; } .note { color: #6b7280; font-size: 13px; margin: 8px 0 0; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
h3 { font-size: 14px; margin: 14px 0 6px; } a { color: #2563eb; }
</style></head><body><main>
<h1>Monday briefing</h1>
<p class="sub">$(E (Get-Date).ToString('dddd d MMMM yyyy, HH:mm')) - $(E $AppUrl)</p>
<div class="tiles">
"@)
foreach ($tile in $tiles) {
    [void]$html.Append("<div class=""tile $($tile[2])""><b>$(E $tile[1])</b><span>$(E $tile[0])</span></div>")
}
[void]$html.Append('</div>')

# Tickets and SLA
[void]$html.Append('<section><h2>Open tickets</h2>')
[void]$html.Append((Html-Table $byPriority @('Priority', 'Open') { param($g) @((E $g.Name), (E $g.Count)) } 'No open tickets.'))
$slaCols = @('Ticket', 'Priority', 'Analyst', 'SLA', 'Subject')
$slaCells = { param($r) @((Ticket-Link $r.Ticket), (E (Name-Of $r.Ticket.priority)), (E (Name-Of $r.Ticket.assigned_analyst '(unassigned)')), (E $r.Detail), (E $r.Ticket.subject)) }
[void]$html.Append("<h3>SLA breached ($($breached.Count))</h3>")
[void]$html.Append((Html-Table $breached $slaCols $slaCells 'None.'))
[void]$html.Append("<h3>SLA at risk - $AtRiskPercent% or more of the time used ($($atRisk.Count))</h3>")
[void]$html.Append((Html-Table $atRisk $slaCols $slaCells 'None.'))
$note = "Checked the SLA of $($toCheck.Count) open ticket(s) with a priority."
foreach ($r in $notMeasured.GetEnumerator()) { $note += " Not measured: $($r.Value) - $($r.Key)." }
if ($unchecked -gt 0) { $note += " $unchecked newer ticket(s) not checked - raise -MaxSlaChecks to include them." }
[void]$html.Append("<p class=""note"">$(E $note) Times are working time on each ticket's SLA calendar.</p></section>")

# Changes
[void]$html.Append("<section><h2>Changes - work starting in the next $ChangeDays days</h2>")
[void]$html.Append((Html-Table $changes @('Change', 'Starts', 'Ends', 'Title', 'Status', 'Risk', 'Assigned to') {
    param($c)
    # schedule times are wall-clock ("2026-11-24T19:00:00", no Z): shown as they are.
    $start = if ($c.schedule.work_start_at) { ([string]$c.schedule.work_start_at).Replace('T', ' ').Substring(0, 16) } else { '' }
    $end = if ($c.schedule.work_end_at) { ([string]$c.schedule.work_end_at).Replace('T', ' ').Substring(0, 16) } else { '' }
    @("<a href=""$(E ($AppUrl + 'change-management/?change_id=' + $c.id))"">$(E $c.change_number)</a>", (E $start), (E $end),
      (E $c.title), (E (Name-Of $c.status)), (E $c.risk.level), (E (Name-Of $c.assigned_to)))
} 'No changes scheduled.'))
[void]$html.Append('</section>')

# Service incidents
[void]$html.Append('<section><h2>Open service incidents</h2>')
[void]$html.Append((Html-Table $incidents @('Incident', 'Status', 'Services affected', 'Since', 'Latest comment') {
    param($s)
    $svc = (@($s.services) | ForEach-Object { if ($_.impact) { "$($_.name) ($($_.impact.name))" } else { $_.name } }) -join ', '
    $st = if ($s.status) { "<span class=""dot"" style=""background:$(Safe-Colour $s.status.colour)""></span>$(E $s.status.name)" } else { '' }
    @((E $s.title), $st, (E $svc), (E (Format-When $s.created_at)), (E $s.comment))
} 'No open incidents.'))
[void]$html.Append('</section>')

# Morning checks
[void]$html.Append("<section><h2>Morning checks - $(E $board.date)</h2>")
[void]$html.Append((Html-Table $checks @('Check', 'Result', 'Notes') {
    param($c)
    $res = '<em>not done yet</em>'
    if ($c.result -and $c.result.status) { $res = "<span class=""dot"" style=""background:$(Safe-Colour $c.result.status.colour)""></span>$(E $c.result.status.label)" }
    elseif ($c.result) { $res = E $c.result.orphan_label }
    $notes = if ($c.result) { E $c.result.notes } else { '' }
    @((E $c.name), $res, $notes)
} 'No checks due today.'))
[void]$html.Append('</section>')

# Jobs
[void]$html.Append("<section><h2>Background jobs - $(E $jobs.status)</h2>")
[void]$html.Append((Html-Table $jobRows @('Job', 'Status', 'Affects', 'Last scheduled success', 'Detail') {
    param($j)
    $detail = (@($j.problems) | ForEach-Object { if ($_.message) { $_.message } else { $_.code } }) -join '; '
    @((E $j.name), (E $j.status), (E $j.affects), (E (Format-When $j.last_scheduled_success_at)), (E $detail))
} 'Every job in use is healthy.'))
[void]$html.Append('</section>')

# Contracts
[void]$html.Append("<section><h2>Contracts ending in the next $ContractDays days</h2>")
[void]$html.Append((Html-Table $contracts @('Contract', 'Ends', 'Title', 'With', 'Owner') {
    param($c)
    $party = if ($c.supplier) { $c.supplier.name } elseif ($c.customer -and $c.customer.company) { $c.customer.company.name } elseif ($c.customer -and $c.customer.person) { $c.customer.person.name } else { '' }
    @((E $c.contract_number), (E $c.dates.end), (E $c.title), (E $party), (E (Name-Of $c.owner)))
} 'No contracts ending.'))
[void]$html.Append('</section>')

[void]$html.Append('<p class="note">Made by the FreeITSM monday-briefing script. Read-only: nothing was changed.</p></main></body></html>')

# UTF-8 without a byte order mark; <meta charset="utf-8"> tells the browser.
# The path is resolved against PowerShell's current folder - .NET's own idea of
# the current folder can differ in Windows PowerShell 5.1.
$path = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutFile)
[IO.File]::WriteAllText($path, $html.ToString(), (New-Object Text.UTF8Encoding $false))
Write-Host "Briefing saved to $path" -ForegroundColor Green
Write-Host ("  {0} open ticket(s), {1} breached, {2} at risk; {3} change(s); {4} incident(s); {5} check(s) not done; {6} job(s) to look at; {7} contract(s) ending" -f `
    $open.Count, $breached.Count, $atRisk.Count, $changes.Count, $incidents.Count, $checksNotDone, $jobRows.Count, $contracts.Count)
