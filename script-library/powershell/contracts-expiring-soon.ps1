# @title       Contracts expiring soon
# @summary     Lists active contracts ending - or reaching their notice deadline - in the next few weeks, as plain text ready to paste into an email.
# @module      contracts
# @changes     no
# @level       starter
# @endpoints   GET /contracts
# @permissions contracts.read
#
# WHAT IT DOES: asks FreeITSM for two lists and prints them as a short report:
#   1. contracts whose NOTICE date falls in the window - the last day to tell the
#      supplier you are leaving or renegotiating. This is usually the date that
#      matters: miss it and many contracts renew themselves for another term.
#   2. contracts whose END date falls in the window.
# Both lists only include ACTIVE contracts - FreeITSM applies that rule itself.
#
# It only reads, so it is safe to run whenever you like - for example every
# Monday from Task Scheduler, with -OutFile, and attach or paste the file.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\contracts-expiring-soon.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\contracts-expiring-soon.ps1 -Url https://helpdesk.example.com -Days 90 -OutFile .\renewals.txt
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# The report shows only the contracts the key's analyst and companies may see.

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far ahead to look, in days from today.
    [int]$Days = 60,

    # Also save the report to this file (UTF-8). Leave it out to only print it.
    [string]$OutFile = ''
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File], not Get-Content: Get-Content tags the text with hidden properties
    # (PSPath, PSDrive...) that cause trouble later in Windows PowerShell 5.1.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- Fetch every page of a list ---------------------------------------------------
# Lists come back a page at a time: { "data": [...], "meta": { "page": 1,
# "per_page": 100, "total": 230, "total_pages": 3 } }. 100 is the most a page can
# hold, so we ask for 100 and keep going until we have them all.
function Get-FreeItsmAll([string]$Path) {
    $all = @()
    $page = 1
    do {
        $sep = if ($Path.Contains('?')) { '&' } else { '?' }
        try {
            $reply = Invoke-RestMethod -Method GET -Uri ($Base + $Path + "${sep}per_page=100&page=$page") -Headers @{ Authorization = "Bearer $ApiKey" }
        } catch {
            # FreeITSM's own explanation, e.g. 403 "This key lacks contracts.read".
            $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
            throw "GET $Path failed: $detail"
        }
        $all += @($reply.data)
        $page++
    } while ($page -le $reply.meta.total_pages)
    return $all
}

# ---- Ask for the two lists ------------------------------------------------------------
# The window filters are worked out by FreeITSM against TODAY in its own time
# zone, so the script needs no date arithmetic to choose the contracts.
# sort=contract_end puts the most urgent first; notice_date is not sortable, so
# that list is sorted here.
# The outer @( ) matters: Sort-Object hands back a single contract as itself, not
# as a list of one, and a lone contract has no .Count in Windows PowerShell 5.1.
$byNotice = @(@(Get-FreeItsmAll "/contracts?notice_within_days=$Days&sort=contract_end") | Sort-Object { $_.dates.notice_date })
$byEnd    = @(Get-FreeItsmAll "/contracts?expiring_within_days=$Days&sort=contract_end")

# ---- Turn one contract into one readable line ---------------------------------------
$today = (Get-Date).Date
function Format-Line($c, [string]$dateText) {
    $when = [datetime]::ParseExact($dateText, 'yyyy-MM-dd', $null)
    $left = ($when - $today).Days
    $leftText = if ($left -eq 0) { 'today' } elseif ($left -eq 1) { 'tomorrow' } else { "in $left days" }
    # Who the contract is with: a supplier, or for a customer contract the
    # customer company or person.
    $party = if ($c.supplier) { $c.supplier.name }
             elseif ($c.customer -and $c.customer.company) { $c.customer.company.name }
             elseif ($c.customer -and $c.customer.person) { $c.customer.person.name }
             else { 'no supplier recorded' }
    $value = if ($null -ne $c.value.amount) { ' - ' + (('{0} {1:N2}' -f $c.value.currency, [double]$c.value.amount).Trim()) } else { '' }
    $owner = if ($c.owner) { " - owner $($c.owner.name)" } else { '' }
    "  {0} ({1})  {2} {3} - {4}{5}{6}" -f $when.ToString('ddd d MMM yyyy'), $leftText, $c.contract_number, $c.title, $party, $value, $owner
}

# ---- Build the report ---------------------------------------------------------------
$lines = @()
$lines += "Contracts - the next $Days days (as of $($today.ToString('d MMMM yyyy')))"
$lines += ''
$lines += "Notice deadlines ($($byNotice.Count)) - tell the supplier by this date:"
if ($byNotice.Count -eq 0) { $lines += '  none' }
foreach ($c in $byNotice) { $lines += Format-Line $c $c.dates.notice_date }
$lines += ''
$lines += "Contracts ending ($($byEnd.Count)):"
if ($byEnd.Count -eq 0) { $lines += '  none' }
foreach ($c in $byEnd) { $lines += Format-Line $c $c.dates.end }
$lines += ''
$lines += "Full details: $($Url.TrimEnd('/'))/contracts/"

$report = $lines -join [Environment]::NewLine
Write-Output $report
if ($OutFile) {
    # UTF-8 without a byte order mark, so it pastes cleanly anywhere.
    [IO.File]::WriteAllText([IO.Path]::GetFullPath($OutFile), $report, (New-Object Text.UTF8Encoding $false))
    Write-Host "`nSaved to $OutFile" -ForegroundColor Cyan
}
