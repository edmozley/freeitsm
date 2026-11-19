# @title       Warranty expiry report
# @summary     Lists the assets whose warranty runs out in the next N days, and those already out of warranty, optionally saved as a CSV.
# @module      assets
# @changes     no
# @level       starter
# @endpoints   GET /assets
# @permissions assets.read
#
# WHAT IT SHOWS: two lists - assets whose warranty has already ended, and assets
# whose warranty ends within the next -Days days (90 unless you say otherwise) -
# soonest first, with how many days are left. Handy for a monthly "what needs
# renewing or replacing" email.
#
# WHAT IT TEACHES:
#   - PAGING. GET /assets returns at most 100 assets per call. The reply's "meta"
#     part says how many pages there are, so we ask for page 1, 2, 3... until done.
#   - DATE LOGIC ON THE CLIENT. FreeITSM gives each asset's warranty end as a
#     plain date (lifecycle.warranty_expiry, "2027-03-31", or null when nobody
#     recorded one). The script works out the days left itself.
#     (FreeITSM can also filter for you - GET /assets?warranty_within_days=90 -
#     which is quicker on a big estate. Doing it here shows how, and lets one pass
#     produce both lists plus a count of assets with no date at all.)
#
# Retired assets are left out, as on the asset screens. Leased kit has its own
# end date: change "warranty_expiry" to "lease_expiry" below for a lease report.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\warranty-expiry-report.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\warranty-expiry-report.ps1 -Url https://helpdesk.example.com -Days 180 -OutFile .\warranty.csv
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# A key with just assets.read is enough - this script only reads.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far ahead to look, in days.
    [int]$Days = 90,

    # Optional: also save the report as a CSV file (opens in Excel).
    [string]$OutFile
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Unlike the simplest scripts, this one returns the WHOLE reply, not just "data":
# list endpoints put the paging details in "meta" beside it, and we need both.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- Fetch every asset, a page at a time -----------------------------------------
# per_page=100 is the most FreeITSM allows. meta looks like
# { "page": 1, "per_page": 100, "total": 734, "total_pages": 8 }.
$assets = @()
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/assets?per_page=100&sort=warranty_expiry&page=$page"
    $assets += @($reply.data)
    Write-Host "  read page $page of $([Math]::Max(1, $reply.meta.total_pages))" -ForegroundColor DarkGray
    $page++
} while ($page -le $reply.meta.total_pages)

# ---- Work out the days left for each one ------------------------------------------
# Today as a DATE (midnight), so "ends today" is 0 days, not 0.4 of one.
$today = (Get-Date).Date
$rows = @()
$noDate = 0
foreach ($a in $assets) {
    $end = $a.lifecycle.warranty_expiry
    if (-not $end) { $noDate++; continue }
    # ParseExact with the invariant culture: "2027-03-31" means the same thing
    # whatever the PC's regional settings are.
    $endDate = [datetime]::ParseExact($end, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
    $left = ($endDate - $today).Days
    if ($left -gt $Days) { continue }   # comfortably in warranty - not in the report
    $rows += [pscustomobject]@{
        DaysLeft     = $left
        WarrantyEnds = $end
        Hostname     = $a.hostname
        Type         = if ($a.type) { $a.type.name } else { '' }
        Manufacturer = $a.hardware.manufacturer
        Model        = $a.hardware.model
        Serial       = $a.hardware.service_tag
        AssetId      = $a.id
    }
}
$rows = @($rows | Sort-Object DaysLeft)
$expired  = @($rows | Where-Object { $_.DaysLeft -lt 0 })
$expiring = @($rows | Where-Object { $_.DaysLeft -ge 0 })

# ---- Print it -------------------------------------------------------------------------
Write-Host "`nWarranty report for $Base - $($assets.Count) assets checked, $(Get-Date -Format 'yyyy-MM-dd')" -ForegroundColor Cyan

Write-Host "`nAlready out of warranty: $($expired.Count)" -ForegroundColor Red
if ($expired.Count) { $expired | Format-Table DaysLeft, WarrantyEnds, Hostname, Type, Model, Serial -AutoSize | Out-String | Write-Host }

Write-Host "Warranty ends in the next $Days days: $($expiring.Count)" -ForegroundColor Yellow
if ($expiring.Count) { $expiring | Format-Table DaysLeft, WarrantyEnds, Hostname, Type, Model, Serial -AutoSize | Out-String | Write-Host }

# Worth knowing: an asset with no date can never appear above, so a big number
# here means the report is quieter than it should be.
Write-Host "Assets with no warranty date recorded: $noDate" -ForegroundColor DarkGray

if ($OutFile) {
    # UTF8 so names with accents open correctly in Excel.
    $rows | Export-Csv -Path $OutFile -NoTypeInformation -Encoding UTF8
    Write-Host "Saved $($rows.Count) rows to $OutFile" -ForegroundColor Green
}
