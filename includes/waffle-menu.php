<?php
/**
 * Shared Waffle Menu Component
 * Cross-module navigation menu for ITSM system
 *
 * Required variables before including:
 *   $path_prefix - Path to root (e.g., '../' or '../../')
 *   $current_module - Current module identifier (tickets, assets, knowledge, changes, calendar, morning-checks, reporting)
 *
 * Optional variables:
 *   $analyst_name - User's display name (defaults to 'Analyst')
 */

$path_prefix = $path_prefix ?? '../';
$current_module = $current_module ?? '';
$analyst_name = $analyst_name ?? ($_SESSION['analyst_name'] ?? 'Analyst');

require_once __DIR__ . '/module-colors.php';

// Password expiry guard — force redirect if password is expired
if (!empty($_SESSION['password_expired'])) {
    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($currentUrl, 'force_password_change.php') === false && strpos($currentUrl, 'analyst_logout.php') === false && strpos($currentUrl, 'api/') === false) {
        header('Location: ' . $path_prefix . 'force_password_change.php');
        exit;
    }
}

// Module definitions - add new modules here
$modules = [
    'watchtower' => [
        'name' => 'Watchtower',
        'path' => 'watchtower/',
        'icon' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>'
    ],
    'tickets' => [
        'name' => 'Tickets',
        'path' => 'tickets/',
        'icon' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>'
    ],
    'assets' => [
        'name' => 'Assets',
        'path' => 'asset-management/',
        'icon' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>'
    ],
    'knowledge' => [
        'name' => 'Knowledge',
        'path' => 'knowledge/',
        'icon' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>'
    ],
    'changes' => [
        'name' => 'Changes',
        'path' => 'change-management/',
        'icon' => '<polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line>'
    ],
    'calendar' => [
        'name' => 'Calendar',
        'path' => 'calendar/',
        'icon' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>'
    ],
    'morning-checks' => [
        'name' => 'Checks',
        'path' => 'morning-checks/',
        'icon' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>'
    ],
    'reporting' => [
        'name' => 'Reporting',
        'path' => 'reporting/',
        'icon' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>'
    ],
    'software' => [
        'name' => 'Software',
        'path' => 'software/',
        'icon' => '<rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line>'
    ],
    'forms' => [
        'name' => 'Forms',
        'path' => 'forms/',
        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>'
    ],
    'contracts' => [
        'name' => 'Contracts',
        'path' => 'contracts/',
        'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="12" y1="9" x2="8" y2="9"></line>'
    ],
    'service-status' => [
        'name' => 'Status',
        'path' => 'service-status/',
        'icon' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>'
    ],
    'wiki' => [
        'name' => 'Wiki',
        'path' => 'system-wiki/',
        'icon' => '<circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>'
    ],
    'system' => [
        'name' => 'System',
        'path' => 'system/',
        'icon' => '<line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line>'
    ]
];
?>
<style>
    /* Waffle Menu Styles */
    .waffle-menu-container {
        position: relative;
        display: flex;
        align-items: center;
    }

    .waffle-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 8px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.15s;
        margin-right: 15px;
    }

    .waffle-btn:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .waffle-icon {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 3px;
        width: 18px;
        height: 18px;
    }

    .waffle-icon span {
        width: 4px;
        height: 4px;
        background-color: #fff;
        border-radius: 50%;
    }

    .waffle-panel {
        position: absolute;
        top: 100%;
        left: 0;
        margin-top: 8px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 6px 30px rgba(0, 0, 0, 0.25);
        padding: 20px;
        min-width: 280px;
        z-index: 1000;
        display: none;
    }

    .waffle-panel.active {
        display: block;
    }

    .waffle-panel-header {
        font-size: 14px;
        font-weight: 600;
        color: #333;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .waffle-modules {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }

    .waffle-module-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 15px 10px;
        border-radius: 8px;
        text-decoration: none;
        color: #333;
        transition: background-color 0.15s;
    }

    .waffle-module-link:hover {
        background-color: #f5f5f5;
    }

    .waffle-module-link.current {
        background-color: #e8f4fd;
    }

    .waffle-module-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 8px;
    }

    .waffle-module-icon svg {
        width: 24px;
        height: 24px;
        color: #fff;
    }

    <?php foreach (getModuleColors() as $key => $c): ?>
    .waffle-module-icon.<?php echo $key; ?> { background: linear-gradient(135deg, <?php echo $c[0]; ?>, <?php echo $c[1]; ?>); }
    <?php endforeach; ?>

    .waffle-module-name {
        font-size: 12px;
        font-weight: 500;
        text-align: center;
    }

    /* Overlay to close waffle menu when clicking outside */
    .waffle-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 999;
        display: none;
    }

    .waffle-overlay.active {
        display: block;
    }

    /* Module title in header */
    .module-title {
        font-size: 14px;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.9);
        margin-right: 20px;
        padding: 6px 12px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
    }

    /* Module header colors */
    <?php foreach (getModuleColors() as $key => $c): ?>
    .header.<?php echo $key; ?>-header { background: linear-gradient(135deg, <?php echo $c[0]; ?>, <?php echo $c[1]; ?>); }
    <?php endforeach; ?>
