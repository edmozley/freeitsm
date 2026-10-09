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
require_once '../../includes/tenancy.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'csat';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'tickets'];
$days = max(1, min(365, (int)($_GET['days'] ?? 30)));

$conn = connectToDatabase();

// GH #157: every figure on this page follows the company switcher in the
// header, like the ticket list - the company picked there, or every company
// this analyst may see under "All companies". ticketTenantFilter() is the
// ticket list's own scope; it is empty on a single-company install, where the
// join to tickets changes nothing (a CSAT row is deleted with its ticket).
[$ttSql, $ttParams] = ticketTenantFilter($conn, (int)$_SESSION['analyst_id'], 't');

// ---------------------------------------------------------------------------
// Filters (GH #157). All of them are plain GET parameters, so a filtered view
// can be bookmarked or pasted to a colleague.
//
//  - Period: the 7/30/90/365 buttons, or a From/To pair of the analyst's own
//    calendar days (converted to UTC, which is how the columns are stored).
//    Either end may be left blank.
//  - Analyst / Customer: narrow EVERYTHING on the page.
//  - Rating: narrows the responses LIST only. Averaging only the 5s gives 5,
//    so applied to the headline figures it would make them meaningless.
//
// 🔴 The company scope above is applied to every query, the drop-down lists
// included - an id typed into the URL for someone in another company simply
// matches nothing.
// ---------------------------------------------------------------------------
$ratingBands = ['positive' => [4, 5], 'neutral' => [3, 3], 'negative' => [1, 2]];
$ratingF   = isset($ratingBands[$_GET['rating'] ?? '']) ? $_GET['rating'] : '';
$analystF  = max(0, (int)($_GET['analyst'] ?? 0));
$customerF = max(0, (int)($_GET['customer'] ?? 0));
$pageNo    = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;

$validDay = function ($d): bool {
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
};
$fromIn = $validDay($_GET['from'] ?? null) ? $_GET['from'] : '';
$toIn   = $validDay($_GET['to'] ?? null) ? $_GET['to'] : '';
if ($fromIn !== '' && $toIn !== '' && $fromIn > $toIn) {
    [$fromIn, $toIn] = [$toIn, $fromIn];
}
$customRange = ($fromIn !== '' || $toIn !== '');
if ($customRange) {
    $zone = new DateTimeZone(Tz::current());
    $utc  = new DateTimeZone('UTC');
    $fromUtc = $fromIn !== ''
        ? (new DateTime($fromIn . ' 00:00:00', $zone))->setTimezone($utc)->format('Y-m-d H:i:s')
        : '1970-01-01 00:00:00';
    $toUtc = $toIn !== ''   // up to the END of the To day
        ? (new DateTime($toIn . ' 00:00:00', $zone))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')
        : '9999-12-31 00:00:00';
} else {
    $fromUtc = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $toUtc   = '9999-12-31 00:00:00';
}

// The window on one of the two dates, plus company, analyst and customer.
$scope = function (string $dateCol) use ($ttSql, $ttParams, $fromUtc, $toUtc, $analystF, $customerF): array {
    $sql = " AND cr.$dateCol >= ? AND cr.$dateCol < ?" . $ttSql;
    $params = array_merge([$fromUtc, $toUtc], $ttParams);
    if ($analystF) { $sql .= ' AND cr.analyst_id = ?'; $params[] = $analystF; }
    if ($customerF) { $sql .= ' AND t.user_id = ?'; $params[] = $customerF; }
    return [$sql, $params];
};

