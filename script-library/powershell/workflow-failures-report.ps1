# @title       Workflow failures report
# @summary     Lists the workflows whose runs failed in the last 7 days, with how often, the failure rate and the latest error - as a table and optionally a CSV file.
# @module      workflows
# @changes     no
# @level       intermediate
# @endpoints   GET /workflows, GET /workflow-executions, GET /workflows/{id}/executions
# @permissions workflows.read, workflow_executions.read
#
# WHAT IT SHOWS: filtering a run log on the server, then grouping on the client.
# A workflow that fails does so quietly - the ticket simply is not assigned, the
# email is not sent - so a weekly look at the run log catches what nobody
# noticed. The script:
#
#   1. Reads every workflow once (GET /workflows), to know each one's trigger,
#      whether it is still switched on, and which company it belongs to.
#   2. Asks the install-wide run log (GET /workflow-executions) for the runs
#      that FAILED in the window, and the runs that were ABORTED (see below).
#   3. Groups them per workflow, and for each one asks how many runs it had in
#      the same window (GET /workflows/{id}/executions), to give a failure rate:
#      "3 of 3 failed" is broken, "3 of 900 failed" is a hiccup.
#   4. Prints the table, most failures first, and writes a CSV if asked.
#
# A run's status is one of: running, success, failed, skipped (its conditions did
# not match - normal), aborted (FreeITSM stopped it before it started: loop
# protection, or the module it needs is switched off). Aborted runs did not do
# their job either, so they are counted unless you pass -FailedOnly.
#
# A run whose workflow has since been DELETED is still in the log (the name is a
# snapshot taken when it ran); it is listed as "(deleted)".
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\workflow-failures-report.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\workflow-failures-report.ps1 -Url https://helpdesk.example.com -Days 30 -OutFile .\workflow-failures.csv
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass -KeyFile). A read-only key is
# enough: workflows.read and workflow_executions.read. A key limited to some
# companies sees only those companies' runs - and runs with no company (a
# settings event, say) need a key that reaches every company.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far back to look, by when the run STARTED.
    [int]$Days = 7,

    # Count only 'failed' runs, not 'aborted' ones.
    [switch]$FailedOnly,

    # Also save the table to this CSV file. Leave it out to only print it.
    [string]$OutFile = ''
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File], not Get-Content: Get-Content tags the text with hidden properties
    # (PSPath, PSDrive...) that cause trouble later in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the whole reply ({ data, meta }) because the run log pages; stops with
# FreeITSM's own error message on failure, e.g. 403 "This key lacks ...".
function Invoke-FreeItsm([string]$Path) {
    try {
        return Invoke-RestMethod -Method GET -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "GET $Path failed: $detail"
    }
}

# Every page of a paged list: { "data": [...], "meta": { "total_pages": 3 } }.
function Get-FreeItsmAll([string]$Path) {
    $all = New-Object System.Collections.Generic.List[object]
    $page = 1
    do {
        $sep = if ($Path.Contains('?')) { '&' } else { '?' }
        $reply = Invoke-FreeItsm "$Path${sep}per_page=100&page=$page"
        foreach ($row in @($reply.data)) { $all.Add($row) }
        $page++
    } while ($page -le $reply.meta.total_pages)
    return $all
}

# ---- 1. Every workflow, by id -------------------------------------------------------
# GET /workflows is NOT paged - it returns every workflow the key can see in one
# reply, most recently updated first - so one call is enough.
$workflows = @{}
foreach ($w in @((Invoke-FreeItsm '/workflows').data)) { $workflows[[int]$w.id] = $w }

# ---- 2. The failed (and aborted) runs in the window ----------------------------------
# started_since takes an ISO 8601 date and time; "Z" = UTC, which is how FreeITSM
# stores every date. status= takes ONE value, so it is one list per status.
$since = [DateTime]::UtcNow.AddDays(-$Days).ToString('yyyy-MM-ddTHH:mm:ssZ')
$statuses = @('failed')
if (-not $FailedOnly) { $statuses += 'aborted' }
$runs = New-Object System.Collections.Generic.List[object]
foreach ($s in $statuses) {
    foreach ($r in (Get-FreeItsmAll "/workflow-executions?status=$s&started_since=$since")) { $runs.Add($r) }
}
Write-Host "$($runs.Count) run(s) did not complete since $since" -ForegroundColor Cyan

