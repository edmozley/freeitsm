# @title       Record morning check results
# @summary     Runs a simple test for each of your morning checks - ping, an open port or a web page - and records Green or Red on today's board.
# @module      morning-checks
# @changes     yes
# @level       intermediate
# @endpoints   GET /morning-checks/board, GET /morning-check-statuses, POST /morning-checks/results
# @permissions morning_check_results.read, reference.read, morning_check_results.record
#
# WHAT IT DOES: many morning checks are "is it up?" questions a machine can answer
# before anyone is in. This script:
#   1. reads today's Morning Checks board - every active check, and any result
#      already recorded for today,
#   2. for each check it knows how to test (the $Tests table just below), runs
#      the test from THIS computer: a ping, a TCP port, or a web page,
#   3. records the result - your "pass" status (Green) or your "fail" status
#      (Red) - with a note saying exactly what was tested and what happened.
#
# Checks not in the table are left alone for a person to do. Checks that already
# have a result today are skipped unless you add -Overwrite, so a result an
# analyst typed in by hand is never replaced by the script - and running it twice
# records nothing new the second time.
#
# Run it - first WITHOUT -Apply to see the test results without recording them:
#   powershell -ExecutionPolicy Bypass -File .\record-morning-check-results.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\record-morning-check-results.ps1 -Url https://helpdesk.example.com -Apply
#
# Run it on a machine that can reach the things being tested (a management
# server, say), from Task Scheduler at 07:00, and the board is half done before
# the first analyst logs in.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Optional: a JSON file holding the same table as $Tests below, if you would
    # rather keep your list outside the script, e.g.
    #   [ { "Check": "File server pings", "Test": "ping", "Target": "fs01" } ]
    [string]$TestsFile = '',

    # The status labels to record. They must match YOUR Morning Checks statuses
    # (Morning Checks -> Settings); out of the box they are Green, Amber and Red.
    [string]$PassStatus = 'Green',
    [string]$FailStatus = 'Red',

    # The day to record against, as yyyy-MM-dd. Defaults to today on THIS computer.
    [string]$Date = (Get-Date -Format 'yyyy-MM-dd'),

    # Replace results already recorded for the day. Off by default so a result an
    # analyst entered by hand is never overwritten.
    [switch]$Overwrite,

    # Without -Apply the tests run and are printed, but nothing is recorded.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- YOUR CHECKS: edit this table -----------------------------------------------
# Check  = the check's name EXACTLY as it is on the Morning Checks board.
# Test   = ping  - Target is a host name or IP address; passes if it replies.
#          tcp   - Target is host:port; passes if the port accepts a connection
#                  (a database, RDP, SMTP...). Use this where ping is blocked.
#          url   - Target is a web address; passes on any answer below 400.
# The three below are examples - replace them with your own.
$Tests = @(
    @{ Check = 'File server pings';      Test = 'ping'; Target = 'fs01.example.local' }
    @{ Check = 'SQL server port open';   Test = 'tcp';  Target = 'sql01.example.local:1433' }
    @{ Check = 'Intranet home page';     Test = 'url';  Target = 'https://intranet.example.local/' }
)
if ($TestsFile) {
    # [IO.File] rather than Get-Content - see the note on the key below.
    # TRAP: Windows PowerShell 5.1's ConvertFrom-Json hands back a JSON array as
    # ONE object, so @(...) alone would give a list with a single item - the whole
    # array. Sending it through ForEach-Object unrolls it into separate rows.
    $Tests = @([IO.File]::ReadAllText($TestsFile) | ConvertFrom-Json | ForEach-Object { $_ })
}

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File], not Get-Content: Get-Content tags the text with hidden properties
    # that make ConvertTo-Json crawl in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Sends a request, returns the "data" part of the reply, and stops with FreeITSM's
