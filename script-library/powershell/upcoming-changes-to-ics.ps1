# @title       Upcoming changes as a calendar file
# @summary     Writes the changes scheduled in the next few days to an .ics file that Outlook, Google Calendar or any calendar app can open.
# @module      changes
# @changes     no
# @level       intermediate
# @endpoints   GET /changes
# @permissions changes.read
#
# WHY: a change calendar people actually look at is the one already in their own
# calendar. Run this every morning, put the .ics file on a share or web server,
# and subscribe to it - or just double-click it to import the week's changes.
#
# WHAT IT USES: each change's scheduled work window (work_start_at / work_end_at).
# Changes without a work start are not scheduled yet, so they are left out, and
# so are closed ones (completed, failed, rejected, cancelled).
#
# TIME ZONES - read this once: FreeITSM stores a change's work window as a LOCAL
# wall-clock time with no time zone ("2026-11-20T22:00:00" means 10pm, as typed).
# A calendar file wants an exact moment, so this script reads those times as the
# time zone of the computer it runs on and writes them in UTC (the "Z" times in
# the file). Run it on a machine set to your service desk's time zone, or pass
# -TimeZone with a Windows time zone id such as 'GMT Standard Time'
# (list them with: [TimeZoneInfo]::GetSystemTimeZones() | Select-Object Id).
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\upcoming-changes-to-ics.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\upcoming-changes-to-ics.ps1 -Url https://helpdesk.example.com -Days 30 -OutFile \\server\share\changes.ics
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

    # How many days ahead to look, counting today as day one.
    [ValidateRange(1, 366)]
    [int]$Days = 14,

    # The calendar file to write. Overwritten each run.
    [string]$OutFile = (Join-Path $PSScriptRoot 'upcoming-changes.ics'),

    # The time zone the work windows were typed in. Empty = this computer's.
    [string]$TimeZone = ''
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'

