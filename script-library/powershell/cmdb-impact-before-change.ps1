# @title       Impact before a change
# @summary     For one configuration item, prints everything that depends on it - directly and further down the chain - so you know who to warn before you touch it.
# @module      cmdb
# @changes     no
# @level       starter
# @endpoints   GET /cmdb/objects, GET /cmdb/objects/{id}, GET /cmdb/objects/{id}/impact
# @permissions cmdb_objects.read
#
# WHY: "I'm only rebooting the core switch" is how outages start. Before a change,
# ask the CMDB what sits on top of the thing you are changing. This uses the same
# impact engine as the Impact panel in the CMDB module, so the answer matches what
# analysts see on screen.
#
# HOW FREEITSM WORKS IT OUT: starting from your CI, it follows every edge along
# which a failure spreads - children (contained in it), relationships whose type
# says impact flows that way (e.g. "A depends on B": B failing hits A), and
# object-reference properties marked as spreading impact - hop after hop. A
# relationship type whose impact direction is "none" (e.g. "connects to") is
# NOT followed; set the direction under CMDB -> Settings ->
# Relationship types if that is wrong for you.
#
# Run it - by id, or by name (a name must match one CI):
#   powershell -ExecutionPolicy Bypass -File .\cmdb-impact-before-change.ps1 -Url https://helpdesk.example.com -ObjectId 42
#   powershell -ExecutionPolicy Bypass -File .\cmdb-impact-before-change.ps1 -Url https://helpdesk.example.com -Name "Core switch 01"
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

    # The CI to check: its id (shown in the CMDB module's address bar and details)...
    [int]$ObjectId = 0,

    # ...or its name. Matching ignores upper/lower case.
    [string]$Name
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'

if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }
if (-not $ObjectId -and -not $Name) { throw 'Pass -ObjectId 42 or -Name "Core switch 01".' }

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
# Sends the request with the key as a Bearer token and returns the "data" part of
# FreeITSM's reply. On failure FreeITSM answers { "error": { "code", "message" } };
# we stop and print that message. A CI in a company the key cannot reach answers
# 404 "not found", exactly as if it did not exist.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        $reply = Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
        return $reply.data
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- 1. Find the CI --------------------------------------------------------------
# q= is a "contains" search on the name, so "switch" finds "Core switch 01" and
# "Edge switch 02". We take an exact match if there is one, otherwise insist on a
# single hit - guessing between two CIs is the wrong kind of help before a change.
if (-not $ObjectId) {
    $hits = @(Invoke-FreeItsm 'GET' ("/cmdb/objects?per_page=100&q=" + [uri]::EscapeDataString($Name)))
    $exact = @($hits | Where-Object { $_.name -ieq $Name })
    if ($exact.Count -eq 1) { $hits = $exact }
    if ($hits.Count -eq 0) { throw "No CI has a name containing '$Name'." }
    if ($hits.Count -gt 1) {
        Write-Host "More than one CI matches '$Name' - run again with -ObjectId:" -ForegroundColor Yellow
        foreach ($h in $hits) { Write-Host ('  {0,-7} {1}  ({2})' -f $h.id, $h.name, $h.class.name) }
        exit 1
    }
    $ObjectId = $hits[0].id
}
$ci = Invoke-FreeItsm 'GET' "/cmdb/objects/$ObjectId"
Write-Host "Impact of a change to #$($ci.id) $($ci.name) ($($ci.class.name))" -ForegroundColor Cyan

# ---- 2. Ask for the impact ---------------------------------------------------------
# One call returns two views:
#   incoming_relationships / referenced_by_property / descendants
#       - what is attached DIRECTLY, one hop away
#   blast_radius
#       - EVERYTHING ultimately affected, each with its shortest hop count (depth),
#         how it was reached (via_name + via_label) and a grade
$impact = Invoke-FreeItsm 'GET' "/cmdb/objects/$ObjectId/impact"

Write-Host "`nDirectly attached" -ForegroundColor Yellow
$direct = 0
# relationship is the INVERSE verb, so it reads from this CI's side:
# "this is depended on by Payroll app".
foreach ($r in @($impact.incoming_relationships)) { Write-Host "  this $($r.relationship) $($r.name) ($($r.class_name))"; $direct++ }
foreach ($p in @($impact.referenced_by_property)) { Write-Host "  $($p.name) ($($p.class_name)) points at it in '$($p.property)'"; $direct++ }
foreach ($d in @($impact.descendants)) { Write-Host "  $($d.name) ($($d.class_name)) is inside it, $($d.depth) level(s) down"; $direct++ }
if (-not $direct) { Write-Host '  nothing' }

# ---- 3. The blast radius, nearest first ---------------------------------------------
# Grades: "affected" (goes down with it), "may_be_interrupted" or
# "redundancy_reduced" (a cluster or failover pair should carry it), "unknown"
# (FreeITSM lacks a fact it needs - see unknown_via). Without redundancy rules
# set up in the CMDB, everything reached is simply "affected".
$blast = @($impact.blast_radius)
Write-Host "`nEverything affected ($($blast.Count))" -ForegroundColor Yellow
if (-not $blast.Count) { Write-Host '  nothing - no CI depends on this one through an impact-bearing link' }
foreach ($level in ($blast | Group-Object depth | Sort-Object { [int]$_.Name })) {
    Write-Host "  $($level.Name) hop(s) away:"
    foreach ($n in $level.Group) {
        # via_kind says which kind of edge reached it: child, relationship or property.
        $via = switch ($n.via_kind) {
            'child'    { "inside $($n.via_name)" }
            'property' { "its '$($n.via_label)' is $($n.via_name)" }
            default    { "$($n.via_label) $($n.via_name)" }
        }
        $unknown = if (@($n.unknown_via).Count) { "  (unknown via: $(@($n.unknown_via) -join ', '))" } else { '' }
        Write-Host ('    {0,-20} {1} ({2}) - {3}{4}' -f $n.grade, $n.name, $n.class_name, $via, $unknown)
    }
}

# The walk stops at 1000 CIs or 20 hops. When it does, say so loudly: a capped
# list presented as complete is how somebody gets missed.
if ($impact.blast_radius_truncated) {
    Write-Host "`nWARNING: the list was cut short (over 1000 CIs or 20 hops). The real impact is larger." -ForegroundColor Red
}
foreach ($limit in @($impact.blast_radius_grades.limits)) {
    if ($limit.code -eq 'assumed_available') {
        Write-Host "Note: assumed available because nothing records their state: $(@($limit.names) -join ', ')" -ForegroundColor DarkYellow
    }
}
