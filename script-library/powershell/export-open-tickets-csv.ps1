# @title       Export open tickets to a CSV file
# @summary     Pages through every open ticket and saves them to a CSV file you can open in Excel.
# @module      tickets
# @changes     no
# @level       starter
# @endpoints   GET /tickets
# @permissions tickets.read
#
# WHAT IT SHOWS: paging. FreeITSM never hands back a whole table in one go - a
# list answers with one PAGE of results (at most 100) plus a "meta" block that
# says how many there are in total:
#
#   { "data": [ ...up to 100 tickets... ],
#     "meta": { "page": 1, "per_page": 100, "total": 230, "total_pages": 3 } }
#
# So the script asks for page 1, reads total_pages, and keeps asking for the
# next page until it has them all. Every list in the API pages the same way, so
# once you have this loop you can list anything.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\export-open-tickets-csv.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\export-open-tickets-csv.ps1 -Url https://helpdesk.example.com -OutFile C:\Reports\open.csv
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass -KeyFile). It only needs
# tickets.read - a read-only key is the right key for a report.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com.
    # Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,

    # Where the key is kept if FREEITSM_API_KEY is not set.
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Where to write the CSV. Defaults to open-tickets.csv in the current folder.
    [string]$OutFile = (Join-Path (Get-Location) 'open-tickets.csv')
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content - see check-your-api-key.ps1 for why.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Unlike the simplest scripts, this one returns the WHOLE reply - "data" AND
# "meta" - because paging needs meta.total_pages. On failure it stops and prints
# FreeITSM's own { "error": { "code", "message" } } explanation.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# Dates arrive as ISO 8601 text in UTC, e.g. "2026-11-19T09:30:00Z". Windows
# PowerShell 5.1 leaves them as text; PowerShell 7 turns them into [datetime]
# for you. This keeps the CSV identical whichever one runs the script.
function Format-IsoDate($Value) {
    if ($null -eq $Value) { return '' }
    if ($Value -is [datetime]) { return $Value.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ') }
    return [string]$Value
}

# ---- Page through the open tickets ---------------------------------------------
# state=open   - only tickets whose status is not a "closed" one. FreeITSM decides
#                that from the status's is_closed flag, so your own status names
#                (e.g. "Resolved") work without listing them here.
# sort=id      - a FIXED order. The default is newest first, so a ticket logged
#                while we are on page 2 would push every row down by one and we
#                would see one ticket twice. Sorting by id means new tickets land
#                at the END and never shift the pages we have not read yet.
# per_page=100 - the most FreeITSM allows; fewer calls than the default of 25.
#
# Want a different set? Add filters to $query, for example:
#   &assigned_analyst_id=4   &priority=High   &department_id=2   &company_id=3
$query = 'state=open&sort=id&per_page=100'
$tickets = New-Object System.Collections.Generic.List[object]
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/tickets?$query&page=$page"
    # @() because PowerShell turns a one-item list into a single object.
    foreach ($t in @($reply.data)) { $tickets.Add($t) }
    Write-Host ("  page {0} of {1}: {2} ticket(s) so far (total {3})" -f $page, [Math]::Max(1, $reply.meta.total_pages), $tickets.Count, $reply.meta.total)
    $page++
} while ($page -le $reply.meta.total_pages)

# ---- Flatten each ticket into one CSV row ----------------------------------------
# A ticket's status, priority and so on come back as small objects like
# { "id": 2, "name": "In Progress" } - or null when nobody set one. A CSV cell
# holds one value, so we take the name. Add or remove columns here to suit your
# report; GET /tickets/{id} in the API docs lists every field a ticket has.
function NameOf($ref) { if ($ref) { $ref.name } else { '' } }
$rows = foreach ($t in $tickets) {
    [pscustomobject][ordered]@{
        Number     = $t.ticket_number
        Subject    = $t.subject
        Status     = NameOf $t.status
        Priority   = NameOf $t.priority
        Type       = NameOf $t.ticket_type
        Department = NameOf $t.department
        AssignedTo = NameOf $t.assigned_analyst
        Requester  = if ($t.requester) { $t.requester.email } else { '' }
        Company    = NameOf $t.company
        Created    = Format-IsoDate $t.created_at
        Updated    = Format-IsoDate $t.updated_at
        Id         = $t.id
    }
}

# UTF8 so accented names survive; -NoTypeInformation drops the "#TYPE" line
# Windows PowerShell 5.1 would otherwise put at the top. Excel opens it as-is.
if ($tickets.Count -gt 0) {
    $rows | Export-Csv -Path $OutFile -NoTypeInformation -Encoding UTF8
} else {
    # Export-Csv writes nothing at all for no rows - write the header line ourselves.
    [IO.File]::WriteAllText($OutFile, "Number,Subject,Status,Priority,Type,Department,AssignedTo,Requester,Company,Created,Updated,Id`r`n")
}
Write-Host "Saved $($tickets.Count) open ticket(s) to $OutFile" -ForegroundColor Green
