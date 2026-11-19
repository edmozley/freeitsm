# @title       Sync people from an HR spreadsheet
# @summary     Reads a CSV export from your HR system and creates the people FreeITSM is missing, updates the ones that changed, and leaves the rest alone.
# @module      people
# @changes     yes
# @level       intermediate
# @endpoints   GET /users, POST /users, PATCH /users/{id}
# @permissions users.read, users.create, users.update
#
# WHAT IT DOES: for every row in the spreadsheet it asks FreeITSM "do you know
# this email address?" and then
#   - creates the person if not (a "requester" - someone who can raise tickets
#     and sign in to the self-service portal),
#   - updates only the fields that differ if so,
#   - or reports them as unchanged.
# Matching on one key and then creating-or-updating is called an "upsert".
#
# WHAT IT EXPECTS: a CSV file with a header row. Only "email" is required; any
# other column may be left out, and an EMPTY cell means "leave this as it is in
# FreeITSM" - never "clear it". So a half-filled export cannot wipe data.
#
#   email,display_name,preferred_name,employee_id,active
#   jo.bloggs@example.com,Josephine Bloggs,Jo,E1001,yes
#   sam.patel@example.com,Samir Patel,Sam,E1002,yes
#   old.leaver@example.com,Old Leaver,,E0950,no
#
#   email           the match key - one row per person, compared ignoring case
#   display_name    full name as it should appear on tickets
#   preferred_name  what they like to be called
#   employee_id     the payroll / HR number (needs a recent FreeITSM - see below)
#   active          yes / no. "no" marks them as left: kept on record, but they
#                   can no longer sign in to the portal
#
# Most HR systems can export to CSV. If yours names the columns differently,
# rename them in the header row, or change the $row.<name> lines further down.
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\sync-users-from-hr-csv.ps1 -Url https://helpdesk.example.com -CsvFile .\hr-export.csv
#   powershell -ExecutionPolicy Bypass -File .\sync-users-from-hr-csv.ps1 -Url https://helpdesk.example.com -CsvFile .\hr-export.csv -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# It needs the permissions in the @permissions line above.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The HR export to read.
    [string]$CsvFile = (Join-Path $PSScriptRoot 'hr-export.csv'),

    # Without -Apply the script only reads and prints the plan: no create or
    # update call is ever sent.
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

# ---- One helper for every call -------------------------------------------------
# Sends the request with the key as a Bearer token and returns the "data" part of
# FreeITSM's reply. Success looks like { "data": ... }, failure like
# { "error": { "code": "...", "message": "..." } }; on failure we stop and print
# FreeITSM's own message (e.g. 409 conflict when an address is already taken).
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so accented names survive Windows PowerShell 5.1,
        # which otherwise sends the text in the wrong encoding.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# A CSV cell, trimmed; $null when the cell is empty or the column is missing.
# $null is our signal for "the spreadsheet says nothing about this field".
function Get-Cell($Row, [string]$Column) {
    $prop = $Row.PSObject.Properties[$Column]
    if ($null -eq $prop -or $null -eq $prop.Value) { return $null }
    $v = ([string]$prop.Value).Trim()
    if ($v -eq '') { return $null }
    return $v
}

# ---- Read the spreadsheet ---------------------------------------------------------
# Import-Csv turns each line into an object with one property per column. UTF8
# because Excel's "CSV UTF-8" and most HR exports use it; without it Windows
# PowerShell 5.1 reads accented letters wrongly.
if (-not (Test-Path $CsvFile)) { throw "CSV file not found: $CsvFile" }
$rows = @(Import-Csv -Path $CsvFile -Encoding UTF8)
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to sync' }
Write-Host "$($rows.Count) rows in $CsvFile -> $Base   [$mode]" -ForegroundColor Cyan

