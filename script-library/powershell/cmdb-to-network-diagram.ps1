# @title       Draw a network diagram from the CMDB
# @summary     Starting from one configuration item, follows its relationships a few hops out and draws them as a Network Mapper diagram - one node per item in tidy rows, one line per relationship.
# @module      network-mapper
# @changes     yes
# @level       advanced
# @endpoints   GET /cmdb/objects, GET /cmdb/objects/{id}, GET /cmdb-relationship-types, GET /network-diagrams,
#              GET /network-diagrams/{id}, POST /network-diagrams, POST /network-diagrams/{id}/nodes,
#              POST /network-diagrams/{id}/connectors
# @permissions cmdb_objects.read, reference.read, network_diagrams.read, network_diagrams.create,
#              network_diagrams.update
#
# WHY: the CMDB already knows that the payroll app runs on SRV-01, which connects
# to the core switch. Drawing that by hand in Network Mapper means dragging every
# item on and joining every line yourself. This script does the first draft for
# you; you then open it in Network Mapper and tidy the layout.
#
# WHAT IT DOES:
#   1. finds the starting CI (by id, or by a name that matches exactly one CI),
#   2. walks its relationships - BOTH directions, "depends on" and "is depended on
#      by" alike - level by level, up to -Depth hops (default 2),
#   3. lays the CIs out in rows: the starting CI alone at the top, everything one
#      hop away on the row below, two hops on the row below that, and so on,
#   4. creates the diagram, adds every CI as a node in one call, then draws one
#      connector per relationship, labelled with its verb ("connects to") and
#      bound to the CMDB relationship it stands for.
#
# SAFE TO RUN AGAIN: the diagram is found by its title first. When a current
# diagram with that title already exists, the script ADDS only what is missing -
# CIs not yet on it, and relationships not yet drawn - and never moves, restyles
# or deletes anything. Someone may have spent an hour tidying that layout, and a
# node that is no longer related in the CMDB may be on the diagram on purpose.
# New nodes on an existing diagram are sent WITHOUT coordinates, so FreeITSM
# auto-places them in a fresh column to the right of what is there, where they
# are easy to spot and drag into place. To redraw from scratch, give a new -Title
# (or delete the old diagram in Network Mapper first).
#
# THINGS TO KNOW:
#   - Network diagrams belong to the whole install, not to one company, so the
#     title search sees every diagram. CIs, though, are per company: a key can
#     only read - and only place on a diagram - CIs in companies it can reach.
#   - Relationships only. Parent/child containment and "object reference"
#     properties are not drawn; add them in the editor, which lists them under
#     the diagram's suggestions.
#   - -MaxObjects (default 60) stops a walk from the core switch turning into a
#     diagram of the whole estate. The script says when it stopped early.
#
# Run it - first WITHOUT -Apply to see what it would draw:
#   powershell -ExecutionPolicy Bypass -File .\cmdb-to-network-diagram.ps1 -Url https://helpdesk.example.com -Name "Core switch 01"
#   powershell -ExecutionPolicy Bypass -File .\cmdb-to-network-diagram.ps1 -Url https://helpdesk.example.com -ObjectId 42 -Depth 3 -Apply
#   ... -Name "Core switch 01" -OnlyVerbs "connects to" -Title "Core network" -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# It needs the permissions in the @permissions lines above.

