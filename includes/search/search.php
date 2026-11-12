<?php
/**
 * THE search function. Nothing else in FreeITSM writes a search query.
 *
 * That is the whole point of this file. If `MATCH ... AGAINST` appears in six
 * endpoints then changing how search works — or changing what answers it — is a
 * rewrite. Behind one function it is a second implementation of one function.
 * See the Full-Text-Search wiki page for the reasoning.
 *
 * TWO RULES CALLERS MUST NOT BREAK
 *
 *  1. THE PERMISSION PREDICATE GOES INTO THE QUERY, NEVER OVER THE RESULTS.
 *     Filtering afterwards starves results: the index returns its top N by
 *     relevance, you discard what the caller may not see, and hand back three
 *     rows while hundreds they were entitled to never made the top N. It fails
 *     worst for the least privileged user, who is also the least likely to be
 *     the one testing it.
 *
 *  2. THE SCOPE IS A DATA STRUCTURE, NOT SQL. A SQL fragment would weld this to
 *     MySQL. FreeITSM computes the predicate; the backend merely applies it.
 *     Replicating POLICY into a second system is dangerous; passing a COMPUTED
 *     FILTER to a dumb store is ordinary.
 *
 * WHAT IT WILL AND WILL NOT MATCH. Word order is irrelevant and phrases work,
 * but MySQL has no stemmer and no fuzzy matching: "printers" will not match
 * "printer" and a typo finds nothing. searchParseQuery() adds a trailing
 * wildcard to soften the first of those. See §4.6 of the wiki page.
 */

require_once __DIR__ . '/corpus.php';

/**
 * The shortest word this server will index. Read from the server rather than
 * assumed, because it is not the same everywhere — WAMP ships 0, stock MySQL is
 * 3 — and requiring a term below it in boolean mode returns NOTHING AT ALL
 * rather than ignoring it. That is the difference between "search works" and
 * "search mysteriously returns nothing for that phrase".
 */
function searchMinTokenSize(PDO $conn): int {
    static $n = null;
    if ($n !== null) return $n;
    try {
        $n = (int)$conn->query("SELECT @@innodb_ft_min_token_size")->fetchColumn();
    } catch (Throwable) {
        $n = 3;   // stock MySQL
    }
    return $n;
}

/**
 * Turn what a person typed into a MySQL boolean-mode query.
 *
 * The user-facing language is deliberately tiny — words, "quoted phrases" and
 * -exclusions — so that it can be translated for a different engine later
 * without the syntax having leaked into the UI.
 *
 * ⚠️ Terms too short to be indexed are DROPPED, not passed through. Requiring
 * one would make the whole query match nothing. They come back in `dropped` so
 * the caller can say so rather than silently returning an empty page.
 *
 * @param string $raw  the search text as the user typed it - untrusted
 * @param int $minToken  the shortest word MySQL indexes (innodb_ft_min_token_size); shorter words are dropped
 * @return array{expr:string,terms:array,phrases:array,excluded:array,dropped:array}
 */