# Check the whole file BEFORE touching FreeITSM, so a bad row stops the run at the
# start rather than half way through. Two rows for one address would make the
# second silently undo the first, so that is an error too.
$seen = @{}
$line = 1   # the header is line 1
foreach ($row in $rows) {
    $line++
    $email = Get-Cell $row 'email'
    if (-not $email) { throw "Line ${line}: the email cell is empty. Every row needs one - it is how people are matched." }
    if ($email -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') { throw "Line ${line}: '$email' does not look like an email address." }
    $active = Get-Cell $row 'active'
    if ($active -and $active.ToLower() -notin @('yes', 'no', 'true', 'false', '1', '0')) {
        throw "Line ${line}: active must be yes or no, not '$active'."
    }
    $key = $email.ToLower()
    if ($seen.ContainsKey($key)) { throw "Line ${line}: $email is also on line $($seen[$key]). One row per person." }
    $seen[$key] = $line
}

# ---- Sync each person ---------------------------------------------------------------
$created = 0; $updated = 0; $unchanged = 0
foreach ($row in $rows) {
    # FreeITSM stores addresses in lower case, and GET /users?email= compares
    # exactly, so lower-case it here as well.
    $email = (Get-Cell $row 'email').ToLower()

    # What the spreadsheet wants. Only columns with a value go in, so an empty
    # cell leaves FreeITSM's value alone.
    $want = [ordered]@{}
    foreach ($col in 'display_name', 'preferred_name', 'employee_id') {
        $v = Get-Cell $row $col
        if ($null -ne $v) { $want[$col] = $v }
    }
    $active = Get-Cell $row 'active'
    if ($null -ne $active) { $want['is_active'] = $active.ToLower() -in @('yes', 'true', '1') }

    # Look the person up by address. EscapeDataString because an address may hold
    # a "+" (jo+it@example.com), which would otherwise arrive as a space.
    $found = @(Invoke-FreeItsm 'GET' ('/users?email=' + [uri]::EscapeDataString($email)))

    if ($found.Count -eq 0) {
        # ---- Not in FreeITSM: create ----
        # FreeITSM picks the person's company from the domain of their address,
        # the same as when you add someone by hand. Add tenant_id to $body to set
        # it yourself (see System -> Companies for the ids).
        $body = [ordered]@{ email = $email }
        foreach ($k in $want.Keys) { if ($k -ne 'is_active') { $body[$k] = $want[$k] } }
        if (-not $Apply) {
            Write-Host "  would create  $email  ($(if ($want.display_name) { $want.display_name } else { 'name from the address' }))" -ForegroundColor Yellow
            $created++
            continue
        }
        $new = Invoke-FreeItsm 'POST' '/users' $body
        Write-Host "  created       $email -> person #$($new.id)" -ForegroundColor Green
        # POST /users has no is_active field - everyone starts active - so a row
        # that arrives already marked "no" needs a second call.
        if ($want.Contains('is_active') -and -not $want['is_active']) {
            [void](Invoke-FreeItsm 'PATCH' "/users/$($new.id)" @{ is_active = $false })
            Write-Host "                and marked as left"
        }
        $created++
        continue
    }

    # ---- Already there: compare field by field and send only the differences ----
    # Sending only what changed keeps the person's history honest and means a
    # second run with the same file changes nothing at all.
    $user = $found[0]
    $patch = [ordered]@{}
    $changes = @()
    foreach ($k in $want.Keys) {
        $have = $user.$k
        if ($k -eq 'is_active') { $same = ([bool]$have -eq [bool]$want[$k]) }
        else { $same = ([string]$have -ceq [string]$want[$k]) }   # -ceq: "jo" to "Jo" IS a change
        if (-not $same) {
            $patch[$k] = $want[$k]
            $changes += "$k '$have' -> '$($want[$k])'"
        }
    }
    if ($patch.Count -eq 0) {
        Write-Host "  unchanged     $email (#$($user.id))" -ForegroundColor DarkGray
        $unchanged++
        continue
    }
    if (-not $Apply) {
        Write-Host "  would update  $email (#$($user.id)): $($changes -join '; ')" -ForegroundColor Yellow
    } else {
        [void](Invoke-FreeItsm 'PATCH' "/users/$($user.id)" $patch)
        Write-Host "  updated       $email (#$($user.id)): $($changes -join '; ')" -ForegroundColor Green
    }
    $updated++
}

# People in FreeITSM but NOT in the spreadsheet are deliberately left alone: an
# HR export often covers one office or one company, and treating "missing" as
# "left" would mark everyone else as a leaver. Use the "active" column instead.
$verb = if ($Apply) { '' } else { 'would be ' }
Write-Host "`nDone: $created ${verb}created, $updated ${verb}updated, $unchanged unchanged." -ForegroundColor Cyan
