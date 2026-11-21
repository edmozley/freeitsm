# @title       Spot repeat incidents and open a problem
# @summary     Looks at recent tickets, finds groups that keep coming back - same configuration item, or the same word in the subject - and opens a problem for each group, linking the tickets and noting why.
# @module      problems
# @changes     yes
# @level       advanced
# @endpoints   GET /tickets, GET /problems, GET /problem-statuses, GET /problem-priorities, POST /problems, POST /problems/{id}/tickets, POST /problems/{id}/notes
# @permissions tickets.read, problems.read, reference.read, problems.create, problem_links.create, problem_notes.create
#
# WHY: problem management starts with noticing that the same thing keeps
# breaking. On a busy desk nobody sees that the fifth "printer offline" this
# fortnight is the same printer. This script looks for those patterns and turns
# each into a problem with the evidence already linked. (To link tickets to a
# problem you ALREADY have, by a keyword you choose, use "Link matching tickets
# to a problem" instead - this one FINDS the groups for you.)
#
# HOW THE GROUPS ARE FOUND - deliberately simple, so you can predict it:
#   1. Tickets created in the last -Days days are read, with the records each
#      one is linked to (?include=related). Tickets already linked to a problem
#      are left out - they are explained already - EXCEPT ones linked to a
#      problem this script opened, so that group keeps its name on the next run.
#   2. SAME CONFIGURATION ITEM: tickets linked to the same CMDB item form a group.
#      This is the strongest signal. It needs tickets to be linked to CMDB items,
#      and the key to be allowed to read CMDB items (cmdb_objects read) - without
#      that the API leaves CMDB links out of the reply and this step finds nothing.
#   3. SAME WORD IN THE SUBJECT: for the rest, each subject is cut into words.
#      Short words (under -MinWordLength letters), numbers and everyday words
#      ("cannot", "please", "working"...) are dropped - see $StopWords below and
#      add your own. The word shared by the most tickets becomes a group, those
#      tickets are taken out, and it repeats. "Printer offline on floor 3" and
#      "Floor 3 printer jams" both land in "printer".
#   4. A group with at least -MinTickets tickets is proposed as a problem.
#   Groups are found within one company at a time, because a problem and the
#   tickets linked to it must belong to the same company.
#
# SAFE TO RUN AGAIN: each problem is titled "Repeat incidents: <what they share>".
# Before creating one the script searches the open problems for that exact title
# in that company; if it is there, it only links the new tickets and adds a note
# saying how many. Closing the problem ends it: its tickets are then explained,
# and a NEW group needs -MinTickets fresh tickets before another is proposed.
#
# Run it - first WITHOUT -Apply to see the groups:
#   powershell -ExecutionPolicy Bypass -File .\spot-repeat-incidents.ps1 -Url https://helpdesk.example.com
#   powershell -ExecutionPolicy Bypass -File .\spot-repeat-incidents.ps1 -Url https://helpdesk.example.com -Days 30 -MinTickets 4 -Apply
#
# The key is NEVER written into a script. It is read from (first match wins):
#   1. the FREEITSM_API_KEY environment variable, or
#   2. a file - api-key.txt beside this script unless you pass -KeyFile.
# Keep that file out of source control and readable only by you. Problems, links
# and notes are recorded as the analyst the key acts as.

param(
    # Your FreeITSM address, without /api/v1 - for example https://helpdesk.example.com
    # or http://localhost/freeitsm. Falls back to the FREEITSM_URL environment variable.
    [string]$Url = $env:FREEITSM_URL,
    [string]$KeyFile = (Join-Path $PSScriptRoot 'api-key.txt'),

    # How far back to look, in days.
    [int]$Days = 14,

    # How many tickets make a pattern worth a problem.
    [ValidateRange(2, 10000)]
    [int]$MinTickets = 3,

    # Words shorter than this are ignored ("pc", "mfa" - raise or lower to taste).
    [int]$MinWordLength = 4,

    # Only tickets of this type, by name - for example Incident. Empty = every type.
    [string]$TicketType = '',

    # Only tickets whose subject or number contains this text - to look at one
    # service ("Outlook") or to try the script out on a few tickets first. Its own
    # words are never used as a group (every ticket would share them).
    [string]$SubjectContains = '',

    # The new problem's priority and status, by name. Empty = your defaults
    # (Problems -> Settings).
    [string]$Priority = '',
    [string]$Status = '',

    # A safety net: stop rather than read more tickets than this.
    [int]$Max = 2000,

    # Without -Apply the script only prints the groups it found.
    [switch]$Apply
)