param(
    # Your FreeITSM address, without /api/v1. Falls back to FREEITSM_URL.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # The CI to start from: its id (shown in the CMDB module)...
    [int]$ObjectId = 0,
    # ...or its name. Matching ignores upper/lower case.
    [string]$Name,

    # How many hops out from the starting CI to follow. 1 = its direct neighbours.
    [ValidateRange(1, 6)][int]$Depth = 2,

    # Stop adding CIs once the diagram has this many.
    [ValidateRange(2, 500)][int]$MaxObjects = 60,

    # Follow only these relationship types, by verb as shown under CMDB -> Settings
    # -> Relationship types (e.g. "connects to"). Left out: follow every type.
    [string[]]$OnlyVerbs = @(),

    # The diagram's title. Left out: "CMDB map - <starting CI's name>".
    [string]$Title = '',

    # Without -Apply the script only reads the CMDB and prints what it would draw.
    [switch]$Apply
)
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }
if (-not $ObjectId -and -not $Name) { throw 'Pass -ObjectId 42 or -Name "Core switch 01".' }

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
# Returns the "data" part of the reply, or stops with FreeITSM's own message - for
# example 422 "Unknown cmdb_object_id" when a CI is in a company the key cannot reach.
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # UTF-8 bytes so accented CI names survive Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# ---- 1. Which relationship types to follow --------------------------------------
# The verbs are renameable per install, so a typed verb is checked against YOUR
# list (GET /cmdb-relationship-types) - a typo stops the run here rather than
# silently drawing nothing.
$followTypeIds = @{}
if ($OnlyVerbs.Count) {
    $types = @(Invoke-FreeItsm 'GET' '/cmdb-relationship-types')
    foreach ($v in $OnlyVerbs) {
        $hit = @($types | Where-Object { $_.verb -eq $v.Trim() -or $_.inverse_verb -eq $v.Trim() })
        if (-not $hit.Count) { throw "No relationship type '$v'. Choose from: $((@($types | ForEach-Object { $_.verb })) -join ', ')" }
        foreach ($t in $hit) { $followTypeIds[[int]$t.id] = $true }
    }
}

# ---- 2. Find the starting CI -------------------------------------------------------
# q= is a "contains" search, so take an exact match if there is one, otherwise
# insist on a single hit rather than guess.
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
    $ObjectId = [int]$hits[0].id
}

# ---- 3. Walk the relationships, level by level --------------------------------------
# GET /cmdb/objects/{id} returns relationships.outgoing (this CI -> other) and
# relationships.incoming (other -> this CI), each with the relationship's own id,
# its type and verb, and the other CI's id and name. Neighbours in a company the
# key cannot reach are left out by FreeITSM, so they never appear here.
#
# Every CI found is read once, including those on the last level: their
# relationships are not followed further, but a line BETWEEN two CIs already on
# the diagram is still worth drawing.
# Plain hashtables keyed by id. TRAP: not [ordered]@{} - an ordered dictionary
# treats an [int] index as a POSITION, so $objects[42] would be the 43rd CI.
$objects = @{}   # CI id -> @{ Id; Name; Class; Level; Detail; X; Y }
$edges   = @{}   # relationship id -> @{ Id; From; To; Verb }
$queue   = New-Object System.Collections.Generic.Queue[int]
$capped  = $false

$start = Invoke-FreeItsm 'GET' "/cmdb/objects/$ObjectId"
$objects[[int]$start.id] = @{ Id = [int]$start.id; Name = $start.name; Class = $start.class.name; Level = 0; Detail = $start }
$queue.Enqueue([int]$start.id)
if (-not $Title) { $Title = "CMDB map - $($start.name)" }

while ($queue.Count) {
    $id = $queue.Dequeue()
    $me = $objects[$id]
    if ($null -eq $me.Detail) { $me.Detail = Invoke-FreeItsm 'GET' "/cmdb/objects/$id" }
    # Outgoing: this CI is the "from" end. Incoming: the other CI is.
    $links = @()
    foreach ($r in @($me.Detail.relationships.outgoing)) { $links += @{ Rel = $r; From = $id; To = [int]$r.other_id } }
    foreach ($r in @($me.Detail.relationships.incoming)) { $links += @{ Rel = $r; From = [int]$r.other_id; To = $id } }
    foreach ($l in $links) {
        $r = $l.Rel
        if ($followTypeIds.Count -and -not $followTypeIds.ContainsKey([int]$r.type_id)) { continue }
        $other = [int]$r.other_id
        if (-not $objects.Contains($other)) {
            # Only CIs closer than -Depth have their neighbours added.
            if ($me.Level -ge $Depth) { continue }
            if ($objects.Count -ge $MaxObjects) { $capped = $true; continue }
            $objects[$other] = @{ Id = $other; Name = $r.other_name; Class = $r.other_class_name; Level = $me.Level + 1; Detail = $null }
            $queue.Enqueue($other)
        }
        # Each relationship is seen from both ends; its id keeps it to one line.
        $edges[[int]$r.id] = @{ Id = [int]$r.id; From = $l.From; To = $l.To; Verb = $r.verb }
    }
}

