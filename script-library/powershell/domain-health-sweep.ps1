# @title       Domain health sweep into tickets
# @summary     Checks every domain you monitor and logs one ticket per domain with serious findings - or adds a note to the ticket already open for it, so a nightly run never makes duplicates.
# @module      domains
# @changes     yes
# @level       intermediate
# @endpoints   GET /domains, POST /domains/{id}/check, GET /domains/{id}/findings,
#              GET /tickets, POST /tickets, POST /tickets/{id}/notes
# @permissions domains.read, domains.update, tickets.read, tickets.create, ticket_notes.create
#
# WHAT IT DOES: the Domains module grades every domain (SPF, DMARC, DNSSEC,
# certificates, expiry...) and lists what it found. This script turns the
# serious findings into work for the service desk:
#
#   1. reads every domain in the register (paged, 100 at a time),
#   2. runs the checks on each one again (POST /domains/{id}/check),
#   3. keeps the findings at the level you care about - "fail" by default,
#      add -Level warn to include warnings too,
#   4. for each domain with any left, looks for an OPEN ticket with that
#      domain's stable subject ("Domain health: example.com"), then
#   5. adds a note to it listing today's findings - or, if there is none,
#      opens a new ticket.
#
# Step 4 is what makes it safe to run every night: one domain = one open ticket.
# When an analyst fixes the record and closes the ticket, the next night finds
# nothing and stays quiet. If the problem comes back, a fresh ticket opens -
# which is what you want: it is a new incident.
#
# PREVIEW IS REALLY READ-ONLY. Running a check is itself a write: POST
# /domains/{id}/check asks DNS and the domain's web servers again, then SAVES the
# new findings, grade and certificate dates on the domain (and logs a grade
# change in its history). So without -Apply the script does NOT re-check - it
# reads the findings saved by the LAST check (the scheduled run, or someone
# pressing Check) with GET /domains/{id}/findings, and says what it would log.
# A domain that has never been checked shows "never checked" in the preview.
#
# Run it - first WITHOUT -Apply:
#   powershell -ExecutionPolicy Bypass -File .\domain-health-sweep.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\domain-health-sweep.ps1 -Url https://helpdesk.example.com -Apply
# Then schedule the -Apply line nightly in Task Scheduler.
#
# Checks reach out to the internet from the FreeITSM SERVER (DNS, HTTPS), not
# from this computer, and take a few seconds per domain - a register of 200
# domains is a few minutes' run.
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Which findings count as serious. Each finding has a level: pass, info, warn
    # or fail. "fail" (the default) logs only failures; "warn" logs warnings AND failures.
    [ValidateSet('fail', 'warn')][string]$Level = 'fail',

    # The start of every ticket subject; the domain name is added after it. Keep
    # it STABLE - it is how the next run finds the ticket again. Change it and the
    # next run will not recognise yesterday's tickets.
    [string]$SubjectPrefix = 'Domain health:',

    # A priority NAME as it appears in FreeITSM (e.g. High). Empty = your default.
    [string]$Priority = '',

    # Who the tickets are "from". FreeITSM needs a requester on every ticket and
    # creates a contact for an address it has not seen before - so use one
    # mailbox for all automatic tickets.
    [string]$RequesterEmail = 'monitoring@example.com',

    # Without -Apply nothing is written: no checks run, no tickets, no notes.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

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
# Returns the whole reply ({ data, meta }) - the lists need meta for paging, and
# the findings need meta.checked_at. Sends a body as UTF-8 bytes so accented text
# survives Windows PowerShell 5.1, and stops with FreeITSM's own message on
# failure (e.g. 422 "Unknown priority: Hihg"). A long timeout: a check waits on
# DNS and on each web server's certificate.
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" }; TimeoutSec = 180 }
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

# The OPEN ticket with exactly this subject, or $null.
# q= is a "contains" search on subject and ticket number, so "example.com" also
# finds "Domain health: mail.example.com". We page through the candidates and
# keep only an EXACT match (-eq ignores capitals). sort=id, oldest first, so the
# first match is the original ticket even if someone logged a copy by hand.
function Find-OpenTicket([string]$Subject) {
    $page = 1
    do {
        $reply = Invoke-FreeItsm 'GET' ("/tickets?state=open&sort=id&per_page=100&page=$page&q=" + [uri]::EscapeDataString($Subject))
        foreach ($t in @($reply.data)) {
            if ($t.subject.Trim() -eq $Subject) { return $t }
        }
        $page++
    } while ($page -le $reply.meta.total_pages)
    return $null
}

