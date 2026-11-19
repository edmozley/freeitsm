# @title       Turn a monitoring alert into a ticket
# @summary     Logs a ticket for a monitoring alert - or, if that alert already has an open ticket, adds a note to it instead of a duplicate.
# @module      tickets
# @changes     yes
# @level       intermediate
# @endpoints   GET /tickets, POST /tickets, POST /tickets/{id}/notes
# @permissions tickets.read, tickets.create, ticket_notes.create
#
# WHAT IT SHOWS: "look first, then act". A monitoring tool (PRTG, Zabbix, Nagios,
# Uptime Kuma, a scheduled task...) fires the same alert again and again while a
# problem lasts. Logging a new ticket every time buries the service desk, so:
#
#   1. Look for an OPEN ticket with exactly the same subject.
#   2. Found one?  Add a note to it ("fired again at ...").
#      None?       Create a new ticket.
#
# Once that ticket is closed, the next alert opens a fresh one - which is what
# you want: a problem that comes back is a new incident.
#
# HOW TO CALL IT - from your monitoring tool's "run a program" action, either
# with parameters:
#   powershell -ExecutionPolicy Bypass -File .\monitoring-alert-to-ticket.ps1 -Url https://helpdesk.example.com `
#       -Subject "DISK LOW: FS01 C: below 10%" -Message "Free space 8.2 GB" -Priority High
# or with a JSON file the tool writes out:
#   powershell -ExecutionPolicy Bypass -File .\monitoring-alert-to-ticket.ps1 -Url https://helpdesk.example.com -AlertFile .\alert.json
#
#   alert.json:
#   { "subject": "DISK LOW: FS01 C: below 10%", "message": "Free space 8.2 GB",
#     "priority": "High", "requester_email": "monitoring@example.com" }
#
# PREVIEW FIRST: without -Apply it only looks, and says what it WOULD do. Add
# -Apply once you are happy, and put -Apply in the monitoring tool's command.
#
# KEEP SUBJECTS STABLE. The match is on the subject, so put the changing details
# (the exact free space, the time) in the message, not the subject - otherwise
# every alert looks new and you get the duplicates this script exists to stop.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The alert, as parameters...
    [string]$Subject,
    [string]$Message = '',
    # A priority NAME as it appears in FreeITSM (e.g. High). Leave empty for the
    # default. A name FreeITSM does not know is refused, not silently ignored.
    [string]$Priority = '',
    # Who the ticket is "from". FreeITSM needs a requester on every ticket and
    # creates a contact for an address it has not seen before - so use one
    # mailbox for all monitoring, e.g. monitoring@yourcompany.com.
    [string]$RequesterEmail = 'monitoring@example.com',

    # ...or as a JSON file (its values win over the parameters above).
    [string]$AlertFile = '',

    # Without -Apply nothing is written: the script looks, then says what it would do.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Returns the whole reply ({ data, meta }) so the search can page. Sends a body
# as UTF-8 bytes so accented text survives Windows PowerShell 5.1, and stops with
# FreeITSM's own message on failure (e.g. 422 "Unknown priority: Hihg").
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

# ---- 1. Read the alert ----------------------------------------------------------
if ($AlertFile) {
    # TRAP: [IO.File], never Get-Content, for anything that ends up in
    # ConvertTo-Json - Get-Content's hidden properties make 5.1 hang.
    $alert = [IO.File]::ReadAllText($AlertFile, [Text.Encoding]::UTF8) | ConvertFrom-Json
    if ($alert.subject)         { $Subject = $alert.subject }
    if ($alert.message)         { $Message = $alert.message }
    if ($alert.priority)        { $Priority = $alert.priority }
    if ($alert.requester_email) { $RequesterEmail = $alert.requester_email }
}
$Subject = "$Subject".Trim()
if (-not $Subject) { throw 'The alert needs a subject: pass -Subject or put "subject" in the -AlertFile.' }

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to log it' }
Write-Host "Alert: $Subject   [$mode]" -ForegroundColor Cyan

# ---- 2. Look for an open ticket with the same subject ------------------------------
# q= searches subject AND ticket number with "contains", so "FS01 C:" would also
# match "FS01 C: below 5%". We therefore fetch the candidates and keep only an
# EXACT subject match (ignoring capitals and spaces at the ends). Paged, in
# case a very common word brings back more than 100.
$match = $null
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' ("/tickets?state=open&sort=id&per_page=100&page=$page&q=" + [uri]::EscapeDataString($Subject))
    foreach ($t in @($reply.data)) {
        # sort=id, oldest first: the first exact match is the ORIGINAL ticket,
        # even if someone has since logged a duplicate by hand.
        if (-not $match -and $t.subject.Trim() -eq $Subject) { $match = $t }   # -eq ignores case
    }
    $page++
} while (-not $match -and $page -le $reply.meta.total_pages)

$now = [DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm') + ' UTC'

# ---- 3a. Found: add a note --------------------------------------------------------
if ($match) {
    Write-Host "Open ticket $($match.ticket_number) (#$($match.id)) already covers this alert."
    # is_internal = $true: only analysts see it. Set it to $false and the note is
    # shared with the requester - and may be emailed to them, which for an alert
    # mailbox is just noise.
    $note = @{ text = "Alert fired again at $now.`n$Message".Trim(); is_internal = $true }
    if (-not $Apply) { Write-Host "  would add a note: $($note.text)"; return }
    $n = (Invoke-FreeItsm 'POST' "/tickets/$($match.id)/notes" $note).data
    Write-Host "  added note #$($n.id) to $($match.ticket_number)" -ForegroundColor Green
    return
}

# ---- 3b. Not found: create a ticket -------------------------------------------------
# description is plain text - FreeITSM turns line breaks into the ticket body.
# Other fields you might add: department_id, ticket_type_id, assigned_team_id,
# company_id (a key limited to companies uses its first company otherwise).
$body = @{
    subject         = $Subject
    description     = "Raised automatically by monitoring at $now.`n`n$Message".Trim()
    requester_email = $RequesterEmail
    requester_name  = 'Monitoring'
}
if ($Priority) { $body.priority = $Priority }   # by name; priority_id works too

if (-not $Apply) {
    Write-Host "  no open ticket has this subject - would create one (priority: $(if ($Priority) { $Priority } else { 'default' }), requester: $RequesterEmail)"
    return
}
$created = (Invoke-FreeItsm 'POST' '/tickets' $body).data
Write-Host "  created ticket $($created.ticket_number) (#$($created.id))" -ForegroundColor Green