# Stop at the first error instead of carrying on with bad data.
$ErrorActionPreference = 'Stop'
if (-not $Url) { throw 'Pass -Url https://your-freeitsm (or set FREEITSM_URL).' }

# ---- Words that never make a group: edit this list for your desk ---------------------
# Lower case. Anything three letters or shorter is dropped anyway (-MinWordLength).
$StopWords = @{}
foreach ($w in @(
    'about', 'after', 'again', 'also', 'and', 'any', 'anyone', 'because', 'been', 'before',
    'being', 'cannot', "can't", 'could', 'does', "doesn't", 'done', 'error', 'every', 'from',
    'have', 'having', 'help', 'how', 'issue', 'issues', 'just', 'keeps', 'need', 'needed',
    'needs', 'not', 'please', 'problem', 'problems', 'request', 'since', 'some', 'still',
    'that', 'their', 'them', 'then', 'there', 'this', 'today', 'urgent', 'very', 'want',
    'when', 'where', 'which', 'will', 'with', "won't", 'work', 'working', 'works', 'would',
    'your', 'fwd', 'fw', 're')) { $StopWords[$w] = $true }

$Prefix = 'Repeat incidents: '

# ---- The key ------------------------------------------------------------------
$ApiKey = $env:FREEITSM_API_KEY
if (-not $ApiKey) {
    if (-not (Test-Path $KeyFile)) { throw "No key: set FREEITSM_API_KEY or put the key in $KeyFile" }
    # [IO.File] rather than Get-Content: Get-Content tags the text with hidden
    # properties (PSPath, PSDrive...) that make ConvertTo-Json crawl - and hang.
    $ApiKey = [IO.File]::ReadAllText($KeyFile).Trim()
}

# Every REST call lives under /api/v1 on your server.
$Base = $Url.TrimEnd('/') + '/api/v1'

# ---- Helpers --------------------------------------------------------------------
# Sends a request and returns FreeITSM's WHOLE reply ({ "data": ..., "meta": ... });
# on failure it stops with FreeITSM's own message ({ "error": { "message": ... } }).
function Invoke-FreeItsmRaw([string]$Method, [string]$Path, $Body = $null) {
    $params = @{ Method = $Method; Uri = $Base + $Path; Headers = @{ Authorization = "Bearer $ApiKey" }; TimeoutSec = 60 }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        # Send UTF-8 bytes so non-English text survives Windows PowerShell 5.1.
        $params.Body = [Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10))
    }
    try { return Invoke-RestMethod @params }
    catch {
        $detail = if ($_.ErrorDetails) { $_.ErrorDetails.Message } else { $_.Exception.Message }
        throw "$Method $Path failed: $detail"
    }
}
function Invoke-FreeItsm([string]$Method, [string]$Path, $Body = $null) { (Invoke-FreeItsmRaw $Method $Path $Body).data }

function Format-Query([hashtable]$Filters) {
    ($Filters.GetEnumerator() | ForEach-Object { "$($_.Key)=$([uri]::EscapeDataString([string]$_.Value))" }) -join '&'
}

# The significant words of a subject, each once, lower case.
function Get-Words([string]$Text) {
    $set = @{}
    foreach ($m in [regex]::Matches($Text.ToLowerInvariant(), "[^\W_]+(?:['-][^\W_]+)*")) {
        $w = $m.Value
        if ($w.Length -ge $MinWordLength -and $w -notmatch '^\d+$' -and -not $StopWords.ContainsKey($w)) { $set[$w] = $true }
    }
    return $set
}