if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }
$Zone = if ($TimeZone) { [TimeZoneInfo]::FindSystemTimeZoneById($TimeZone) } else { [TimeZoneInfo]::Local }

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
# Returns FreeITSM's WHOLE reply - { "data": [...], "meta": {...} } - because a list
# says in "meta" how many pages there are. On failure FreeITSM answers
# { "error": { "code": "...", "message": "..." } }; we stop and print that message.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- Calendar text rules (RFC 5545) ---------------------------------------------
# Inside a text value, backslash, semicolon and comma have meanings of their own,
# so they are escaped with a backslash; a line break becomes the two characters \n.
function ConvertTo-IcsText([string]$Text) {
    if ($null -eq $Text) { return '' }
    return $Text.Replace('\', '\\').Replace(';', '\;').Replace(',', '\,').Replace("`r`n", '\n').Replace("`n", '\n').Replace("`r", '\n')
}

# A line in an .ics file may be at most 75 BYTES (not characters) long. Longer
# ones are "folded": broken, with each continuation line starting with a space.
# We count UTF-8 bytes and never split a character - an accented letter is two
# bytes, an emoji four (two UTF-16 chars that must stay together).
$Utf8 = New-Object Text.UTF8Encoding($false)
function Format-IcsLine([string]$Line) {
    $out = New-Object Text.StringBuilder
    $used = 0
    $max = 75
    $i = 0
    while ($i -lt $Line.Length) {
        $len = if ([char]::IsHighSurrogate($Line[$i]) -and $i + 1 -lt $Line.Length) { 2 } else { 1 }
        $piece = $Line.Substring($i, $len)
        $bytes = $Utf8.GetByteCount($piece)
        if ($used + $bytes -gt $max) {
            [void]$out.Append("`r`n ")
            $used = 1      # the leading space counts towards the next line's 75
        }
        [void]$out.Append($piece)
        $used += $bytes
        $i += $len
    }
    return $out.ToString()
}

# Wall-clock text from FreeITSM -> an exact UTC moment in calendar format.
function ConvertTo-IcsUtc([string]$WallClock) {
    $local = [DateTime]::ParseExact($WallClock, 'yyyy-MM-ddTHH:mm:ss', [Globalization.CultureInfo]::InvariantCulture)
    return [TimeZoneInfo]::ConvertTimeToUtc($local, $Zone).ToString('yyyyMMddTHHmmssZ')
}

# ---- 1. Ask for the scheduled changes ----------------------------------------------
# work_start_from / work_start_to filter on the work start, and - like the times
# themselves - take a wall-clock value with NO "Z" (FreeITSM refuses one, so a
# client cannot shift the window by accident). From midnight today, so a change
# that started this morning is still in today's file.
# state=open leaves out every closed status. sort=work_start_at = soonest first.
$today = [TimeZoneInfo]::ConvertTime([DateTime]::UtcNow, $Zone).Date
$from = $today.ToString('yyyy-MM-ddTHH:mm:ss')
$to = $today.AddDays($Days).ToString('yyyy-MM-ddTHH:mm:ss')
$query = "state=open&sort=work_start_at&per_page=100" +
         "&work_start_from=" + [uri]::EscapeDataString($from) +
         "&work_start_to=" + [uri]::EscapeDataString($to)
$changes = @()
$page = 1
do {
    # Pages hold at most 100 rows (the server's cap); meta.total_pages says when to stop.
    $reply = Invoke-FreeItsm 'GET' "/changes?$query&page=$page"
    $changes += @($reply.data)
    $page++
} while ($page -le $reply.meta.total_pages)
Write-Host "$($changes.Count) open change(s) start between $from and $to ($($Zone.Id))" -ForegroundColor Cyan

# ---- 2. One VEVENT per change ---------------------------------------------------------
# UID must stay the same for the same change on every run: that is what lets a
# calendar that re-imports or subscribes to the file UPDATE an event instead of
# adding a second copy. The host part keeps ids from two FreeITSMs apart.
$hostName = ([uri]$Url).Host
$stamp = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')
$lines = New-Object Collections.Generic.List[string]
$lines.Add('BEGIN:VCALENDAR')
$lines.Add('VERSION:2.0')
$lines.Add('PRODID:-//FreeITSM script library//Upcoming changes//EN')
$lines.Add('CALSCALE:GREGORIAN')
$lines.Add('METHOD:PUBLISH')
$lines.Add('X-WR-CALNAME:FreeITSM changes')

foreach ($c in $changes) {
    $s = $c.schedule
    $start = ConvertTo-IcsUtc $s.work_start_at
    # No end typed in? Show it as one hour, so it is visible rather than a zero-length blip.
    $end = if ($s.work_end_at) { ConvertTo-IcsUtc $s.work_end_at } else {
        [TimeZoneInfo]::ConvertTimeToUtc([DateTime]::ParseExact($s.work_start_at, 'yyyy-MM-ddTHH:mm:ss', [Globalization.CultureInfo]::InvariantCulture).AddHours(1), $Zone).ToString('yyyyMMddTHHmmssZ')
    }

    # The description: the facts a reader needs to decide whether it affects them.
    # Each field can be null, so every lookup checks first.
    $details = @("Status: $($c.status.name)")
    if ($c.change_type) { $details += "Type: $($c.change_type.name)" }
    if ($c.risk.level)  { $details += "Risk: $($c.risk.level)" }
    if ($c.assigned_to) { $details += "Assigned to: $($c.assigned_to.name)" }
    if ($s.outage_start_at) { $details += "Outage: $($s.outage_start_at) to $($s.outage_end_at) ($($Zone.Id))" }
    if (-not $s.work_end_at) { $details += 'No end time set in FreeITSM - shown as one hour.' }

    # Approved or being carried out = CONFIRMED; still a draft or awaiting approval
    # = TENTATIVE. status.stage is used rather than the name, because a status can
    # be renamed but its stage cannot.
    $confirmed = @('approved', 'implementing') -contains $c.status.stage
    $lines.Add('BEGIN:VEVENT')
    $lines.Add("UID:change-$($c.id)@$hostName")
    $lines.Add("DTSTAMP:$stamp")
    $lines.Add("DTSTART:$start")
    $lines.Add("DTEND:$end")
    $lines.Add('SUMMARY:' + (ConvertTo-IcsText "$($c.change_number) $($c.title)"))
    $lines.Add('DESCRIPTION:' + (ConvertTo-IcsText ($details -join "`n")))
    $lines.Add('STATUS:' + $(if ($confirmed) { 'CONFIRMED' } else { 'TENTATIVE' }))
    $lines.Add('TRANSP:TRANSPARENT')   # does not mark the reader as busy
    $lines.Add('END:VEVENT')
    Write-Host "  $($c.change_number)  $($s.work_start_at)  $($c.title)"
}
$lines.Add('END:VCALENDAR')

# ---- 3. Write the file --------------------------------------------------------------
# The standard says lines end with CRLF, and the text is UTF-8 - without a byte
# order mark, which some calendar apps reject.
$text = (($lines | ForEach-Object { Format-IcsLine $_ }) -join "`r`n") + "`r`n"
[IO.File]::WriteAllText($OutFile, $text, $Utf8)
Write-Host "`nWrote $OutFile" -ForegroundColor Green
