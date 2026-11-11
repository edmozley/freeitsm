<?php
/**
 * Knowledge gap analysis — the half of the assistant that decides WHAT is missing.
 *
 * Split out of api/knowledge/analyse_gaps.php so the analysis can be run without
 * an HTTP request and a session. That is not architectural tidiness for its own
 * sake: the interesting behaviour here is emergent (does a pile of real tickets
 * actually cluster into the right questions?) and the only way to test it is to
 * build a known pile inside a transaction, run the analysis on the SAME database
 * connection, assert, and roll back. Over HTTP that is impossible — the endpoint
 * gets its own connection and cannot see the uncommitted rows.
 *
 * See tests/knowledge-gaps/ for the harness that does exactly that.
 */

require_once __DIR__ . '/writeup_ai.php';
require_once __DIR__ . '/kb_ai.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/visibility.php';

/**
 * The OpenAI key used for ARTICLE embeddings, reused deliberately.
 *
 * ⚠️ Vectors are only comparable within one model. Ticket embeddings and article
 * embeddings must come from the same key and the same model or every similarity
 * score is silently meaningless — no error, just wrong answers. That is why this
 * reads knowledge_openai_api_key rather than introducing its own setting.
 */
function knowledgeOpenAiKey(PDO $conn): string
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
        $st->execute();
        return (string)decryptValue((string)($st->fetchColumn() ?: ''));
    } catch (Throwable) {
        return '';
    }
}

/**
 * The closed-ticket window this analyst can see.
 *
 * Returns ['from','where','args'] as separate fragments — the cluster query has
 * to slot a LEFT JOIN between FROM and WHERE, and a single pre-joined blob makes
 * that impossible in a way that only breaks on one code path.
 *
 * @param int $lookbackDays  how many days back from now a ticket's closed_datetime may be
 */
function gapWindowSql(PDO $conn, int $analystId, int $lookbackDays): array
{
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 't');
    // Every ticket in this window is sent to an embeddings provider, so a
    // confidential one is left out of the analysis altogether (#62).
    require_once __DIR__ . '/../ticket_sensitivity.php';
    $tSql .= ticketAiExclusionSql($conn, 't');
    return [
        'from'  => "FROM tickets t
                    JOIN ticket_statuses s ON s.id = t.status_id AND s.is_closed = 1",
        'where' => "WHERE t.closed_datetime IS NOT NULL
                      AND t.closed_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . (int)$lookbackDays . " DAY)
                          {$tSql}",
        'args'  => $tArgs,
    ];
}

/**
 * Insert-or-update one knowledge_gap_tickets row from a partial field map.
 *
 * @param array $fields  knowledge_gap_tickets column => value to set; empty does nothing. Column names are not escaped - trusted keys only
 */
function gapUpsertTicket(PDO $conn, int $ticketId, array $fields): void
{
    if (!$fields) {
        return;
    }
    $cols = array_keys($fields);
    $set  = implode(', ', array_map(function ($c) { return "`$c` = VALUES(`$c`)"; }, $cols));
    $ph   = implode(', ', array_fill(0, count($cols) + 1, '?'));

    $sql = "INSERT INTO knowledge_gap_tickets (`ticket_id`, `" . implode('`, `', $cols) . "`)
            VALUES ($ph) ON DUPLICATE KEY UPDATE $set";
    $conn->prepare($sql)->execute(array_merge([$ticketId], array_values($fields)));
}

/**
 * How many tickets are in the window, and how many already have an embedding.
 *
 * @param int $lookbackDays  how many days back from now a ticket's closed_datetime may be
 */
function gapWindowCounts(PDO $conn, int $analystId, int $lookbackDays): array
{
    $w = gapWindowSql($conn, $analystId, $lookbackDays);

    $st = $conn->prepare("SELECT COUNT(*) {$w['from']} {$w['where']}");
    $st->execute($w['args']);
    $total = (int)$st->fetchColumn();

    $st = $conn->prepare(
        "SELECT COUNT(*) {$w['from']} {$w['where']}
           AND EXISTS (SELECT 1 FROM knowledge_gap_tickets g
                        WHERE g.ticket_id = t.id AND g.embedding IS NOT NULL AND LENGTH(g.embedding) > 0)"
    );
    $st->execute($w['args']);
    $embedded = (int)$st->fetchColumn();

    return ['tickets' => $total, 'embedded' => $embedded];
}

