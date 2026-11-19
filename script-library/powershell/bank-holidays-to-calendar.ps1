# @title       Bank holidays into the calendar
# @summary     Reads the official UK bank holiday list from GOV.UK and adds every upcoming one to the FreeITSM calendar - running it again adds nothing twice.
# @module      calendar
# @changes     yes
# @level       intermediate
# @endpoints   GET /calendar/events, POST /calendar/events, GET /calendar-categories
# @permissions calendar_events.read, calendar_events.create, reference.read
#
# WHAT IT DOES:
#   1. downloads https://www.gov.uk/bank-holidays.json - a free, public list kept
#      by the UK government, with no key needed - and keeps the upcoming dates
#      for one part of the UK (England and Wales, Scotland, or Northern Ireland:
#      they differ, e.g. St Andrew's Day is Scotland only),
#   2. reads the FreeITSM calendar for the same dates,
#   3. adds an all-day event for each bank holiday that is not there yet.
#
# Step 2 is what makes it safe to run again: an event with the same title on the
# same day counts as "already there". So you can schedule it once a month and new
# dates appear as GOV.UK publishes them, with no duplicates. (The calendar API has
# no "client_reference" to deduplicate for us, so we look first.)
#
# It also shows a pattern worth copying: read ANOTHER web service, reshape what
# it returns, then write it into FreeITSM.
#
# Run it - first WITHOUT -Apply to see what it would add:
#   powershell -ExecutionPolicy Bypass -File .\bank-holidays-to-calendar.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\bank-holidays-to-calendar.ps1 -Url https://helpdesk.example.com -Division scotland -Category "Holidays" -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Which part of the UK - the three names GOV.UK uses.
    [ValidateSet('england-and-wales', 'scotland', 'northern-ireland')]
    [string]$Division = 'england-and-wales',

    # Optional: put the events in this calendar category (by name, as shown under
    # Calendar -> Settings). Leave it out for no category.
    [string]$Category = '',

    # Put in front of each holiday's name, so the events are easy to spot and
    # search for: "Bank holiday: Christmas Day".
    [string]$TitlePrefix = 'Bank holiday: ',

    # Without -Apply the script only reads both sides and prints what it would add.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# Windows PowerShell 5.1 can default to old TLS versions that GOV.UK (and many
# servers) refuse. Allow TLS 1.2 as well as whatever is already allowed.
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

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
        # Send UTF-8 bytes: holiday names contain a curly apostrophe (New Year's Day)
        # that Windows PowerShell 5.1 would otherwise mangle on the way out.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return Invoke-RestMethod @params }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}
# The usual case: just the "data" part.
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) { (Invoke-FreeItsmRaw $Method $Path $Body).data }

# ---- 1. The bank holidays from GOV.UK -------------------------------------------------
# WebClient with its Encoding set to UTF-8, so "New Year's Day" keeps its curly
# apostrophe (Invoke-RestMethod would guess the encoding instead).
$web = New-Object Net.WebClient
$web.Encoding = [Text.Encoding]::UTF8
$govuk = $web.DownloadString('https://www.gov.uk/bank-holidays.json') | ConvertFrom-Json
# The file looks like { "england-and-wales": { "events": [ { "title": "Christmas Day",
# "date": "2026-12-25", "notes": "", "bunting": true }, ... ] }, "scotland": ... }
$today = (Get-Date).ToString('yyyy-MM-dd')
# Dates are yyyy-MM-dd text, which sorts and compares correctly as plain strings.
$upcoming = @($govuk.$Division.events | Where-Object { $_.date -ge $today } | Sort-Object date)

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to add the events' }
Write-Host "$($upcoming.Count) upcoming bank holidays for $Division -> $Base   [$mode]" -ForegroundColor Cyan
if ($upcoming.Count -eq 0) { return }

# ---- 2. Optional category, checked before anything is written ----------------------------
$categoryId = $null
if ($Category) {
    $cats = @(Invoke-FreeItsm 'GET' '/calendar-categories') | Where-Object { $_.is_active }
    $cat = $cats | Where-Object { $_.name -eq $Category } | Select-Object -First 1
    if (-not $cat) { throw "'$Category' is not an active calendar category. Yours are: $(($cats | ForEach-Object { $_.name }) -join ', ')" }
    $categoryId = $cat.id
}

# ---- 3. What is already in the calendar for those dates --------------------------------
# The calendar works in the server's LOCAL time with no time zone, as
# "yyyy-MM-dd HH:mm:ss" - a time zone suffix such as Z is refused. "from" is
# inclusive and "to" exclusive, so we go to the day after the last holiday.
# Lists come a page at a time (at most 100), so we keep asking until we have them all.
$from = $upcoming[0].date + ' 00:00:00'
$to   = ([datetime]::ParseExact($upcoming[-1].date, 'yyyy-MM-dd', $null)).AddDays(1).ToString('yyyy-MM-dd') + ' 00:00:00'
$existing = @{}
$page = 1
do {
    $reply = Invoke-FreeItsmRaw 'GET' "/calendar/events?from=$([uri]::EscapeDataString($from))&to=$([uri]::EscapeDataString($to))&per_page=100&page=$page"
    foreach ($ev in @($reply.data)) {
        # start_at comes back as "2026-12-25 00:00:00"; the date part is enough.
        $existing["$($ev.start_at.Substring(0, 10))|$($ev.title)"] = $true
    }
    $page++
} while ($page -le $reply.meta.total_pages)

# ---- 4. Add the missing ones ----------------------------------------------------------
$added = 0
foreach ($h in $upcoming) {
    $title = $TitlePrefix + $h.title
    if ($existing.ContainsKey("$($h.date)|$title")) {
        Write-Host "  already there  $($h.date)  $title" -ForegroundColor DarkGray
        continue
    }
    if (-not $Apply) { Write-Host "  would add      $($h.date)  $title" -ForegroundColor Yellow; continue }

    # GOV.UK's notes say things like "Substitute day" when a holiday moves.
    $description = "UK bank holiday ($Division), from GOV.UK."
    if ($h.notes) { $description += " $($h.notes)." }
    $ev = Invoke-FreeItsm 'POST' '/calendar/events' @{
        title       = $title
        description = $description
        # An all-day event starts and ends at midnight on its day - the same
        # shape FreeITSM's own generated calendar entries use.
        start_at    = $h.date
        end_at      = $h.date
        all_day     = $true
        category_id = $categoryId
    }
    Write-Host "  added          $($h.date)  $title  (event #$($ev.id))" -ForegroundColor Green
    $added++
}
if ($Apply) { Write-Host "`nAdded $added event(s)." -ForegroundColor Cyan }