function searchParseQuery(string $raw, int $minToken = 3): array {
    $raw = trim($raw);
    $phrases = $terms = $excluded = $dropped = [];

    // Quoted phrases first, so their spaces survive the word split.
    if (preg_match_all('~"([^"]+)"~u', $raw, $m)) {
        foreach ($m[1] as $p) {
            $p = trim(preg_replace('~[+\-><()\~*@"]+~u', ' ', $p));
            if ($p !== '') $phrases[] = $p;
        }
        $raw = preg_replace('~"[^"]*"~u', ' ', $raw);
    }

    foreach (preg_split('~\s+~u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $negate = ($word[0] ?? '') === '-';
        if ($negate) $word = mb_substr($word, 1, null, 'UTF-8');

        // SPLIT on anything that is not a word character — do not DELETE it.
        //
        // This used to strip the punctuation out and glue what was left
        // together, so "05145-8841815" was searched for as the single token
        // "051458841815". MySQL had indexed it as TWO tokens, "05145" and
        // "8841815", because it splits on punctuation exactly like this — so the
        // glued-together string appeared nowhere and the search found nothing,
        // while the very same value typed with a space found it at once (GH #102).
        //
        // The quoted-phrase branch above never had the bug: it replaces the same
        // characters with a SPACE. The two branches differed by one character.
        //
        // Word characters here mirror what InnoDB's default parser treats as one:
        // letters, digits and underscore. Everything else — hyphen, slash, dot,
        // comma, colon, apostrophe, and the boolean operators, which are ours to
        // add rather than the user's to inject — is a separator.
        $bits = preg_split('~[^\p{L}\p{N}_]+~u', (string)$word, -1, PREG_SPLIT_NO_EMPTY);
        if (!$bits) continue;

        foreach ($bits as $clean) {
            if (mb_strlen($clean, 'UTF-8') < max(1, $minToken)) { $dropped[] = $clean; continue; }
            if ($negate) $excluded[] = $clean; else $terms[] = $clean;
        }
    }

    $parts = [];
    foreach ($phrases  as $p) $parts[] = '+"' . $p . '"';
    // Trailing wildcard is the documented mitigation for MySQL having no stemmer:
    // it lets "printer" find "printers". It over-matches on short stems, which is
    // the accepted trade.
    foreach ($terms    as $t) $parts[] = '+' . $t . '*';
    foreach ($excluded as $e) $parts[] = '-' . $e;

    return [
        'expr'     => implode(' ', $parts),
        'terms'    => $terms,
        'phrases'  => $phrases,
        'excluded' => $excluded,
        'dropped'  => $dropped,
    ];
}

/**
 * Build the scope structure for an analyst.
 *
 * Company scoping mirrors ticketTenantFilter() exactly — including that a NULL
 * tenant means "the default company" and is only visible when the analyst's
 * active company IS the default. Ticket search in FreeITSM is not restricted by
 * department or team (see api/tickets/search_tickets.php), so neither is this.
 *
 * @param array $overrides  scope keys to replace after the defaults, e.g. ['include_internal' => false, 'source_types' => [...]]
 */
function searchScopeForAnalyst(PDO $conn, int $analystId, array $overrides = []): array {
    $scope = [
        'tenant_id'         => null,   // null = no company filtering at all
        'include_default'   => true,   // may this caller see rows whose source had a NULL tenant?
        'include_internal'  => true,   // internal notes — false for anything customer-facing
        'include_deleted'   => false,  // trashed tickets stay out
        'exclude_closed'    => false,  // true = drop tickets whose status is flagged closed (#149)
        'source_types'      => null,   // null = every kind
        'ticket_ids'        => null,   // null = no restriction
        'require_ticket'    => false,  // true = only documents attached to a ticket
        // Which modules this caller may read. Attached documents inherit their
        // visibility from the record they hang off, so the corpus row alone
        // cannot answer whether they may be listed — see below.
        // The grants NOW, not the copy taken at sign-in (analystAllowedModulesNow()).
        // Less any module switched off for the install (GH #217).
        'allowed_modules'   => modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, (int)$analystId)),
        // The department rule (includes/ticket_department_scope.php): whose departments' tickets.
        'department_analyst' => (int)$analystId,
    ];

    if (function_exists('isMultiTenant') && isMultiTenant($conn)) {
        $active  = getActiveTenantId($conn, $analystId);
        $default = getDefaultTenantId($conn);
        if (!analystCanAccessTenant($conn, $analystId, $active)) {
            // No company at all: shared entries only, never the Default company's
            // (see activeTenantFilter() in tenancy.php). -1 matches no company.
            $scope['tenant_id']       = -1;
            $scope['include_default'] = false;
        } else {
            $scope['tenant_id']       = $active;
            $scope['include_default'] = ($active === $default);
        }
    }
    $scope = array_merge($scope, $overrides);

    /*
     * ⚠️ ATTACHED DOCUMENTS NEED MORE THAN THE ROW SAYS (discussion #76).
     *
     * Every other source type can be judged from the corpus row itself: a tenant
     * and an internal flag are the whole rule. A document's visibility lives in
     * OTHER TABLES — it is readable if you can see at least one record it is
     * attached to — and that changes after indexing, so it cannot be baked in.
     *
     * The clause is built here, where the analyst is known, and rendered by
     * searchScopeToSql() with everything else. Computed once per search rather
     * than per row: it is a join, not a loop.
     */
    try {
        require_once __DIR__ . '/documents_index.php';
        $scope['document_clause'] = documentSearchVisibilityClause(
            $conn, $analystId, $scope['allowed_modules'], 'sd'
        );
    } catch (Throwable) {
        // If the clause cannot be built, exclude documents entirely rather than
        // letting them through unfiltered. Fail closed.
        $scope['document_clause'] = [" AND sd.source_type <> 'document'", []];
    }

    return $scope;
}