// Headline KPIs — average, count, response rate over the window. By SENT date,
// so the rate compares surveys sent in the period with their answers.
[$sSql, $sParams] = $scope('sent_datetime');
$kpiStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS sent_count,
        SUM(CASE WHEN cr.responded_datetime IS NOT NULL THEN 1 ELSE 0 END) AS response_count,
        AVG(cr.rating) AS avg_rating
     FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     WHERE 1=1" . $sSql
);
$kpiStmt->execute($sParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['sent_count' => 0, 'response_count' => 0, 'avg_rating' => null];

$sent     = (int)($kpi['sent_count'] ?? 0);
$received = (int)($kpi['response_count'] ?? 0);
$avg      = $kpi['avg_rating'] !== null ? (float)$kpi['avg_rating'] : null;
$rate     = $sent > 0 ? round($received / $sent * 100, 1) : 0;

// Everything else is by RESPONSE date, as before
[$rSql, $rParams] = $scope('responded_datetime');

// Distribution of scores 1-5 in the window
$distStmt = $conn->prepare(
    "SELECT cr.rating, COUNT(*) AS n FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     WHERE cr.rating IS NOT NULL" . $rSql . "
     GROUP BY cr.rating"
);
$distStmt->execute($rParams);
$dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($distStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $dist[(int)$r['rating']] = (int)$r['n'];
}
$distMax = max(array_values($dist) + [1]);

// Per-analyst breakdown
$analystStmt = $conn->prepare(
    "SELECT a.full_name, COUNT(cr.rating) AS responses, AVG(cr.rating) AS avg_rating
     FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     LEFT JOIN analysts a ON a.id = cr.analyst_id
     WHERE cr.rating IS NOT NULL" . $rSql . "
     GROUP BY a.id, a.full_name
     HAVING responses > 0
     ORDER BY avg_rating DESC, responses DESC"
);
$analystStmt->execute($rParams);
$perAnalyst = $analystStmt->fetchAll(PDO::FETCH_ASSOC);

// Responses, newest first, a page at a time - plus the rating band if chosen
$listSql    = $rSql;
$listParams = $rParams;
if ($ratingF !== '') {
    $listSql   .= ' AND cr.rating BETWEEN ? AND ?';
    $listParams = array_merge($listParams, $ratingBands[$ratingF]);
}
$countStmt = $conn->prepare(
    "SELECT COUNT(*) FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     WHERE cr.rating IS NOT NULL" . $listSql
);
$countStmt->execute($listParams);
$listTotal = (int)$countStmt->fetchColumn();
$pages  = max(1, (int)ceil($listTotal / $perPage));
$pageNo = min($pageNo, $pages);
$offset = ($pageNo - 1) * $perPage;

$recentStmt = $conn->prepare(
    "SELECT cr.rating, cr.comment, cr.responded_datetime,
            t.ticket_number, t.id AS ticket_id, t.subject,
            a.full_name AS analyst_name,
            COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email) AS customer_name
     FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     LEFT JOIN analysts a ON a.id = cr.analyst_id
     LEFT JOIN users u ON u.id = t.user_id
     WHERE cr.rating IS NOT NULL" . $listSql . "
     ORDER BY cr.responded_datetime DESC, cr.id DESC
     LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
);
$recentStmt->execute($listParams);
$recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// The two drop-downs: only people with a CSAT response in THIS company scope
// (not the period, so a choice does not vanish when the dates change).
$optStmt = $conn->prepare(
    "SELECT DISTINCT a.id, a.full_name AS name
     FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     INNER JOIN analysts a ON a.id = cr.analyst_id
     WHERE 1=1" . $ttSql . "
     ORDER BY a.full_name"
);
$optStmt->execute($ttParams);
$analystOpts = $optStmt->fetchAll(PDO::FETCH_ASSOC);

$optStmt = $conn->prepare(
    "SELECT DISTINCT u.id, COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email) AS name
     FROM ticket_csat_responses cr
     INNER JOIN tickets t ON t.id = cr.ticket_id
     INNER JOIN users u ON u.id = t.user_id
     WHERE 1=1" . $ttSql . "
     ORDER BY name"
);
$optStmt->execute($ttParams);
$customerOpts = $optStmt->fetchAll(PDO::FETCH_ASSOC);

/** This page's URL with some parameters changed (null removes one). */
$csatUrl = function (array $change): string {
    $keep = array_intersect_key($_GET, array_flip(['days', 'from', 'to', 'rating', 'analyst', 'customer', 'page']));
    $q = array_filter(array_merge($keep, $change), function ($v) {
        return $v !== null && $v !== '' && $v !== 0 && $v !== '0';
    });
    return '?' . http_build_query($q);
};
$filtersOn = ($customRange || $ratingF !== '' || $analystF || $customerF);

$emojis = ['', '😡', '🙁', '😐', '🙂', '😀'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::getLocale()) ?>" data-theme="<?= htmlspecialchars(Theme::active()) ?>" data-theme-mode="<?= htmlspecialchars(Theme::mode()) ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(t('tickets.csat.page_title')) ?></title>
<link rel="stylesheet" href="../../assets/css/theme.css?v=24">
<link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
<script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
<?php echo Tz::scriptTag(); ?>
<script src="../../assets/js/tz.js?v=5"></script>
<script src="../../assets/js/i18n.js?v=3"></script>
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