# ---- 4. Lay out the rows ---------------------------------------------------------------
# x/y are the node's TOP-LEFT corner in canvas pixels. A medium node is 56px and
# its name is drawn underneath, so 190px across and 170px down leaves room for
# the labels. Each row is centred under the widest one. Within a row, CIs sit
# in the order of the CI above that found them, which keeps lines from crossing
# more than they have to.
$GapX = 190; $GapY = 170; $Left = 80; $Top = 80
$rows = @{}
foreach ($o in $objects.Values) { if (-not $rows.ContainsKey($o.Level)) { $rows[$o.Level] = @() }; $rows[$o.Level] += $o }
$widest = ($rows.Values | ForEach-Object { @($_).Count } | Measure-Object -Maximum).Maximum
$slot = @{}   # id -> position in its row, used to order the next row
foreach ($level in ($rows.Keys | Sort-Object)) {
    $row = @($rows[$level] | Sort-Object @{ Expression = {
        # The left-most neighbour on the row above.
        $oid = $_.Id
        $above = @($edges.Values | Where-Object { $_.From -eq $oid -or $_.To -eq $oid } | ForEach-Object {
            $n = if ($_.From -eq $oid) { $_.To } else { $_.From }
            if ($objects[$n].Level -eq $level - 1) { $slot[$n] }
        } | Sort-Object)
        if ($above.Count) { $above[0] } else { 0 }
    } }, @{ Expression = { $_.Name } })
    $offset = ($widest - $row.Count) * $GapX / 2
    for ($i = 0; $i -lt $row.Count; $i++) {
        $row[$i].X = [int]($Left + $offset + $i * $GapX)
        $row[$i].Y = [int]($Top + $level * $GapY)
        $slot[$row[$i].Id] = $i
    }
}

# ---- 5. Show what was found ----------------------------------------------------------------
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to draw it' }
Write-Host "Diagram '$Title' -> $Base   [$mode]" -ForegroundColor Cyan
foreach ($level in ($rows.Keys | Sort-Object)) {
    Write-Host "  Row $level" -ForegroundColor Yellow
    foreach ($o in @($rows[$level] | Sort-Object { $_.X })) { Write-Host "    #$($o.Id) $($o.Name) ($($o.Class))" }
}
Write-Host "  Relationships ($($edges.Count))" -ForegroundColor Yellow
foreach ($e in @($edges.Values | Sort-Object { $_.Id })) { Write-Host "    $($objects[$e.From].Name) $($e.Verb) $($objects[$e.To].Name)" }
if ($capped) { Write-Host "  Stopped at -MaxObjects $MaxObjects - there is more beyond these. Raise it or lower -Depth." -ForegroundColor DarkYellow }

# ---- 6. Is there a diagram already? ------------------------------------------------------------
# GET /network-diagrams lists only CURRENT versions (the editable leaf of each
# version chain); q= is a "contains" search on the title, so compare exactly here.
$same = @(Invoke-FreeItsm 'GET' ("/network-diagrams?per_page=100&q=" + [uri]::EscapeDataString($Title)) |
    Where-Object { $_.title -ieq $Title })
if ($same.Count -gt 1) { throw "$($same.Count) diagrams are called '$Title' (ids $(($same | ForEach-Object { $_.id }) -join ', ')). Pass a different -Title." }