# own message on failure - for example 422 "Notes are required for the 'Red' status".
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so accented text survives Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- The three tests ---------------------------------------------------------------
# Each returns @{ Ok = $true/$false; Detail = 'what happened' }. The detail goes
# into the result's notes, so whoever reads the board sees WHY it is red.
function Test-Ping([string]$HostName) {
    # One echo request. Many firewalls drop ping - use a tcp test for those.
    $ok = Test-Connection -ComputerName $HostName -Count 1 -Quiet -ErrorAction SilentlyContinue
    if ($ok) { @{ Ok = $true; Detail = "ping $HostName replied" } } else { @{ Ok = $false; Detail = "ping $HostName - no reply" } }
}
function Test-Tcp([string]$Target, [int]$TimeoutMs = 5000) {
    $hostName, $port = $Target -split ':', 2
    $client = New-Object Net.Sockets.TcpClient
    try {
        # BeginConnect + a wait, because a plain Connect() can hang for 20 seconds
        # or more on a host that silently drops the packet.
        $wait = $client.BeginConnect($hostName, [int]$port, $null, $null)
        if (-not $wait.AsyncWaitHandle.WaitOne($TimeoutMs)) {
            return @{ Ok = $false; Detail = "port $port on $hostName did not answer within $($TimeoutMs / 1000)s" }
        }
        # EndConnect throws if the attempt failed (for example "actively refused"),
        # which lands in the catch below with the reason.
        $client.EndConnect($wait)
        return @{ Ok = $true; Detail = "port $port on $hostName is open" }
    } catch {
        return @{ Ok = $false; Detail = "port $port on $hostName - $($_.Exception.InnerException.Message)" }
    } finally { $client.Close() }
}
function Test-Url([string]$Address) {
    try {
        # -UseBasicParsing: no Internet Explorer engine needed (servers often lack it).
        $watch = [Diagnostics.Stopwatch]::StartNew()
        $r = Invoke-WebRequest -Uri $Address -UseBasicParsing -TimeoutSec 15 -MaximumRedirection 5
        return @{ Ok = $true; Detail = "$Address answered HTTP $($r.StatusCode) in $($watch.ElapsedMilliseconds) ms" }
    } catch {
        # A 4xx/5xx answer lands here too, with the status on the response.
        $code = if ($_.Exception.Response) { "HTTP $([int]$_.Exception.Response.StatusCode)" } else { $_.Exception.Message }
        return @{ Ok = $false; Detail = "$Address - $code" }
    }
}

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - tests run, nothing is recorded; add -Apply to record' }
Write-Host "Morning checks for $Date on $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. Look up your pass and fail statuses ------------------------------------------
# Statuses are configurable, so we check the labels exist and are active before
# testing anything. requires_notes matters: FreeITSM refuses a result in a status
# that needs notes when none are sent - this script always sends a note anyway.
$statuses = @(Invoke-FreeItsm 'GET' '/morning-check-statuses') | Where-Object { $_.is_active }
$pass = $statuses | Where-Object { $_.label -eq $PassStatus } | Select-Object -First 1
$fail = $statuses | Where-Object { $_.label -eq $FailStatus } | Select-Object -First 1
$labels = ($statuses | ForEach-Object { $_.label }) -join ', '
if (-not $pass) { throw "'$PassStatus' is not an active morning check status. Yours are: $labels" }
if (-not $fail) { throw "'$FailStatus' is not an active morning check status. Yours are: $labels" }

# ---- 2. Read the day's board --------------------------------------------------------
# The board is every ACTIVE check with its result for that date, or null if
# nobody has done it yet - exactly what the Morning Checks screen shows.
$board = Invoke-FreeItsm 'GET' "/morning-checks/board?date=$Date"
$recorded = 0
foreach ($check in @($board.checks)) {
    $test = $Tests | Where-Object { $_.Check -eq $check.name } | Select-Object -First 1
    if (-not $test) { Write-Host "  -       $($check.name): no automatic test - left for a person" -ForegroundColor DarkGray; continue }
    if ($check.result -and -not $Overwrite) {
        $already = if ($check.result.status) { $check.result.status.label } else { $check.result.orphan_label }
        Write-Host "  skip    $($check.name): already recorded today as $already (add -Overwrite to replace)" -ForegroundColor DarkGray
        continue
    }

    # ---- 3. Run the test --------------------------------------------------------------
    $outcome = switch ($test.Test) {
        'ping'  { Test-Ping $test.Target }
        'tcp'   { Test-Tcp $test.Target }
        'url'   { Test-Url $test.Target }
        default { throw "Check '$($check.name)': unknown test '$($test.Test)' - use ping, tcp or url." }
    }
    $status = if ($outcome.Ok) { $pass } else { $fail }
    $colour = if ($outcome.Ok) { 'Green' } else { 'Red' }
    Write-Host ("  {0,-7} {1}: {2}" -f $status.label, $check.name, $outcome.Detail) -ForegroundColor $colour
    if (-not $Apply) { continue }

    # ---- 4. Record it -------------------------------------------------------------------
    # One result per check per day: posting again for the same day replaces it.
    # The analyst the key acts as is recorded as the one who did it.
    [void](Invoke-FreeItsm 'POST' '/morning-checks/results' @{
        check_id  = $check.id
        status_id = $status.id
        date      = $Date
        notes     = "Automatic check: $($outcome.Detail)"
    })
    $recorded++
}
if ($Apply) { Write-Host "`nRecorded $recorded result(s)." -ForegroundColor Cyan }
