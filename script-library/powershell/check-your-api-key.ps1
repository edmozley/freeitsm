# @title       Check your API key
# @summary     Connects to FreeITSM, proves the key works, and lists exactly what it is allowed to do.
# @module      general
# @changes     no
# @level       starter
# @endpoints   GET /ping
# @permissions none
#
# START HERE. Run this first with any new key: if it works, every other script in
# the library will be able to reach your FreeITSM, and you will see which
# permissions the key has - so you know which scripts it can run.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\check-your-api-key.ps1 -Url https://helpdesk.example.com
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
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt')
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'

if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content: Get-Content tags the text with hidden
    # properties (PSPath, PSDrive...) that make ConvertTo-Json crawl - a trap the
    # other scripts in this library avoid the same way.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}

# Every REST call lives under /api/v1 on your server.
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- One helper for every call -------------------------------------------------
# Sends the request with the key as a Bearer token and returns the "data" part of
# FreeITSM's reply. Every answer looks like { "data": ... } on success and
# { "error": { "code": "...", "message": "..." } } on failure; on failure we stop
# and print FreeITSM's own explanation, which is usually all you need to fix it.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        $reply = Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
        return $reply.data
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- Ask FreeITSM who we are ---------------------------------------------------
# /ping needs no permission at all: any valid key may call it. It answers with the
# key's name, the analyst it acts as, its permissions, its companies and expiry.
$ping = Invoke-FreeItsm 'GET' '/ping'

Write-Host "Connected to $Base" -ForegroundColor Green
Write-Host ("Key name    : " + $ping.key.name)
Write-Host ("Acts as     : " + $ping.key.acts_as + "   (everything the key does is recorded as this analyst)")
Write-Host ("Expires     : " + $(if ($ping.key.expires_at) { $ping.key.expires_at } else { 'never' }))
Write-Host ("Server time : " + $ping.server_time + "   (UTC)")

# Companies: an empty list means the key is not limited to particular companies.
if ($ping.key.companies -and @($ping.key.companies).Count -gt 0) {
    Write-Host ("Companies   : " + ((@($ping.key.companies) | ForEach-Object { $_.name }) -join ', '))
} else {
    Write-Host  "Companies   : not limited"
}

# Permissions come back as { "tickets": ["read","create"], "assets": ["read"], ... }.
# Each script in the library lists the ones it needs in its @permissions line.
Write-Host "`nPermissions:" -ForegroundColor Cyan
$perms = $ping.key.permissions
if ($null -eq $perms -or @($perms.PSObject.Properties).Count -eq 0) {
    Write-Host '  (none - this key can only call /ping)'
} else {
    foreach ($p in ($perms.PSObject.Properties | Sort-Object Name)) {
        Write-Host ('  {0,-28} {1}' -f $p.Name, (@($p.Value) -join ', '))
    }
}
