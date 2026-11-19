# @title       Back up the knowledge base to HTML
# @summary     Saves every knowledge article as its own HTML page in a folder, with an index.html that links them all - readable in any browser, even when FreeITSM is down.
# @module      knowledge
# @changes     no
# @level       intermediate
# @endpoints   GET /knowledge/articles, GET /knowledge/articles/{id}
# @permissions knowledge.read
#
# WHY: the runbook that tells you how to bring the helpdesk back is no use if it
# lives only inside the helpdesk. Run this nightly and copy the folder somewhere
# that does not depend on FreeITSM - a file share, a USB stick in the safe.
#
# WHAT YOU GET:
#   knowledge-backup\index.html              a table of every article, linking to...
#   knowledge-backup\191-escalating-a-p1-outage.html   ...one page per article
#
# Links between articles (the ?article=123 links the editor writes) are pointed at
# the backup copies, so you can click from one article to another offline.
#
# NOT INCLUDED: pictures. An article's pictures are served by FreeITSM and need
# the knowledge_images.read permission; offline they show as broken images. Add
# GET /knowledge/articles/{id}/images to fetch them if you need them.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\knowledge-backup-to-html.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\knowledge-backup-to-html.ps1 -Url https://helpdesk.example.com -OutFolder D:\kb-backup -IncludeDrafts
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# Keep that file out of source control and readable only by you. The backup is
# only as private as the folder you put it in: the key sees internal articles.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com
    # or http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,

    # Where the key is kept if FREEITSM_API_KEY is not set.
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # Where the HTML files go. Created if missing; files from an earlier run with
    # the same names are overwritten, so the folder always matches today.
    [string]$OutFolder = (Join-Path $PSScriptRoot 'knowledge-backup'),

    # Published articles only, unless you add this.
    [switch]$IncludeDrafts
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'

