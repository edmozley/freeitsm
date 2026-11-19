# @title       Link matching tickets to a problem
# @summary     Finds the tickets whose subject or number contains a keyword and links each one to a problem, skipping any already linked.
# @module      problems
# @changes     yes
# @level       intermediate
# @endpoints   GET /problems/{id}, GET /tickets, POST /problems/{id}/tickets
# @permissions problems.read, tickets.read, problem_links.create
#
# WHY: when you open a problem ("VPN drops every afternoon") the evidence is the
# pile of incidents behind it. Linking them by hand, one ticket at a time, is
# slow; this does the search and the linking in one go, and the problem then
# shows every affected ticket and how many there are.
#
# HOW IT WORKS - search first, act second:
#   1. Read the problem, including the tickets already linked to it.
#   2. Search tickets for the keyword. The search looks at the SUBJECT and the
#      ticket NUMBER only - not the body - so pick a word people put in subjects.
#   3. Print what it would link. Nothing is written until you add -Apply.
#   4. With -Apply, link each new match. Ones already linked are skipped, so it
#      is safe to run again tomorrow to pick up new tickets.
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\link-tickets-to-problem.ps1 -Url https://helpdesk.example.com -ProblemId 12 -Keyword VPN
#   powershell -ExecutionPolicy Bypass -File .\link-tickets-to-problem.ps1 -Url https://helpdesk.example.com -ProblemId 12 -Keyword VPN -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# Keep that file out of source control and readable only by you. Each link is
# recorded in the problem's history as the analyst the key acts as.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com
    # or http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,

    # Where the key is kept if FREEITSM_API_KEY is not set.
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The problem's id - the number in its address, not the PRB-... reference.
    [Parameter(Mandatory = $true)] [int]$ProblemId,

    # The text to look for. "VPN" also matches "VPNs" and "OpenVPN": it is a
    # "contains" search, upper or lower case alike.
    [Parameter(Mandatory = $true)] [string]$Keyword,

    # open, closed or all. A problem is about the pattern, so past (closed)
    # incidents count as evidence too - hence "all". Use open to link only live ones.
    [ValidateSet('open', 'closed', 'all')]
    [string]$State = 'all',

    # A safety net: a keyword like "e" matches everything. If more tickets match
    # than this, the script stops and asks you to narrow it (or raise -Max).
    [int]$Max = 50,

    # Without -Apply the script only searches and prints the plan.
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

# ---- One helper for every call -------------------------------------------------
# Sends a request and returns FreeITSM's WHOLE reply ({ "data": ..., "meta": ... });
# on failure it stops with FreeITSM's own message
# ({ "error": { "code": "...", "message": "..." } }) - for example 422 when a
# ticket belongs to a different company than the problem.
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
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

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to link' }

# ---- 1. The problem, and what is already linked ----------------------------------
# GET /problems/{id} includes linked_tickets. We keep their ids in a set so the
# check in step 3 is instant, however many there are.
$problem = (Invoke-FreeItsm 'GET' "/problems/$ProblemId").data
$linked = @{}
foreach ($t in @($problem.linked_tickets)) { $linked[[int]$t.id] = $true }
Write-Host "$($problem.problem_number) $($problem.title)   [$mode]" -ForegroundColor Cyan
Write-Host "Already linked: $($linked.Count) ticket(s)"
if ($problem.status.is_closed) {
    Write-Host 'Note: this problem is closed. Links can still be added, but check it is the right one.' -ForegroundColor DarkYellow
}

# ---- 2. Search the tickets -------------------------------------------------------
# q= matches the subject or ticket number. Results come a page at a time (at most
# 100 per page, the server's cap); meta.total tells us the full count on the
# first page, so we can apply the -Max safety net before fetching the rest.
$query = "q=" + [uri]::EscapeDataString($Keyword) + "&state=$State&sort=created_at&per_page=100"
$first = Invoke-FreeItsm 'GET' "/tickets?$query&page=1"
if ($first.meta.total -gt $Max) {
    throw "$($first.meta.total) tickets match '$Keyword' - more than -Max $Max. Use a more specific keyword, or raise -Max if you mean it."
}
$tickets = @($first.data)
for ($page = 2; $page -le $first.meta.total_pages; $page++) {
    $tickets += @((Invoke-FreeItsm 'GET' "/tickets?$query&page=$page").data)
}
Write-Host "Tickets matching '$Keyword' ($State): $($tickets.Count)`n"

# ---- 3. Link the new ones --------------------------------------------------------
$done = 0; $skipped = 0
foreach ($t in $tickets) {
    $label = "$($t.ticket_number)  $($t.subject)"
    if ($linked.ContainsKey([int]$t.id)) {
        Write-Host "  skip  $label  (already linked)" -ForegroundColor DarkGray
        $skipped++
        continue
    }
    if (-not $Apply) {
        Write-Host "  would link  $label"
        $done++
        continue
    }
    # The body is just { "ticket_id": n }. If somebody linked the same ticket in
    # the seconds since step 1, FreeITSM answers 409 "conflict" rather than making
    # a second link - that is not a failure for us, so we treat it as a skip.
    try {
        [void](Invoke-FreeItsm 'POST' "/problems/$ProblemId/tickets" @{ ticket_id = [int]$t.id })
        Write-Host "  linked  $label" -ForegroundColor Green
        $done++
    } catch {
        if ("$_" -match '"code"\s*:\s*"conflict"') {
            Write-Host "  skip  $label  (linked meanwhile)" -ForegroundColor DarkGray
            $skipped++
        } else { throw }
    }
}

$verb = if ($Apply) { 'Linked' } else { 'Would link' }
Write-Host "`n$verb $done ticket(s); skipped $skipped already linked." -ForegroundColor Cyan
