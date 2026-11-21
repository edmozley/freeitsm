# @title       What the knowledge base does not answer
# @summary     Groups the tickets closed in the last 30 days by the words in their subjects, searches the knowledge base for each group, and lists the busy topics with no article - your next articles to write.
# @module      knowledge
# @changes     no
# @level       intermediate
# @endpoints   GET /tickets, GET /knowledge/articles
# @permissions tickets.read, knowledge.read
#
# WHAT IT SHOWS: joining two modules on the client. FreeITSM has no "which
# tickets have no article" call - the two modules do not know about each other -
# but the API gives you both halves:
#
#   1. The tickets CLOSED in the last -Days days (GET /tickets, paged).
#   2. The words their subjects have in common. Each word is counted once per
#      ticket, and everyday words ("the", "please", "issue") are skipped - see
#      $StopWords below and add your own.
#   3. For every word shared by at least -MinTickets tickets, a keyword search
#      of the knowledge base (GET /knowledge/articles?q=word). No published
#      article mentioning the word = a gap: people keep asking, nothing answers.
#      For a gap it also looks for an unpublished DRAFT, so you can finish that
#      instead of starting again.
#
# The knowledge search matches the word anywhere in an article's title or text
# (it is a "contains" search, so "print" also finds "printer"). Treat the result
# as a shortlist to read, not a verdict: a word like "laptop" may be covered by
# an article that never answers this particular question.
#
# "Closed" means a status marked as closed in Tickets -> Settings -> Statuses.
# If your team uses a "Resolved" status that is NOT marked closed, those tickets
# have no closed date yet and are not counted until they close.
#
# Run it:
#   powershell -ExecutionPolicy Bypass -File .\knowledge-gaps-from-tickets.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\knowledge-gaps-from-tickets.ps1 -Url https://helpdesk.example.com -Days 90 -MinTickets 5
#
# The key is read from the FREEITSM_API_KEY environment variable, or from a file
# (api-key.txt beside this script unless you pass -KeyFile). A read-only key is
# enough: tickets.read and knowledge.read. The knowledge search sees only the
# articles the key's analyst may read, so use the key of someone who can see
# the whole knowledge base, or "no article" may only mean "none you can see".

param(
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far back to look, by when the ticket was CLOSED.
    [int]$Days = 30,

    # A word must appear in at least this many tickets to be a topic.
    [int]$MinTickets = 3,

    # Search the knowledge base for at most this many topics (busiest first) -
    # each one is a call, and every call counts towards the key's rate limit.
    [int]$MaxTopics = 25
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

# Words too common to be a topic. All lower case; add the ones your own
# subjects are full of (a company name, "laptop" if every ticket says it...).
$StopWords = @(
    'the', 'and', 'for', 'with', 'not', 'can', 'cannot', 'cant', 'from', 'this', 'that', 'have', 'has',
    'are', 'was', 'were', 'our', 'your', 'you', 'its', 'but', 'all', 'any', 'some', 'into', 'out', 'off',
    'how', 'what', 'when', 'why', 'who', 'which', 'does', 'doesn', 'don', 'did', 'will', 'won', 'just',
    'please', 'help', 'issue', 'issues', 'problem', 'problems', 'request', 'requests', 'urgent', 'asap',
    'ticket', 'need', 'needs', 'needed', 'new', 'unable', 'working', 'work', 'works', 'again',
    'still', 'after', 'before', 'get', 'getting', 'set', 'use', 'using', 'fwd', 'query', 'question',
    'support', 'user', 'users', 'there', 'their', 'they', 'them', 'been', 'being', 'one', 'two', 'way',
    'every', 'keeps', 'also', 'about', 'more', 'than', 'then', 'only', 'very'
)
$stop = New-Object 'System.Collections.Generic.HashSet[string]'
foreach ($w in $StopWords) { [void]$stop.Add($w) }

# ---- One helper for every call -------------------------------------------------
# Returns the whole reply ({ data, meta }) because lists page; stops with
# FreeITSM's own error message on failure, e.g. 403 "This key lacks ...".
function Invoke-FreeItsm([string]$Path) {
    try {
        return Invoke-RestMethod -Method GET -Uri ($Base + $Path) -Headers @{ Authorization = "Bearer $ApiKey" }
    } catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "GET $Path failed: $detail"
    }
}

