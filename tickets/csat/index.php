<?php
/**
 * CSAT Analytics — dedicated page for CSAT KPIs, distribution, per-analyst
 * breakdown, and recent responses with comments. Reachable via Tickets > nav.
 *
 * v1 ships as a standalone page rather than as a dashboard widget because
 * the existing widget library models "group X by Y" and doesn't fit CSAT's
 * average-of-ratings semantics cleanly. Can be migrated to the widget library
 * later if it proves useful enough to deserve the plumbing.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$current_page = 'csat';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'tickets'];
$days = max(1, min(365, (int)($_GET['days'] ?? 30)));

$conn = connectToDatabase();

// Headline KPIs — average, count, response rate over the window
$kpiStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS sent_count,
        SUM(CASE WHEN responded_datetime IS NOT NULL THEN 1 ELSE 0 END) AS response_count,
        AVG(rating) AS avg_rating
     FROM ticket_csat_responses
     WHERE sent_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)"
);
$kpiStmt->execute([$days]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['sent_count' => 0, 'response_count' => 0, 'avg_rating' => null];

$sent     = (int)($kpi['sent_count'] ?? 0);
$received = (int)($kpi['response_count'] ?? 0);
$avg      = $kpi['avg_rating'] !== null ? (float)$kpi['avg_rating'] : null;
$rate     = $sent > 0 ? round($received / $sent * 100, 1) : 0;

// Distribution of scores 1-5 in the window
$distStmt = $conn->prepare(
    "SELECT rating, COUNT(*) AS n FROM ticket_csat_responses
     WHERE rating IS NOT NULL AND responded_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
     GROUP BY rating"
);
$distStmt->execute([$days]);
$dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($distStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dist[(int)$r['rating']] = (int)$r['n'];
}
$distMax = max(array_values($dist) + [1]);

// Per-analyst breakdown
$analystStmt = $conn->prepare(
    "SELECT a.full_name, COUNT(cr.rating) AS responses, AVG(cr.rating) AS avg_rating
     FROM ticket_csat_responses cr
     LEFT JOIN analysts a ON a.id = cr.analyst_id
     WHERE cr.rating IS NOT NULL AND cr.responded_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
     GROUP BY a.id, a.full_name
     HAVING responses > 0
     ORDER BY avg_rating DESC, responses DESC"
);
$analystStmt->execute([$days]);
$perAnalyst = $analystStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent responses (last 25 in window, with comment if any)
$recentStmt = $conn->prepare(
    "SELECT cr.rating, cr.comment, cr.responded_datetime,
            t.ticket_number, t.id AS ticket_id, t.subject,
            a.full_name AS analyst_name
     FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     LEFT JOIN analysts a ON a.id = cr.analyst_id
     WHERE cr.rating IS NOT NULL AND cr.responded_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
     ORDER BY cr.responded_datetime DESC
     LIMIT 25"
);
$recentStmt->execute([$days]);
$recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$emojis = ['', '😡', '🙁', '😐', '🙂', '😀'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::getLocale()) ?>" data-theme="<?= htmlspecialchars(Theme::active()) ?>" data-theme-mode="<?= htmlspecialchars(Theme::mode()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(t('tickets.csat.page_title')) ?></title>
<link rel="stylesheet" href="../../assets/css/theme.css?v=20">
<link rel="stylesheet" href="../../assets/css/inbox.css">
<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<?php echo Tz::scriptTag(); ?>
<script src="../../assets/js/tz.js?v=1"></script>
<script src="../../assets/js/i18n.js?v=2"></script>
<style>
/* Theming: colours use var(--token, #original-light) so light mode is unchanged. */
body { background: var(--app-bg, #f5f5f5); }
.csat-page { height: calc(100vh - 48px); overflow-y: auto; padding: 24px; }
.csat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.csat-header h1 { font-size: 22px; margin: 0; color: var(--text, #333); }
.range-picker { display: flex; gap: 4px; }
.range-picker a {
    padding: 6px 12px; border: 1px solid var(--border, #ddd); background: var(--surface, white);
    border-radius: 4px; color: var(--text-muted, #555); text-decoration: none; font-size: 13px;
}
.range-picker a.active { background: var(--accent, #0078d4); color: var(--on-accent, white); border-color: var(--accent, #0078d4); }

.kpi-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
.kpi-card {
    background: var(--surface, white); border-radius: 8px; padding: 20px;
    box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.06));
}
.kpi-label { font-size: 12px; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.04em; }
.kpi-value { font-size: 32px; font-weight: 600; color: var(--text, #333); margin-top: 6px; }
.kpi-sub { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }

.panel {
    background: var(--surface, white); border-radius: 8px; padding: 20px; margin-bottom: 20px;
    box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.06));
}
.panel h2 { font-size: 16px; margin: 0 0 16px 0; color: var(--text, #333); }

.dist-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.dist-label { width: 60px; text-align: right; font-size: 20px; }
.dist-bar { flex: 1; background: var(--surface-hover, #f0f0f0); border-radius: 4px; height: 24px; overflow: hidden; }
.dist-fill { height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); border-radius: 4px; transition: width 0.3s; }
.dist-count { width: 60px; font-size: 13px; color: var(--text-muted, #666); }

table.analyst-table { width: 100%; border-collapse: collapse; }
table.analyst-table th, table.analyst-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--border-soft, #eee); font-size: 14px; }
table.analyst-table th { color: var(--text-dim, #888); font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
table.analyst-table td.score { font-weight: 600; }

.recent-row {
    border-bottom: 1px solid var(--border-soft, #eee); padding: 14px 0;
    display: grid; grid-template-columns: 60px 1fr 160px; gap: 14px; align-items: start;
}
.recent-row:last-child { border-bottom: none; }
.recent-rating { font-size: 28px; text-align: center; }
.recent-subject { font-weight: 500; color: var(--text, #333); }
.recent-subject a { color: var(--accent, #0078d4); text-decoration: none; }
.recent-comment { color: var(--text-muted, #555); margin-top: 4px; font-size: 13px; font-style: italic; }
.recent-meta { font-size: 12px; color: var(--text-dim, #888); text-align: right; }

.empty { color: var(--text-faint, #999); font-style: italic; padding: 20px 0; text-align: center; }
</style>
</head>
<body>

<?php require_once '../includes/header.php'; ?>

<div class="csat-page">
    <div class="csat-header">
        <h1><?= htmlspecialchars(t('tickets.csat.heading')) ?></h1>
        <div class="range-picker">
            <?php foreach ([7, 30, 90, 365] as $d): ?>
                <a href="?days=<?= $d ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= htmlspecialchars(t('tickets.csat.range_days', ['days' => $d])) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-label"><?= htmlspecialchars(t('tickets.csat.avg_rating')) ?></div>
            <div class="kpi-value"><?= $avg !== null ? number_format($avg, 2) : '—' ?></div>
            <div class="kpi-sub"><?= htmlspecialchars(t('tickets.csat.out_of_5')) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label"><?= htmlspecialchars(t('tickets.csat.responses')) ?></div>
            <div class="kpi-value"><?= $received ?></div>
            <div class="kpi-sub"><?= htmlspecialchars(t('tickets.csat.in_last_days', ['days' => $days])) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label"><?= htmlspecialchars(t('tickets.csat.response_rate')) ?></div>
            <div class="kpi-value"><?= $rate ?>%</div>
            <div class="kpi-sub"><?= htmlspecialchars(t('tickets.csat.rate_of_sent', ['received' => $received, 'sent' => $sent])) ?></div>
        </div>
    </div>

    <div class="panel">
        <h2><?= htmlspecialchars(t('tickets.csat.score_distribution')) ?></h2>
        <?php if ($received === 0): ?>
            <div class="empty"><?= htmlspecialchars(t('tickets.csat.no_responses_window')) ?></div>
        <?php else: ?>
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <div class="dist-row">
                    <div class="dist-label"><?= $emojis[$i] ?></div>
                    <div class="dist-bar"><div class="dist-fill" style="width: <?= ($dist[$i] / $distMax * 100) ?>%"></div></div>
                    <div class="dist-count"><?= $dist[$i] ?> (<?= $received > 0 ? round($dist[$i] / $received * 100) : 0 ?>%)</div>
                </div>
            <?php endfor; ?>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2><?= htmlspecialchars(t('tickets.csat.by_analyst')) ?></h2>
        <?php if (empty($perAnalyst)): ?>
            <div class="empty"><?= htmlspecialchars(t('tickets.csat.no_analyst_responses')) ?></div>
        <?php else: ?>
            <table class="analyst-table">
                <thead><tr><th><?= htmlspecialchars(t('tickets.csat.col_analyst')) ?></th><th><?= htmlspecialchars(t('tickets.csat.col_avg_rating')) ?></th><th><?= htmlspecialchars(t('tickets.csat.col_responses')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($perAnalyst as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['full_name'] ?? t('tickets.csat.unassigned')) ?></td>
                            <td class="score"><?= number_format((float)$row['avg_rating'], 2) ?> / 5</td>
                            <td><?= (int)$row['responses'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2><?= htmlspecialchars(t('tickets.csat.recent_responses')) ?></h2>
        <?php if (empty($recent)): ?>
            <div class="empty"><?= htmlspecialchars(t('tickets.csat.no_recent_responses')) ?></div>
        <?php else: ?>
            <?php foreach ($recent as $r): $rating = (int)$r['rating']; ?>
                <div class="recent-row">
                    <div class="recent-rating"><?= $emojis[$rating] ?></div>
                    <div>
                        <div class="recent-subject">
                            <a href="../index.php?ticket_id=<?= (int)$r['ticket_id'] ?>"><?= htmlspecialchars($r['ticket_number']) ?></a>
                            &middot; <?= htmlspecialchars($r['subject']) ?>
                        </div>
                        <?php if (!empty($r['comment'])): ?>
                            <div class="recent-comment">&ldquo;<?= htmlspecialchars($r['comment']) ?>&rdquo;</div>
                        <?php endif; ?>
                    </div>
                    <div class="recent-meta">
                        <?= htmlspecialchars($r['analyst_name'] ?? t('tickets.csat.unassigned')) ?><br>
                        <?= htmlspecialchars(fmt_local($r['responded_datetime'], 'd M Y H:i')) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
