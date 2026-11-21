# @title       Migrate a knowledge base into FreeITSM
# @summary     Recreates another knowledge base - folders, articles, pictures, access lists and links - and is safe to stop and run again.
# @module      knowledge
# @changes     yes
# @level       advanced
# @endpoints   GET /knowledge/images/constraints, POST /knowledge/folders, POST /knowledge/articles,
#              POST /knowledge/articles/{id}/images, GET /knowledge/articles/{id}, PATCH /knowledge/articles/{id}
# @permissions knowledge_images.read, knowledge_folders.create, knowledge.create, knowledge_images.create, knowledge.read, knowledge.update
#
# WHAT IT EXPECTS: an export folder from your old system, in this simple shape
# (write a small exporter for your old tool, or reshape its export into this):
#
#   export\kb.json                 the index - see the example below
#   export\articles\kb-101.html    one HTML file per article
#   export\images\diagram.png      pictures, referenced from the HTML as ../images/diagram.png
#
#   kb.json:
#   { "articles": [
#       { "id": "KB-101", "title": "Reset your VPN token", "folder": "Networking/VPN",
#         "file": "articles/kb-101.html", "tags": ["vpn"], "audience": "internal",
#         "restricted_to_team": null },
#       { "id": "KB-103", "title": "Escalating a P1 outage", "folder": "Service Desk/Runbooks",
#         "file": "articles/kb-103.html", "tags": ["p1"], "audience": "internal",
#         "restricted_to_team": "Escalation Team" } ] }
#
#   Links between articles are written as <a href="kb://KB-101">; the script turns
#   them into links to the new FreeITSM articles.
#
# HOW IT WORKS - the order matters:
#   0. Every picture is checked against what THIS install accepts - its size
#      limit and formats, read from the API - before anything is written, so a
#      picture that would be refused is found now, not half way through.
#   1. Folders, parents first.
#   2. Each article as a DRAFT (only analysts can see it), already in its folder,
#      with its audience and access list - so a restricted article is never
#      visible to everyone, not even for a moment.
#   3. Its pictures, uploaded to that article; each <img src> is pointed at the
#      picture's new address.
#   4. Links rewritten to the new article ids, then each article is PUBLISHED.
#
# SAFE TO RUN AGAIN: every create carries a client_reference (your old system's
# id). Send the same request twice and FreeITSM returns what it made the first
# time instead of making another - so after a timeout or Ctrl+C, just run it again.
#
# Run it - first WITHOUT -Apply to see what it would do:
#   powershell -ExecutionPolicy Bypass -File .\knowledge-migration.ps1 -Url https://helpdesk.example.com -ExportFolder .\export
#   powershell -ExecutionPolicy Bypass -File .\knowledge-migration.ps1 -Url https://helpdesk.example.com -ExportFolder .\export -Apply
#
# The key needs the permissions in the @permissions line above, and its analyst
# must be a Knowledge administrator to set access lists. Docs:
# https://github.com/edmozley/freeitsm/wiki/REST-API-Knowledge#-migrating-a-knowledge-base-in

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),
    [string]$ExportFolder = (Join-Path $PSScriptRoot 'export'),

    # Your old system's team names -> FreeITSM team ids. Map EVERY team your
    # export restricts articles to: FreeITSM refuses an access entry it cannot
    # match rather than quietly dropping it, because a dropped rule could open an
    # article to people who should not see it. Find team ids under System -> Teams.
    [hashtable]$TeamMap = @{ 'Escalation Team' = 10 },

    # Without -Apply the script only reads the export and prints the plan.
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

# Sends a request, returns the "data" part of the reply, stops with FreeITSM's
# own message on failure (e.g. 409 reference_conflict, 422 invalid_access).
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" } }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so accented titles survive Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return (Invoke-RestMethod @params).data }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}
function Outcome($r) { if ($r.replayed) { '(already there - reused)' } else { '(created)' } }

$export = [IO.File]::ReadAllText((Join-Path $ExportFolder 'kb.json'), [Text.Encoding]::UTF8) | ConvertFrom-Json
$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to migrate' }
Write-Host "$($export.articles.Count) articles from $ExportFolder -> $Base   [$mode]" -ForegroundColor Cyan

