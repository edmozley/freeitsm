<?php
/**
 * System — Webhooks.
 *
 * Admin view over the outbound-webhook delivery queue (webhook_deliveries):
 *   1. A prominent SETUP card that makes it unmistakable what has to be in place
 *      for webhooks to actually leave the building — chiefly the background
 *      delivery worker (cron/webhook_deliveries.php). We detect live whether that
 *      worker is running (webhook_cron_last_run) and show the exact command to
 *      schedule if not.
 *   2. An OVERVIEW dashboard — 7-day success rate / volume / avg delivery time /
 *      queued / dead-letter KPIs, a 14-day delivered-vs-failed volume chart, and
 *      top target endpoints + source workflows with per-row success rates. All
 *      computed server-side from webhook_deliveries on page load.
 *   3. The delivery log — every queued webhook, its full request payload, the
 *      full response from the endpoint, and a Replay button.
 *
 * Read/replay data comes from api/workflow/deliveries.php + deliveries_replay.php
 * (shared with the Workflows nav link, which points here).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();
require_once '../../includes/functions.php';

$current_page = 'webhooks';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

$conn = connectToDatabase();

// --- Load the delivery-cron settings -------------------------------------
$settings = [];
foreach ($conn->query(
    "SELECT setting_key, setting_value FROM system_settings
     WHERE setting_key IN ('webhook_cron_token','webhook_cron_last_run',
                           'webhook_cron_min_interval_seconds','webhook_delivery_retention_days')"
) as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$cronToken     = $settings['webhook_cron_token'] ?? '';
$retentionDays = (int)($settings['webhook_delivery_retention_days'] ?? 30);

// Age of the last cron run, computed in the DB so UTC comparison is exact.
$lastRun    = $settings['webhook_cron_last_run'] ?? null;
$lastRunAge = null; // seconds since last run, or null if never
if ($lastRun) {
    $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
    $ageStmt->execute([$lastRun]);
    $lastRunAge = (int)$ageStmt->fetchColumn();
}

// How many webhooks are waiting to go out right now.
$pendingCount = (int)$conn->query(
    "SELECT COUNT(*) FROM webhook_deliveries WHERE status IN ('pending','failed')"
)->fetchColumn();

// Worker health: cron runs every minute, so <3min = healthy, <15min = stale, else down.
if ($lastRunAge === null)      { $cronState = 'never'; }
elseif ($lastRunAge <= 180)    { $cronState = 'running'; }
elseif ($lastRunAge <= 900)    { $cronState = 'stale'; }
else                           { $cronState = 'down'; }

// --- Dashboard metrics ---------------------------------------------------
// One aggregate pass for the KPI strip (all-time + rolling windows). Booleans
// summed as 0/1; AVG latency is NULL when nothing has been delivered yet.
$m = $conn->query(
    "SELECT
        COUNT(*)                                                                      AS total_all,
        SUM(status='delivered')                                                       AS delivered_all,
        SUM(status IN ('pending','delivering'))                                        AS inflight_all,
        SUM(status='failed')                                                          AS retrying_all,
        SUM(status='dead')                                                            AS dead_all,
        SUM(created_datetime >= UTC_TIMESTAMP() - INTERVAL 7 DAY)                       AS total_7d,
        SUM(status='delivered' AND created_datetime >= UTC_TIMESTAMP() - INTERVAL 7 DAY)  AS delivered_7d,
        SUM(status='dead'      AND created_datetime >= UTC_TIMESTAMP() - INTERVAL 7 DAY)  AS dead_7d,
        SUM(created_datetime >= UTC_TIMESTAMP() - INTERVAL 24 HOUR)                     AS total_24h,
        AVG(CASE WHEN status='delivered' AND delivered_datetime IS NOT NULL
                  AND created_datetime >= UTC_TIMESTAMP() - INTERVAL 7 DAY
                 THEN TIMESTAMPDIFF(SECOND, created_datetime, delivered_datetime) END) AS avg_latency_7d
     FROM webhook_deliveries"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$hasData = ((int)($m['total_all'] ?? 0)) > 0;

// 7-day success rate = delivered vs terminal (delivered + dead); in-flight excluded.
$term7    = (int)($m['delivered_7d'] ?? 0) + (int)($m['dead_7d'] ?? 0);
$success7 = $term7 > 0 ? (int)round(100 * (int)$m['delivered_7d'] / $term7) : null;

// 14-day volume series, delivered vs failed (failed = retrying + dead), gap-filled.
$volRaw = [];
foreach ($conn->query(
    "SELECT DATE(created_datetime) d,
            SUM(status='delivered')            AS delivered,
            SUM(status IN ('dead','failed'))   AS failed,
            COUNT(*)                           AS total
       FROM webhook_deliveries
      WHERE created_datetime >= UTC_TIMESTAMP() - INTERVAL 13 DAY
      GROUP BY DATE(created_datetime)"
) as $r) { $volRaw[$r['d']] = $r; }

$vol = [];
$today = strtotime(gmdate('Y-m-d'));            // UTC midnight — matches the UTC-stored rows
for ($i = 13; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', strtotime("-$i day", $today));
    $row = $volRaw[$day] ?? [];
    $vol[] = [
        'day'       => $day,
        'delivered' => (int)($row['delivered'] ?? 0),
        'failed'    => (int)($row['failed'] ?? 0),
        'total'     => (int)($row['total'] ?? 0),
    ];
}
$volMax = 1;
foreach ($vol as $v) { if ($v['total'] > $volMax) $volMax = $v['total']; }

// Top target endpoints (by host) and top source workflows — with success rates.
$byHost = $conn->query(
    "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(url,'/',3),'/',-1) AS host,
            COUNT(*) AS total, SUM(status='delivered') AS delivered,
            SUM(status='dead') AS dead,
            SUM(status IN ('pending','delivering','failed')) AS inflight,
            MAX(created_datetime) AS last_at
       FROM webhook_deliveries
      GROUP BY host ORDER BY total DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

$byWorkflow = $conn->query(
    "SELECT COALESCE(w.name,
              CASE WHEN d.workflow_id IS NULL THEN '(direct send)'
                   ELSE CONCAT('workflow #', d.workflow_id, ' (deleted)') END) AS name,
            COUNT(*) AS total, SUM(d.status='delivered') AS delivered, SUM(d.status='dead') AS dead
       FROM webhook_deliveries d
       LEFT JOIN workflows w ON w.id = d.workflow_id
      GROUP BY d.workflow_id, w.name ORDER BY total DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

function whLatency($s) {
    if ($s === null) return '—';
    $s = (float)$s;
    if ($s < 1)    return '<1s';
    if ($s < 90)   return round($s) . 's';
    if ($s < 5400) return round($s / 60) . 'm';
    return round($s / 3600, 1) . 'h';
}
// Success-rate cell: delivered vs terminal (delivered + dead); in-flight ignored.
function whRate($delivered, $dead) {
    $term = (int)$delivered + (int)$dead;
    if ($term === 0) return '<span class="pct">—</span>';
    $pct = (int)round(100 * (int)$delivered / $term);
    $cls = $pct >= 95 ? '' : ($pct >= 80 ? 'warn' : 'bad');
    return '<span class="rate"><span class="track"><span class="fill ' . $cls . '" style="width:' . $pct . '%"></span></span>'
         . '<span class="pct">' . $pct . '%</span></span>';
}

// Exact commands for this install.
$scriptPath = realpath(__DIR__ . '/../../cron/webhook_deliveries.php') ?: 'cron/webhook_deliveries.php';
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$httpUrl    = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL
            . 'cron/webhook_deliveries.php?token=' . urlencode($cronToken);
$cliCmd     = 'php ' . $scriptPath;

function whAgo($s) {
    if ($s === null) return '';
    if ($s < 90)    return $s . ' seconds ago';
    if ($s < 5400)  return round($s / 60) . ' minutes ago';
    if ($s < 129600) return round($s / 3600) . ' hours ago';
    return round($s / 86400) . ' days ago';
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Webhooks</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .wh-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 26px 0; max-width: 720px; line-height: 1.5; }

        .card { background: #fff; border-radius: 8px; padding: 22px 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 22px; }
        .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 4px 0; }
        .card .desc { font-size: 13px; color: #888; margin: 0 0 16px 0; line-height: 1.5; }

        /* ---- Setup / status banner ---- */
        .setup { border-left: 4px solid #90a4ae; }
        .setup.running { border-left-color: #2e7d32; }
        .setup.stale   { border-left-color: #f39c12; }
        .setup.down, .setup.never { border-left-color: #c0392b; }
        .setup-head { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
        .setup-pill { display: inline-flex; align-items: center; gap: 7px; padding: 5px 13px; border-radius: 16px; font-size: 12.5px; font-weight: 600; }
        .setup-pill .dot { width: 8px; height: 8px; border-radius: 50%; }
        .setup-pill.running { background: #e6f4ea; color: #1e7e34; } .setup-pill.running .dot { background: #1e7e34; }
        .setup-pill.stale   { background: #fef6e7; color: #b26a00; } .setup-pill.stale .dot { background: #f39c12; }
        .setup-pill.down, .setup-pill.never { background: #fce8e8; color: #c0392b; } .setup-pill.down .dot, .setup-pill.never .dot { background: #c0392b; }
        .setup-when { font-size: 12.5px; color: #78909c; }

        .setup-explain { font-size: 13px; color: #55606a; line-height: 1.6; margin: 12px 0 0; }
        .setup-explain strong { color: #333; }
        .steps { margin: 16px 0 0; padding: 0; list-style: none; counter-reset: step; }
        .steps li { position: relative; padding: 0 0 16px 34px; font-size: 13px; color: #444; line-height: 1.55; }
        .steps li:last-child { padding-bottom: 0; }
        .steps li::before { counter-increment: step; content: counter(step); position: absolute; left: 0; top: -1px; width: 22px; height: 22px; border-radius: 50%; background: #546e7a; color: #fff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        .steps li strong { color: #333; }
        .cmd-row { display: flex; align-items: stretch; gap: 8px; margin: 8px 0 4px; }
        .cmd-row code { flex: 1; background: #263238; color: #eceff1; border-radius: 5px; padding: 9px 12px; font-size: 12px; font-family: Consolas, Monaco, monospace; overflow-x: auto; white-space: nowrap; }
        .copy-btn { padding: 6px 14px; background: #eceff1; color: #455a64; border: none; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .copy-btn:hover { background: #cfd8dc; }
        .sub { font-size: 12px; color: #90a4ae; margin: 2px 0 0; }
        .sub a { color: #546e7a; }

        .facts { display: flex; flex-wrap: wrap; gap: 26px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #eef2f4; }
        .fact { font-size: 12px; color: #90a4ae; }
        .fact b { display: block; font-size: 13px; color: #37474f; font-weight: 600; margin-top: 2px; }

        /* ---- Queue table ---- */
        .wh-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .wh-filters { display: flex; gap: 8px; flex-wrap: wrap; }
        .wh-chip { padding: 5px 12px; border: 1px solid #d7dce1; border-radius: 16px; background: #fff; font-size: 12.5px; color: #445; cursor: pointer; }
        .wh-chip.active { background: #546e7a; color: #fff; border-color: #546e7a; }
        .wh-chip .n { opacity: 0.7; margin-left: 4px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #546e7a; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.wh { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.wh th { text-align: left; color: #78909c; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 10px; border-bottom: 1px solid #e8ecef; }
        table.wh td { padding: 8px 10px; border-bottom: 1px solid #f2f4f6; vertical-align: middle; }
        .st { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .st.delivered { background: #e6f4ea; color: #1e7e34; }
        .st.pending, .st.delivering { background: #e8eef2; color: #465a66; }
        .st.failed { background: #fdf0e2; color: #b26a00; }
        .st.dead { background: #fce8e8; color: #c0392b; }
        .wh-url { font-family: Consolas, Monaco, monospace; font-size: 11.5px; color: #37474f; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom; }
        .table-action-btn { padding: 4px 10px; background: #f4f6f7; border: 1px solid #e0e5e8; border-radius: 5px; font-size: 12px; color: #455a64; cursor: pointer; }
        .table-action-btn:hover { background: #e8ecef; }
        .wh-empty { padding: 30px; text-align: center; color: #90a4ae; font-size: 13px; }
        .modal-body pre { background: #263238; color: #eceff1; border-radius: 6px; padding: 12px; font-size: 11.5px; overflow: auto; max-height: 300px; white-space: pre-wrap; word-break: break-word; }

        /* ---- Overview dashboard ---- */
        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(148px, 1fr)); gap: 14px; margin: 4px 0 22px; }
        .kpi { border: 1px solid #eef2f4; border-radius: 8px; padding: 13px 16px; background: #fbfcfd; }
        .kpi .k-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.4px; color: #90a4ae; font-weight: 600; }
        .kpi .k-value { font-size: 25px; font-weight: 700; color: #37474f; margin-top: 5px; line-height: 1; }
        .kpi .k-sub { font-size: 11.5px; color: #a3adb5; margin-top: 6px; }
        .kpi.good .k-value { color: #1e7e34; }
        .kpi.warn .k-value { color: #b26a00; }
        .kpi.bad  .k-value { color: #c0392b; }

        .ov-grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 22px; }
        @media (max-width: 880px) { .ov-grid { grid-template-columns: 1fr; } }
        .ov-panel h4 { font-size: 12.5px; font-weight: 600; color: #55606a; margin: 0 0 12px; }

        .chart { display: flex; align-items: flex-end; gap: 5px; height: 132px; }
        .chart .bar { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; justify-content: flex-end; gap: 5px; }
        .chart .col { width: 62%; max-width: 24px; display: flex; flex-direction: column; justify-content: flex-end; }
        .chart .col .seg { width: 100%; }
        .chart .col .seg-fail { background: #ef9a9a; border-radius: 3px 3px 0 0; }
        .chart .col .seg-ok   { background: #66bb6a; }
        .chart .col .seg-ok.top { border-radius: 3px 3px 0 0; }
        .chart .col .seg-zero { height: 2px; background: #e4e9ec; border-radius: 2px; }
        .chart .cap { font-size: 9px; color: #b0bec5; white-space: nowrap; }
        .chart-legend { display: flex; gap: 16px; margin-top: 10px; font-size: 11.5px; color: #90a4ae; }
        .chart-legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; vertical-align: -1px; margin-right: 5px; }

        table.mini { width: 100%; border-collapse: collapse; font-size: 12px; }
        table.mini th { text-align: left; color: #90a4ae; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; padding: 4px 8px; border-bottom: 1px solid #eef2f4; }
        table.mini td { padding: 6px 8px; border-bottom: 1px solid #f4f6f8; vertical-align: middle; }
        table.mini td.name { font-weight: 500; color: #37474f; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        table.mini td.mono { font-family: Consolas, Monaco, monospace; font-size: 11px; color: #455a64; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        table.mini td.num { text-align: right; color: #607d8b; font-variant-numeric: tabular-nums; }
        .rate { display: flex; align-items: center; gap: 7px; justify-content: flex-end; }
        .rate .track { width: 42px; height: 5px; border-radius: 3px; background: #eef2f4; overflow: hidden; }
        .rate .fill { height: 100%; background: #66bb6a; }
        .rate .fill.warn { background: #ffb74d; }
        .rate .fill.bad { background: #e57373; }
        .rate .pct { font-size: 11px; color: #607d8b; min-width: 30px; text-align: right; font-variant-numeric: tabular-nums; }
        .ov-empty { padding: 26px; text-align: center; color: #b0bec5; font-size: 12.5px; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="wh-container">
        <h1 class="page-title">Webhooks</h1>
        <p class="page-subtitle">
            Outbound webhooks — queued by the <em>Send a webhook</em> workflow action — are delivered in the
            background with automatic retries, so a slow or dead endpoint never holds up a ticket. This page shows
            whether delivery is set up, an overview of delivery health, and the full log where you can inspect every
            request, its response, and replay any of them.
        </p>

        <!-- ============ SETUP / STATUS ============ -->
        <div class="card setup <?php echo $cronState; ?>">
            <div class="setup-head">
                <?php
                    $pillLabel = ['running' => 'Delivery worker is running',
                                  'stale'   => 'Delivery worker is delayed',
                                  'down'    => 'Delivery worker has stopped',
                                  'never'   => 'Not set up yet'][$cronState];
                ?>
                <span class="setup-pill <?php echo $cronState; ?>"><span class="dot"></span><?php echo $pillLabel; ?></span>
                <?php if ($lastRun): ?>
                    <span class="setup-when">Last ran <?php echo htmlspecialchars(whAgo($lastRunAge)); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($cronState === 'running'): ?>
                <p class="setup-explain">
                    The background worker is running and delivering the queue.
                    <?php if ($pendingCount > 0): ?>
                        <strong><?php echo $pendingCount; ?></strong> webhook<?php echo $pendingCount === 1 ? '' : 's'; ?>
                        waiting — <?php echo $pendingCount === 1 ? 'it' : 'they'; ?> will go out within a minute.
                    <?php else: ?>
                        Nothing is currently waiting.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="setup-explain">
                    <?php if ($cronState === 'never'): ?>
                        <strong>Webhooks will not be sent</strong> until the background delivery worker is scheduled to run.
                    <?php elseif ($cronState === 'down'): ?>
                        <strong>Webhooks are no longer being sent</strong> — the worker last ran
                        <?php echo htmlspecialchars(whAgo($lastRunAge)); ?> and appears to have stopped.
                    <?php else: ?>
                        The worker is running but behind schedule (last run <?php echo htmlspecialchars(whAgo($lastRunAge)); ?>).
                        It should run every minute.
                    <?php endif; ?>
                    <?php if ($pendingCount > 0): ?>
                        <strong><?php echo $pendingCount; ?></strong> webhook<?php echo $pendingCount === 1 ? '' : 's'; ?>
                        <?php echo $pendingCount === 1 ? 'is' : 'are'; ?> queued and waiting.
                    <?php endif; ?>
                </p>

                <ol class="steps">
                    <li>
                        <strong>Schedule the delivery worker to run every minute.</strong> On the server, run this command
                        from a scheduled task (Windows Task Scheduler) or cron (Linux):
                        <div class="cmd-row">
                            <code id="cliCmd"><?php echo htmlspecialchars($cliCmd); ?></code>
                            <button class="copy-btn" data-copy="cliCmd" type="button">Copy</button>
                        </div>
                        <p class="sub">Can't run PHP from the shell? Call it over HTTP instead (e.g. from a hosted cron service):</p>
                        <div class="cmd-row">
                            <code id="httpCmd"><?php echo htmlspecialchars($httpUrl); ?></code>
                            <button class="copy-btn" data-copy="httpCmd" type="button">Copy</button>
                        </div>
                    </li>
                    <li>
                        <strong>Add the <em>Send a webhook</em> action to a workflow</strong> so events start filling the queue —
                        under <a href="<?php echo BASE_URL; ?>workflow/">Workflows</a>.
                    </li>
                    <li>
                        <strong>Watch this page.</strong> Once the worker runs, the status above turns green and deliveries appear below.
                        Full setup notes (Windows &amp; Linux, signature verification) are in
                        <a href="https://github.com/edmozley/freeitsm/wiki/Workflows" target="_blank" rel="noopener">the Workflows wiki</a>.
                    </li>
                </ol>
            <?php endif; ?>

            <div class="facts">
                <div class="fact">Worker script<b>cron/webhook_deliveries.php</b></div>
                <div class="fact">Retry schedule<b>1m · 5m · 15m · 1h · 6h, then failed</b></div>
                <div class="fact">Log retention<b><?php echo $retentionDays; ?> days</b></div>
                <?php if ($cronState !== 'never'): ?>
                    <div class="fact">Queued now<b><?php echo $pendingCount; ?></b></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ OVERVIEW DASHBOARD ============ -->
        <div class="card">
            <h3>Overview</h3>
            <p class="desc">Delivery health at a glance — the last 7 days, plus your busiest endpoints and the workflows sending the most.</p>

            <?php
                $srClass = $success7 === null ? '' : ($success7 >= 99 ? 'good' : ($success7 >= 90 ? 'warn' : 'bad'));
                $deadAll = (int)($m['dead_all'] ?? 0);
            ?>
            <div class="kpis">
                <div class="kpi <?php echo $srClass; ?>">
                    <div class="k-label">Success rate · 7d</div>
                    <div class="k-value"><?php echo $success7 === null ? '—' : $success7 . '%'; ?></div>
                    <div class="k-sub"><?php echo (int)($m['delivered_7d'] ?? 0); ?> of <?php echo $term7; ?> delivered</div>
                </div>
                <div class="kpi">
                    <div class="k-label">Sent · 7d</div>
                    <div class="k-value"><?php echo (int)($m['total_7d'] ?? 0); ?></div>
                    <div class="k-sub"><?php echo (int)($m['total_24h'] ?? 0); ?> in the last 24h</div>
                </div>
                <div class="kpi">
                    <div class="k-label">Avg delivery time</div>
                    <div class="k-value"><?php echo whLatency($m['avg_latency_7d'] ?? null); ?></div>
                    <div class="k-sub">queue → delivered · 7d</div>
                </div>
                <div class="kpi <?php echo $pendingCount > 0 ? 'warn' : ''; ?>">
                    <div class="k-label">Queued now</div>
                    <div class="k-value"><?php echo $pendingCount; ?></div>
                    <div class="k-sub"><?php echo (int)($m['inflight_all'] ?? 0); ?> in flight · <?php echo (int)($m['retrying_all'] ?? 0); ?> retrying</div>
                </div>
                <div class="kpi <?php echo $deadAll > 0 ? 'bad' : ''; ?>">
                    <div class="k-label">Dead-letter</div>
                    <div class="k-value"><?php echo $deadAll; ?></div>
                    <div class="k-sub"><?php echo $deadAll > 0 ? 'gave up after retries' : 'none — all clear'; ?></div>
                </div>
            </div>

            <?php if (!$hasData): ?>
                <div class="ov-empty">No webhook deliveries recorded yet — once a <em>Send a webhook</em> action fires, its stats appear here.</div>
            <?php else: ?>
                <div class="ov-grid">
                    <!-- 14-day volume -->
                    <div class="ov-panel">
                        <h4>Volume — last 14 days</h4>
                        <div class="chart">
                            <?php foreach ($vol as $v):
                                $okpx   = $v['delivered'] > 0 ? max(2, (int)round(110 * $v['delivered'] / $volMax)) : 0;
                                $failpx = $v['failed']    > 0 ? max(2, (int)round(110 * $v['failed']    / $volMax)) : 0;
                                $dd = (int)substr($v['day'], 8, 2);
                            ?>
                                <div class="bar" title="<?php echo htmlspecialchars($v['day']); ?>: <?php echo $v['delivered']; ?> delivered, <?php echo $v['failed']; ?> failed">
                                    <div class="col">
                                        <?php if ($v['total'] === 0): ?>
                                            <div class="seg seg-zero"></div>
                                        <?php else: ?>
                                            <?php if ($failpx > 0): ?><div class="seg seg-fail" style="height:<?php echo $failpx; ?>px;"></div><?php endif; ?>
                                            <?php if ($okpx > 0): ?><div class="seg seg-ok <?php echo $failpx > 0 ? '' : 'top'; ?>" style="height:<?php echo $okpx; ?>px;"></div><?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cap"><?php echo $dd; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="chart-legend">
                            <span><span class="sw" style="background:#66bb6a;"></span>Delivered</span>
                            <span><span class="sw" style="background:#ef9a9a;"></span>Failed / retrying</span>
                        </div>
                    </div>

                    <!-- breakdowns -->
                    <div class="ov-panel">
                        <h4>Top endpoints</h4>
                        <table class="mini">
                            <thead><tr><th>Endpoint</th><th class="num">Sent</th><th class="num">Success</th></tr></thead>
                            <tbody>
                                <?php foreach ($byHost as $h): ?>
                                    <tr>
                                        <td class="mono" title="<?php echo htmlspecialchars($h['host']); ?>"><?php echo htmlspecialchars($h['host']); ?></td>
                                        <td class="num"><?php echo (int)$h['total']; ?></td>
                                        <td class="num"><?php echo whRate($h['delivered'], $h['dead']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <h4 style="margin-top:18px;">Top source workflows</h4>
                        <table class="mini">
                            <thead><tr><th>Workflow</th><th class="num">Sent</th><th class="num">Success</th></tr></thead>
                            <tbody>
                                <?php foreach ($byWorkflow as $w): ?>
                                    <tr>
                                        <td class="name" title="<?php echo htmlspecialchars($w['name']); ?>"><?php echo htmlspecialchars($w['name']); ?></td>
                                        <td class="num"><?php echo (int)$w['total']; ?></td>
                                        <td class="num"><?php echo whRate($w['delivered'], $w['dead']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============ DELIVERY LOG ============ -->
        <div class="card">
            <div class="wh-head">
                <div class="wh-filters" id="filters"></div>
                <button class="add-btn" id="refreshBtn" type="button">Refresh</button>
            </div>
            <div id="tableWrap">
                <table class="wh">
                    <thead>
                        <tr>
                            <th>When</th><th>Workflow</th><th>Format</th><th>URL</th>
                            <th>Status</th><th>Attempts</th><th>Last code</th><th>Next retry</th><th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rows"></tbody>
                </table>
                <div class="wh-empty" id="empty" style="display:none;">No webhook deliveries yet. Add a <em>Send a webhook</em> action to a workflow and trigger it.</div>
            </div>
        </div>
    </div>

    <!-- Payload modal -->
    <div class="modal" id="payloadModal" style="display:none;">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header"><h3 id="pmTitle">Delivery</h3><button class="modal-close" id="pmClose" type="button">&times;</button></div>
            <div class="modal-body" id="pmBody"></div>
        </div>
    </div>

    <script>
    const API = '<?php echo htmlspecialchars(BASE_URL . 'api/workflow'); ?>';
    let filter = '';
    let cache = [];
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const host = u => { try { return new URL(u).host; } catch (e) { return u; } };
    const fmt = s => s ? s.replace('T', ' ').replace(/\.\d+Z?$/, '') + ' UTC' : '';

    async function load() {
        const res = await fetch(API + '/deliveries.php' + (filter ? '?status=' + filter : ''), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { document.getElementById('rows').innerHTML = '<tr><td colspan="9">' + esc(data.error || 'Error') + '</td></tr>'; return; }
        cache = data.deliveries;
        renderFilters(data.summary);
        renderRows(data.deliveries);
    }

    function renderFilters(summary) {
        const total = Object.values(summary).reduce((a, b) => a + b, 0);
        const defs = [['', 'All', total], ['pending', 'Pending', summary.pending || 0], ['delivered', 'Delivered', summary.delivered || 0],
                      ['failed', 'Retrying', summary.failed || 0], ['dead', 'Failed', summary.dead || 0]];
        document.getElementById('filters').innerHTML = defs.map(([v, l, n]) =>
            `<button class="wh-chip ${filter === v ? 'active' : ''}" data-f="${v}">${l}<span class="n">${n}</span></button>`).join('');
        document.querySelectorAll('.wh-chip').forEach(c => c.onclick = () => { filter = c.dataset.f; load(); });
    }

    function renderRows(rows) {
        document.getElementById('empty').style.display = rows.length ? 'none' : 'block';
        document.getElementById('rows').innerHTML = rows.map(r => {
            const statusLabel = r.status === 'failed' ? 'retrying' : (r.status === 'dead' ? 'failed' : r.status);
            const replay = (r.status === 'delivered' || r.status === 'failed' || r.status === 'dead')
                ? `<button class="table-action-btn" data-replay="${r.id}" title="Send again">Replay</button>` : '';
            return `<tr>
                <td>${esc(fmt(r.created))}</td>
                <td>${esc(r.workflow)}</td>
                <td>${esc(r.preset || 'custom')}</td>
                <td><span class="wh-url" title="${esc(r.url)}">${esc(host(r.url))}</span></td>
                <td><span class="st ${r.status}">${esc(statusLabel)}</span></td>
                <td>${r.attempts}/${r.max_attempts}</td>
                <td>${r.last_status !== null ? r.last_status : '—'}</td>
                <td>${r.status === 'failed' && r.next_attempt ? esc(fmt(r.next_attempt)) : '—'}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="table-action-btn" data-view="${r.id}">View</button> ${replay}
                </td></tr>`;
        }).join('');
        document.querySelectorAll('[data-view]').forEach(b => b.onclick = () => view(+b.dataset.view));
        document.querySelectorAll('[data-replay]').forEach(b => b.onclick = () => replay(+b.dataset.replay));
    }

    function view(id) {
        const r = cache.find(x => x.id === id);
        if (!r) return;
        document.getElementById('pmTitle').textContent = 'Delivery #' + r.id + ' — ' + (r.preset || 'custom');
        document.getElementById('pmBody').innerHTML =
            '<p style="font-size:12px;color:#667;margin:0 0 8px;">' + esc(r.method) + ' ' + esc(r.url) + '</p>'
            + '<strong style="font-size:12px;">Request headers</strong><pre>' + esc((r.headers || []).join('\n')) + '</pre>'
            + '<strong style="font-size:12px;">Request body (sent)</strong><pre>' + esc(r.body || '') + '</pre>'
            + (r.response ? '<strong style="font-size:12px;">Response' + (r.last_status ? ' (HTTP ' + r.last_status + ')' : '') + '</strong><pre>' + esc(r.response) + '</pre>' : '')
            + (r.last_error ? '<strong style="font-size:12px;color:#c0392b;">Last error</strong><pre>' + esc(r.last_error) + '</pre>' : '');
        document.getElementById('payloadModal').style.display = 'flex';
    }

    async function replay(id) {
        const res = await fetch(API + '/deliveries_replay.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!data.success) alert(data.error || 'Replay failed');
        load();
    }

    document.querySelectorAll('[data-copy]').forEach(b => b.onclick = () => {
        const t = document.getElementById(b.dataset.copy).textContent;
        navigator.clipboard.writeText(t).then(() => { const o = b.textContent; b.textContent = 'Copied'; setTimeout(() => b.textContent = o, 1200); });
    });
    document.getElementById('refreshBtn').onclick = load;
    document.getElementById('pmClose').onclick = () => document.getElementById('payloadModal').style.display = 'none';
    document.getElementById('payloadModal').onclick = e => { if (e.target.id === 'payloadModal') e.target.style.display = 'none'; };
    load();
    </script>
</body>
</html>
