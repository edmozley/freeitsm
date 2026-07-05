<?php
/**
 * System - Security Settings
 * Trusted device, password policy, and account lockout configuration
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'security';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.security.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .security-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 30px 20px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 6px 0;
        }

        .page-subtitle {
            font-size: 13px;
            color: #888;
            margin: 0 0 30px 0;
        }

        .settings-card {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }

        .settings-card h3 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 0 0 4px 0;
        }

        .settings-card .card-desc {
            font-size: 13px;
            color: #888;
            margin: 0 0 20px 0;
            line-height: 1.5;
        }

        .setting-row {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .setting-row:last-child { margin-bottom: 0; }

        .setting-label {
            flex: 1;
            font-size: 13px;
            color: #555;
        }

        .setting-label strong {
            display: block;
            color: #333;
            margin-bottom: 2px;
        }

        .setting-input {
            width: 100px;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            text-align: center;
            font-family: inherit;
        }

        .setting-input:focus { outline: none; border-color: #546e7a; }

        .setting-unit {
            font-size: 12px;
            color: #999;
            min-width: 50px;
        }

        .save-area {
            margin-top: 30px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-primary {
            background: #546e7a;
            color: #fff;
        }

        .btn-primary:hover { background: #455a64; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .info-note {
            background: #f5f7fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 14px 16px;
            font-size: 12px;
            color: #666;
            line-height: 1.6;
            margin-top: 6px;
        }

        .info-note strong { color: #333; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="security-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.security.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.security.subtitle')); ?></p>

        <form id="securityForm">
            <!-- Trusted Device -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.security.trusted_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.security.trusted_desc')); ?></p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('system.security.trust_duration')); ?></strong>
                        <?php echo htmlspecialchars(t('system.security.trust_duration_hint')); ?>
                    </div>
                    <input type="number" class="setting-input" id="trustedDeviceDays" min="0" max="365" value="0">
                    <span class="setting-unit"><?php echo htmlspecialchars(t('system.security.unit_days')); ?></span>
                </div>
            </div>

            <!-- Password Policy -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.security.password_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.security.password_desc')); ?></p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('system.security.password_expiry')); ?></strong>
                        <?php echo htmlspecialchars(t('system.security.password_expiry_hint')); ?>
                    </div>
                    <input type="number" class="setting-input" id="passwordExpiryDays" min="0" max="365" value="0">
                    <span class="setting-unit"><?php echo htmlspecialchars(t('system.security.unit_days')); ?></span>
                </div>
            </div>

            <!-- Account Lockout -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.security.lockout_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.security.lockout_desc')); ?></p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('system.security.max_attempts')); ?></strong>
                        <?php echo htmlspecialchars(t('system.security.max_attempts_hint')); ?>
                    </div>
                    <input type="number" class="setting-input" id="maxFailedLogins" min="0" max="20" value="0">
                    <span class="setting-unit"><?php echo htmlspecialchars(t('system.security.unit_attempts')); ?></span>
                </div>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('system.security.lockout_duration')); ?></strong>
                        <?php echo htmlspecialchars(t('system.security.lockout_duration_hint')); ?>
                    </div>
                    <input type="number" class="setting-input" id="lockoutDuration" min="1" max="1440" value="30">
                    <span class="setting-unit"><?php echo htmlspecialchars(t('system.security.unit_minutes')); ?></span>
                </div>
            </div>

            <!-- IP Ban -->
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.security.ipban_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.security.ipban_desc')); ?></p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('system.security.first_ban')); ?></strong>
                        <?php echo htmlspecialchars(t('system.security.first_ban_hint')); ?>
                    </div>
                    <input type="number" class="setting-input" id="maxIpAttempts" min="0" max="20" value="5">
                    <span class="setting-unit"><?php echo htmlspecialchars(t('system.security.unit_attempts')); ?></span>
                </div>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong><?php echo htmlspecialchars(t('system.security.min_threshold')); ?></strong>
                        <?php echo htmlspecialchars(t('system.security.min_threshold_hint')); ?>
                    </div>
                    <input type="number" class="setting-input" id="minIpAttempts" min="1" max="10" value="2">
                    <span class="setting-unit"><?php echo htmlspecialchars(t('system.security.unit_attempts')); ?></span>
                </div>
                <div class="info-note">
                    <strong><?php echo htmlspecialchars(t('system.security.ipban_example_strong')); ?></strong> <?php echo htmlspecialchars(t('system.security.ipban_example_text')); ?>
                </div>
            </div>

            <div class="save-area">
                <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('system.security.save')); ?></button>
            </div>
        </form>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <script>
    const API_BASE = '<?php echo $path_prefix; ?>api/settings/';

    async function loadSettings() {
        try {
            const resp = await fetch(API_BASE + 'get_system_settings.php');
            const data = await resp.json();
            if (data.success) {
                const s = data.settings;
                document.getElementById('trustedDeviceDays').value = s.trusted_device_days || '0';
                document.getElementById('passwordExpiryDays').value = s.password_expiry_days || '0';
                document.getElementById('maxFailedLogins').value = s.max_failed_logins || '0';
                document.getElementById('lockoutDuration').value = s.lockout_duration_minutes || '30';
                document.getElementById('maxIpAttempts').value = s.max_ip_attempts || '5';
                document.getElementById('minIpAttempts').value = s.min_ip_attempts || '2';
            }
        } catch (e) {
            console.error('Failed to load settings', e);
        }
    }

    document.getElementById('securityForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;

        const settings = {
            trusted_device_days: document.getElementById('trustedDeviceDays').value,
            password_expiry_days: document.getElementById('passwordExpiryDays').value,
            max_failed_logins: document.getElementById('maxFailedLogins').value,
            lockout_duration_minutes: document.getElementById('lockoutDuration').value,
            max_ip_attempts: document.getElementById('maxIpAttempts').value,
            min_ip_attempts: document.getElementById('minIpAttempts').value
        };

        try {
            const resp = await fetch(API_BASE + 'save_system_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: settings })
            });
            const data = await resp.json();
            if (data.success) {
                showToast(window.t('system.security.saved'), 'success');
            } else {
                showToast(window.t('system.security.error', { error: data.error }), 'error');
            }
        } catch (e) {
            showToast(window.t('system.security.save_failed'), 'error');
        }

        btn.disabled = false;
    });

    loadSettings();
    </script>
</body>
</html>