# ---- 3. Group per workflow ----------------------------------------------------------
# A run of a deleted workflow has workflow.id = null; those are grouped by the
# name it had when it ran.
$groups = @{}
foreach ($r in $runs) {
    $key = if ($null -ne $r.workflow.id) { "id:$($r.workflow.id)" } else { "name:$($r.workflow.name)" }
    if (-not $groups.ContainsKey($key)) {
        $groups[$key] = [pscustomobject]@{ WorkflowId = $r.workflow.id; Name = $r.workflow.name; Failed = 0; Aborted = 0; Latest = $null }
    }
    $g = $groups[$key]
    if ($r.status -eq 'failed') { $g.Failed++ } else { $g.Aborted++ }
    # started_at is ISO 8601 in UTC, so comparing the text sorts by time.
    if ($null -eq $g.Latest -or $r.started_at -gt $g.Latest.started_at) { $g.Latest = $r }
}

$rows = New-Object System.Collections.Generic.List[object]
foreach ($g in $groups.Values) {
    $wf = $null
    if ($null -ne $g.WorkflowId) { $wf = $workflows[[int]$g.WorkflowId] }
    # How many runs in the same window, of any status? meta.total answers that
    # without fetching them: ask for a page of one and read the count.
    $totalRuns = ''
    if ($null -ne $g.WorkflowId) {
        $totalRuns = (Invoke-FreeItsm "/workflows/$($g.WorkflowId)/executions?started_since=$since&per_page=1").meta.total
    }
    $bad = $g.Failed + $g.Aborted
    $state = if ($null -eq $g.WorkflowId) { '(deleted)' } elseif ($wf -and $wf.is_active) { 'on' } else { 'off' }
    $rows.Add([pscustomobject]@{
        Workflow      = $g.Name
        State         = $state
        Trigger       = if ($wf) { $wf.trigger_event } else { $g.Latest.trigger_event }
        Company       = if ($wf -and $wf.company) { $wf.company.name } elseif ($wf) { 'shared' } else { '' }
        Failed        = $g.Failed
        Aborted       = $g.Aborted
        Runs          = $totalRuns
        FailRate      = if ($totalRuns) { '{0}%' -f [Math]::Round(100 * $bad / $totalRuns) } else { '' }
        LatestAt      = ([datetime]::Parse($g.Latest.started_at)).ToString('yyyy-MM-dd HH:mm')
        LatestRunId   = $g.Latest.id
        LatestError   = [string]$g.Latest.error_message
    })
}

# ---- 4. The report ----------------------------------------------------------------
# Most failures first. Times are in this computer's time zone ([datetime]::Parse
# turns the UTC "Z" time into local time).
$rows = @($rows | Sort-Object @{ Expression = { $_.Failed + $_.Aborted }; Descending = $true }, Workflow)
Write-Host "`nWorkflow runs that did not complete - the last $Days day(s)" -ForegroundColor Yellow
if ($rows.Count -eq 0) {
    Write-Host '  none - every run in the window finished.'
} else {
    # The table shortens long errors; the CSV keeps them whole. Open a run in
    # Workflows -> Run log (or GET /workflow-executions/{id}) for its step log.
    $rows | Select-Object Workflow, State, Trigger, Failed, Aborted, Runs, FailRate, LatestAt,
        @{ Name = 'LatestError'; Expression = { if ($_.LatestError.Length -gt 70) { $_.LatestError.Substring(0, 67) + '...' } else { $_.LatestError } } } |
        Format-Table -AutoSize | Out-String -Width 220 | Write-Host
    $off = @($rows | Where-Object { $_.State -eq 'off' }).Count
    if ($off -gt 0) { Write-Host "  $off of these are switched off now - perhaps already dealt with." }
}
if ($OutFile) {
    # Export-Csv in Windows PowerShell 5.1 writes UTF-8 WITH a byte order mark,
    # which is what Excel needs to show accented names correctly.
    $rows | Export-Csv -Path $OutFile -NoTypeInformation -Encoding UTF8
    Write-Host "Saved to $OutFile" -ForegroundColor Cyan
}