/**
 * Translate the scope structure into SQL. The ONLY place this happens.
 *
 * @param array $scope  the scope structure built by searchScopeForAnalyst()
 * @param string $alias  the alias the calling query gives search_documents
 * @return array{0:string,1:array} [sql fragment, params]
 */
function searchScopeToSql(array $scope, string $alias = 'sd'): array {
    $sql = '';
    $params = [];

    if (!empty($scope['tenant_id'])) {
        // 'shared' is always visible; 'default' only when this caller is in the
        // default company; otherwise the company must match exactly.
        $clause = "($alias.tenant_scope = 'shared' OR $alias.tenant_id = ?";
        $params[] = (int)$scope['tenant_id'];
        if (!empty($scope['include_default'])) $clause .= " OR $alias.tenant_scope = 'default'";
        $sql .= " AND " . $clause . ")";
    }

    if (empty($scope['include_internal'])) {
        $sql .= " AND $alias.is_internal = 0";
    }

    // Attached documents: "not a document, OR a document you can see". Built in
    // searchScopeForAnalyst(), where the analyst is known.
    if (!empty($scope['document_clause']) && is_array($scope['document_clause'])) {
        $sql .= $scope['document_clause'][0];
        foreach ($scope['document_clause'][1] as $p) $params[] = $p;
    }

    if (!empty($scope['source_types']) && is_array($scope['source_types'])) {
        $sql .= " AND $alias.source_type IN (" . implode(',', array_fill(0, count($scope['source_types']), '?')) . ")";
        foreach ($scope['source_types'] as $t) $params[] = (string)$t;
    }

    // "Only things that belong to a ticket." Used by the search-inside-tickets
    // endpoint, which renders ticket rows and nothing else: a knowledge article
    // matches with ticket_id NULL, and was counted in the total and then silently
    // dropped from the list — "4 tickets found" above three rows.
    //
    // Deliberately NOT expressed as a source_types list. Enumerating the types that
    // happen to hang off a ticket today means a new one added later is silently
    // excluded, which hides real results instead of merely miscounting them.
    if (!empty($scope['require_ticket'])) {
        $sql .= " AND $alias.ticket_id IS NOT NULL";
    }

    if (!empty($scope['ticket_ids']) && is_array($scope['ticket_ids'])) {
        $sql .= " AND $alias.ticket_id IN (" . implode(',', array_fill(0, count($scope['ticket_ids']), '?')) . ")";
        foreach ($scope['ticket_ids'] as $t) $params[] = (int)$t;
    }

    return [$sql, $params];
}

/**
 * Search the corpus.
 *
 * Two bounded queries rather than one clever one: rank the TICKETS, then fetch
 * the matching documents for just that page of tickets. Doing it the other way
 * round — fetch documents, collapse afterwards — reintroduces a top-N
 * distortion, because the top 200 documents may collapse to a handful of
 * tickets.
 *
 * @param string $rawQuery  the search text as the user typed it - untrusted
 * @param array $scope  the scope structure built by searchScopeForAnalyst()
 * @param array $opts limit, offset, title_only, snippet_chars
 * @return array{total:int,results:array,query:array,ok:bool,reason:?string}
 */