</style>

<div class="waffle-overlay" id="waffleOverlay" onclick="closeWaffleMenu()"></div>

<!-- Waffle Menu Button and Panel - to be placed inside .waffle-menu-container -->
<?php
/**
 * Output the waffle menu button and panel
 */
function renderWaffleMenuButton() {
    ?>
    <button class="waffle-btn" onclick="toggleWaffleMenu()" title="ITSM Modules">
        <div class="waffle-icon">
            <span></span><span></span><span></span>
            <span></span><span></span><span></span>
            <span></span><span></span><span></span>
        </div>
    </button>
    <?php
}

function renderWaffleMenuPanel($modules, $current_module, $path_prefix) {
    $allowed = $_SESSION['allowed_modules'] ?? null;
    ?>
    <div class="waffle-panel" id="wafflePanel">
        <div class="waffle-panel-header">ITSM Modules</div>
        <div class="waffle-modules">
            <?php foreach ($modules as $key => $module):
                if ($allowed !== null && !in_array($key, $allowed)) continue;
            ?>
            <a href="<?php echo $path_prefix . $module['path']; ?>" class="waffle-module-link <?php echo $key === $current_module ? 'current' : ''; ?>">
                <div class="waffle-module-icon <?php echo $key; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?php echo $module['icon']; ?>
                    </svg>
                </div>
                <span class="waffle-module-name"><?php echo $module['name']; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function renderWaffleMenuJS() {
    ?>
    <script>
    function toggleWaffleMenu() {
        const panel = document.getElementById('wafflePanel');
        const overlay = document.getElementById('waffleOverlay');
        const isActive = panel.classList.contains('active');

        if (isActive) {
            closeWaffleMenu();
        } else {
            panel.classList.add('active');
            overlay.classList.add('active');
        }
    }

    function closeWaffleMenu() {
        document.getElementById('wafflePanel').classList.remove('active');
        document.getElementById('waffleOverlay').classList.remove('active');
    }

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeWaffleMenu();
        }
    });
    </script>
    <?php
}