/**
 * Embed up to $batch not-yet-embedded tickets. Returns a progress report.
 *
 * Resumable by design — every vector is cached in knowledge_gap_tickets, so a
 * closed tab, a timeout or a rate limit costs only the batch in flight.
 *
 * @param int $lookbackDays  how many days back from now a ticket's closed_datetime may be
 * @param int $batch  how many tickets to embed this call, clamped to 1-50
 * @param string $openaiKey  the OpenAI key from knowledgeOpenAiKey(); '' skips the batch
 */
function gapEmbedBatch(PDO $conn, int $analystId, int $lookbackDays, int $batch, string $openaiKey): array
{
    if ($openaiKey === '') {
        return ['embedded' => 0, 'failed' => 0, 'remaining' => 0, 'skipped' => true, 'stalled' => false];
    }

    $w = gapWindowSql($conn, $analystId, $lookbackDays);
    $batch = max(1, min(50, $batch));

    $st = $conn->prepare(
        "SELECT t.id, t.subject, t.tenant_id {$w['from']} {$w['where']}
            AND NOT EXISTS (SELECT 1 FROM knowledge_gap_tickets g
                             WHERE g.ticket_id = t.id AND g.embedding IS NOT NULL AND LENGTH(g.embedding) > 0)
         ORDER BY t.closed_datetime DESC
            LIMIT {$batch}"
    );
    $st->execute($w['args']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $done = 0; $failed = 0;
    foreach ($rows as $r) {
        $text = writeupEmbeddingText($conn, (int)$r['id'], (string)$r['subject']);
        if (trim($text) === '') {
            // A permanent no, not a transient one — mark it so it is never retried.
            gapUpsertTicket($conn, (int)$r['id'], [
                'embedding'         => '[]',
                'embedded_datetime' => gmdate('Y-m-d H:i:s'),
                'tenant_id'         => $r['tenant_id'],
            ]);
            $done++;
            continue;
        }
        $vec = aiEmbed($text, $openaiKey);
        if (!$vec) {
            $failed++;
            continue;
        }
        gapUpsertTicket($conn, (int)$r['id'], [
            'embedding'         => json_encode($vec),
            'embedded_datetime' => gmdate('Y-m-d H:i:s'),
            'tenant_id'         => $r['tenant_id'],
        ]);
        $done++;
    }

    $st = $conn->prepare(
        "SELECT COUNT(*) {$w['from']} {$w['where']}
            AND NOT EXISTS (SELECT 1 FROM knowledge_gap_tickets g
                             WHERE g.ticket_id = t.id AND g.embedding IS NOT NULL AND LENGTH(g.embedding) > 0)"
    );
    $st->execute($w['args']);

    return [
        'embedded'  => $done,
        'failed'    => $failed,
        'remaining' => (int)$st->fetchColumn(),
        'skipped'   => false,
        // Nothing achieved and everything failed: a dead key or a rate limit.
        // Tell the caller to stop looping rather than grind through the window.
        'stalled'   => ($done === 0 && $failed > 0),
    ];
}

/**
 * Score every closed ticket in the window against the knowledge base, cluster
 * what nothing covers, and persist the result.
 *
 * $opts['force_wording'] runs the free engine even when a key exists — used by
 * the test harness so the clustering logic can be exercised without spending
 * money on synthetic data.
 *
 * @param array $opts  optional: lookback_days (int, else the setting) and force_wording (true runs the free wording engine)
 */
function gapAnalyse(PDO $conn, int $analystId, array $opts = []): array
{
    $cfg      = writeupSettings($conn);
    $lookback = (int)($opts['lookback_days'] ?? $cfg['knowledge_gap_lookback_days']);
    $w        = gapWindowSql($conn, $analystId, $lookback);

    $openaiKey = empty($opts['force_wording']) ? knowledgeOpenAiKey($conn) : '';

    $st = $conn->prepare(
        "SELECT t.id, t.subject, t.tenant_id, t.closed_datetime, g.embedding
           {$w['from']}
      LEFT JOIN knowledge_gap_tickets g ON g.ticket_id = t.id
           {$w['where']}
       ORDER BY t.closed_datetime DESC
          LIMIT 5000"
    );
    $st->execute($w['args']);
    $tickets = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$tickets) {
        gapClearOpenClusters($conn);
        return ['analysed' => 0, 'gaps' => 0, 'clusters' => 0, 'mode' => 'wording',
                'message' => 'No closed tickets in the last ' . $lookback . ' days.'];
    }

    // The knowledge base as it stands. Published and not in the recycle bin —
    // an archived article answers nobody's question.
    //
    // ⚠️ SCOPED TO THE SAME ANALYST AS THE TICKETS ABOVE. This read used to be
    // bare, while gapWindowSql() has always company-scoped the ticket side — so
    // one company's tickets were matched against EVERY company's articles. That
    // had two faces, and the quieter one is the worse:
    //   • the matched article_id is stored on the cluster and api/knowledge/
    //     gap_clusters.php joins back for its title, so another company's
    //     article title could be displayed;
    //   • a ticket "covered" by an article this company cannot see is dropped
    //     from the results — the feature exists to report what is MISSING, and
    //     it was silently under-reporting with no error and no empty state.
    $gapViewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    [$artSql, $artParams] = knowledgeVisibilitySql($conn, $gapViewer, '', ['lifecycle' => 'live']);
    $artStmt = $conn->prepare("SELECT id, title, embedding FROM knowledge_articles WHERE 1=1" . $artSql);
    $artStmt->execute($artParams);
    $articles = $artStmt->fetchAll(PDO::FETCH_ASSOC);

    $articleVecs = [];
    $articleToks = [];
    foreach ($articles as $a) {
        $v = $a['embedding'] ? json_decode($a['embedding'], true) : null;
        if (is_array($v) && $v) {
            $articleVecs[] = ['id' => (int)$a['id'], 'vec' => $v];
        }
        $articleToks[] = ['id' => (int)$a['id'], 'toks' => writeupSubjectTokens((string)$a['title'])];
    }

    $useVectors = ($openaiKey !== '' && $articleVecs);

    // Wording mode gets its OWN bars, not the vector settings scaled by some
    // factor. The two engines produce numbers that mean different things —
    // cosine on 1536 dimensions of meaning versus token overlap on a subject
    // line — and pretending one converts into the other by multiplication is
    // how you end up with a setting nobody can reason about. These two are
    // tuned against tests/knowledge-gaps and are deliberately not exposed:
    // the settings the admin sees govern the engine they are actually running.
    $WORDING_ARTICLE_BAR = 0.55;   // shorter of {ticket, article title} mostly contained in the other
    $WORDING_CLUSTER_BAR = 0.50;   // ...and the same test between two tickets

    $articleBar = $useVectors ? (float)$cfg['knowledge_gap_article_threshold'] : $WORDING_ARTICLE_BAR;
    $clusterBar = $useVectors ? (float)$cfg['knowledge_gap_cluster_threshold'] : $WORDING_CLUSTER_BAR;
    $minCluster = (int)$cfg['knowledge_gap_min_cluster'];

    $candidates = [];
    $now = gmdate('Y-m-d H:i:s');
    foreach ($tickets as $t) {
        $vec  = $t['embedding'] ? json_decode($t['embedding'], true) : null;
        $toks = writeupSubjectTokens((string)$t['subject']);

        $bestId = null; $best = 0.0;
        if ($useVectors && is_array($vec) && $vec) {
            foreach ($articleVecs as $a) {
                $sim = aiCosineSimilarity($vec, $a['vec']);
                if ($sim > $best) { $best = $sim; $bestId = $a['id']; }
            }
        } else {
            foreach ($articleToks as $a) {
                $sim = writeupTokenSimilarity($toks, $a['toks']);
                if ($sim > $best) { $best = $sim; $bestId = $a['id']; }
            }
        }

        gapUpsertTicket($conn, (int)$t['id'], [
            'best_article_id'   => $bestId,
            'best_similarity'   => $best,
            'analysed_datetime' => $now,
            'tenant_id'         => $t['tenant_id'],
        ]);

        if ($best < $articleBar) {
            $candidates[] = [
                'id'      => (int)$t['id'],
                'subject' => (string)$t['subject'],
                'tenant'  => $t['tenant_id'],
                'closed'  => $t['closed_datetime'],
                'vec'     => (is_array($vec) && $vec) ? $vec : null,
                'toks'    => $toks,
            ];
        }
    }

    if (!$candidates) {
        gapClearOpenClusters($conn);
        return ['analysed' => count($tickets), 'gaps' => 0, 'clusters' => 0,
                'mode' => $useVectors ? 'meaning' : 'wording',
                'message' => 'Every closed ticket in the window is already covered by an article.'];
    }

    // Richness in bulk, for candidates only. This score decides ORDER — which
    // ticket seeds a cluster and gets drafted from. The real plain-text bundle
    // is read once, later, for the single ticket we actually write from.
    $richness = gapBulkRichness($conn, array_column($candidates, 'id'));
    foreach ($candidates as &$c) {
        $c['richness'] = $richness[$c['id']] ?? 0;
    }
    unset($c);

    // Richest first, so each cluster forms around its most writable ticket.
    usort($candidates, function ($a, $b) { return $b['richness'] <=> $a['richness']; });

    $simFn = $useVectors
        ? function ($a, $b) {
            // One of the pair never embedded — fall back to wording for this
            // comparison rather than silently dropping the ticket.
            if (!$a['vec'] || !$b['vec']) {
                return writeupTokenSimilarity($a['toks'], $b['toks']);
            }
            return aiCosineSimilarity($a['vec'], $b['vec']);
        }
        : function ($a, $b) { return writeupTokenSimilarity($a['toks'], $b['toks']); };

    $kept = [];
    foreach (writeupCluster($candidates, $simFn, $clusterBar) as $c) {
        if (count($c['members']) >= $minCluster) {
            $kept[] = $c;
        }
    }

    $written = gapPersistClusters($conn, $kept);

    return [
        'analysed' => count($tickets),
        'gaps'     => count($candidates),
        'clusters' => $written,
        'mode'     => $useVectors ? 'meaning' : 'wording',
        'message'  => gapSummarySentence(count($tickets), $lookback, $written),
    ];
}