if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

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
# Returns FreeITSM's WHOLE reply - { "data": ..., "meta": {...} } - because a list
# says in "meta" how many pages there are. On failure FreeITSM answers
# { "error": { "code": "...", "message": "..." } }; we stop and print that message.
function Invoke-FreeItsm([string]$Method, [string]$Path) {
    try {
        return Invoke-RestMethod -Method $Method -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}

# Text we put into OUR page (titles, tags, names) must be escaped, or a title like
# "Fixing <Outlook> & Teams" would break the page or, worse, inject markup.
# The article BODY is different: it is already HTML, stored by FreeITSM's editor,
# so it goes in as it is - the same HTML the app itself shows.
function ConvertTo-HtmlText([string]$Text) { return [Net.WebUtility]::HtmlEncode($Text) }

# A safe file name: the article id (always unique) plus a readable slug of the
# title made of a-z, 0-9 and hyphens only - so no slashes, colons, dots or
# reserved Windows names can sneak in, whatever the title says.
function Get-SafeFileName($Article) {
    $slug = ($Article.title.ToLowerInvariant() -replace '[^a-z0-9]+', '-').Trim('-')
    if ($slug.Length -gt 60) { $slug = $slug.Substring(0, 60).Trim('-') }
    if (-not $slug) { $slug = 'article' }
    return "$($Article.id)-$slug.html"
}

# The pages are written as UTF-8 without a byte order mark, which is what the
# <meta charset="utf-8"> line in each page promises the browser.
$Utf8 = New-Object Text.UTF8Encoding($false)

# A little CSS so the backup is pleasant to read. Change it to taste.
$Style = 'body{font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:2em auto;padding:0 1em;line-height:1.5;color:#222}' +
         'table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:4px 8px;text-align:left;vertical-align:top}' +
         '.meta{color:#555;font-size:.9em}img{max-width:100%}'

# ---- 1. List every article -------------------------------------------------------
# The list gives ids, titles and details but only a 300-character preview of the
# body; the full body needs one GET per article (step 2). Archived (deleted)
# articles are never in this list.
$query = 'sort=title&per_page=100'
if ($IncludeDrafts) { $query += '&include_unpublished=true' }
$articles = @()
$page = 1
do {
    $reply = Invoke-FreeItsm 'GET' "/knowledge/articles?$query&page=$page"
    $articles += @($reply.data)
    $page++
} while ($page -le $reply.meta.total_pages)
Write-Host "$($articles.Count) article(s) to back up from $Base into $OutFolder" -ForegroundColor Cyan

# Work out every file name FIRST, so that while writing article A we already know
# the file name of article B it links to.
$fileFor = @{}
foreach ($a in $articles) { $fileFor[[int]$a.id] = Get-SafeFileName $a }

if (-not (Test-Path $OutFolder)) { [void](New-Item -ItemType Directory -Path $OutFolder) }

# ---- 2. One page per article -------------------------------------------------------
foreach ($a in $articles) {
    # count_view is NOT sent, so a backup does not inflate the article's view count.
    $full = (Invoke-FreeItsm 'GET' "/knowledge/articles/$($a.id)").data
    $body = [string]$full.body_html

    # ?article=123 -> 123-some-title.html when 123 is in this backup. A link to an
    # article the key cannot see (or a draft you left out) is left as it was.
    $body = [regex]::Replace($body, 'href="\?article=(\d+)"', {
        param($m)
        $target = [int]$m.Groups[1].Value
        if ($fileFor.ContainsKey($target)) { 'href="' + $fileFor[$target] + '"' } else { $m.Value }
    })

    $owner  = if ($full.owner)  { $full.owner.name }  else { '(none)' }
    $author = if ($full.author) { $full.author.name } else { '(unknown)' }
    $review = if ($full.next_review_date) { $full.next_review_date } else { '(not set)' }
    $state  = if ($full.is_published) { 'published' } else { 'DRAFT' }
    $title  = ConvertTo-HtmlText $full.title
    $html = @"
<!doctype html>
<html><head><meta charset="utf-8"><title>$title</title><style>$Style</style></head>
<body>
<p><a href="index.html">&larr; All articles</a></p>
<h1>$title</h1>
<p class="meta">Article #$($full.id), version $($full.version), $state, audience $(ConvertTo-HtmlText $full.audience)<br>
Owner: $(ConvertTo-HtmlText $owner) &middot; Author: $(ConvertTo-HtmlText $author) &middot; Last changed: $(ConvertTo-HtmlText $full.modified_at) &middot; Next review: $(ConvertTo-HtmlText $review)<br>
Tags: $(ConvertTo-HtmlText ((@($full.tags)) -join ', '))</p>
<hr>
<article>
$body
</article>
</body></html>
"@
    [IO.File]::WriteAllText((Join-Path $OutFolder $fileFor[[int]$a.id]), $html + "`n", $Utf8)
    Write-Host "  #$($a.id) -> $($fileFor[[int]$a.id])"
}

# ---- 3. The index ------------------------------------------------------------------
$rows = foreach ($a in $articles) {
    $owner = if ($a.owner) { $a.owner.name } else { '' }
    $state = if ($a.is_published) { '' } else { ' (draft)' }
    "<tr><td><a href=""$($fileFor[[int]$a.id])"">$(ConvertTo-HtmlText $a.title)</a>$state</td><td>$(ConvertTo-HtmlText ((@($a.tags)) -join ', '))</td><td>$(ConvertTo-HtmlText $owner)</td><td>$(ConvertTo-HtmlText $a.modified_at)</td></tr>"
}
$stamp = [DateTime]::UtcNow.ToString('yyyy-MM-dd HH:mm')
$index = @"
<!doctype html>
<html><head><meta charset="utf-8"><title>Knowledge base backup</title><style>$Style</style></head>
<body>
<h1>Knowledge base backup</h1>
<p class="meta">$($articles.Count) article(s) from $(ConvertTo-HtmlText $Url), taken $stamp UTC.</p>
<table><tr><th>Title</th><th>Tags</th><th>Owner</th><th>Last changed (UTC)</th></tr>
$($rows -join "`n")
</table>
</body></html>
"@
[IO.File]::WriteAllText((Join-Path $OutFolder 'index.html'), $index + "`n", $Utf8)
Write-Host "`nDone. Open $(Join-Path $OutFolder 'index.html') in a browser." -ForegroundColor Green
