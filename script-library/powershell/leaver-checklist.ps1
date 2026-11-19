# @title       Leaver checklist
# @summary     For someone leaving, finds them by email, lists the equipment they hold and their open tickets, and creates a task to collect the kit.
# @module      people
# @changes     yes
# @level       intermediate
# @endpoints   GET /users, GET /assets, GET /tickets, GET /tasks, POST /tasks
# @permissions users.read, assets.read, tickets.read, tasks.read, tasks.create
#
# WHAT IT DOES: one job that crosses four modules, the way a real leaver does.
#   1. People   - find the person by email address.
#   2. Assets   - every asset assigned to them (laptop, phone, monitor...).
#   3. Tickets  - their tickets that are still open, so nothing is left hanging.
#   4. Tasks    - one task, "Leaver: collect equipment from <email>", with the
#                 asset list and the open tickets written into it, due a few days
#                 from now, so someone owns getting the kit back.
#
# SAFE TO RUN AGAIN: before creating the task it looks for an OPEN task with the
# same title, and if there is one it says so instead of making a second.
#
# Run it - first WITHOUT -Apply to see the checklist without creating the task:
#   powershell -ExecutionPolicy Bypass -File .\leaver-checklist.ps1 -Url https://helpdesk.example.com -Email jo.bloggs@example.com
#   powershell -ExecutionPolicy Bypass -File .\leaver-checklist.ps1 -Url https://helpdesk.example.com -Email jo.bloggs@example.com -Apply -AssignTo 3
#
# Ideas for your own version: mark the person as left afterwards (PATCH /users/{id}
# with is_active false - add users.update to the key), add a note to each open
# ticket, or create one task per asset instead of one for all of them.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# It needs the permissions in the @permissions line above.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The leaver's email address, as held in FreeITSM.
    [Parameter(Mandatory = $true)][string]$Email,

    # How many days from today the collection task is due.
    [int]$DueInDays = 5,

    # Optional: the id of the analyst who should own the task (see System ->
    # Analysts). Left out, the task is unassigned and shows in the task queue.
    [int]$AssignTo = 0,

    # Without -Apply the script only reads and prints the checklist.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content: Get-Content tags the text with hidden
    # properties that make ConvertTo-Json crawl in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- Helpers ----------------------------------------------------------------------
# Returns the WHOLE reply ({ "data": ..., "meta": ... }) - Get-All needs "meta"
# for paging. Stops with FreeITSM's own error message on failure.
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # UTF-8 bytes so accented names survive Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params) }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# Every page of a list: asks for 100 at a time (the most FreeITSM allows) and
# follows meta.total_pages, so someone with 150 open tickets still gets them all.
function Get-All([string]$Path) {
    $items = @(); $page = 1
    do {
        $reply = Invoke-FreeItsm 'GET' "$Path&per_page=100&page=$page"
        $items += @($reply.data)
        $page++
    } while ($page -le $reply.meta.total_pages)
    return , $items   # the comma keeps a one-item list a list
}

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - no task will be created; add -Apply to create it' }
Write-Host "Leaver checklist for $Email -> $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. The person ---------------------------------------------------------------------
# ?email= is an exact match on the stored (lower-case) address.
$people = @((Invoke-FreeItsm 'GET' ('/users?email=' + [uri]::EscapeDataString($Email.Trim().ToLower()))).data)
if ($people.Count -eq 0) { throw "Nobody in FreeITSM has the address $Email." }
$person = $people[0]
$name = if ($person.display_name) { $person.display_name } else { $person.email }
Write-Host "`n1. Person: $name (#$($person.id))$(if (-not $person.is_active) { '  - already marked as left' })"

# ---- 2. Their equipment -----------------------------------------------------------------
# assigned_user_id lists the assets this person currently holds. Retired assets
# are left out (the API's default), since nobody needs to collect those.
$assets = Get-All "/assets?assigned_user_id=$($person.id)&sort=hostname"
Write-Host "`n2. Equipment they hold: $($assets.Count)" -ForegroundColor Yellow
$assetLines = @()
foreach ($a in $assets) {
    $what = (@($a.type.name, $a.hardware.manufacturer, $a.hardware.model) | Where-Object { $_ }) -join ' '
    $serial = if ($a.hardware.service_tag) { "serial $($a.hardware.service_tag)" } else { 'no serial' }
    $line = "$($a.hostname) - $what ($serial)"
    $assetLines += $line
    Write-Host "   $line"
}

# ---- 3. Their open tickets -------------------------------------------------------------------
# state=open means "in a status that is not closed". user_id is the requester.
$tickets = Get-All "/tickets?user_id=$($person.id)&state=open&sort=created_at"
Write-Host "`n3. Open tickets they raised: $($tickets.Count)" -ForegroundColor Yellow
$ticketLines = @()
foreach ($t in $tickets) {
    $who = if ($t.assigned_analyst) { $t.assigned_analyst.name } else { 'unassigned' }
    $line = "$($t.ticket_number) - $($t.subject) [$($t.status.name), $who]"
    $ticketLines += $line
    Write-Host "   $line"
}

# ---- 4. The collection task -----------------------------------------------------------------
$title = "Leaver: collect equipment from $($person.email)"

# Look before creating. ?q= searches titles with SQL LIKE, where "_" in an address
# matches any character - so we also compare the title exactly ourselves.
$existing = @((Get-All ('/tasks?state=open&q=' + [uri]::EscapeDataString($title))) | Where-Object { $_.title -eq $title })
if ($existing.Count -gt 0) {
    Write-Host "`n4. Task already open: #$($existing[0].id) '$title' - not creating another." -ForegroundColor Green
    return
}

# A plain-text description: what to collect and what to tidy up. Whoever picks
# the task up has everything in one place without opening the person's record.
$description = @(
    "$name ($($person.email)) is leaving."
    ''
    "Equipment to collect ($($assets.Count)):"
    $(if ($assetLines) { $assetLines | ForEach-Object { "- $_" } } else { '- none assigned in FreeITSM - check with their manager' })
    ''
    "Open tickets to close or hand over ($($tickets.Count)):"
    $(if ($ticketLines) { $ticketLines | ForEach-Object { "- $_" } } else { '- none' })
) -join "`n"
$due = (Get-Date).AddDays($DueInDays).ToString('yyyy-MM-dd')   # the API wants YYYY-MM-DD

$body = [ordered]@{ title = $title; description = $description; due_date = $due }
if ($AssignTo -gt 0) { $body.assigned_analyst_id = $AssignTo }

if (-not $Apply) {
    Write-Host "`n4. Would create task '$title', due ${due}:" -ForegroundColor Yellow
    Write-Host ($description -replace '(?m)^', '   ')
    return
}
# The task gets the company's default status and priority. Add "priority" or
# "status" (by name) to $body to choose others.
$task = (Invoke-FreeItsm 'POST' '/tasks' $body).data
Write-Host "`n4. Created task #$($task.id) '$title', due $due." -ForegroundColor Green
