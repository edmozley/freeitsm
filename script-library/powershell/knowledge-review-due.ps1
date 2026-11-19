# @title       Knowledge articles due for review
# @summary     Lists the knowledge articles that are overdue for review or due soon, grouped by the analyst who owns them.
# @module      knowledge
# @changes     no
# @level       starter
# @endpoints   GET /knowledge/articles
# @permissions knowledge.read
#
# WHY: an article nobody has looked at for two years is worse than no article,
# because people trust it. FreeITSM gives every article an optional "next review
# date" and an owner. This script asks for the ones whose date has passed or is
# coming up, and prints them owner by owner - ready to paste into an email to
# each owner, or to run every Monday from Task Scheduler.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\knowledge-review-due.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\knowledge-review-due.ps1 -Url https://helpdesk.example.com -Days 7 -IncludeDrafts
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# Keep that file out of source control and readable only by you.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com
    # or http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,

    # Where the key is kept if FREEITSM_API_KEY is not set.
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far ahead counts as "due soon". FreeITSM's own "upcoming" filter looks 30
    # days ahead (the same window as the Knowledge module's review screen), so this
    # can narrow that window but not widen it - see the note in step 2.
    [ValidateRange(0, 30)]
    [int]$Days = 30,

    # Published articles only, unless you add this. A draft can still be wrong,
    # so turn it on if your team keeps long-lived drafts.
    [switch]$IncludeDrafts
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'

if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content: Get-Content tags the text with hidden
    # properties (PSPath, PSDrive...) that make ConvertTo-Json crawl.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}

# Every REST call lives under /api/v1 on your server.
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Sends the request with the key as a Bearer token and returns FreeITSM's WHOLE
# reply - { "data": [...], "meta": {...} } - because a list also tells us, in
# "meta", how many pages there are. On failure FreeITSM answers
# { "error": { "code": "...", "message": "..." } }; we stop and print that message.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- Paging --------------------------------------------------------------------
# Lists come back a page at a time: at most 100 rows per page (per_page is capped
# at 100 on the server), and meta.total_pages says how many pages there are.
# This keeps asking for the next page until it has them all.
function Get-AllArticles([string]$Query) {
    $all = @()
    $page = 1
    do {
        $reply = Invoke-FreeItsm 'GET' ("/knowledge/articles?$Query&per_page=100&page=$page")
        $all += @($reply.data)
        $page++
    } while ($page -le $reply.meta.total_pages)
    return $all
}

# ---- 1. Build the filter -------------------------------------------------------
# review=overdue  -> next review date is before today
# review=upcoming -> next review date is today or within the next 30 days
# Both are worked out on the server in UTC, so "today" below is the UTC date too,
# which keeps our day counts in step with FreeITSM's.
# sort=next_review_date puts the most urgent first.
$common = 'sort=next_review_date'
if ($IncludeDrafts) { $common += '&include_unpublished=true' }

# ---- 2. Ask for both lists -----------------------------------------------------
# Articles with NO review date are not in either list - FreeITSM has nothing to
# compare. Ask for them with review=none if you want to chase those too.
$today = [DateTime]::UtcNow.Date
$overdue  = Get-AllArticles "review=overdue&$common"
$upcoming = Get-AllArticles "review=upcoming&$common"

# "upcoming" is always 30 days on the server; -Days narrows it here, on our side.
# (To look further than 30 days ahead you would list articles without a review
# filter, sorted by next_review_date, and stop once the dates pass your limit.)
$limit = $today.AddDays($Days)
$upcoming = @($upcoming | Where-Object { [DateTime]::ParseExact($_.next_review_date, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture) -le $limit })

# ---- 3. One row per article, with how late or how soon it is ---------------------
$rows = foreach ($a in @($overdue) + @($upcoming)) {
    $due = [DateTime]::ParseExact($a.next_review_date, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
    $diff = [int]($due - $today).TotalDays
    # owner is { id, name } or null. An article with no owner is the most likely to
    # be forgotten, so it gets its own group rather than disappearing.
    $owner = if ($a.owner) { $a.owner.name } else { '(no owner)' }
    [pscustomobject]@{
        Owner  = $owner
        Id     = $a.id
        Title  = $a.title
        Due    = $a.next_review_date
        When   = if ($diff -lt 0) { "$(-$diff) day(s) OVERDUE" } elseif ($diff -eq 0) { 'due today' } else { "due in $diff day(s)" }
        Draft  = -not $a.is_published
        Sort   = $diff
    }
}

# ---- 4. Print them, owner by owner -----------------------------------------------
Write-Host "Knowledge review report from $Base   (UTC date $($today.ToString('yyyy-MM-dd')), looking $Days day(s) ahead)" -ForegroundColor Cyan
if (-not $rows) {
    Write-Host 'Nothing is overdue or due soon. Good.' -ForegroundColor Green
    return
}
foreach ($group in ($rows | Group-Object Owner | Sort-Object Name)) {
    Write-Host "`n$($group.Name) - $($group.Count) article(s)" -ForegroundColor Yellow
    foreach ($r in ($group.Group | Sort-Object Sort)) {
        $draft = if ($r.Draft) { '  [draft]' } else { '' }
        Write-Host ('  #{0,-6} {1,-22} {2}  {3}{4}' -f $r.Id, $r.When, $r.Due, $r.Title, $draft)
    }
}
$late = @($rows | Where-Object { $_.Sort -lt 0 }).Count
Write-Host "`n$(@($rows).Count) article(s) need review: $late overdue, $(@($rows).Count - $late) due soon." -ForegroundColor Cyan