$nodeOf = @{}          # object id -> node id on the diagram
$drawnRel = @{}        # relationship ids already drawn
$drawnPair = @{}       # "a-b" node pairs already joined (FreeITSM refuses a second line, 409)
if ($same.Count -eq 1) {
    $diagramId = [int]$same[0].id
    $existing = Invoke-FreeItsm 'GET' "/network-diagrams/$diagramId"
    foreach ($n in @($existing.nodes)) { if (-not $nodeOf.ContainsKey([int]$n.object.id)) { $nodeOf[[int]$n.object.id] = [int]$n.id } }
    foreach ($c in @($existing.connectors)) {
        if ($c.relationship) { $drawnRel[[int]$c.relationship.id] = $true }
        $drawnPair["$($c.from.node_id)-$($c.to.node_id)"] = $true
        $drawnPair["$($c.to.node_id)-$($c.from.node_id)"] = $true
    }
    Write-Host "`nDiagram #$diagramId '$Title' already exists ($($existing.node_count) nodes) - only what is missing is added." -ForegroundColor Cyan
} else {
    $diagramId = 0
}

$newObjects = @($objects.Values | Where-Object { -not $nodeOf.ContainsKey($_.Id) })
# A connector is new when its relationship is not drawn yet - and, if both ends
# are already on the diagram, when those two nodes are not joined already.
$newEdges = @($edges.Values | Where-Object {
    if ($drawnRel.ContainsKey($_.Id)) { return $false }
    if ($nodeOf.ContainsKey($_.From) -and $nodeOf.ContainsKey($_.To)) {
        return -not $drawnPair.ContainsKey("$($nodeOf[$_.From])-$($nodeOf[$_.To])")
    }
    return $true
})

if (-not $Apply) {
    $what = if ($diagramId) { "add to diagram #$diagramId" } else { 'create the diagram with' }
    Write-Host "`nWould $what $($newObjects.Count) node(s) and $($newEdges.Count) connector(s)." -ForegroundColor Cyan
    exit 0
}

# ---- 7. Draw it ------------------------------------------------------------------------------------
if (-not $diagramId) {
    $created = Invoke-FreeItsm 'POST' '/network-diagrams' ([ordered]@{
        title       = $Title
        description = "Drawn from the CMDB, starting at $($start.name), $Depth hop(s) out, on $(Get-Date -Format 'yyyy-MM-dd')."
    })
    $diagramId = [int]$created.id
    Write-Host "Created diagram #$diagramId" -ForegroundColor Green
}

# All new nodes in ONE call: {"nodes": [...]} answers with the list of new nodes,
# each carrying its object - so the node ids are matched back by object id, not
# by trusting the order. On an existing diagram x/y are left out (see the top).
if ($newObjects.Count) {
    $nodes = @()
    foreach ($o in $newObjects) {
        $n = [ordered]@{ cmdb_object_id = $o.Id; size = $(if ($o.Level -eq 0) { 'large' } else { 'medium' }) }
        if (-not $same.Count) { $n.x = $o.X; $n.y = $o.Y }
        $nodes += $n
    }
    $added = @(Invoke-FreeItsm 'POST' "/network-diagrams/$diagramId/nodes" @{ nodes = $nodes })
    foreach ($n in $added) { $nodeOf[[int]$n.object.id] = [int]$n.id }
    Write-Host "Added $($added.Count) node(s)" -ForegroundColor Green
}

# One connector per relationship. cmdb_relationship_id ties the line to the CMDB
# relationship, so the diagram knows what it means (GET /network-diagrams/{id}
# shows its verbs); the label is what the editor draws on the line.
$lines = 0
foreach ($e in $newEdges) {
    $c = Invoke-FreeItsm 'POST' "/network-diagrams/$diagramId/connectors" ([ordered]@{
        from_node_id         = $nodeOf[$e.From]
        to_node_id           = $nodeOf[$e.To]
        cmdb_relationship_id = $e.Id
        label                = $e.Verb
    })
    $lines++
}
Write-Host "Added $lines connector(s)" -ForegroundColor Green
Write-Host "`nDone. Open it in Network Mapper to tidy the layout: $($Url.TrimEnd('/'))/network-mapper/diagram.php?id=$diagramId&fit=1" -ForegroundColor Cyan
