<?php
/**
 * System - Maintenance - Assets whose Intune user differs (GH #335).
 *
 * Assets that have a holder here while a linked Intune device names somebody
 * else as its primary user - a laptop handed on in Intune but not here, or a
 * holder corrected here that Intune still disagrees with. Read only: each row
 * opens the asset, where its Intune tab shows the device's whole history and a
 * person decides. "Follow changes of primary user" (Assets -> Settings ->
 * Intune) settles the ones Intune itself assigned.
 *
 * The list and its rule are intuneAssetsWithDifferentUser()
 * (includes/intune_user_history.php); the card's count is the same rule.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'intune-user-mismatch';
require_once __DIR__ . '/../includes/page_gate.php';
require_once '../../includes/intune_user_history.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

$LIMIT = 500;
$rows = [];
$total = 0;
$failed = false;
try {
    $conn = connectToDatabase();
    $total = intuneCountAssetsWithDifferentUser($conn);
    $rows = intuneAssetsWithDifferentUser($conn, $LIMIT);
} catch (Throwable $e) {
    error_log('intune-user-mismatch: ' . $e->getMessage());
    $failed = true;
}
$e = function (string $k, array $p = []): string { return htmlspecialchars(t('system.intune_mismatch.' . $k, $p)); };
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.intune_mismatch.heading'))); ?></title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css?v=79">
    <style>
        body {
            /* System's dark accent is a LIGHT colour, so --on-accent is pinned too
               (see system/debug-tools/index.php). */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .ium-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f7fa); padding: 24px 28px 60px; box-sizing: border-box; }
        .ium-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; margin: 0 0 12px; }
        .ium-back:hover { text-decoration: underline; }
        .ium-wrap h2 { font-size: 22px; color: var(--text, #333); margin: 0 0 6px; }
        .ium-sub { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 18px; max-width: 820px; line-height: 1.5; }
        table.ium { width: 100%; border-collapse: collapse; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; overflow: hidden; }
        table.ium th, table.ium td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); font-size: 13px; color: var(--text, #444); vertical-align: top; }
        table.ium th { background: var(--surface-3, #f9fafb); font-weight: 600; font-size: 12px; }
        table.ium tr:last-child td { border-bottom: none; }
        table.ium a { color: var(--sys-accent, #546e7a); }
        .ium-dim { color: var(--text-dim, #888); font-size: 12px; }
        .ium-empty { background: var(--success-bg, #ecfdf5); border: 1px solid #6ee7b7; color: var(--success-text, #065f46); border-radius: 10px; padding: 20px; font-size: 14px; }
        .ium-error { color: var(--danger-text, #c0392b); font-size: 13px; }
        .ium-note { font-size: 12px; color: var(--text-faint, #999); margin-top: 10px; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="intune-user-mismatch">
    <?php include '../includes/header.php'; ?>

    <div class="ium-wrap">
        <a class="ium-back" href="../maintenance/">&larr; <?php echo htmlspecialchars(t('system.maintenance.back')); ?></a>
        <h2><?php echo $e('heading'); ?></h2>
        <p class="ium-sub"><?php echo $e('intro'); ?></p>

        <?php if ($failed): ?>
            <p class="ium-error"><?php echo $e('load_failed'); ?></p>
        <?php elseif (!$rows): ?>
            <div class="ium-empty"><?php echo $e('none'); ?></div>
        <?php else: ?>
            <table class="ium">
                <thead><tr>
                    <th><?php echo $e('col_asset'); ?></th>
                    <th><?php echo $e('col_holder'); ?></th>
                    <th><?php echo $e('col_intune'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a href="<?php echo $path_prefix; ?>asset-management/?asset_id=<?php echo (int)$r['asset_id']; ?>"><?php echo htmlspecialchars($r['hostname'] ?: ('#' . $r['asset_id'])); ?></a></td>
                        <td><?php echo htmlspecialchars((string)$r['holders']); ?></td>
                        <td>
                            <?php if ($r['person_id']): ?>
                                <?php echo htmlspecialchars((string)$r['person_name']); ?>
                            <?php else: ?>
                                <?php echo htmlspecialchars((string)($r['intune_name'] ?: $r['upn'])); ?>
                                <div class="ium-dim"><?php echo $e('not_imported'); ?></div>
                            <?php endif; ?>
                            <?php if ($r['upn']): ?><div class="ium-dim"><?php echo htmlspecialchars($r['upn']); ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="ium-note"><?php echo $total > count($rows) ? $e('more', ['n' => count($rows), 'total' => $total]) : $e('count', ['n' => $total]); ?></p>
        <?php endif; ?>
    </div>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