/**
 * Approximate richness for many tickets in four queries.
 *
 * Lengths are of the RAW stored bodies, so HTML markup inflates them. That is
 * fine: every ticket is inflated by roughly the same amount and this score is
 * only ever compared against other tickets, never an absolute bar. The one place
 * an absolute bar IS applied (interview vs draft) uses the real plain-text
 * bundle from writeupTicketBundle() instead.
 *
 * @param array $ticketIds  the ticket ids to score; empty gives []
 */
function gapBulkRichness(PDO $conn, array $ticketIds): array
{
    if (!$ticketIds) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($ticketIds), '?'));
    $acc = [];
    foreach ($ticketIds as $id) {
        $acc[(int)$id] = ['message_count' => 0, 'notes_len' => 0, 'resolution_len' => 0, 'minutes' => 0, 'has_problem' => false];
    }

    $q = function (string $sql) use ($conn, $ticketIds) {
        $st = $conn->prepare($sql);
        $st->execute($ticketIds);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        foreach ($q("SELECT ticket_id, COUNT(*) AS n,
                            MAX(CASE WHEN direction <> 'Inbound' THEN CHAR_LENGTH(body_content) ELSE 0 END) AS longest_out
                       FROM emails WHERE ticket_id IN ($in) GROUP BY ticket_id") as $r) {
            $acc[(int)$r['ticket_id']]['message_count']  = (int)$r['n'];
            // Halved as a rough allowance for the HTML wrapped around the prose.
            $acc[(int)$r['ticket_id']]['resolution_len'] = (int)((int)$r['longest_out'] / 2);
        }
    } catch (Throwable) { /* leave at zero */ }

    try {
        foreach ($q("SELECT ticket_id, SUM(CHAR_LENGTH(note_text)) AS len
                       FROM ticket_notes WHERE ticket_id IN ($in) GROUP BY ticket_id") as $r) {
            $acc[(int)$r['ticket_id']]['notes_len'] = (int)((int)$r['len'] / 2);
        }
    } catch (Throwable) { /* leave at zero */ }

    // Not while Time tracking is switched off for the install (GH #217).
    if (moduleIsEnabled($conn, 'time-tracking')) try {
        foreach ($q("SELECT ticket_id, SUM(time_spent_minutes) AS m
                       FROM ticket_time_entries WHERE is_active = 1 AND ticket_id IN ($in) GROUP BY ticket_id") as $r) {
            $acc[(int)$r['ticket_id']]['minutes'] = (int)$r['m'];
        }
    } catch (Throwable) { /* leave at zero */ }

    try {
        foreach ($q("SELECT DISTINCT ticket_id FROM problem_tickets WHERE ticket_id IN ($in)") as $r) {
            $acc[(int)$r['ticket_id']]['has_problem'] = true;
        }
    } catch (Throwable) { /* leave at zero */ }

    $out = [];
    foreach ($acc as $id => $parts) {
        $out[$id] = writeupRichness($parts);
    }
    return $out;
}

/**
 * Persist clusters, preserving what the analyst has already decided about them.
 *
 * 🔑 A freshly computed cluster is matched to a stored one by TICKET OVERLAP,
 * not by id, seed or label. A cluster grows as new tickets close and its seed
 * changes as richer examples arrive, so any identity built on "same seed" or
 * "same subject" breaks on the next run and re-raises something the analyst has
 * already dismissed. Overlap survives both.
 *
 * @param array $clusters  clusters from writeupCluster() over gapAnalyse()'s candidates (seed + members of {item, similarity})
 */
function gapPersistClusters(PDO $conn, array $clusters): int
{
    $existing = $conn->query(
        "SELECT c.id, c.status, c.article_id,
                GROUP_CONCAT(ct.ticket_id) AS ticket_ids
           FROM knowledge_gap_clusters c
      LEFT JOIN knowledge_gap_cluster_tickets ct ON ct.cluster_id = c.id
       GROUP BY c.id, c.status, c.article_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $prior = [];
    foreach ($existing as $e) {
        $ids = array_filter(array_map('intval', explode(',', (string)$e['ticket_ids'])));
        if (!$ids) {
            continue;
        }
        $prior[] = ['id' => (int)$e['id'], 'status' => $e['status'], 'article_id' => $e['article_id'], 'ids' => array_flip($ids)];
    }

    $seenClusterIds = [];
    $matchedPrior   = [];
    $count = 0;

    foreach ($clusters as $c) {
        $memberIds = array_map(function ($m) { return (int)$m['item']['id']; }, $c['members']);
        $seed      = $c['seed'];

        $matchId = null; $matchStatus = 'open'; $matchArticle = null; $bestOverlap = 0.0;
        foreach ($prior as $p) {
            if (in_array($p['id'], $matchedPrior, true)) {
                continue;
            }
            $hit = 0;
            foreach ($memberIds as $mid) {
                if (isset($p['ids'][$mid])) { $hit++; }
            }
            $overlap = $hit / max(count($memberIds), count($p['ids']));
            if ($overlap > $bestOverlap) {
                $bestOverlap = $overlap; $matchId = $p['id']; $matchStatus = $p['status']; $matchArticle = $p['article_id'];
            }
        }
        // Half the tickets in common means it is the same recurring question.
        if ($bestOverlap < 0.5) {
            $matchId = null; $matchStatus = 'open'; $matchArticle = null;
        }

        $dates = array_values(array_filter(array_map(function ($m) { return $m['item']['closed']; }, $c['members'])));
        sort($dates);

        $richest = $seed;
        foreach ($c['members'] as $m) {
            if (($m['item']['richness'] ?? 0) > ($richest['richness'] ?? 0)) {
                $richest = $m['item'];
            }
        }

        $row = [
            'label'                 => writeupCleanLabel($seed['subject']),
            'seed_ticket_id'        => $seed['id'],
            'best_ticket_id'        => $richest['id'],
            'max_richness'          => (int)($richest['richness'] ?? 0),
            'ticket_count'          => count($memberIds),
            'first_ticket_datetime' => $dates ? $dates[0] : null,
            'last_ticket_datetime'  => $dates ? $dates[count($dates) - 1] : null,
            'tenant_id'             => $seed['tenant'],
        ];

        if ($matchId) {
            $sets = implode(', ', array_map(function ($k) { return "`$k` = ?"; }, array_keys($row)));
            $conn->prepare("UPDATE knowledge_gap_clusters SET $sets WHERE id = ?")
                 ->execute(array_merge(array_values($row), [$matchId]));
            $clusterId = $matchId;
            $matchedPrior[] = $matchId;
        } else {
            $cols = array_keys($row);
            $ph   = implode(', ', array_fill(0, count($cols), '?'));
            $conn->prepare("INSERT INTO knowledge_gap_clusters (`" . implode('`, `', $cols) . "`) VALUES ($ph)")
                 ->execute(array_values($row));
            $clusterId = (int)$conn->lastInsertId();
        }

        $seenClusterIds[] = $clusterId;
        $conn->prepare("DELETE FROM knowledge_gap_cluster_tickets WHERE cluster_id = ?")->execute([$clusterId]);
        $ins = $conn->prepare("INSERT INTO knowledge_gap_cluster_tickets (cluster_id, ticket_id, similarity) VALUES (?, ?, ?)");
        foreach ($c['members'] as $m) {
            $ins->execute([$clusterId, (int)$m['item']['id'], (float)$m['similarity']]);
        }

        // Restore the analyst's decision — an UPDATE of the computed fields must
        // never quietly reopen something they dismissed.
        if ($matchStatus !== 'open') {
            $conn->prepare("UPDATE knowledge_gap_clusters SET status = ?, article_id = ? WHERE id = ?")
                 ->execute([$matchStatus, $matchArticle, $clusterId]);
        }
        $count++;
    }

    // An OPEN cluster this run no longer sees has been overtaken — usually
    // because an article now covers it. Drop it rather than leave a stale card.
    // Dismissed and written clusters stay: those are the analyst's decisions,
    // not our findings.
    if ($seenClusterIds) {
        $in = implode(',', array_fill(0, count($seenClusterIds), '?'));
        $conn->prepare("DELETE FROM knowledge_gap_clusters WHERE status = 'open' AND id NOT IN ($in)")
             ->execute($seenClusterIds);
    } else {
        $conn->exec("DELETE FROM knowledge_gap_clusters WHERE status = 'open'");
    }

    return $count;
}

/** Clear the open cards but keep the analyst's decisions. */
function gapClearOpenClusters(PDO $conn): void
{
    $conn->exec("DELETE FROM knowledge_gap_clusters WHERE status = 'open'");
}

/**
 * The assistant's opening line, in plain English.
 *
 * @param int $read  how many closed tickets were read
 * @param int $days  the look-back window in days
 * @param int $clusters  how many gap clusters were found
 */
function gapSummarySentence(int $read, int $days, int $clusters): string
{
    $t = 'I read ' . number_format($read) . ' closed ' . ($read === 1 ? 'ticket' : 'tickets')
       . ' from the last ' . $days . ' days. ';
    if ($clusters === 0) {
        return $t . 'Nothing is being asked often enough to need an article you do not already have.';
    }
    if ($clusters === 1) {
        return $t . 'There is one thing your knowledge base is missing.';
    }
    return $t . 'Your knowledge base is missing ' . $clusters . ' things.';
}