# Check the team mapping BEFORE writing anything, so a missing team stops the run
# at the start instead of half way through.
foreach ($a in $export.articles) {
    if ($a.restricted_to_team -and -not $TeamMap.ContainsKey($a.restricted_to_team)) {
        throw "Article $($a.id) is restricted to '$($a.restricted_to_team)', which is not in -TeamMap."
    }
}

# ---- 0. Will FreeITSM take every picture? -----------------------------------------
# The largest picture is an administrator's choice (Knowledge -> Settings -> Images,
# 10 MB unless changed) and only some formats are accepted - never SVG, which can
# carry a script. So ask the install rather than guessing, and check every picture
# NOW: a refused picture otherwise stops the run half way, with drafts already made.
$limits = Invoke-FreeItsm 'GET' '/knowledge/images/constraints'
$accepted = @($limits.formats | ForEach-Object { $_.extension })
$limitMb = $limits.max_bytes / 1MB
Write-Host "`n0. Pictures - this install takes up to $limitMb MB each: $(($accepted | Sort-Object) -join ', ')" -ForegroundColor Yellow
if (-not $limits.within_server_limits) {
    # FreeITSM's limit is fine but the server's PHP is set too low to carry it.
    Write-Host "  ! This server's PHP settings are too low for pictures that big - see the warning" -ForegroundColor DarkYellow
    Write-Host "    under Knowledge -> Settings -> Images, or keep the pictures smaller." -ForegroundColor DarkYellow
}

$refused = @()
foreach ($a in $export.articles) {
    $articleFile = Join-Path $ExportFolder $a.file
    $articleDir = Split-Path $articleFile -Parent
    $html = [IO.File]::ReadAllText($articleFile, [Text.Encoding]::UTF8)
    foreach ($m in [regex]::Matches($html, 'src="((?!https?:|data:)[^"]+)"')) {
        $rel = $m.Groups[1].Value
        $file = [IO.Path]::GetFullPath((Join-Path $articleDir $rel))
        $ext = [IO.Path]::GetExtension($file).TrimStart('.').ToLower()
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
            $refused += "$($a.id): $rel - the file is not in the export"
        } elseif ($accepted -notcontains $ext) {
            $refused += "$($a.id): $rel - .$ext pictures are not accepted (convert it to PNG or JPEG)"
        } elseif ((Get-Item -LiteralPath $file).Length -gt $limits.max_bytes) {
            $refused += "$($a.id): $rel - {0:N1} MB, over the $limitMb MB limit" -f ((Get-Item -LiteralPath $file).Length / 1MB)
        }
    }
}

if ($refused.Count) {
    Write-Host "  $($refused.Count) picture(s) would be refused:" -ForegroundColor Red
    foreach ($line in $refused) { Write-Host "    - $line" }
    Write-Host '  Shrink or convert them, or raise the limit under Knowledge -> Settings -> Images.'
    if ($Apply) { throw 'Nothing was written. Fix the pictures above and run again.' }
} else {
    Write-Host '  every picture fits'
}

# ---- 1. Folders -----------------------------------------------------------------
# "Networking/VPN" needs "Networking" first. The cache stops us asking twice.
$folderIds = @{}
function Get-FolderId([string]$Path) {
    if ($folderIds.ContainsKey($Path)) { return $folderIds[$Path] }
    $parts = $Path -split '/'
    $parentId = $null
    if ($parts.Count -gt 1) { $parentId = Get-FolderId (($parts[0..($parts.Count - 2)]) -join '/') }
    if (-not $Apply) { Write-Host "  would create folder $Path"; $folderIds[$Path] = "($Path)"; return $folderIds[$Path] }
    $f = Invoke-FreeItsm 'POST' '/knowledge/folders' @{ name = $parts[-1]; parent_id = $parentId; client_reference = "folder:$Path" }
    Write-Host "  folder  $Path -> #$($f.id) $(Outcome $f)"
    $folderIds[$Path] = $f.id
    return $f.id
}
Write-Host "`n1. Folders" -ForegroundColor Yellow
foreach ($a in $export.articles) { [void](Get-FolderId $a.folder) }