function searchCorpusQuery(PDO $conn, string $rawQuery, array $scope, array $opts = []): array {
    $limit   = max(1, min(100, (int)($opts['limit']  ?? 20)));
    $offset  = max(0, (int)($opts['offset'] ?? 0));
    $snipLen = max(60, min(600, (int)($opts['snippet_chars'] ?? 220)));
    $empty   = ['total' => 0, 'results' => [], 'query' => [], 'ok' => false, 'reason' => null];

    if (!searchCorpusReady($conn)) {
        return array_merge($empty, ['reason' => 'not_ready']);   // Database Verification not run
    }

    $parsed = searchParseQuery($rawQuery, searchMinTokenSize($conn));
    $empty['query'] = $parsed;
    if ($parsed['expr'] === '' || ($parsed['terms'] === [] && $parsed['phrases'] === [])) {
        // Nothing searchable survived. Saying so beats returning "no results",
        // which would read as "that isn't in your tickets".
        return array_merge($empty, ['reason' => 'no_usable_terms']);
    }

    // MATCH must name exactly the columns of a FULLTEXT index — hence two indexes.
    $cols = !empty($opts['title_only']) ? 'sd.title' : 'sd.title, sd.body';
    [$scopeSql, $scopeParams] = searchScopeToSql($scope, 'sd');

    // Deleted tickets stay out. The join is also what keeps ticket-level truth in
    // the tickets table rather than duplicated into the corpus.
    $joinSql = " LEFT JOIN tickets t ON t.id = sd.ticket_id";
    $delSql  = empty($scope['include_deleted']) ? " AND (sd.ticket_id IS NULL OR t.deleted_datetime IS NULL)" : '';
    // Closed tickets out (#149), on the same join and for the same reason: here,
    // inside the WHERE, the total and the page are counted from one filtered set.
    // Dropping them afterwards would print "12 found" above nine rows.
    // Documents with no ticket are untouched - "closed" is not a thing they can be.
    if (!empty($scope['exclude_closed'])) {
        $delSql .= " AND (sd.ticket_id IS NULL OR NOT EXISTS (SELECT 1 FROM ticket_statuses vf_ts"
                 . " WHERE vf_ts.id = t.status_id AND vf_ts.is_closed = 1))";
    }

    // 🔴 A ticket's row is judged by the ticket's company NOW, not the copy taken
    // when it was indexed. Moving a ticket to another company, and routing it out
    // of triage, write only tickets.tenant_id and re-index nothing, so the old
    // company kept finding it - with snippets of its messages and internal notes -
    // until the next reply happened to re-index it. Every move path (the move
    // button, PATCH, the bulk triage sweeps) is covered here, in the one query they
    // all go through. Reported privately, October 2026.
    $liveParams = [];
    if (!empty($scope['tenant_id'])) {
        $delSql .= " AND (sd.ticket_id IS NULL OR t.tenant_id = ?"
                 . (!empty($scope['include_default']) ? " OR t.tenant_id IS NULL" : '') . ")";
        $liveParams[] = (int)$scope['tenant_id'];
    }
    // The department rule, judged on the ticket NOW, like the company above.
    if (!empty($scope['department_analyst'])) {
        require_once __DIR__ . '/../ticket_department_scope.php';
        [$dSql, $dParams] = ticketDepartmentScopeSql($conn, (int)$scope['department_analyst'], 't');
        if ($dSql !== '') {
            $delSql .= " AND (sd.ticket_id IS NULL OR (1 = 1" . $dSql . "))";
            $liveParams = array_merge($liveParams, $dParams);
        }
    }

    $where = "MATCH($cols) AGAINST (? IN BOOLEAN MODE)" . $scopeSql . $delSql;
    $args  = array_merge([$parsed['expr']], $scopeParams, $liveParams);

    // --- 1. how many tickets match, and which page of them ------------------
    $countSql = "SELECT COUNT(DISTINCT COALESCE(sd.ticket_id, -sd.id))
                   FROM search_documents sd $joinSql WHERE $where";
    $st = $conn->prepare($countSql);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    if ($total === 0) return array_merge($empty, ['ok' => true, 'query' => $parsed]);

    $rankSql = "SELECT COALESCE(sd.ticket_id, -sd.id) AS grp,
                       MAX(MATCH($cols) AGAINST (? IN BOOLEAN MODE)) AS score
                  FROM search_documents sd $joinSql
                 WHERE $where
                 GROUP BY grp
                 ORDER BY score DESC, grp DESC
                 LIMIT $limit OFFSET $offset";
    $st = $conn->prepare($rankSql);
    $st->execute(array_merge([$parsed['expr']], $args));
    $groups = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$groups) return array_merge($empty, ['ok' => true, 'query' => $parsed]);

    // --- 2. the matching documents for just those tickets --------------------
    $grpIds = array_map(fn($g) => (int)$g['grp'], $groups);
    $in     = implode(',', array_fill(0, count($grpIds), '?'));
    $hitSql = "SELECT sd.id, sd.source_type, sd.source_id, sd.ticket_id, sd.title, sd.body,
                      sd.is_internal, sd.source_datetime,
                      COALESCE(sd.ticket_id, -sd.id) AS grp,
                      MATCH($cols) AGAINST (? IN BOOLEAN MODE) AS score
                 FROM search_documents sd $joinSql
                WHERE $where AND COALESCE(sd.ticket_id, -sd.id) IN ($in)
                ORDER BY score DESC";
    $st = $conn->prepare($hitSql);
    $st->execute(array_merge([$parsed['expr']], $args, $grpIds));

    $byGroup = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = (int)$r['grp'];
        $byGroup[$g][] = [
            'source_type'     => $r['source_type'],
            'source_id'       => (int)$r['source_id'],
            'title'           => (string)$r['title'],
            'snippet'         => searchSnippet((string)$r['body'], $parsed, $snipLen),
            'is_internal'     => (bool)$r['is_internal'],
            'source_datetime' => $r['source_datetime'],
            'score'           => (float)$r['score'],
        ];
    }

    $results = [];
    foreach ($groups as $g) {
        $id   = (int)$g['grp'];
        $hits = $byGroup[$id] ?? [];
        $results[] = [
            'ticket_id' => $id > 0 ? $id : null,
            'score'     => (float)$g['score'],
            'matched'   => array_values(array_unique(array_column($hits, 'source_type'))),
            'hits'      => $hits,
        ];
    }

    return ['total' => $total, 'results' => $results, 'query' => $parsed, 'ok' => true, 'reason' => null];
}

