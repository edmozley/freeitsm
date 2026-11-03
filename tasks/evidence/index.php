<?php
/**
 * Tasks -> Evidence (#181) - every repeating series, and the way into its evidence report.
 *
 * Lists series from their rule history as well as their tasks, so a series that was
 * stopped, finished, or whose every occurrence has been deleted is still here and its
 * evidence still reachable (Dr4inder on #181: removed records must stay accessible if
 * the series is removed). The task window links to the same report for one series.
 *
 * Gated like the report: module access, then "Run the evidence report" where Tasks ->
 * Settings -> Evidence restricts it. Series in companies the viewer cannot see are left out.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/rbac.php';
require_once '../../includes/task_evidence.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('tasks');
$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$mayRun = analystMayAct($conn, $analystId, Cap::TASKS_EVIDENCE_REPORT);
$series = $mayRun ? TaskEvidence::seriesList($conn, $analystId) : [];

$current_page = 'evidence';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];
$T = fn($k, $p = []) => htmlspecialchars(t('tasks.evidence.' . $k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('tasks', t('tasks.nav.evidence'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=58">
    <link rel="stylesheet" href="../../assets/css/task-evidence.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-analyst-id="<?php echo $analystId; ?>">
    <?php include '../includes/header.php'; ?>

    <div class="ev-page">
        <h2><?php echo $T('list_heading'); ?></h2>
        <p class="ev-intro"><?php echo $T('list_intro'); ?></p>

        <?php if (!$mayRun): ?>
            <div class="ev-empty"><?php echo $T('no_permission'); ?></div>
        <?php elseif (!$series): ?>
            <div class="ev-empty"><?php echo $T('list_none'); ?></div>
        <?php else: ?>
        <div class="ev-table-wrap">
        <table class="ev-table">
            <thead><tr>
                <th><?php echo $T('col_series'); ?></th>
                <th><?php echo $T('col_rule'); ?></th>
                <th><?php echo $T('col_state'); ?></th>
                <th><?php echo $T('col_occurrences'); ?></th>
                <th><?php echo $T('col_last_due'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($series as $s): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['title']); ?></strong></td>
                    <td class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::describeRule($s['rule'])); ?></td>
                    <td><span class="ev-pill ev-state-<?php echo htmlspecialchars($s['state']); ?>"><?php echo $T('state_' . $s['state']); ?></span></td>
                    <td><?php echo (int)$s['occurrences']; ?><?php if ($s['removed']): ?> <span class="ev-sub"><?php echo $T('n_removed', ['n' => $s['removed']]); ?></span><?php endif; ?></td>
                    <td class="ev-sub"><?php echo $s['last_due'] ? htmlspecialchars(TaskEvidence::day($s['last_due'])) : '-'; ?></td>
                    <td><a class="btn btn-secondary btn-sm" href="report.php?series=<?php echo (int)$s['id']; ?>"><?php echo $T('open'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
