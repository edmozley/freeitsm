<?php
/**
 * Reporting - Landing page with links to reporting areas
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
I18n::initFromSession();

$current_page = 'reporting';
$path_prefix = '../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <style>
        /* Module accent (rust-orange) — drives card hover/icon, focus rings,
           shared .btn primaries. */
        body { --accent: var(--rep-accent, #ca5010); --accent-hover: var(--rep-accent-hover, #a5410a); }

        .reporting-landing {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--app-bg, #f5f7fa);
        }

        .landing-content {
            text-align: center;
            max-width: 700px;
        }

        .landing-content h2 {
            font-size: 24px;
            color: var(--text, #333);
            margin: 0 0 8px 0;
        }

        .landing-content .subtitle {
            font-size: 14px;
            color: var(--text-dim, #888);
            margin: 0 0 40px 0;
        }

        .report-cards {
            display: flex;
            gap: 24px;
            justify-content: center;
        }

        .report-card {
            background: var(--surface, #fff);
            border-radius: 12px;
            padding: 40px 36px;
            box-shadow: 0 2px 12px var(--shadow, rgba(0,0,0,0.08));
            text-decoration: none;
            color: inherit;
            width: 280px;
            transition: transform 0.15s, box-shadow 0.15s;
            border: 2px solid transparent;
        }

        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px var(--shadow, rgba(0,0,0,0.14));
            border-color: var(--rep-accent, #ca5010);
        }

        .report-card svg {
            color: var(--rep-accent, #ca5010);
            margin-bottom: 16px;
        }

        .report-card h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .report-card p {
            margin: 0;
            font-size: 13px;
            color: var(--text-dim, #888);
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container reporting-landing">
        <div class="landing-content">
            <h2><?php echo htmlspecialchars(t('reporting.landing.heading')); ?></h2>
            <p class="subtitle"><?php echo htmlspecialchars(t('reporting.landing.subtitle')); ?></p>

            <div class="report-cards">
                <a href="logs/" class="report-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('reporting.landing.logs_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('reporting.landing.logs_desc')); ?></p>
                </a>

                <a href="tickets/" class="report-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('reporting.landing.tickets_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('reporting.landing.tickets_desc')); ?></p>
                </a>

                <a href="intune/" class="report-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('reporting.landing.intune_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('reporting.landing.intune_desc')); ?></p>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