# Find a reference row by name (ignoring capitals), or stop with the choices.
function Find-ByName($Rows, [string]$Name, [string]$What) {
    $row = @($Rows) | Where-Object { $_.name.Trim() -eq $Name.Trim() } | Select-Object -First 1
    if (-not $row) { throw "No $What called '$Name'. Choose one of: $((@($Rows) | ForEach-Object { $_.name }) -join ', ')" }
    return $row
}

# A company's id, or $null for "no company" (see step 3).
function Get-CompanyId($Record) { if ($Record.company) { [int]$Record.company.id } else { $null } }

$mode = if ($Apply) { 'APPLYING' } else { 'PREVIEW - nothing will be written; add -Apply to open the problems' }
Write-Host "Spot repeat incidents -> $Base   [$mode]" -ForegroundColor Cyan

# ---- 1. The new problem's priority and status ---------------------------------------
# Checked first, so a typo stops the script before it has read a thing. Sent by
# id; left out entirely when not given, so FreeITSM applies your defaults.
$problemFields = @{}
if ($Priority) { $problemFields.priority_id = (Find-ByName (Invoke-FreeItsm 'GET' '/problem-priorities') $Priority 'problem priority').id }
if ($Status) {
    $st = Find-ByName (Invoke-FreeItsm 'GET' '/problem-statuses') $Status 'problem status'
    if ($st.is_closed) { throw "'$($st.name)' is a closed status - a new problem should start open." }
    $problemFields.status_id = $st.id
}

# ---- 2. The problems this script opened before ------------------------------------------
# q= is a "contains" search over the title and PRB number; state=open leaves
# closed ones out on purpose (see SAFE TO RUN AGAIN).
$ours = @(); $page = 1
do {
    $reply = Invoke-FreeItsmRaw 'GET' "/problems?$(Format-Query @{ q = $Prefix.Trim(); state = 'open'; per_page = 100; page = $page })"
    $ours += @($reply.data | Where-Object { $_.title.StartsWith($Prefix) })
    $page++
} while ($page -le $reply.meta.total_pages)
$ourIds = @{}
foreach ($p in $ours) { $ourIds[[int]$p.id] = $true }

# Our open problem with this exact title in this company. A ticket with no
# company (company: null - usual on a single-company install) belongs to the
# default company, which is where a problem created without company_id lands;
# so a null on either side matches any company.
function Find-Ours($CompanyId, [string]$Title) {
    foreach ($p in $ours) {
        $pid_ = Get-CompanyId $p
        if ($p.title -ceq $Title -and ($null -eq $CompanyId -or $null -eq $pid_ -or $pid_ -eq $CompanyId)) { return $p }
    }
    return $null
}

# ---- 3. The recent tickets, with what they are linked to --------------------------------------
# created_since is an exact moment (UTC, with Z). include=related adds each
# ticket's links - problems, CMDB items, assets... - in the same reply, so there
# is no need for a second call per ticket.
$since = [datetime]::UtcNow.AddDays(-$Days).ToString('yyyy-MM-ddTHH:mm:ssZ')
$filters = @{ created_since = $since; sort = 'created_at' }
if ($SubjectContains) { $filters.q = $SubjectContains }
$query = Format-Query $filters
$first = Invoke-FreeItsmRaw 'GET' "/tickets?$query&include=related&per_page=100&page=1"
if ($first.meta.total -gt $Max) {
    throw "$($first.meta.total) tickets in the last $Days days - more than -Max $Max. Use fewer -Days, or raise -Max if you mean it."
}
$tickets = @($first.data)
for ($page = 2; $page -le $first.meta.total_pages; $page++) {
    $tickets += @(Invoke-FreeItsm 'GET' "/tickets?$query&include=related&per_page=100&page=$page")
}