/**
 * A readable fragment of the body around the first matching term.
 *
 * MySQL has no highlighting function, so this is done here. Returns PLAIN TEXT —
 * the caller escapes it. Never build HTML in this file; a snippet is user
 * content and the one place it must not be trusted is on its way to a page.
 *
 * @param string $body  the matched document's plain-text body
 * @param array $parsed  the parsed query from searchParseQuery(); its phrases and terms locate the excerpt
 * @param int $len  the most characters to return, before any ellipses
 */
function searchSnippet(string $body, array $parsed, int $len = 220): string {
    $body = trim(preg_replace('~\s+~u', ' ', $body));
    if ($body === '') return '';
    if (mb_strlen($body, 'UTF-8') <= $len) return $body;

    $needles = array_merge($parsed['phrases'] ?? [], $parsed['terms'] ?? []);
    $at = 0;
    foreach ($needles as $n) {
        $pos = mb_stripos($body, $n, 0, 'UTF-8');
        if ($pos !== false) { $at = max(0, $pos - (int)($len / 4)); break; }
    }
    $out = mb_substr($body, $at, $len, 'UTF-8');
    if ($at > 0) $out = '…' . ltrim($out);
    if ($at + $len < mb_strlen($body, 'UTF-8')) $out = rtrim($out) . '…';
    return $out;
}
