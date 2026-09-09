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
$canManage = analystHasCapability($conn, (int)$_SESSION['analyst_id'], Cap::LMS_MANAGE);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('lms.my.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/lms.css?v=7">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--lms-accent, #2563eb); }
        .myc-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f6fa); }
        /* Full width. This page used to sit in a 940px column, which left a
           narrow strip of content in the middle of a wide screen and made a
           handful of courses look like an empty page. */
        .myc-inner { margin: 0 auto; padding: 32px 28px 56px; }
        .myc-head { display: flex; align-items: flex-start; gap: 16px; }
        .myc-head-text { flex: 1; min-width: 0; }
        .myc-inner h1 { margin: 0 0 4px; font-size: 22px; color: var(--text, #1f2330); }
        .myc-inner p.myc-lead { margin: 0 0 24px; color: var(--text-muted, #6b7280); font-size: 14px; }

        /* The layout switch. Same shape and the same two glyphs as the Knowledge
           module's, so somebody who has met one already knows this one. */
        .myc-layout-toggle { display: flex; gap: 2px; flex-shrink: 0; }
        .myc-layout-btn {
            background: none; border: 1px solid transparent; border-radius: 4px;
            padding: 4px 8px; font-size: 14px; line-height: 1;
            color: var(--text-muted, #6b7280); cursor: pointer;
        }
        .myc-layout-btn:hover { background: var(--surface-hover, #f0f0f0); }
        .myc-layout-btn.active {
            background: var(--accent-soft, #eef2ff);
            border-color: var(--accent, #2563eb);
            color: var(--text, #1f2330);
        }

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

        /* ---- Progress ----
           The bar is never shown on its own: the words beside it say "Lesson 2
           of 3", because that is the only thing the product actually records.
           A bare bar would read as a measured percentage of a course. */
        .myc-progress { margin-top: 10px; }
        .myc-progress-track {
            height: 5px; border-radius: 999px;
            background: var(--border, #e5e7eb); overflow: hidden;
        }
        .myc-progress-fill {
            height: 100%; border-radius: 999px;
            background: var(--lms-accent, #2563eb);
            transition: width .2s ease;
        }
        .myc-progress-fill.is-done { background: #16a34a; }
        .myc-progress-label {
            display: block; margin-top: 5px;
            font-size: 11.5px; color: var(--text-muted, #6b7280);
        }

        /* ---- Card layout ----
           Two columns at most on a normal screen rather than three or four: the
           description is a real sentence and a narrow card turns it into five
           ragged lines. Wide cards keep the status, score and deadline on one
           row, which is what makes them scannable. */
        .myc-list.myc-layout-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 14px;
            align-items: stretch;
        }
        .myc-layout-cards .myc-card {
            flex-direction: column; align-items: stretch; gap: 14px;
            padding: 20px;
        }
        .myc-layout-cards .myc-card-main {
            flex: 1;
            /* A column, so the block below the text can be pushed to the foot
               of it. Without this the `margin-top: auto` below does nothing. */
            display: flex; flex-direction: column;
        }
        .myc-layout-cards .myc-card-main h3 { font-size: 16px; }

        /* 🔑 THE STATUS ROW AND THE BAR ARE PINNED TO THE FOOT OF THE CARD, not
           hung under the description.
           Course descriptions are real sentences of different lengths, so at any
           given column width some wrap to two lines and some to three. Left to
           follow the text, the pills, the bar and the "Lesson 2 of 3" label all
           sat at a different height in every card, and a row of bars that nearly
           lines up reads worse than one that obviously does not.
           The grid already stretches every card in a row to the same height, so
           anchoring to the bottom makes them align exactly.
           ⭐ Chosen over clamping the description to a fixed number of lines,
           which would have aligned them too but at the cost of truncating a
           sentence mid-word. A little air under a short description is cheaper
           than an ellipsis. */
        .myc-layout-cards .myc-meta { margin-top: auto; padding-top: 12px; }
        /* The bar follows the status row, so it inherits that alignment; this
           only restores the gap the auto margin above would otherwise collapse. */
        .myc-layout-cards .myc-progress { margin-top: 10px; }

        /* The button sits on its own line at the bottom of every card, so the
           row of buttons lines up across the grid however tall the text is. */
        .myc-layout-cards .myc-actions { align-self: flex-start; }

        .myc-empty { text-align: center; padding: 60px 20px; color: var(--text-muted, #6b7280); }
        .myc-empty h3 { margin: 0 0 6px; font-size: 16px; color: var(--text, #1f2330); }
        .myc-empty p { margin: 0; font-size: 14px; }

        /* Reuse the .lms-status pills from lms.css for status colour. */
        .myc-score { font-size: 12px; color: var(--text-muted, #6b7280); }

        @media (max-width: 640px) {
            .myc-card { flex-direction: column; align-items: stretch; gap: 12px; }
            .myc-actions .btn { width: 100%; }
            /* One column on a phone whichever layout is chosen — a 420px
               minimum would otherwise overflow a 360px screen. */
            .myc-list.myc-layout-cards { grid-template-columns: 1fr; }
        }
    </style>
    <!-- Mobile layer: linked AFTER this page's own CSS so its @media rules win on ties. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=138">
</head>
<body data-mobile-module="lms">
    <?php include 'includes/header.php'; ?>

    <div class="myc-wrap">
        <div class="myc-inner">
            <div class="myc-head">
                <div class="myc-head-text">
                    <h1><?php echo htmlspecialchars(t('lms.my.heading')); ?></h1>
                    <p class="myc-lead"><?php echo htmlspecialchars(t('lms.my.lead')); ?></p>
                </div>
                <!-- How this page draws things. A toggle rather than a setting:
                     it is something people flip during a session. It persists
                     per analyst all the same. Same glyphs as Knowledge. -->
                <div class="myc-layout-toggle">
                    <button type="button" class="myc-layout-btn active" data-layout="list" onclick="setMycLayout('list')" title="<?php echo htmlspecialchars(t('lms.layout.list')); ?>" aria-label="<?php echo htmlspecialchars(t('lms.layout.list')); ?>">☰</button>
                    <button type="button" class="myc-layout-btn" data-layout="cards" onclick="setMycLayout('cards')" title="<?php echo htmlspecialchars(t('lms.layout.cards')); ?>" aria-label="<?php echo htmlspecialchars(t('lms.layout.cards')); ?>">▦</button>
                </div>
            </div>
            <div id="mycList" class="myc-list">
                <div class="myc-empty"><?php echo htmlspecialchars(t('lms.my.loading')); ?></div>
            </div>
        </div>
    </div>

    <script>window.API_BASE = '../api/lms/';</script>
    <script src="../assets/js/lms-my-courses.js?v=4"></script>
    <script src="../assets/js/mobile.js?v=57"></script>
</body>
</html>
