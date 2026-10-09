<?php
/**
 * System → Contributors — thanks to the people who have made a substantial
 * contribution to FreeITSM.
 *
 * Read-only by design. The list lives in includes/contributors.php, a code
 * file, because it is part of what FreeITSM is rather than something each
 * install curates — see the note at the top of that file.
 *
 * Card layout follows LMS → My courses (lms/my-courses.php), which is the
 * product's existing "a stack of readable cards" shape.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/contributors.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

$current_page = 'contributors';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

$contributors = getContributors();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars(t('system.contributors.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=188">
    <style>
        /* System's accent. --on-accent is pinned too: System is the one module
           whose dark accent is a LIGHT colour, and the global --on-accent stays
           white, which would be white-on-light. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        html, body { height: auto !important; min-height: 100vh; overflow-y: auto !important; margin: 0; padding: 0; background: var(--app-bg, #f8fafc); }

        .con-wrap { padding: 24px 32px 48px; }
        .con-head { margin-bottom: 6px; }
        .con-head h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .con-lead { margin: 6px 0 26px; font-size: 13px; color: var(--text-muted, #6b7280); max-width: 760px; line-height: 1.6; }

        /* Two columns at most: the blurb is a real paragraph and a narrow card
           turns it into a column of ragged fragments. Same reasoning as
           LMS → My courses. */
        .con-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 16px;
            align-items: stretch;
        }
        .con-card {
            display: flex; flex-direction: column; gap: 12px;
            padding: 22px 24px;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 10px;
        }

        /* The name, starred either side. The stars are decoration, so they are
           aria-hidden — a screen reader announcing "star Sandy star" helps
           nobody. */
        .con-name {
            display: flex; align-items: center; justify-content: center;
            gap: 10px; text-align: center;
            margin: 0; font-size: 17px; font-weight: 700;
            color: var(--text, #1f2330);
        }
        .con-star { color: #f59e0b; font-size: 15px; line-height: 1; flex-shrink: 0; }

        .con-github { text-align: center; }
        .con-github a {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 12.5px; font-weight: 500;
            color: var(--accent, #546e7a); text-decoration: none;
            padding: 3px 10px; border-radius: 999px;
            background: var(--surface-hover, #f1f5f9);
        }
        .con-github a:hover { text-decoration: underline; }
        .con-github svg { width: 13px; height: 13px; }

        .con-what {
            margin: 0; flex: 1;
            font-size: 13px; line-height: 1.6;
            color: var(--text-muted, #6b7280);
        }
        .con-date {
            margin: 0; padding-top: 10px;
            border-top: 1px solid var(--border, #e5e7eb);
            font-size: 11.5px; color: var(--text-dim, #9ca3af);
            text-align: center;
        }
        .con-empty { padding: 30px; text-align: center; color: var(--text-muted, #6b7280); font-size: 13px; }

        @media (max-width: 700px) {
            .con-wrap { padding: 18px 16px 40px; }
            .con-list { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include '../includes/header.php'; ?>

    <div class="con-wrap">
        <div class="con-head">
            <h2><?php echo htmlspecialchars(t('system.contributors.heading')); ?></h2>
        </div>
        <p class="con-lead"><?php echo htmlspecialchars(t('system.contributors.lead')); ?></p>

        <?php if (empty($contributors)): ?>
            <div class="con-empty"><?php echo htmlspecialchars(t('system.contributors.none')); ?></div>
        <?php else: ?>
            <div class="con-list">
                <?php foreach ($contributors as $c): ?>
                    <?php
                        // Fail soft on a malformed date rather than printing "01 Jan 1970".
                        $ts = strtotime($c['date'] ?? '');
                        $when = $ts ? date('F Y', $ts) : '';
                    ?>
                    <div class="con-card">
                        <h3 class="con-name">
                            <span class="con-star" aria-hidden="true">★</span>
                            <span><?php echo htmlspecialchars($c['name']); ?></span>
                            <span class="con-star" aria-hidden="true">★</span>
                        </h3>

                        <?php if (!empty($c['github'])): ?>
                            <div class="con-github">
                                <a href="https://github.com/<?php echo rawurlencode($c['github']); ?>"
                                   target="_blank" rel="noopener noreferrer">
                                    <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.4 7.4 0 0 1 2-.27c.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"></path></svg>
                                    @<?php echo htmlspecialchars($c['github']); ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <p class="con-what"><?php echo htmlspecialchars($c['what']); ?></p>

                        <?php if ($when !== ''): ?>
                            <p class="con-date"><?php echo htmlspecialchars($when); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
