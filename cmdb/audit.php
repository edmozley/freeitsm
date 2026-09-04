<?php
/**
 * CMDB Data quality — advisory audit of whether the CMDB can still answer the
 * question the module exists to answer.
 *
 * Read-only: it reports, it never edits. The checks and their reasoning live in
 * includes/cmdb_audit.php.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('cmdb');

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'audit';
$path_prefix = '../';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - <?php echo htmlspecialchars(t('cmdb.audit.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { background: var(--app-bg,#f5f5f5); --accent: var(--cmdb-accent); }
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; margin: 24px 0; padding: 0 20px; }

        .audit-head { margin-bottom: 18px; }
        .audit-head h2 { font-size: 18px; color: var(--text,#111827); margin: 0 0 4px; }
        .audit-head p { margin: 0; color: var(--text-muted,#6b7280); font-size: 13px; }

        .audit-summary {
            display: flex; gap: 10px; flex-wrap: wrap; margin: 14px 0 20px;
        }
        .audit-stat {
            background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb);
            border-radius: 8px; padding: 12px 18px; min-width: 130px;
        }
        .audit-stat .n { font-size: 22px; font-weight: 700; color: var(--text,#111827); display: block; }
        .audit-stat .l { font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
                         color: var(--text-muted,#6b7280); }
        .audit-stat.is-clean .n { color: #15803d; }
        .audit-stat.has-findings .n { color: var(--cmdb-accent,#be185d); }

        .audit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px; }
        .audit-card {
            background: var(--surface,#fff); border: 1px solid var(--border,#e5e7eb);
            border-radius: 8px; padding: 14px 16px;
        }
        .audit-card.sev-error   { border-left: 3px solid #dc2626; }
        .audit-card.sev-warning { border-left: 3px solid #d97706; }
        .audit-card.sev-info    { border-left: 3px solid #6b7280; }
        .audit-card.is-clean    { border-left: 3px solid #15803d; }
        .audit-card h3 {
            font-size: 14px; margin: 0 0 4px; color: var(--text,#111827);
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
        }
        .audit-count {
            font-size: 12px; font-weight: 700; padding: 1px 9px; border-radius: 999px;
            background: var(--surface-2,#f3f4f6); color: var(--text-muted,#6b7280);
        }
        .audit-card.sev-error   .audit-count { background: #fee2e2; color: #991b1b; }
        .audit-card.sev-warning .audit-count { background: #fef3c7; color: #92400e; }
        .audit-card.is-clean    .audit-count { background: #dcfce7; color: #166534; }
        .audit-why { font-size: 12px; color: var(--text-muted,#6b7280); margin: 0 0 10px; line-height: 1.5; }
        .audit-items { list-style: none; padding: 0; margin: 0; max-height: 240px; overflow-y: auto; }
        .audit-items li { padding: 5px 0; font-size: 13px; border-bottom: 1px solid var(--border-soft,#f3f4f6); }
        .audit-items li:last-child { border-bottom: none; }
        .audit-items a { color: var(--cmdb-accent,#be185d); text-decoration: none; font-weight: 500; }
        .audit-items a:hover { text-decoration: underline; }
        .audit-items .meta { color: var(--text-muted,#6b7280); font-size: 11px; }
        .audit-clean-msg { font-size: 13px; color: #15803d; }
        .audit-capped {
            margin-top: 8px; font-size: 11px; color: var(--text-muted,#6b7280);
            border-top: 1px dashed var(--border,#e5e7eb); padding-top: 6px;
        }
        .audit-fix { margin-top: 10px; font-size: 12px; }
        .audit-fix a { color: var(--cmdb-accent,#be185d); font-weight: 600; text-decoration: none; }
        .audit-loading { color: var(--text-muted,#6b7280); font-size: 13px; }
    </style>
    <!-- Mobile layer: after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=130">
</head>
<body data-mobile-module="cmdb" data-mobile-page="cmdb-audit">
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="audit-head">
            <h2><?php echo htmlspecialchars(t('cmdb.audit.heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('cmdb.audit.intro')); ?></p>
        </div>
        <div id="auditBody" class="audit-loading"><?php echo htmlspecialchars(t('cmdb.audit.loading')); ?></div>
    </div>

    <script src="../assets/js/theme.js?v=3"></script>
    <script src="audit.js?v=1"></script>
    <script src="../assets/js/mobile.js?v=53"></script>
</body>
</html>
