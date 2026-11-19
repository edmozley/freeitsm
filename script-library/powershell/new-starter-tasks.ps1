# @title       New starter tasks
# @summary     From one name and start date, creates a new starter task with a checklist of subtasks - accounts, laptop, induction - each due a set number of days before or after the start.
# @module      tasks
# @changes     yes
# @level       intermediate
# @endpoints   GET /tasks, POST /tasks
# @permissions tasks.read, tasks.create
#
# WHAT IT DOES: you give it a person's name and start date. It creates:
#   - one task, "Jane Smith - new starter (2026-12-07)", due on the start date,
#   - under it, one SUBTASK for each line of the $Checklist table below, each due
#     a set number of days before (negative) or after (positive) the start date.
# Subtasks show on the task as a progress count ("3 of 9 done"), so the whole
# onboarding is one card on the Tasks board.
#
# A due date that would land on a Saturday or Sunday is moved back to the Friday
# before, so nothing is due on a day nobody is in.
#
# SAFE TO RUN AGAIN: it looks for the parent task by its exact title first, and
# for each subtask by its title under that parent, and only creates what is
# missing. Add a line to the table, run it again for the same person, and only
# the new subtask appears. (The tasks API has no "client_reference" to
# deduplicate for us, so we look first.)
#
# Run it - first WITHOUT -Apply to see the list and the dates:
#   powershell -ExecutionPolicy Bypass -File .\new-starter-tasks.ps1 -Url https://helpdesk.example.com -Name "Jane Smith" -StartDate 2026-12-07
#   powershell -ExecutionPolicy Bypass -File .\new-starter-tasks.ps1 -Url https://helpdesk.example.com -Name "Jane Smith" -StartDate 2026-12-07 -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The new starter's name, as you want it to appear on the tasks.
    [Parameter(Mandatory = $true)][string]$Name,

    # Their first day, as yyyy-MM-dd.
    [Parameter(Mandatory = $true)][string]$StartDate,

    # Without -Apply the script only prints the tasks and dates it would create.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- YOUR CHECKLIST: edit this table ------------------------------------------------
# Days = when it is due, counted from the start date: -5 is five days before,
#        0 is the first day, 7 is a week after.
# Task = what to do. The person's name is added in front automatically.
# Note = optional detail, saved as the subtask's description.
$Checklist = @(
    @{ Days = -10; Task = 'Order laptop and phone';           Note = 'Standard build unless the manager asked for something else.' }
    @{ Days = -5;  Task = 'Create network account and email'; Note = 'Add to the department groups and distribution lists.' }
    @{ Days = -5;  Task = 'Add to HR and payroll systems';    Note = '' }
    @{ Days = -3;  Task = 'Set up laptop and phone';          Note = 'Install line-of-business apps; enrol in device management.' }
    @{ Days = -2;  Task = 'Order building pass';              Note = '' }
    @{ Days = 0;   Task = 'First-day IT induction';           Note = 'Hand over kit, set the password, enrol MFA, walk through the portal.' }
    @{ Days = 1;   Task = 'Security awareness training';      Note = '' }
    @{ Days = 7;   Task = 'One-week check-in';                Note = 'Anything missing? Access working? Raise tickets for gaps.' }
)

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File], not Get-Content: Get-Content tags the text with hidden properties
    # that make ConvertTo-Json crawl in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- Helpers --------------------------------------------------------------------------
# Sends a request and returns the whole reply ({ "data": ..., "meta": ... }), or
# stops with FreeITSM's own message on failure.
function Invoke-FreeItsmRaw([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so accented names survive Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return Invoke-RestMethod @params }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) { (Invoke-FreeItsmRaw $Method $Path $Body).data }

# Every task on every page of a list (at most 100 a page).
function Get-AllTasks([string]$Query) {
    $all = @(); $page = 1
    do {
        $reply = Invoke-FreeItsmRaw 'GET' "/tasks?$Query&per_page=100&page=$page"
        $all += @($reply.data); $page++
    } while ($page -le $reply.meta.total_pages)
    return $all
}

# ---- Work out the dates ----------------------------------------------------------------
try { $start = [datetime]::ParseExact($StartDate, 'yyyy-MM-dd', $null) }
catch { throw "-StartDate must be yyyy-MM-dd, e.g. 2026-12-07 (got '$StartDate')." }
function Get-DueDate([int]$Days) {
    $d = $start.AddDays($Days)
    # Saturday -> Friday, Sunday -> Friday.
    if ($d.DayOfWeek -eq 'Saturday') { $d = $d.AddDays(-1) }
    if ($d.DayOfWeek -eq 'Sunday')   { $d = $d.AddDays(-2) }
    return $d.ToString('yyyy-MM-dd')
}

$parentTitle = "$Name - new starter ($StartDate)"
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to create the tasks' }
Write-Host "$parentTitle -> $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. The parent task: find it, or create it ------------------------------------------
# ?q= is a "contains" search over title and description, and the list shows
# top-level tasks only (not subtasks) - so we search, then insist on the exact title.
$parent = @(Get-AllTasks "q=$([uri]::EscapeDataString($parentTitle))") | Where-Object { $_.title -eq $parentTitle } | Select-Object -First 1
$existingTitles = @{}
if ($parent) {
    Write-Host "Found task #$($parent.id) - adding only what is missing." -ForegroundColor DarkGray
    # parent_task_id=N lists that task's subtasks instead of top-level tasks.
    foreach ($s in @(Get-AllTasks "parent_task_id=$($parent.id)")) { $existingTitles[$s.title] = $true }
} elseif ($Apply) {
    # New tasks start in your default status (usually To Do) and priority.
    $parent = Invoke-FreeItsm 'POST' '/tasks' @{
        title       = $parentTitle
        description = "Onboarding checklist for $Name, starting $($start.ToString('dddd d MMMM yyyy')). Created by the new-starter-tasks script."
        due_date    = $StartDate
    }
    Write-Host "Created task #$($parent.id)" -ForegroundColor Green
} else {
    Write-Host "Would create task '$parentTitle', due $StartDate" -ForegroundColor Yellow
}

# ---- 2. One subtask per checklist line -------------------------------------------------
$created = 0
foreach ($item in ($Checklist | Sort-Object { $_.Days })) {
    $title = "${Name}: $($item.Task)"
    $due = Get-DueDate $item.Days
    if ($existingTitles.ContainsKey($title)) { Write-Host "  already there  $due  $title" -ForegroundColor DarkGray; continue }
    if (-not $Apply) { Write-Host "  would add      $due  $title" -ForegroundColor Yellow; continue }

    $body = @{
        title          = $title
        # parent_task_id makes it a subtask. It also inherits the parent's company.
        parent_task_id = $parent.id
        due_date       = $due
    }
    if ($item.Note) { $body.description = $item.Note }
    $t = Invoke-FreeItsm 'POST' '/tasks' $body
    Write-Host "  added          $due  $title  (task #$($t.id))" -ForegroundColor Green
    $created++
}
if ($Apply) { Write-Host "`nCreated $created subtask(s)." -ForegroundColor Cyan }
