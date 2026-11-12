<?php
/**
 * API: Knowledge — what the assistant found.
 *
 * GET ?status=open|dismissed|written|all  (default open)
 *
 * Returns the gap clusters plus the sample tickets behind each one, so the
 * analyst can check the assistant's reasoning before acting on it. Showing the
 * evidence matters: "you have been asked this 14 times" is only persuasive if
 * you can click through and see the fourteen.
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rbac.php';
require_once '../../includes/knowledge/writeup_ai.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');

$analystId = (int)$_SESSION['analyst_id'];
$status    = (string)($_GET['status'] ?? 'open');
if (!in_array($status, ['open', 'dismissed', 'written', 'all'], true)) {
    $status = 'open';
}

try {
    $conn = connectToDatabase();

    // Pre-Database-Verify installs get an empty assistant, never a fatal.
    if (!writeupSchemaReady($conn)) {
        echo json_encode([
            'success'         => true,
            'clusters'        => [],
            'needs_db_verify' => true,
            'summary'         => 'The assistant needs a database update before it can look for gaps. Run System → Database Verification.',
        ]);
        exit;
    }

    // Clusters are derived from TICKETS, so they follow ticket tenancy (the
    // analyst's active company), NOT knowledge tenancy — where NULL means
    // "shared with everyone" and would be exactly backwards here.
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'c');

    $statusSql = $status === 'all' ? '' : ' AND c.status = ?';
    $args      = $tArgs;
    if ($status !== 'all') {
        $args[] = $status;
    }

    $st = $conn->prepare(
        "SELECT c.id, c.label, c.ticket_count, c.status, c.article_id,
                c.best_ticket_id, c.max_richness,
                c.first_ticket_datetime, c.last_ticket_datetime,
                a.title AS article_title, a.is_published
           FROM knowledge_gap_clusters c
      LEFT JOIN knowledge_articles a ON a.id = c.article_id
          WHERE 1=1 {$tSql} {$statusSql}
       ORDER BY c.ticket_count DESC, c.last_ticket_datetime DESC
          LIMIT 100"
    );
    $st->execute($args);
    $clusters = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($clusters) {
        $ids = array_column($clusters, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        // Only tickets of the analyst's departments (includes/ticket_department_scope.php).
        require_once __DIR__ . '/../../includes/ticket_department_scope.php';
        [$dSql, $dParams] = ticketDepartmentScopeSql($conn, (int)$_SESSION['analyst_id'], 't');

        $st = $conn->prepare(
            "SELECT ct.cluster_id, ct.ticket_id, ct.similarity,
                    t.subject, t.closed_datetime,
                    COALESCE(NULLIF(t.ticket_number, ''), CONCAT('#', t.id)) AS ticket_ref
               FROM knowledge_gap_cluster_tickets ct
               JOIN tickets t ON t.id = ct.ticket_id
              WHERE ct.cluster_id IN ($in)" . $dSql . "
           ORDER BY ct.similarity DESC, t.closed_datetime DESC"
        );
        $st->execute(array_merge($ids, $dParams));

        $byCluster = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int)$r['cluster_id'];
            if (!isset($byCluster[$cid])) {
                $byCluster[$cid] = [];
            }
            // Cap the evidence list — a cluster of 200 does not need 200 rows in
            // a card. The count is the headline; these are just proof.
            if (count($byCluster[$cid]) < 12) {
                $byCluster[$cid][] = [
                    'ticket_id'  => (int)$r['ticket_id'],
                    'ticket_ref' => $r['ticket_ref'],
                    'subject'    => $r['subject'],
                    'closed'     => $r['closed_datetime'],
                ];
            }
        }
        foreach ($clusters as &$c) {
            $c['id']             = (int)$c['id'];
            $c['ticket_count']   = (int)$c['ticket_count'];
            $c['max_richness']   = (int)$c['max_richness'];
            $c['best_ticket_id'] = $c['best_ticket_id'] === null ? null : (int)$c['best_ticket_id'];
            $c['article_id']     = $c['article_id'] === null ? null : (int)$c['article_id'];
            $c['tickets']        = $byCluster[(int)$c['id']] ?? [];
        }
        unset($c);
    }

    // When was the assistant last run? Drives "I read N tickets on <date>".
    $lastRun = null;
    try {
        $lastRun = $conn->query("SELECT MAX(analysed_datetime) FROM knowledge_gap_tickets")->fetchColumn() ?: null;
    } catch (Throwable $e) { $lastRun = null; }

    echo json_encode([
        'success'  => true,
        'clusters' => $clusters,
        'last_run' => $lastRun,
        'counts'   => gapStatusCounts($conn, $tSql, $tArgs),
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * How many clusters sit in each state, for the view's tabs.
 *
 * @param string $tSql  the company filter SQL from activeTenantFilter() on alias c, starting with AND (or empty)
 * @param array $tArgs  the values bound to the placeholders in $tSql
 */
function gapStatusCounts(PDO $conn, string $tSql, array $tArgs): array
{
    $out = ['open' => 0, 'dismissed' => 0, 'written' => 0];
    try {
        $st = $conn->prepare("SELECT c.status, COUNT(*) AS n FROM knowledge_gap_clusters c WHERE 1=1 {$tSql} GROUP BY c.status");
        $st->execute($tArgs);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['status']] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* zeroes are fine */ }
    return $out;
}
