# @title       Close stale tickets with a note
# @summary     Finds open tickets nobody has touched for a number of days, adds a note saying why, then closes them.
# @module      tickets
# @changes     yes
# @level       intermediate
# @endpoints   GET /statuses, GET /tickets, POST /tickets/{id}/notes, PATCH /tickets/{id}
# @permissions reference.read, tickets.read, ticket_notes.create, tickets.update
#
# WHAT IT SHOWS: filtering, then two writes per ticket in a deliberate order.
#
#   1. Ask FreeITSM which statuses count as CLOSED (GET /statuses) - never a
#      hard-coded id, because ids differ between installs.
#   2. Collect every open ticket whose last update is older than -Days. ALL of
#      them first, before changing anything (see the TRAP in step 2).
#   3. For each: add a note explaining why, THEN change its status.
#
# The typical use is tickets parked waiting for the customer:
#   powershell -ExecutionPolicy Bypass -File .\close-stale-tickets.ps1 -Url https://helpdesk.example.com -Days 14 -WaitingStatus "Awaiting Response"
#   powershell -ExecutionPolicy Bypass -File .\close-stale-tickets.ps1 -Url https://helpdesk.example.com -Days 14 -WaitingStatus "Awaiting Response" -Apply
#
# PREVIEW FIRST: without -Apply it lists the tickets it would close and writes
# nothing. Read that list before you add -Apply - closing is easy to undo one
# ticket at a time, tedious for two hundred.
#
# "Untouched" means the ticket's updated_at: a note, a reply, a status or field
# change all move it on. Logging time does not.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How many days without an update makes a ticket stale.
    [int]$Days = 14,

    # Only tickets in this status (its NAME, e.g. "Awaiting Response"). Leave it
    # empty to consider EVERY open ticket - think twice about that on a busy desk.
    [string]$WaitingStatus = '',

    # Only tickets whose subject or number CONTAINS this text, e.g. "[Printer]".
    # Empty = no subject filter.
    [string]$Search = '',

    # The status to close with, by name (e.g. "Resolved"). Empty = the first
    # status FreeITSM marks as closed, in your System -> Tickets order.
    [string]$CloseStatus = '',

    # The note added before closing. {days} is replaced with -Days.
    [string]$NoteText = 'Closed automatically: no activity for {days} days. If you still need help, reply to this ticket or contact the service desk.',

    # Without -Apply nothing is written: it lists what it would close.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }
# 0 is allowed - "anything not touched before now" - which is handy with -Search
# to try the script on a few test tickets; the preview shows what it would catch.
if ($Days -lt 0) { throw '-Days must be 0 or more.' }

$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the whole reply ({ data, meta }) because the ticket list pages. Sends
# a body as UTF-8 bytes (accents survive 5.1) and stops with FreeITSM's message
# on failure - for example a closure refused because a required checklist on the
# ticket is not finished.
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return Invoke-RestMethod @params }
    catch {
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

# ---- 1. Which status closes a ticket? ---------------------------------------------
# GET /statuses lists every ticket status with an is_closed flag. Closing means
# "a status whose is_closed is true" - FreeITSM then stamps closed_at, stops the
# SLA clock and takes the ticket out of the open queues.
$statuses = @((Invoke-FreeItsm 'GET' '/statuses').data)
if ($CloseStatus) {
    $close = $statuses | Where-Object { $_.name -eq $CloseStatus } | Select-Object -First 1
    if (-not $close) { throw "No status called '$CloseStatus'. Statuses: $(($statuses | ForEach-Object { $_.name }) -join ', ')" }
    if (-not $close.is_closed) { throw "'$CloseStatus' is not a closed status, so it would not close anything." }
} else {
    $close = $statuses | Where-Object { $_.is_closed } | Select-Object -First 1
    if (-not $close) { throw 'This install has no closed status - add one under System -> Tickets.' }
}

$cutoff = [DateTime]::UtcNow.AddDays(-$Days)
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to close them' }
Write-Host "Open tickets$(if ($WaitingStatus) { " in '$WaitingStatus'" }) untouched since $($cutoff.ToString('yyyy-MM-dd HH:mm')) UTC -> '$($close.name)'   [$mode]" -ForegroundColor Cyan

# ---- 2. Collect the stale tickets ---------------------------------------------------
# There is no "updated before" filter, so we sort by updated_at, OLDEST first,
# and stop paging at the first ticket that is newer than the cutoff - every
# ticket after it is newer still.
#
# TRAP: collect them ALL before closing any. state=open pages are counted over
# the OPEN tickets; close ten from page 1 and what was page 2 slides up into
# page 1, so asking for page 2 next would silently skip a page of tickets.
$query = 'state=open&sort=updated_at&per_page=100'
# status= matches a status NAME exactly; q= is "subject or number contains".
# EscapeDataString makes spaces and symbols safe to put in an address.
if ($WaitingStatus) { $query += '&status=' + [uri]::EscapeDataString($WaitingStatus) }
if ($Search)        { $query += '&q=' + [uri]::EscapeDataString($Search) }
$stale = New-Object System.Collections.Generic.List[object]
$page = 1
$done = $false
do {
    $reply = Invoke-FreeItsm 'GET' "/tickets?$query&page=$page"
    foreach ($t in @($reply.data)) {
        $last = if ($t.updated_at) { ConvertTo-Utc $t.updated_at } else { ConvertTo-Utc $t.created_at }
        if ($last -ge $cutoff) { $done = $true; break }
        $t | Add-Member -NotePropertyName LastTouched -NotePropertyValue $last
        $stale.Add($t)
    }
    $page++
} while (-not $done -and $page -le $reply.meta.total_pages)

if ($stale.Count -eq 0) { Write-Host 'Nothing is stale. Done.' -ForegroundColor Green; return }

# ---- 3. Note, then close ----------------------------------------------------------
# The note goes FIRST so anyone opening the ticket sees the reason directly
# above the closure in its history. is_internal = $false shares the note with
# the requester (FreeITSM may email it to them, depending on your notification
# settings); make it $true to keep it among analysts.
#
# If FreeITSM refuses a closure (for example a required checklist is not done),
# the script stops there with FreeITSM's reason. That ticket keeps its note and
# stays open - deal with it by hand, then run the script again.
$text = $NoteText.Replace('{days}', [string]$Days)
foreach ($t in $stale) {
    $age = [Math]::Floor(([DateTime]::UtcNow - $t.LastTouched).TotalDays)
    $line = "  $($t.ticket_number)  '$($t.subject)'  [$($t.status.name), untouched $age days]"
    if (-not $Apply) { Write-Host "would close$line"; continue }
    [void](Invoke-FreeItsm 'POST' "/tickets/$($t.id)/notes" @{ text = $text; is_internal = $false })
    [void](Invoke-FreeItsm 'PATCH' "/tickets/$($t.id)" @{ status_id = $close.id })
    Write-Host "closed$line" -ForegroundColor Green
}
Write-Host "`n$($stale.Count) ticket(s) $(if ($Apply) { 'closed' } else { 'would be closed' }). Done." -ForegroundColor Cyan