# ---- 1. The tickets closed in the window ----------------------------------------
# state=closed keeps only tickets in a closed status NOW (a ticket closed and
# then reopened has left the list); closed_since filters on the closed date.
# "Z" = UTC, which is how FreeITSM stores every date. sort=id keeps the pages
# steady if tickets close while we read.
$since = [DateTime]::UtcNow.AddDays(-$Days).ToString('yyyy-MM-ddTHH:mm:ssZ')
$tickets = New-Object System.Collections.Generic.List[object]
$page = 1
do {
    $reply = Invoke-FreeItsm "/tickets?state=closed&closed_since=$since&sort=id&per_page=100&page=$page"
    foreach ($t in @($reply.data)) { $tickets.Add($t) }
    $page++
} while ($page -le $reply.meta.total_pages)
Write-Host "$($tickets.Count) ticket(s) closed since $since" -ForegroundColor Cyan

# ---- 2. The words their subjects share -----------------------------------------------
# Split on anything that is not a letter or a digit (\p{L} keeps accented and
# non-Latin letters), lower case, drop short words, numbers and stop words. A
# HashSet per ticket counts a word once even if the subject says it twice.
$topics = @{}          # word -> list of tickets
foreach ($t in $tickets) {
    $seen = New-Object 'System.Collections.Generic.HashSet[string]'
    foreach ($w in ([string]$t.subject).ToLowerInvariant() -split '[^\p{L}\p{N}]+') {
        if ($w.Length -lt 3 -or $w -match '^\d+$' -or $stop.Contains($w) -or -not $seen.Add($w)) { continue }
        if (-not $topics.ContainsKey($w)) { $topics[$w] = New-Object System.Collections.Generic.List[object] }
        $topics[$w].Add($t)
    }
}
$busy = @($topics.GetEnumerator() | Where-Object { $_.Value.Count -ge $MinTickets } |
    Sort-Object @{ Expression = { $_.Value.Count }; Descending = $true }, Name | Select-Object -First $MaxTopics)
Write-Host "$($busy.Count) topic(s) shared by $MinTickets or more tickets" -ForegroundColor Cyan

# ---- 3. Search the knowledge base for each topic -----------------------------------
# GET /knowledge/articles?q= searches PUBLISHED articles unless
# include_unpublished=true is added. per_page=3 is enough: meta.total says how
# many matched, and the first titles show what they are.
$gaps = New-Object System.Collections.Generic.List[object]
$covered = New-Object System.Collections.Generic.List[object]
foreach ($topic in $busy) {
    $word = $topic.Name
    $q = [uri]::EscapeDataString($word)
    $found = Invoke-FreeItsm "/knowledge/articles?q=$q&per_page=3"
    # Which ticket types the group is made of - "8 Incident, 2 Question" says
    # whether the article is a fix or a how-to.
    $types = ($topic.Value | Group-Object { if ($_.ticket_type) { $_.ticket_type.name } else { 'no type' } } |
        Sort-Object Count -Descending | ForEach-Object { "$($_.Count) $($_.Name)" }) -join ', '
    $row = [pscustomobject]@{
        Topic    = $word
        Tickets  = $topic.Value.Count
        Types    = $types
        Examples = @($topic.Value | Select-Object -First 3 | ForEach-Object { "$($_.ticket_number) $($_.subject)" })
        Articles = $found.meta.total
        Titles   = @($found.data | ForEach-Object { $_.title })
        Draft    = ''
    }
    if ($found.meta.total -eq 0) {
        $drafts = Invoke-FreeItsm "/knowledge/articles?q=$q&include_unpublished=true&per_page=1"
        if ($drafts.meta.total -gt 0) { $row.Draft = $drafts.data[0].title }
        $gaps.Add($row)
    } else {
        $covered.Add($row)
    }
}

# ---- 4. The report ----------------------------------------------------------------
Write-Host "`nWhat the knowledge base does not answer - tickets closed in the last $Days day(s)" -ForegroundColor Yellow
Write-Host "`nSuggested articles to write ($($gaps.Count)) - busy topics with no published article:" -ForegroundColor Yellow
if ($gaps.Count -eq 0) { Write-Host '  none' }
foreach ($g in $gaps) {
    Write-Host ("`n  ""{0}"" - {1} ticket(s): {2}" -f $g.Topic, $g.Tickets, $g.Types)
    foreach ($e in $g.Examples) { Write-Host "      $e" }
    if ($g.Draft) { Write-Host "      A draft already mentions it: $($g.Draft)" -ForegroundColor Cyan }
}
Write-Host "`nAlready covered ($($covered.Count)) - check the article answers what people asked:" -ForegroundColor Yellow
if ($covered.Count -eq 0) { Write-Host '  none' }
foreach ($c in $covered) {
    Write-Host ("  ""{0}"" - {1} ticket(s), {2} article(s): {3}" -f $c.Topic, $c.Tickets, $c.Articles, ($c.Titles -join '; '))
}
