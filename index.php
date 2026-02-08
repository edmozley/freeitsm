<?php
/**
 * Index - ITSM Module Selection
 * Landing page showing available modules when logged in
 */
session_start();
require_once 'config.php';
require_once 'includes/waffle-menu.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$allowed_modules = $_SESSION['allowed_modules'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - ITSM</title>
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

        .landing-header {
            background: linear-gradient(135deg, #0078d4, #106ebe);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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
            color: #333;
            margin: 0 0 10px 0;
        }

        .welcome-text p {
            font-size: 16px;
            color: #666;
            margin: 0;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            max-width: 750px;
            width: 100%;
            justify-content: center;
        }

        .module-card {
            background: white;
            border-radius: 16px;
            padding: 30px 16px;
            text-align: center;
            text-decoration: none;
            color: #333;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .module-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .module-card.tickets:hover { border-color: #0078d4; }
        .module-card.assets:hover { border-color: #107c10; }
        .module-card.knowledge:hover { border-color: #8764b8; }
        .module-card.changes:hover { border-color: #00897b; }
        .module-card.calendar:hover { border-color: #ef6c00; }
        .module-card.morning-checks:hover { border-color: #00acc1; }
        .module-card.reporting:hover { border-color: #ca5010; }
        .module-card.software:hover { border-color: #5c6bc0; }
        .module-card.forms:hover { border-color: #00897b; }
        .module-card.wiki:hover { border-color: #c62828; }
        .module-card.system:hover { border-color: #546e7a; }

        .module-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .module-icon svg {
            width: 30px;
            height: 30px;
            color: white;
        }

        .module-icon.tickets { background: linear-gradient(135deg, #0078d4, #106ebe); }
        .module-icon.assets { background: linear-gradient(135deg, #107c10, #0b5c0b); }
        .module-icon.knowledge { background: linear-gradient(135deg, #8764b8, #6b4fa2); }
        .module-icon.changes { background: linear-gradient(135deg, #00897b, #00695c); }
        .module-icon.calendar { background: linear-gradient(135deg, #ef6c00, #e65100); }
        .module-icon.morning-checks { background: linear-gradient(135deg, #00acc1, #00838f); }
        .module-icon.reporting { background: linear-gradient(135deg, #ca5010, #a5410a); }
        .module-icon.software { background: linear-gradient(135deg, #5c6bc0, #3f51b5); }
        .module-icon.forms { background: linear-gradient(135deg, #00897b, #00695c); }
        .module-icon.wiki { background: linear-gradient(135deg, #c62828, #b71c1c); }
        .module-icon.system { background: linear-gradient(135deg, #546e7a, #37474f); }

        .module-name {
            font-size: 14px;
            font-weight: 600;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="landing-header">
        <h1>Service Desk</h1>
        <?php renderHeaderRight($analyst_name, ''); ?>
    </div>
    <script>function closeWaffleMenu() {}</script>

    <div class="landing-container">
        <img src="assets/images/CompanyLogo.png" alt="Company Logo" class="company-logo">
        <div class="welcome-text">
            <h2>What would you like to do?</h2>
            <p>Select a module to get started</p>
        </div>

        <div class="modules-grid">
            <?php if ($allowed_modules === null || in_array('tickets', $allowed_modules)): ?>
            <a href="tickets/" class="module-card tickets" title="Manage support requests, emails, and user issues">
                <div class="module-icon tickets">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline>
                        <path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>
                    </svg>
                </div>
                <div class="module-name">Tickets</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('assets', $allowed_modules)): ?>
            <a href="asset-management/" class="module-card assets" title="Track IT assets and user assignments">
                <div class="module-icon assets">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </div>
                <div class="module-name">Assets</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('knowledge', $allowed_modules)): ?>
            <a href="knowledge/" class="module-card knowledge" title="Create and browse knowledge base articles">
                <div class="module-icon knowledge">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
                <div class="module-name">Knowledge</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('changes', $allowed_modules)): ?>
            <a href="change-management/" class="module-card changes" title="Plan, track and manage IT changes">
                <div class="module-icon changes">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="16 3 21 3 21 8"></polyline>
                        <line x1="4" y1="20" x2="21" y2="3"></line>
                        <polyline points="21 16 21 21 16 21"></polyline>
                        <line x1="15" y1="15" x2="21" y2="21"></line>
                        <line x1="4" y1="4" x2="9" y2="9"></line>
                    </svg>
                </div>
                <div class="module-name">Changes</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('calendar', $allowed_modules)): ?>
            <a href="calendar/" class="module-card calendar" title="Track events, deadlines and schedules">
                <div class="module-icon calendar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <div class="module-name">Calendar</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('morning-checks', $allowed_modules)): ?>
            <a href="morning-checks/" class="module-card morning-checks" title="Record daily infrastructure checks">
                <div class="module-icon morning-checks">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <div class="module-name">Checks</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('reporting', $allowed_modules)): ?>
            <a href="reporting/" class="module-card reporting" title="View system logs and analytics">
                <div class="module-icon reporting">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                </div>
                <div class="module-name">Reporting</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('software', $allowed_modules)): ?>
            <a href="software/" class="module-card software" title="Browse software inventory and licensing">
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
                <div class="module-name">Software</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('forms', $allowed_modules)): ?>
            <a href="forms/" class="module-card forms" title="Design custom forms and view submissions">
                <div class="module-icon forms">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                </div>
                <div class="module-name">Forms</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('wiki', $allowed_modules)): ?>
            <a href="system-wiki/" class="module-card wiki" title="Browse auto-generated codebase documentation">
                <div class="module-icon wiki">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                    </svg>
                </div>
                <div class="module-name">Wiki</div>
            </a>
            <?php endif; ?>

            <?php if ($allowed_modules === null || in_array('system', $allowed_modules)): ?>
            <a href="system/" class="module-card system" title="System administration and configuration">
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
                <div class="module-name">System</div>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        Service Desk ITSM
    </div>
</body>
</html>