/* Filters (GH #157) */
.csat-filters {
    display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;
    background: var(--surface, white); border-radius: 8px; padding: 14px 20px; margin-bottom: 24px;
    box-shadow: 0 1px 3px var(--shadow, rgba(0,0,0,0.06));
}
.cf-field { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--text-dim, #888); }
.cf-field input, .cf-field select {
    padding: 7px 9px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: 13px;
    font-family: inherit; background: var(--surface, white); color: var(--text, #333); min-width: 150px;
}
.cf-actions { display: flex; align-items: center; gap: 12px; }
.cf-apply {
    padding: 8px 18px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600;
    background: var(--accent, #0078d4); color: var(--on-accent, white);
}
.cf-clear { font-size: 13px; color: var(--accent, #0078d4); text-decoration: none; }
/* Phone-only: revealed by mobile.css */
.csat-filters-head, .csat-filter-fab { display: none; }

.rating-note { font-size: 12px; color: var(--text-dim, #888); margin: -8px 0 8px; }
.recent-customer { font-size: 12px; color: var(--text-dim, #888); margin-top: 2px; }
.csat-pager { display: flex; justify-content: center; align-items: center; gap: 16px; padding-top: 14px; font-size: 13px; color: var(--text-dim, #888); }
.csat-pager a { color: var(--accent, #0078d4); text-decoration: none; font-weight: 600; }
</style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=186">
</head>
<body data-mobile-page="tickets-csat">

<?php require_once '../includes/header.php'; ?>

<div class="csat-page">
    <div class="csat-header">
        <h1><?= htmlspecialchars(t('tickets.csat.heading')) ?></h1>
        <div class="range-picker">
            <?php foreach ([7, 30, 90, 365] as $d): ?>
                <?php /* A preset replaces a custom From/To, and keeps the other filters. */ ?>
                <a href="<?= htmlspecialchars($csatUrl(['days' => $d, 'from' => null, 'to' => null, 'page' => null])) ?>" class="<?= (!$customRange && $days === $d) ? 'active' : '' ?>"><?= htmlspecialchars(t('tickets.csat.range_days', ['days' => $d])) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    // GH #157. One form: a row under the heading on desktop; on a phone
    // mobile.css turns it into a full-screen sheet opened from the sticky
    // Filters button at the bottom (hidden on desktop).
    $activeCount = ($customRange ? 1 : 0) + ($ratingF !== '' ? 1 : 0) + ($analystF ? 1 : 0) + ($customerF ? 1 : 0);
    ?>
    <form class="csat-filters" id="csatFilters" method="get">
        <div class="csat-filters-head">
            <span><?= htmlspecialchars(t('tickets.csat.filters')) ?></span>
            <button type="button" class="ms-close" onclick="csatShowFilters(false)"><?= htmlspecialchars(t('tickets.csat.close')) ?></button>
        </div>
        <input type="hidden" name="days" value="<?= (int)$days ?>">
        <label class="cf-field"><span><?= htmlspecialchars(t('tickets.csat.filter_from')) ?></span>
            <input type="date" name="from" value="<?= htmlspecialchars($fromIn) ?>"></label>
        <label class="cf-field"><span><?= htmlspecialchars(t('tickets.csat.filter_to')) ?></span>
            <input type="date" name="to" value="<?= htmlspecialchars($toIn) ?>"></label>
        <label class="cf-field"><span><?= htmlspecialchars(t('tickets.csat.filter_rating')) ?></span>
            <select name="rating">
                <option value=""><?= htmlspecialchars(t('tickets.csat.rating_all')) ?></option>
                <?php foreach (['positive', 'neutral', 'negative'] as $band): ?>
                    <option value="<?= $band ?>"<?= $ratingF === $band ? ' selected' : '' ?>><?= htmlspecialchars(t('tickets.csat.rating_' . $band)) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="cf-field"><span><?= htmlspecialchars(t('tickets.csat.filter_analyst')) ?></span>
            <select name="analyst">
                <option value=""><?= htmlspecialchars(t('tickets.csat.analyst_all')) ?></option>
                <?php foreach ($analystOpts as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"<?= $analystF === (int)$o['id'] ? ' selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="cf-field"><span><?= htmlspecialchars(t('tickets.csat.filter_customer')) ?></span>
            <select name="customer">
                <option value=""><?= htmlspecialchars(t('tickets.csat.customer_all')) ?></option>
                <?php foreach ($customerOpts as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"<?= $customerF === (int)$o['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string)$o['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <div class="cf-actions">
            <button type="submit" class="cf-apply"><?= htmlspecialchars(t('tickets.csat.apply')) ?></button>
            <?php if ($filtersOn): ?>
                <a class="cf-clear" href="?days=<?= (int)$days ?>"><?= htmlspecialchars(t('tickets.csat.clear')) ?></a>
            <?php endif; ?>
        </div>
    </form>
    <button type="button" class="csat-filter-fab" onclick="csatShowFilters(true)">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
        <span><?= htmlspecialchars(t('tickets.csat.filters')) ?></span>
        <?php if ($activeCount): ?><span class="cf-badge"><?= $activeCount ?></span><?php endif; ?>
    </button>

    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-label"><?= htmlspecialchars(t('tickets.csat.avg_rating')) ?></div>
            <div class="kpi-value"><?= $avg !== null ? number_format($avg, 2) : '—' ?></div>
            <div class="kpi-sub"><?= htmlspecialchars(t('tickets.csat.out_of_5')) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label"><?= htmlspecialchars(t('tickets.csat.responses')) ?></div>
            <div class="kpi-value"><?= $received ?></div>
            <div class="kpi-sub"><?= htmlspecialchars($customRange ? t('tickets.csat.in_period') : t('tickets.csat.in_last_days', ['days' => $days])) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label"><?= htmlspecialchars(t('tickets.csat.response_rate')) ?></div>
            <div class="kpi-value"><?= $rate ?>%</div>
            <div class="kpi-sub"><?= htmlspecialchars(t('tickets.csat.rate_of_sent', ['received' => $received, 'sent' => $sent])) ?></div>
        </div>
    </div>

    <div class="panel">
        <h2><?= htmlspecialchars(t('tickets.csat.score_distribution')) ?></h2>
        <?php /* Percentages of the bars' OWN total: the headline count is by
                 sent date and these are by response date, so dividing by it
                 could give shares that do not add up to 100. */
              $rated = array_sum($dist); ?>
        <?php if ($rated === 0): ?>
            <div class="empty"><?= htmlspecialchars(t('tickets.csat.no_responses_window')) ?></div>
        <?php else: ?>
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <div class="dist-row">
                    <div class="dist-label"><?= $emojis[$i] ?></div>
                    <div class="dist-bar"><div class="dist-fill" style="width: <?= ($dist[$i] / $distMax * 100) ?>%"></div></div>
                    <div class="dist-count"><?= $dist[$i] ?> (<?= round($dist[$i] / $rated * 100) ?>%)</div>
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
        <?php if ($ratingF !== ''): ?>
            <div class="rating-note"><?= htmlspecialchars(t('tickets.csat.rating_filter_note', ['rating' => t('tickets.csat.rating_' . $ratingF)])) ?></div>
        <?php endif; ?>
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
                        <?php if (!empty($r['customer_name'])): ?>
                            <div class="recent-customer"><?= htmlspecialchars(t('tickets.csat.from_customer', ['name' => $r['customer_name']])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($r['comment'])): ?>
                            <div class="recent-comment">&ldquo;<?= htmlspecialchars($r['comment']) ?>&rdquo;</div>
                        <?php endif; ?>
                    </div>
                    <div class="recent-meta">
                        <?= htmlspecialchars($r['analyst_name'] ?? t('tickets.csat.unassigned')) ?><br>
                        <?= htmlspecialchars(fmt_datetime($r['responded_datetime'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($pages > 1): ?>
                <div class="csat-pager">
                    <?php if ($pageNo > 1): ?>
                        <a href="<?= htmlspecialchars($csatUrl(['page' => $pageNo - 1])) ?>"><?= htmlspecialchars(t('tickets.csat.prev')) ?></a>
                    <?php endif; ?>
                    <span><?= htmlspecialchars(t('tickets.csat.showing', ['from' => $offset + 1, 'to' => $offset + count($recent), 'total' => $listTotal])) ?></span>
                    <?php if ($pageNo < $pages): ?>
                        <a href="<?= htmlspecialchars($csatUrl(['page' => $pageNo + 1])) ?>"><?= htmlspecialchars(t('tickets.csat.next')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
/* The phone-only Filters sheet (GH #157). On desktop the form is simply
   always visible and nothing calls this. */
function csatShowFilters(open) {
    document.body.classList.toggle('csat-filters-open', open);
}
</script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
