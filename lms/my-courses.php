<?php
/**
 * LMS — My Courses (the learner's landing).
 *
 * Everyone with the LMS module gets this. It shows only the courses assigned to
 * them, with their own status and a Launch button — no management, no other
 * people's data. Managers and admins can reach it too (they take training as
 * well), but their default landing is the dashboard.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
require_once '../includes/rbac.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('lms');

$current_page = 'my-courses';
$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];

$conn = connectToDatabase();
$canManage = analystHasCapability($conn, (int)$_SESSION['analyst_id'], 'lms.manage');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('lms.my.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--lms-accent, #2563eb); }
        .myc-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f6fa); }
        .myc-inner { max-width: 940px; margin: 0 auto; padding: 32px 28px 56px; }
        .myc-inner > h1 { margin: 0 0 4px; font-size: 22px; color: var(--text, #1f2330); }
        .myc-inner > p.myc-lead { margin: 0 0 24px; color: var(--text-muted, #6b7280); font-size: 14px; }

        .myc-list { display: flex; flex-direction: column; gap: 12px; }
        .myc-card {
            display: flex; align-items: center; gap: 18px;
            padding: 18px 20px; background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb); border-radius: 10px;
        }
        .myc-card-main { flex: 1; min-width: 0; }
        .myc-card-main h3 { margin: 0 0 4px; font-size: 15.5px; color: var(--text, #1f2330); }
        .myc-card-main p { margin: 0; font-size: 13px; color: var(--text-muted, #6b7280); line-height: 1.5; }
        .myc-meta { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .myc-deadline { font-size: 12px; color: var(--text-muted, #6b7280); }
        .myc-deadline.overdue { color: #dc2626; font-weight: 600; }
        .myc-actions { flex-shrink: 0; }

        .myc-empty { text-align: center; padding: 60px 20px; color: var(--text-muted, #6b7280); }
        .myc-empty h3 { margin: 0 0 6px; font-size: 16px; color: var(--text, #1f2330); }
        .myc-empty p { margin: 0; font-size: 14px; }

        /* Reuse the .lms-status pills from lms.css for status colour. */
        .myc-score { font-size: 12px; color: var(--text-muted, #6b7280); }

        @media (max-width: 640px) {
            .myc-card { flex-direction: column; align-items: stretch; gap: 12px; }
            .myc-actions .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="myc-wrap">
        <div class="myc-inner">
            <h1><?php echo htmlspecialchars(t('lms.my.heading')); ?></h1>
            <p class="myc-lead"><?php echo htmlspecialchars(t('lms.my.lead')); ?></p>
            <div id="mycList" class="myc-list">
                <div class="myc-empty"><?php echo htmlspecialchars(t('lms.my.loading')); ?></div>
            </div>
        </div>
    </div>

    <script>window.API_BASE = '../api/lms/';</script>
    <script src="../assets/js/lms-my-courses.js?v=1"></script>
</body>
</html>
