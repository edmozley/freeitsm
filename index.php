<?php
/**
 * Index - ITSM Module Selection
 * Landing page showing available modules when logged in
 */
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'includes/waffle-menu.php';

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$allowed_modules = $_SESSION['allowed_modules'] ?? null;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('common.home.browser_title')); ?></title>
    <link rel="stylesheet" href="assets/css/theme.css?v=11">
    <link rel="stylesheet" href="assets/css/inbox.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            height: auto;
            overflow: auto;
            display: flex;
            flex-direction: column;
        }

        /* Dark palettes: swap the light-grey wash for the dark app surfaces
           (light mode keeps its original gradient, pixel-identical). */
        [data-theme-mode="dark"] body {
            background: linear-gradient(135deg, var(--app-bg, #14171c) 0%, var(--surface-2, #232830) 100%);
        }

        .landing-header {
            background: linear-gradient(135deg, #0078d4, #106ebe);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        /* Same translucent-black wash the module headers get in dark, so the
           blue landing header tones down while keeping a hint of brand blue. */
        [data-theme="dark"] .landing-header {
            box-shadow: inset 0 0 0 2000px rgba(0, 0, 0, 0.55), 0 2px 4px rgba(0, 0, 0, 0.4);
        }

        .landing-header h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }

        .company-logo {
            width: 300px;
            height: auto;
            margin-bottom: 30px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .landing-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .welcome-text {
            text-align: center;
            margin-bottom: 50px;
        }

        .welcome-text h2 {
            font-size: 32px;
            font-weight: 300;
            color: var(--text, #333);
            margin: 0 0 10px 0;
        }

        .welcome-text p {
            font-size: 16px;
            color: var(--text-muted, #666);
            margin: 0;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 14px;
            max-width: 1180px;
            width: 100%;
            justify-content: center;
        }

        .module-card {
            background: var(--surface, white);
            border-radius: 14px;
            padding: 16px 8px;
            text-align: center;
            text-decoration: none;
            color: var(--text, #333);
            box-shadow: 0 4px 20px var(--shadow, rgba(0, 0, 0, 0.08));
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .module-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 40px var(--shadow, rgba(0, 0, 0, 0.15));
        }

        <?php foreach (getModuleColors() as $key => $c): ?>
        .module-card.<?php echo $key; ?>:hover { border-color: <?php echo $c[0]; ?>; }
        <?php endforeach; ?>

        .module-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
        }

        .module-icon svg {
            width: 24px;
            height: 24px;
            color: white;
        }

        <?php foreach (getModuleColors() as $key => $c): ?>
        .module-icon.<?php echo $key; ?> { background: linear-gradient(135deg, <?php echo $c[0]; ?>, <?php echo $c[1]; ?>); }
        <?php endforeach; ?>

        .module-name {
            font-size: 14px;
            font-weight: 600;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-faint, #999);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="landing-header">
        <h1><?php echo htmlspecialchars(t('common.home.header_title')); ?></h1>
        <?php renderHeaderRight($analyst_name, ''); ?>
    </div>
    <script>function closeWaffleMenu() {}</script>

    <div class="landing-container">
        <img src="assets/images/CompanyLogo.png" alt="Company Logo" class="company-logo">
        <div class="welcome-text">
            <h2><?php echo htmlspecialchars(t('common.home.welcome_heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('common.home.welcome_subtitle')); ?></p>
        </div>

        <div class="modules-grid">
            <?php if ($allowed_modules === null || in_array('watchtower', $allowed_modules)): ?>
            <a href="watchtower/" class="module-card watchtower" title="<?php echo htmlspecialchars(t('common.modules.watchtower.description')); ?>">
                <div class="module-icon watchtower">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.watchtower.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('tickets', $allowed_modules)): ?>
            <a href="tickets/" class="module-card tickets" title="<?php echo htmlspecialchars(t('common.modules.tickets.description')); ?>">
                <div class="module-icon tickets">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline>
                        <path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.tickets.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('assets', $allowed_modules)): ?>
            <a href="asset-management/" class="module-card assets" title="<?php echo htmlspecialchars(t('common.modules.assets.description')); ?>">
                <div class="module-icon assets">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.assets.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('knowledge', $allowed_modules)): ?>
            <a href="knowledge/" class="module-card knowledge" title="<?php echo htmlspecialchars(t('common.modules.knowledge.description')); ?>">
                <div class="module-icon knowledge">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.knowledge.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('changes', $allowed_modules)): ?>
            <a href="change-management/" class="module-card changes" title="<?php echo htmlspecialchars(t('common.modules.changes.description')); ?>">
                <div class="module-icon changes">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="16 3 21 3 21 8"></polyline>
                        <line x1="4" y1="20" x2="21" y2="3"></line>
                        <polyline points="21 16 21 21 16 21"></polyline>
                        <line x1="15" y1="15" x2="21" y2="21"></line>
                        <line x1="4" y1="4" x2="9" y2="9"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.changes.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('problems', $allowed_modules)): ?>
            <a href="problem-management/" class="module-card problems" title="<?php echo htmlspecialchars(t('common.modules.problems.description')); ?>">
                <div class="module-icon problems">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.problems.name_short')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('calendar', $allowed_modules)): ?>
            <a href="calendar/" class="module-card calendar" title="<?php echo htmlspecialchars(t('common.modules.calendar.description')); ?>">
                <div class="module-icon calendar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.calendar.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('morning-checks', $allowed_modules)): ?>
            <a href="morning-checks/" class="module-card morning-checks" title="<?php echo htmlspecialchars(t('common.modules.morning-checks.description')); ?>">
                <div class="module-icon morning-checks">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.morning-checks.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('reporting', $allowed_modules)): ?>
            <a href="reporting/" class="module-card reporting" title="<?php echo htmlspecialchars(t('common.modules.reporting.description')); ?>">
                <div class="module-icon reporting">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.reporting.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('software', $allowed_modules)): ?>
            <a href="software/" class="module-card software" title="<?php echo htmlspecialchars(t('common.modules.software.description')); ?>">
                <div class="module-icon software">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
                        <rect x="9" y="9" width="6" height="6"></rect>
                        <line x1="9" y1="1" x2="9" y2="4"></line>
                        <line x1="15" y1="1" x2="15" y2="4"></line>
                        <line x1="9" y1="20" x2="9" y2="23"></line>
                        <line x1="15" y1="20" x2="15" y2="23"></line>
                        <line x1="20" y1="9" x2="23" y2="9"></line>
                        <line x1="20" y1="14" x2="23" y2="14"></line>
                        <line x1="1" y1="9" x2="4" y2="9"></line>
                        <line x1="1" y1="14" x2="4" y2="14"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.software.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('forms', $allowed_modules)): ?>
            <a href="forms/" class="module-card forms" title="<?php echo htmlspecialchars(t('common.modules.forms.description')); ?>">
                <div class="module-icon forms">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.forms.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('contracts', $allowed_modules)): ?>
            <a href="contracts/" class="module-card contracts" title="<?php echo htmlspecialchars(t('common.modules.contracts.description')); ?>">
                <div class="module-icon contracts">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <line x1="12" y1="9" x2="8" y2="9"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.contracts.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('service-status', $allowed_modules)): ?>
            <a href="service-status/" class="module-card service-status" title="<?php echo htmlspecialchars(t('common.modules.service-status.description')); ?>">
                <div class="module-icon service-status">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.service-status.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('wiki', $allowed_modules)): ?>
            <a href="system-wiki/" class="module-card wiki" title="<?php echo htmlspecialchars(t('common.modules.wiki.description')); ?>">
                <div class="module-icon wiki">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.wiki.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('lms', $allowed_modules)): ?>
            <a href="lms/" class="module-card lms" title="<?php echo htmlspecialchars(t('common.modules.lms.description')); ?>">
                <div class="module-icon lms">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                        <path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5"></path>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.lms.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('process-mapper', $allowed_modules)): ?>
            <a href="process-mapper/" class="module-card process-mapper" title="<?php echo htmlspecialchars(t('common.modules.process-mapper.description')); ?>">
                <div class="module-icon process-mapper">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.process-mapper.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('tasks', $allowed_modules)): ?>
            <a href="tasks/" class="module-card tasks" title="<?php echo htmlspecialchars(t('common.modules.tasks.description')); ?>">
                <div class="module-icon tasks">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.tasks.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('cmdb', $allowed_modules)): ?>
            <a href="cmdb/" class="module-card cmdb" title="<?php echo htmlspecialchars(t('common.modules.cmdb.description')); ?>">
                <div class="module-icon cmdb">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 22V8l10-6 10 6v14"></path>
                        <path d="M2 12h20"></path>
                        <path d="M2 17h20"></path>
                        <line x1="12" y1="2" x2="12" y2="22"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.cmdb.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('network-mapper', $allowed_modules)): ?>
            <a href="network-mapper/" class="module-card network-mapper" title="<?php echo htmlspecialchars(t('common.modules.network-mapper.description')); ?>">
                <div class="module-icon network-mapper">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="6" cy="6" r="2.5"></circle>
                        <circle cx="18" cy="6" r="2.5"></circle>
                        <circle cx="12" cy="18" r="2.5"></circle>
                        <line x1="7.5" y1="7.5" x2="11" y2="16"></line>
                        <line x1="16.5" y1="7.5" x2="13" y2="16"></line>
                        <line x1="8.5" y1="6" x2="15.5" y2="6"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.network-mapper.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('workflow', $allowed_modules)): ?>
            <a href="workflow/" class="module-card workflow" title="<?php echo htmlspecialchars(t('common.modules.workflow.description')); ?>">
                <div class="module-icon workflow">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        <circle cx="6" cy="12" r="2"></circle>
                        <circle cx="18" cy="12" r="2"></circle>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.workflow.name')); ?></div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('system', $allowed_modules)): ?>
            <a href="system/" class="module-card system" title="<?php echo htmlspecialchars(t('common.modules.system.description')); ?>">
                <div class="module-icon system">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" y1="21" x2="4" y2="14"></line>
                        <line x1="4" y1="10" x2="4" y2="3"></line>
                        <line x1="12" y1="21" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12" y2="3"></line>
                        <line x1="20" y1="21" x2="20" y2="16"></line>
                        <line x1="20" y1="12" x2="20" y2="3"></line>
                        <line x1="1" y1="14" x2="7" y2="14"></line>
                        <line x1="9" y1="8" x2="15" y2="8"></line>
                        <line x1="17" y1="16" x2="23" y2="16"></line>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars(t('common.modules.system.name')); ?></div>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <?php echo htmlspecialchars(t('common.home.footer')); ?>
    </div>

    <!-- The landing page draws the header-right user menu (renderHeaderRight) but
         not the waffle-menu JS, so it must load the shared toast/confirm helpers
         itself — the logout button relies on showConfirm. (Both self-guard against
         double-loading.) -->
    <script src="assets/js/toast.js"></script>
    <script src="assets/js/confirm.js"></script>
</body>
</html>