# Tickets from before companies were switched on say "company": null. They
# belong to the default company - FreeITSM treats null as the default everywhere,
# including the problem-to-ticket "same company" rule - but the reply does not
# say which company that is. The ticket list's own company_id filter does know:
# asking for the default company also returns the null ones. So for each company
# we saw, one small call (per_page=1, we only want meta.total) tells us whether
# its count includes the nulls. Without this, the same company would be split in
# two and a group could be missed.
$nulls = @($tickets | Where-Object { -not $_.company })
$seen = @{}
foreach ($t in $tickets) { if ($t.company) { $seen[[int]$t.company.id] = $t.company } }
if ($nulls.Count -gt 0 -and $seen.Count -gt 0) {
    foreach ($cid in @($seen.Keys)) {
        $own = @($tickets | Where-Object { $_.company -and [int]$_.company.id -eq $cid }).Count
        $total = (Invoke-FreeItsmRaw 'GET' "/tickets?$query&company_id=$cid&per_page=1").meta.total
        if ($total -eq $own + $nulls.Count) {
            foreach ($t in $nulls) { $t.company = $seen[$cid] }
            break
        }
    }
}

$ignoreWords = Get-Words $SubjectContains
$candidates = @()
foreach ($t in $tickets) {
    if ($TicketType -and (-not $t.ticket_type -or $t.ticket_type.name -ne $TicketType)) { continue }
    $links = @($t.related.links)
    $problemIds = @($links | Where-Object { $_.type -eq 'problem' } | ForEach-Object { [int]$_.id })
    if (@($problemIds | Where-Object { -not $ourIds.ContainsKey($_) }).Count -gt 0) { continue }   # explained by somebody else's problem
    $words = Get-Words $t.subject
    foreach ($w in @($ignoreWords.Keys)) { $words.Remove($w) }
    $candidates += [pscustomobject]@{
        Ticket   = $t
        Id       = [int]$t.id
        Company  = Get-CompanyId $t
        LinkedTo = $problemIds
        Cis      = @($links | Where-Object { $_.type -eq 'cmdb_object' })
        Words    = $words
    }
}
Write-Host "Tickets since $($since.Substring(0, 10)): $($tickets.Count); still unexplained: $($candidates.Count)"

# ---- 4. Find the groups, one company at a time ---------------------------------------------------
$groups = @()
$companies = @($candidates | Group-Object -Property { if ($null -eq $_.Company) { '' } else { [string]$_.Company } })
foreach ($cg in $companies) {
    $pool = @($cg.Group)
    $companyId = $pool[0].Company
    $companyName = if ($pool[0].Ticket.company) { $pool[0].Ticket.company.name } else { $null }

    # 4a. Same configuration item. A ticket linked to two items can count in both.
    $byCi = @{}
    foreach ($c in $pool) {
        foreach ($ci in $c.Cis) {
            $key = "$($ci.id)"
            if (-not $byCi.ContainsKey($key)) { $byCi[$key] = [pscustomobject]@{ Name = $ci.name; Members = @() } }
            $byCi[$key].Members += $c
        }
    }
    $grouped = @{}
    foreach ($g in ($byCi.Values | Sort-Object @{ Expression = { $_.Members.Count }; Descending = $true }, Name)) {
        if ($g.Members.Count -ge $MinTickets) {
            $groups += [pscustomobject]@{ CompanyId = $companyId; CompanyName = $companyName; Title = "$Prefix$($g.Name)"
                Why = "$($g.Members.Count) tickets linked to the configuration item '$($g.Name)'"; Members = $g.Members }
            foreach ($m in $g.Members) { $grouped[$m.Id] = $true }
        }
    }

    # 4b. Same subject word, for the tickets no CI group took. Greedy: the most
    # shared word wins (ties: alphabetical, so every run gives the same answer),
    # its tickets leave the pool, repeat until no word reaches -MinTickets.
    $rest = @($pool | Where-Object { -not $grouped.ContainsKey($_.Id) })
    while ($true) {
        $count = @{}
        foreach ($c in $rest) {
            foreach ($w in $c.Words.Keys) {
                if (-not $count.ContainsKey($w)) { $count[$w] = @() }
                $count[$w] += $c
            }
        }
        $best = $count.GetEnumerator() | Where-Object { $_.Value.Count -ge $MinTickets } |
            Sort-Object @{ Expression = { $_.Value.Count }; Descending = $true }, @{ Expression = { $_.Key } } |
            Select-Object -First 1
        if (-not $best) { break }
        $groups += [pscustomobject]@{ CompanyId = $companyId; CompanyName = $companyName; Title = "$Prefix'$($best.Key)' in the subject"
            Why = "$($best.Value.Count) tickets with the word '$($best.Key)' in the subject"; Members = @($best.Value) }
        $taken = @{}
        foreach ($m in $best.Value) { $taken[$m.Id] = $true }
        $rest = @($rest | Where-Object { -not $taken.ContainsKey($_.Id) })
    }
}

