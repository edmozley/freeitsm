<?php
/**
 * Ticket Dashboards - KPI reporting for tickets (coming soon)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

$current_page = 'tickets';
$path_prefix = '../../';
$translationNamespaces = ['common', 'reporting'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('reporting.tickets.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        .coming-soon-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--app-bg, #f5f7fa);
        }

        .coming-soon-card {
            text-align: center;
            background: var(--surface, #fff);
            border-radius: 12px;
            padding: 60px 80px;
            box-shadow: 0 2px 12px var(--shadow, rgba(0,0,0,0.08));
        }

        .coming-soon-card svg {
            color: var(--rep-accent, #ca5010);
            margin-bottom: 20px;
        }

        .coming-soon-card h2 {
            margin: 0 0 10px 0;
            font-size: 22px;
            color: var(--text, #333);
        }

        .coming-soon-card p {
            margin: 0;
            font-size: 14px;
            color: var(--text-dim, #888);
            max-width: 360px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container coming-soon-container">
        <div class="coming-soon-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            <h2><?php echo htmlspecialchars(t('reporting.tickets.heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('reporting.tickets.coming_soon')); ?></p>
        </div>
    </div>
</body>
</html>