# ---- 2 + 3. Articles as drafts, then their pictures -------------------------------
Write-Host "`n2. Articles (as drafts) and 3. their pictures" -ForegroundColor Yellow
$new = @{}
foreach ($a in $export.articles) {
    $articleFile = Join-Path $ExportFolder $a.file
    # TRAP: [IO.File], not Get-Content. Get-Content tags the string with hidden
    # PSPath/PSDrive properties and ConvertTo-Json then walks the whole provider
    # object graph - in Windows PowerShell 5.1 it effectively never finishes.
    $html = [IO.File]::ReadAllText($articleFile, [Text.Encoding]::UTF8)
    # Local pictures only - leave http:, https: and data: images alone. Paths are
    # relative to the ARTICLE's folder, the way a browser previewing the export reads them.
    $pictures = @([regex]::Matches($html, 'src="((?!https?:|data:)[^"]+)"') | ForEach-Object { $_.Groups[1].Value })

    if (-not $Apply) {
        $note = if ($a.restricted_to_team) { " restricted to $($a.restricted_to_team)" } else { '' }
        Write-Host "  would create draft '$($a.title)' in $($a.folder)$note, with $($pictures.Count) picture(s)"
        continue
    }
    $body = @{
        title            = $a.title
        body_html        = $html
        tags             = @($a.tags)
        audience         = $a.audience
        folder_id        = $folderIds[$a.folder]
        is_published     = $false      # a draft until its pictures and links are in place
        processing       = 'suppress'  # no AI or workflow calls for each imported article
        client_reference = "article:$($a.id)"
    }
    if ($a.restricted_to_team) {
        # Saved in the same call as the article: never briefly open to everyone.
        $body.access = @{ is_restricted = $true; inherits = $false
                          entries = @(@{ principal_type = 'team'; principal_id = $TeamMap[$a.restricted_to_team] }) }
    }
    $art = Invoke-FreeItsm 'POST' '/knowledge/articles' $body
    Write-Host "  article $($a.id) '$($a.title)' -> #$($art.id) $(Outcome $art)"

    $imageIds = @()
    $articleDir = Split-Path $articleFile -Parent
    foreach ($rel in $pictures) {
        $file = [IO.Path]::GetFullPath((Join-Path $articleDir $rel))
        $img = Invoke-FreeItsm 'POST' "/knowledge/articles/$($art.id)/images" @{
            filename = [IO.Path]::GetFileName($file)
            content_base64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes($file))
            client_reference = "image:$($a.id):$rel" }
        Write-Host "    picture $rel -> image #$($img.id) $(Outcome $img)"
        # The returned url checks the article's permissions on every view.
        $html = $html.Replace("src=""$rel""", "src=""$($img.url)""")
        $imageIds += $img.id
    }
    $new[$a.id] = @{ id = $art.id; html = $html; images = $imageIds }
}

# ---- 4. Links, then publish ---------------------------------------------------------
if ($Apply) {
    Write-Host "`n4. Links and publishing" -ForegroundColor Yellow
    foreach ($a in $export.articles) {
        $n = $new[$a.id]
        # kb://KB-101 -> ?article=<new id>, the same link the editor's link picker writes.
        $html = [regex]::Replace($n.html, 'href="kb://([^"]+)"', {
            param($m)
            if (-not $new.ContainsKey($m.Groups[1].Value)) { throw "Article $($a.id) links to $($m.Groups[1].Value), which is not in the export." }
            'href="?article=' + $new[$m.Groups[1].Value].id + '"' })
        # Read it first: the revision proves nobody edited it since, and we skip
        # articles a previous run already finished.
        $current = Invoke-FreeItsm 'GET' "/knowledge/articles/$($n.id)"
        if ($current.is_published -and $current.body_html -eq $html) { Write-Host "  #$($n.id) already published - nothing to do"; continue }
        $patch = @{ body_html = $html; is_published = $true; expected_revision = $current.revision }
        # Refuse to publish if a picture went missing or belongs to another article.
        if ($n.images.Count -gt 0) { $patch.required_image_ids = @($n.images) }
        [void](Invoke-FreeItsm 'PATCH' "/knowledge/articles/$($n.id)" $patch)
        Write-Host "  #$($n.id) '$($a.title)' published" -ForegroundColor Green
    }
}
Write-Host "`nDone." -ForegroundColor Cyan