$seriousLevels = if ($Level -eq 'warn') { @('fail', 'warn') } else { @('fail') }
$now = [DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm') + ' UTC'
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - no checks run, nothing is written; add -Apply' }
Write-Host "Domain health sweep on $Base   [$mode]" -ForegroundColor Cyan
Write-Host "Serious = level $($seriousLevels -join ' or ')`n"

# ---- 1. Every domain in the register ------------------------------------------------
# GET /domains is paged (at most 100 a page); meta.total_pages says when to stop.
# The key's company scope applies: a key limited to one company sees its domains.
$domains = @()
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/domains?sort=domain_name&per_page=100&page=$page"
    $domains += @($reply.data)
    $page++
} while ($page -le $reply.meta.total_pages)

$created = 0; $noted = 0; $clean = 0; $skipped = 0
foreach ($d in $domains) {
    $name = $d.domain_name
    # Someone turned monitoring off for this domain (a name being let go, say) -
    # respect that, just as the scheduled checks do.
    if (-not $d.monitoring_enabled) {
        Write-Host "  skip   ${name}: monitoring is off for this domain"
        $skipped++
        continue
    }

    # ---- 2. Its findings: fresh (apply) or the last saved ones (preview) --------
    if ($Apply) {
        # Re-runs every check and saves the result; the reply is the domain with
        # its new grade and a "findings" list.
        $checked = (Invoke-FreeItsm 'POST' "/domains/$($d.id)/check").data
        $findings = @($checked.findings); $grade = $checked.security_grade
    } else {
        $reply = Invoke-FreeItsm 'GET' "/domains/$($d.id)/findings"
        $findings = @($reply.data); $grade = $d.security_grade
        if ($null -eq $reply.meta.checked_at) {
            Write-Host "  ?      ${name}: never checked - -Apply would check it now"
            continue
        }
    }
    if (-not $grade) { $grade = '-' }

    # ---- 3. Keep only the serious ones ----------------------------------------------
    # Each finding has its English title and advice filled in by the API, so the
    # ticket is readable without opening the Domains screen.
    $serious = @($findings | Where-Object { $seriousLevels -contains $_.level })
    if ($serious.Count -eq 0) {
        Write-Host "  ok     ${name}: grade $grade, nothing serious" -ForegroundColor DarkGray
        $clean++
        continue
    }
    $lines = ($serious | ForEach-Object {
        $line = "- [$($_.level.ToUpper())] $($_.title)"
        if ($_.advice) { $line += "`n  $($_.advice)" }
        $line
    }) -join "`n"

    # ---- 4. Look first: is a ticket already open for this domain? -----------------
    $subject = "$SubjectPrefix $name"
    $ticket = Find-OpenTicket $subject

    if ($ticket) {
        # ---- 5a. Yes: add today's findings as a note ---------------------------------
        # is_internal = $true: only analysts see it. A note shared with the
        # requester may be emailed to them - noise for an automatic mailbox.
        $note = @{ text = "Domain checks at ${now}: grade $grade, $($serious.Count) serious finding(s).`n$lines"; is_internal = $true }
        Write-Host "  NOTE   ${name}: $($serious.Count) serious - open ticket $($ticket.ticket_number) already covers it" -ForegroundColor Yellow
        if ($Apply) {
            $n = (Invoke-FreeItsm 'POST' "/tickets/$($ticket.id)/notes" $note).data
            Write-Host "         added note #$($n.id)" -ForegroundColor Green
        }
        $noted++
        continue
    }

    # ---- 5b. No: open a ticket -------------------------------------------------------
    # description is plain text - FreeITSM turns line breaks into the ticket body.
    # Fields you might add: department_id, ticket_type_id, assigned_team_id, or
    # company_id ($d.company.id) to log it under the domain's own company.
    $body = @{
        subject         = $subject
        description     = "The domain checks at $now graded $name $grade with $($serious.Count) serious finding(s):`n`n$lines`n`n" +
                          'Raised automatically by the domain health sweep. Fix the records, press Check on the domain, then close this ticket.'
        requester_email = $RequesterEmail
        requester_name  = 'Domain health sweep'
    }
    if ($Priority) { $body.priority = $Priority }   # by name; priority_id works too
    $verb = if ($Apply) { 'creating' } else { 'would create' }
    Write-Host "  NEW    ${name}: $($serious.Count) serious - no open ticket, $verb '$subject'" -ForegroundColor Yellow
    foreach ($f in $serious) { Write-Host "           [$($f.level.ToUpper())] $($f.title)" }
    if ($Apply) {
        $t = (Invoke-FreeItsm 'POST' '/tickets' $body).data
        Write-Host "         created ticket $($t.ticket_number) (#$($t.id))" -ForegroundColor Green
    }
    $created++
}

$verb = if ($Apply) { '' } else { 'would be ' }
Write-Host "`n$($domains.Count) domain(s): $clean clean, $skipped not monitored, $created ticket(s) ${verb}created, $noted note(s) ${verb}added."