if ($groups.Count -eq 0) { Write-Host "No group reaches $MinTickets tickets. Nothing to do."; return }

# ---- 5. One problem per group: find it or open it, then link and note ---------------------------
$opened = 0; $linked = 0
foreach ($g in $groups) {
    $existing = Find-Ours $g.CompanyId $g.Title
    $newMembers = @($g.Members | Where-Object { -not ($existing -and ($_.LinkedTo -contains [int]$existing.id)) })
    $where = if ($companies.Count -gt 1 -and $g.CompanyName) { "  [$($g.CompanyName)]" } else { '' }
    $label = if ($existing) { $existing.problem_number } else { 'new problem' }
    Write-Host "`n$($g.Title)  ($label)$where" -ForegroundColor Cyan
    Write-Host "  why: $($g.Why)"
    foreach ($m in $g.Members) {
        $mark = if ($newMembers -notcontains $m) { 'linked' } elseif ($Apply) { 'link' } else { 'would link' }
        Write-Host ("    {0,-11} {1}  {2}" -f $mark, $m.Ticket.ticket_number, $m.Ticket.subject)
    }
    if ($newMembers.Count -eq 0) { Write-Host '  nothing new - every ticket is already linked' -ForegroundColor DarkGray; continue }
    if (-not $Apply) { continue }

    if (-not $existing) {
        # The description says how the group was found, so whoever picks the
        # problem up knows it came from a script and on what evidence.
        $title = if ($g.Title.Length -gt 255) { $g.Title.Substring(0, 255) } else { $g.Title }
        $body = @{ title = $title; description = "Opened by the spot-repeat-incidents script: $($g.Why) in the last $Days days." }
        foreach ($k in $problemFields.Keys) { $body[$k] = $problemFields[$k] }
        if ($null -ne $g.CompanyId) { $body.company_id = $g.CompanyId }
        $existing = Invoke-FreeItsm 'POST' '/problems' $body
        $opened++
        Write-Host "  opened $($existing.problem_number)" -ForegroundColor Green
    }

    # Link each ticket. 409 conflict = linked by somebody else meanwhile: fine.
    $done = @()
    foreach ($m in $newMembers) {
        try {
            [void](Invoke-FreeItsm 'POST' "/problems/$($existing.id)/tickets" @{ ticket_id = $m.Id })
            $done += $m
        } catch {
            if ("$_" -notmatch '"code"\s*:\s*"conflict"') { throw }
        }
    }
    $linked += $done.Count

    # One note per run that changed something: what was linked, and why.
    # Problem notes are an append-only journal, so this is the problem's history.
    if ($done.Count -gt 0) {
        $lines = @("$($done.Count) ticket(s) linked by the spot-repeat-incidents script - $($g.Why) (last $Days days, at least $MinTickets needed):")
        foreach ($m in $done) { $lines += "- $($m.Ticket.ticket_number)  $($m.Ticket.subject)" }
        [void](Invoke-FreeItsm 'POST' "/problems/$($existing.id)/notes" @{ note = ($lines -join "`n") })
        Write-Host "  linked $($done.Count) ticket(s) and added a note" -ForegroundColor Green
    }
}

if ($Apply) { Write-Host "`nOpened $opened problem(s); linked $linked ticket(s)." -ForegroundColor Cyan }
else { Write-Host "`nPreview only - add -Apply to open these problems and link the tickets." -ForegroundColor Cyan }
