<?php
/**
 * System - Landing page with links to system areas
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

$current_page = 'system';
$path_prefix = '../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .system-landing {
            flex: 1;
            display: flex;
            justify-content: center;
            background: #f5f7fa;
            overflow-y: auto;
        }

        .landing-content {
            text-align: center;
            max-width: 700px;
            margin: auto 0;
            padding: 30px 20px;
        }

        .landing-content h2 {
            font-size: 24px;
            color: #333;
            margin: 0 0 8px 0;
        }

        .landing-content .subtitle {
            font-size: 14px;
            color: #888;
            margin: 0 0 40px 0;
        }

        .system-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            max-width: 700px;
        }

        .system-card {
            background: #fff;
            border-radius: 12px;
            padding: 32px 30px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s, box-shadow 0.15s;
            border: 2px solid transparent;
        }

        .system-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: #546e7a;
        }

        .system-card svg {
            color: #546e7a;
            margin-bottom: 16px;
        }

        .system-card h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: #333;
        }

        .system-card p {
            margin: 0;
            font-size: 13px;
            color: #888;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container system-landing">
        <div class="landing-content">
            <h2><?php echo htmlspecialchars(t('system.landing.heading')); ?></h2>
            <p class="subtitle"><?php echo htmlspecialchars(t('system.landing.subtitle')); ?></p>

            <div class="system-cards">
                <a href="encryption/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.encryption_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.encryption_desc')); ?></p>
                </a>

                <a href="modules/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.modules_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.modules_desc')); ?></p>
                </a>

                <a href="db-verify/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                        <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.db_verify_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.db_verify_desc')); ?></p>
                </a>

                <a href="colours/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="13.5" cy="6.5" r="2.5"></circle>
                        <circle cx="17.5" cy="10.5" r="2.5"></circle>
                        <circle cx="8.5" cy="7.5" r="2.5"></circle>
                        <circle cx="6.5" cy="12.5" r="2.5"></circle>
                        <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.colours_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.colours_desc')); ?></p>
                </a>

                <a href="branding/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 19l7-7 3 3-7 7-3-3z"></path>
                        <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path>
                        <path d="M2 2l7.586 7.586"></path>
                        <circle cx="11" cy="11" r="2"></circle>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.branding_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.branding_desc')); ?></p>
                </a>

                <a href="security/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.security_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.security_desc')); ?></p>
                </a>

                <a href="sso/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.sso_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.sso_desc')); ?></p>
                </a>

                <a href="preferences/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.preferences_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.preferences_desc')); ?></p>
                </a>

                <a href="demo-data/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.demo_data_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.demo_data_desc')); ?></p>
                </a>

                <a href="debug-tools/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 2v4"></path>
                        <path d="M16 2v4"></path>
                        <rect x="3" y="6" width="18" height="15" rx="2"></rect>
                        <path d="M3 13h18"></path>
                        <path d="M9 17l2 2 4-4"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.debug_tools_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.debug_tools_desc')); ?></p>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