function renderHeaderRight($analyst_name, $path_prefix) {
    // Extract initials from analyst name
    $parts = explode(' ', trim($analyst_name));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr(end($parts), 0, 1));
    }
    $analyst_username = $_SESSION['analyst_username'] ?? '';
    ?>
    <style>
        /* Avatar & User Menu */
        .header-right { position: relative; }

        .mail-check-btn {
            background: none;
            border: none;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
            padding: 4px;
            margin-right: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            transition: color 0.15s, background 0.15s;
            position: relative;
        }

        .mail-check-btn:hover { color: #fff; background: rgba(255,255,255,0.1); }

        .mail-check-btn.checking svg {
            animation: mail-spin 1s linear infinite;
        }

        .mail-check-btn.checking { color: #80cbc4; }

        @keyframes mail-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #546e7a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid rgba(255,255,255,0.3);
            transition: border-color 0.15s;
            user-select: none;
        }

        .user-avatar:hover {
            border-color: rgba(255,255,255,0.6);
        }

        .user-menu-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 1099;
            display: none;
        }

        .user-menu-overlay.active { display: block; }

        .user-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 6px 30px rgba(0,0,0,0.25);
            min-width: 240px;
            z-index: 1100;
            display: none;
            overflow: hidden;
        }

        .user-menu.active { display: block; }

        .user-menu-header {
            padding: 16px;
            border-bottom: 1px solid #eee;
        }

        .user-menu-name {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        .user-menu-username {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        .user-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 16px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            transition: background 0.15s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }

        .user-menu-item:hover { background: #f5f5f5; }

        .user-menu-item svg {
            width: 16px;
            height: 16px;
            color: #666;
            flex-shrink: 0;
        }

        .user-menu-divider {
            height: 1px;
            background: #eee;
            margin: 0;
        }

        .user-menu-item.logout-item {
            color: #d32f2f;
        }

        .user-menu-item.logout-item svg { color: #d32f2f; }

        .mfa-badge {
            margin-left: auto;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 3px;
        }

        .mfa-badge.enabled { background: #e8f5e9; color: #2e7d32; }
        .mfa-badge.disabled { background: #f5f5f5; color: #999; }

        /* Account modals */
        .account-modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .account-modal.active { display: flex; }

        .account-modal-box {
            background: #fff;
            border-radius: 8px;
            width: 90%;
            max-width: 460px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }

        .account-modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .account-modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #999;
            font-size: 20px;
            line-height: 1;
        }

        .account-modal-close:hover { color: #333; }

        .account-modal-body { padding: 24px; }

        .account-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .acct-form-group { margin-bottom: 16px; }

        .acct-form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
            font-size: 13px;
        }

        .acct-form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
        }

        .acct-form-input:focus { outline: none; border-color: #546e7a; }

        .acct-btn {
            padding: 9px 18px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }

        .acct-btn-primary { background: #546e7a; color: #fff; }
        .acct-btn-primary:hover { background: #455a64; }
        .acct-btn-secondary { background: #e0e0e0; color: #333; }
        .acct-btn-secondary:hover { background: #d0d0d0; }
        .acct-btn-danger { background: #fff; color: #d32f2f; border: 1px solid #d32f2f; }
        .acct-btn-danger:hover { background: #ffebee; }
        .acct-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .acct-msg {
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }

        .acct-msg.success { display: block; background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .acct-msg.error { display: block; background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

        /* MFA specific */
        .mfa-status-card {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .mfa-status-card.enabled {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
        }

        .mfa-status-card.not-enabled {
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
        }

        .mfa-status-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .mfa-status-desc {
            font-size: 12px;
            color: #666;
        }

        .mfa-setup-area { margin-top: 16px; }

        .qr-container {
            text-align: center;
            padding: 16px;
            background: #fff;
            border: 1px solid #eee;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .qr-container img { image-rendering: pixelated; }

        .secret-display {
            text-align: center;
            margin-bottom: 16px;
        }

        .secret-display code {
            background: #f5f5f5;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Consolas', monospace;
            letter-spacing: 2px;
            user-select: all;
        }

        .secret-display p {
            font-size: 11px;
            color: #999;
            margin-top: 6px;
        }

        .verify-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .verify-row .acct-form-group { flex: 1; margin-bottom: 0; }

        .otp-input {
            font-size: 18px;
            letter-spacing: 6px;
            text-align: center;
            font-family: 'Consolas', monospace;
        }

        .mfa-disable-area { margin-top: 16px; }
    </style>

    <div class="header-right">
        <button class="mail-check-btn" id="mailCheckBtn" onclick="triggerMailCheck()" title="Check for new emails" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
        </button>
        <div class="user-menu-overlay" id="userMenuOverlay" onclick="closeUserMenu()"></div>
        <div class="user-avatar" onclick="toggleUserMenu()" title="<?php echo htmlspecialchars($analyst_name); ?>">
            <?php echo htmlspecialchars($initials); ?>
        </div>
        <div class="user-menu" id="userMenu">
            <div class="user-menu-header">
                <div class="user-menu-name"><?php echo htmlspecialchars($analyst_name); ?></div>
                <div class="user-menu-username"><?php echo htmlspecialchars($analyst_username); ?></div>
            </div>
            <button class="user-menu-item" onclick="openPasswordModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <span>Change Password</span>
            </button>
            <button class="user-menu-item" onclick="openMfaModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                <span>Multi-Factor Auth</span>
                <span class="mfa-badge disabled" id="mfaBadgeMenu">Off</span>
            </button>
            <button class="user-menu-item" id="trustDeviceItem" onclick="toggleTrustDevice()" style="display:none;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                <span>Trusted Device</span>
                <span class="mfa-badge disabled" id="trustBadgeMenu">Off</span>
            </button>
            <div class="user-menu-divider"></div>
            <button class="user-menu-item logout-item" onclick="if(confirm('Are you sure you want to logout?')) window.location.href='<?php echo $path_prefix; ?>analyst_logout.php';">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span>Logout</span>
            </button>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="account-modal" id="passwordModal">
        <div class="account-modal-box">
            <div class="account-modal-header">
                Change Password
                <button class="account-modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <div class="account-modal-body">
                <div id="pwMsg" class="acct-msg"></div>
                <div class="acct-form-group">
                    <label class="acct-form-label">Current Password</label>
                    <input type="password" class="acct-form-input" id="pwCurrent" autocomplete="current-password">
                </div>
                <div class="acct-form-group">
                    <label class="acct-form-label">New Password</label>
                    <input type="password" class="acct-form-input" id="pwNew" autocomplete="new-password">
                </div>
                <div class="acct-form-group">
                    <label class="acct-form-label">Confirm New Password</label>
                    <input type="password" class="acct-form-input" id="pwConfirm" autocomplete="new-password">
                </div>
            </div>
            <div class="account-modal-footer">
                <button class="acct-btn acct-btn-secondary" onclick="closePasswordModal()">Cancel</button>
                <button class="acct-btn acct-btn-primary" id="pwSaveBtn" onclick="savePassword()">Change Password</button>
            </div>
        </div>
    </div>

    <!-- MFA Modal -->
    <div class="account-modal" id="mfaModal">
        <div class="account-modal-box">
            <div class="account-modal-header">
                Multi-Factor Authentication
                <button class="account-modal-close" onclick="closeMfaModal()">&times;</button>
            </div>
            <div class="account-modal-body">
                <div id="mfaMsg" class="acct-msg"></div>
                <div id="mfaContent">Loading...</div>
            </div>
        </div>
    </div>

    <script src="<?php echo $path_prefix; ?>assets/js/qrcode.min.js"></script>
    <script>
    const _pathPrefix = '<?php echo $path_prefix; ?>';

    /* --- User Menu --- */
    function toggleUserMenu() {
        const menu = document.getElementById('userMenu');
        const overlay = document.getElementById('userMenuOverlay');
        const active = menu.classList.contains('active');
        closeWaffleMenu();
        if (active) {
            closeUserMenu();
        } else {
            menu.classList.add('active');
            overlay.classList.add('active');
            loadMfaBadge();
        }
    }

    function closeUserMenu() {
        document.getElementById('userMenu').classList.remove('active');
        document.getElementById('userMenuOverlay').classList.remove('active');
    }

    /* --- Password Modal --- */
    function openPasswordModal() {
        closeUserMenu();
        document.getElementById('pwCurrent').value = '';
        document.getElementById('pwNew').value = '';
        document.getElementById('pwConfirm').value = '';
        hidePwMsg();
        document.getElementById('passwordModal').classList.add('active');
        setTimeout(() => document.getElementById('pwCurrent').focus(), 100);
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').classList.remove('active');
    }

    function hidePwMsg() {
        const el = document.getElementById('pwMsg');
        el.className = 'acct-msg';
        el.textContent = '';
    }

    function showPwMsg(msg, type) {
        const el = document.getElementById('pwMsg');
        el.className = 'acct-msg ' + type;
        el.textContent = msg;
    }

    async function savePassword() {
        hidePwMsg();
        const btn = document.getElementById('pwSaveBtn');
        btn.disabled = true;

        const current = document.getElementById('pwCurrent').value;
        const newPw = document.getElementById('pwNew').value;
        const confirm = document.getElementById('pwConfirm').value;

        if (!current || !newPw || !confirm) {
            showPwMsg('All fields are required', 'error');
            btn.disabled = false;
            return;
        }

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/change_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ current_password: current, new_password: newPw, confirm_password: confirm })
            });
            const data = await resp.json();
            if (data.success) {
                showPwMsg('Password changed successfully', 'success');
                document.getElementById('pwCurrent').value = '';
                document.getElementById('pwNew').value = '';
                document.getElementById('pwConfirm').value = '';
                setTimeout(() => closePasswordModal(), 1500);
            } else {
                showPwMsg(data.error, 'error');
            }
        } catch (e) {
            showPwMsg('Failed to change password', 'error');
        }
        btn.disabled = false;
    }

    /* --- MFA Badge & Trust Device Badge --- */
    async function loadMfaBadge() {
        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/get_mfa_status.php');
            const data = await resp.json();
            const badge = document.getElementById('mfaBadgeMenu');
            if (data.success && data.mfa_enabled) {
                badge.className = 'mfa-badge enabled';
                badge.textContent = 'On';
            } else {
                badge.className = 'mfa-badge disabled';
                badge.textContent = 'Off';
            }

            // Trust device badge
            const trustItem = document.getElementById('trustDeviceItem');
            const trustBadge = document.getElementById('trustBadgeMenu');
            if (data.success && data.trusted_device_days > 0) {
                trustItem.style.display = '';
                if (data.trust_device_enabled) {
                    trustBadge.className = 'mfa-badge enabled';
                    trustBadge.textContent = 'On';
                } else {
                    trustBadge.className = 'mfa-badge disabled';
                    trustBadge.textContent = 'Off';
                }
            } else {
                trustItem.style.display = 'none';
            }
        } catch (e) {}
    }

    /* --- Trust Device Toggle --- */
    async function toggleTrustDevice() {
        closeUserMenu();
        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/toggle_trust_device.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await resp.json();
            if (data.success) {
                const trustBadge = document.getElementById('trustBadgeMenu');
                if (data.enabled) {
                    trustBadge.className = 'mfa-badge enabled';
                    trustBadge.textContent = 'On';
                } else {
                    trustBadge.className = 'mfa-badge disabled';
                    trustBadge.textContent = 'Off';
                }
            }
        } catch (e) {}
    }

    /* --- MFA Modal --- */
    let mfaEnabled = false;

    async function openMfaModal() {
        closeUserMenu();
        document.getElementById('mfaMsg').className = 'acct-msg';
        document.getElementById('mfaContent').innerHTML = 'Loading...';
        document.getElementById('mfaModal').classList.add('active');
        await loadMfaContent();
    }

    function closeMfaModal() {
        document.getElementById('mfaModal').classList.remove('active');
    }

    function showMfaMsg(msg, type) {
        const el = document.getElementById('mfaMsg');
        el.className = 'acct-msg ' + type;
        el.textContent = msg;
    }

    async function loadMfaContent() {
        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/get_mfa_status.php');
            const data = await resp.json();
            mfaEnabled = data.success && data.mfa_enabled;
            renderMfaContent();
        } catch (e) {
            document.getElementById('mfaContent').innerHTML = '<p>Failed to load MFA status</p>';
        }
    }

    function renderMfaContent() {
        const container = document.getElementById('mfaContent');
        if (mfaEnabled) {
            container.innerHTML = `
                <div class="mfa-status-card enabled">
                    <div class="mfa-status-title" style="color:#2e7d32;">MFA is enabled</div>
                    <div class="mfa-status-desc">Your account is protected with a time-based one-time password (TOTP). You will be asked for a code from your authenticator app each time you log in.</div>
                </div>
                <div class="mfa-disable-area">
                    <p style="font-size:13px;color:#666;margin:0 0 12px 0;">To disable MFA, enter your password below:</p>
                    <div class="acct-form-group">
                        <input type="password" class="acct-form-input" id="mfaDisablePw" placeholder="Enter your password">
                    </div>
                    <button class="acct-btn acct-btn-danger" onclick="disableMfa()">Disable MFA</button>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="mfa-status-card not-enabled">
                    <div class="mfa-status-title">MFA is not enabled</div>
                    <div class="mfa-status-desc">Add an extra layer of security by setting up a time-based one-time password (TOTP) with an authenticator app like Google Authenticator or Microsoft Authenticator.</div>
                </div>
                <button class="acct-btn acct-btn-primary" onclick="startMfaSetup()">Set Up MFA</button>
            `;
        }
    }

    async function startMfaSetup() {
        const container = document.getElementById('mfaContent');
        container.innerHTML = '<p style="color:#888;">Generating secret...</p>';

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/setup_mfa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await resp.json();
            if (!data.success) {
                showMfaMsg(data.error, 'error');
                renderMfaContent();
                return;
            }

            // Generate QR code
            let qrHtml = '';
            try {
                const qr = qrcode(0, 'M');
                qr.addData(data.uri);
                qr.make();
                qrHtml = qr.createImgTag(5, 0);
            } catch (e) {
                qrHtml = '<p style="color:#c62828;">QR generation failed. Use the manual key below.</p>';
            }

            container.innerHTML = `
                <p style="font-size:13px;color:#333;margin:0 0 16px 0;"><strong>Step 1:</strong> Scan this QR code with your authenticator app</p>
                <div class="qr-container">${qrHtml}</div>
                <div class="secret-display">
                    <code>${data.secret}</code>
                    <p>Or enter this key manually in your authenticator app</p>
                </div>
                <p style="font-size:13px;color:#333;margin:0 0 12px 0;"><strong>Step 2:</strong> Enter the 6-digit code from your app to verify</p>
                <div class="verify-row">
                    <div class="acct-form-group">
                        <input type="text" class="acct-form-input otp-input" id="mfaVerifyCode" maxlength="6" placeholder="000000" inputmode="numeric" autocomplete="one-time-code">
                    </div>
                    <button class="acct-btn acct-btn-primary" id="mfaVerifyBtn" onclick="verifyMfaSetup()" style="margin-bottom:0;height:40px;">Verify</button>
                </div>
            `;
            setTimeout(() => document.getElementById('mfaVerifyCode').focus(), 100);
        } catch (e) {
            showMfaMsg('Failed to start MFA setup', 'error');
            renderMfaContent();
        }
    }

    async function verifyMfaSetup() {
        const code = document.getElementById('mfaVerifyCode').value.trim();
        if (!code || code.length !== 6) {
            showMfaMsg('Please enter a 6-digit code', 'error');
            return;
        }

        const btn = document.getElementById('mfaVerifyBtn');
        btn.disabled = true;

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/verify_mfa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code })
            });
            const data = await resp.json();
            if (data.success) {
                showMfaMsg('MFA has been enabled successfully', 'success');
                mfaEnabled = true;
                loadMfaBadge();
                setTimeout(() => {
                    document.getElementById('mfaMsg').className = 'acct-msg';
                    renderMfaContent();
                }, 2000);
            } else {
                showMfaMsg(data.error, 'error');
                btn.disabled = false;
            }
        } catch (e) {
            showMfaMsg('Verification failed', 'error');
            btn.disabled = false;
        }
    }

    async function disableMfa() {
        const pw = document.getElementById('mfaDisablePw').value;
        if (!pw) {
            showMfaMsg('Password is required', 'error');
            return;
        }

        try {
            const resp = await fetch(_pathPrefix + 'api/myaccount/disable_mfa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: pw })
            });
            const data = await resp.json();
            if (data.success) {
                showMfaMsg('MFA has been disabled', 'success');
                mfaEnabled = false;
                loadMfaBadge();
                setTimeout(() => {
                    document.getElementById('mfaMsg').className = 'acct-msg';
                    renderMfaContent();
                }, 2000);
            } else {
                showMfaMsg(data.error, 'error');
            }
        } catch (e) {
            showMfaMsg('Failed to disable MFA', 'error');
        }
    }

    /* --- Keyboard & click handlers --- */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeUserMenu();
            closePasswordModal();
            closeMfaModal();
        }
    });

    document.getElementById('passwordModal').addEventListener('click', function(e) {
        if (e.target === this) closePasswordModal();
    });

    document.getElementById('mfaModal').addEventListener('click', function(e) {
        if (e.target === this) closeMfaModal();
    });
    </script>
    <?php
}
?>
