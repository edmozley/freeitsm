<?php
/**
 * System - Maintenance - Deleted folders kept (GH #293).
 *
 * The Files recycle bin's retention job deletes a folder for good only when the
 * person who deleted it could have deleted ALL of it by hand - never a subfolder
 * kept from them (Ed, 16 Nov 2026: "keep it safe"). The rest stay in the bin past
 * their date, and this page says which, why, and what to do. Administrators only
 * (the System module), so folder names are shown in full.
 *
 * Read only. The list and its rule are FilesRecycleService::heldEntries(); the
 * card's count is the same list, the ones past their date.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'files-held';
require_once __DIR__ . '/../includes/page_gate.php';
require_once '../../includes/services/files_recycle.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

$rows = [];
$days = null;
$failed = false;
try {
    $conn = connectToDatabase();
    $rows = FilesRecycleService::heldEntries($conn);
    $days = FilesRecycleService::retentionDays($conn);
} catch (Throwable $ex) {
    error_log('files-held: ' . $ex->getMessage());
    $failed = true;
}
$e = function (string $k, array $p = []): string { return htmlspecialchars(t('system.files_held.' . $k, $p)); };
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.files_held.heading'))); ?></title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css?v=97">
    <style>
        body {
            /* System's dark accent is a LIGHT colour, so --on-accent is pinned too
               (see system/debug-tools/index.php). */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Page layout, title and subtitle: assets/css/system-page.css (.sys-page on .fh-wrap). */
        .fh-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f7fa); }
        .fh-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; margin: 0 0 12px; }
        .fh-back:hover { text-decoration: underline; }
        .fh-todo { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; margin: 0 0 16px; font-size: 13px; color: var(--text, #444); }
        .fh-todo h3 { margin: 0 0 6px; font-size: 14px; }
        .fh-todo ol { margin: 0; padding-left: 20px; }
        .fh-todo li { margin: 3px 0; }
        table.fh { width: 100%; border-collapse: collapse; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; }
        table.fh th, table.fh td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); font-size: 13px; color: var(--text, #444); vertical-align: top; }
        table.fh th { background: var(--surface-3, #f9fafb); font-weight: 600; font-size: 12px; }
        table.fh tr:last-child td { border-bottom: none; }
        table.fh ul { margin: 4px 0 0; padding-left: 18px; }
        .fh-dim { color: var(--text-dim, #888); font-size: 12px; }
        .fh-due { color: var(--warning-text, #92400e); font-weight: 600; }
        .fh-empty { background: var(--success-bg, #ecfdf5); border: 1px solid var(--success-border, #b7e1c4); color: var(--success-text, #065f46); border-radius: 10px; padding: 20px; font-size: 14px; }
        .fh-error { color: var(--danger-text, #c0392b); font-size: 13px; }
        .fh-note { font-size: 12px; color: var(--text-faint, #999); margin-top: 10px; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="files-held">
    <?php include '../includes/header.php'; ?>

    <div class="fh-wrap sys-page">
        <a class="fh-back" href="../maintenance/">&larr; <?php echo htmlspecialchars(t('system.maintenance.back')); ?></a>
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo $e('heading'); ?></h1>
            <p class="sys-subtitle"><?php echo $e('intro'); ?></p>
        </div></div>

        <?php if ($failed): ?>
            <p class="fh-error"><?php echo $e('load_failed'); ?></p>
        <?php elseif (!$rows): ?>
            <div class="fh-empty"><?php echo $e('none'); ?></div>
        <?php else: ?>
            <div class="fh-todo">
                <h3><?php echo $e('todo_heading'); ?></h3>
                <ol>
                    <li><?php echo $e('todo_restore'); ?></li>
                    <li><?php echo $e('todo_grant'); ?></li>
                    <li><?php echo $e('todo_purge'); ?></li>
                </ol>
            </div>
            <table class="fh">
                <thead><tr>
                    <th><?php echo $e('col_folder'); ?></th>
                    <th><?php echo $e('col_deleted'); ?></th>
                    <th><?php echo $e('col_status'); ?></th>
                    <th><?php echo $e('col_why'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['path']); ?></td>
                        <td>
                            <?php echo htmlspecialchars(fmt_datetime($r['deleted_at'])); ?>
                            <div class="fh-dim"><?php echo $r['deleted_by_name'] !== null ? htmlspecialchars($r['deleted_by_name']) : $e('by_unknown'); ?></div>
                        </td>
                        <td><?php echo $r['due'] ? '<span class="fh-due">' . $e('status_due') . '</span>' : $e(!$days ? 'status_off' : 'status_not_due'); ?></td>
                        <td>
                            <?php if ($r['deleter_lost_access']): ?>
                                <?php echo $e('why_lost_access'); ?>
                            <?php elseif ($r['deleted_by_name'] === null): ?>
                                <?php echo $e('why_unknown'); ?>
                            <?php else: ?>
                                <?php echo $e('why_blocking', ['n' => $r['blocking_count']]); ?>
                            <?php endif; ?>
                            <?php if ($r['blocking']): ?>
                                <ul>
                                <?php foreach ($r['blocking'] as $p): ?><li><?php echo htmlspecialchars($p); ?></li><?php endforeach; ?>
                                <?php if ($r['blocking_count'] > count($r['blocking'])): ?><li class="fh-dim"><?php echo $e('and_more', ['n' => $r['blocking_count'] - count($r['blocking'])]); ?></li><?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="fh-note"><?php echo $e('count', ['n' => count($rows)]); ?></p>
        <?php endif; ?>
    </div>
    <script src="../../assets/js/mobile.js?v=87"></script>
</body>
</html>
