<?php
/**
 * System - Security Settings
 * Trusted device, password policy, and account lockout configuration
 */
session_start();
require_once '../../config.php';

$current_page = 'security';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Security</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        body { overflow: auto; height: auto; }

        .security-container {
            max-width: 800px;
            margin: 0 auto;
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
        <h1 class="page-title">Security</h1>
        <p class="page-subtitle">Configure authentication policies and account protection</p>

        <form id="securityForm">
            <!-- Trusted Device -->
            <div class="settings-card">
                <h3>Trusted Device</h3>
                <p class="card-desc">Allow users to skip OTP verification on trusted browsers. Users opt in individually via their avatar menu. Set to 0 to disable this feature entirely.</p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong>Trust duration</strong>
                        How long a device stays trusted after OTP verification
                    </div>
                    <input type="number" class="setting-input" id="trustedDeviceDays" min="0" max="365" value="0">
                    <span class="setting-unit">days</span>
                </div>
            </div>

            <!-- Password Policy -->
            <div class="settings-card">
                <h3>Password Policy</h3>
                <p class="card-desc">Require users to change their password periodically. When a password expires, the user is redirected to a mandatory password change screen on next login. Set to 0 to disable.</p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong>Password expiry</strong>
                        Maximum age of a password before it must be changed
                    </div>
                    <input type="number" class="setting-input" id="passwordExpiryDays" min="0" max="365" value="0">
                    <span class="setting-unit">days</span>
                </div>
            </div>

            <!-- Account Lockout -->
            <div class="settings-card">
                <h3>Account Lockout</h3>
                <p class="card-desc">Lock accounts after repeated failed login attempts to prevent brute-force attacks. Set max attempts to 0 to disable lockout.</p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong>Max failed attempts</strong>
                        Number of wrong passwords before the account is locked
                    </div>
                    <input type="number" class="setting-input" id="maxFailedLogins" min="0" max="20" value="0">
                    <span class="setting-unit">attempts</span>
                </div>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong>Lockout duration</strong>
                        How long the account stays locked (counter resets after unlock)
                    </div>
                    <input type="number" class="setting-input" id="lockoutDuration" min="1" max="1440" value="30">
                    <span class="setting-unit">minutes</span>
                </div>
            </div>

            <!-- IP Ban -->
            <div class="settings-card">
                <h3>IP Ban</h3>
                <p class="card-desc">Automatically ban IP addresses that repeatedly attempt logins against non-existent or locked accounts. Each ban lasts 24 hours. After each ban the threshold drops by 1 (down to the minimum), making repeat offenders harder to abuse. Set max attempts to 0 to disable.</p>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong>First ban threshold</strong>
                        Failed attempts before the IP is banned the first time
                    </div>
                    <input type="number" class="setting-input" id="maxIpAttempts" min="0" max="20" value="5">
                    <span class="setting-unit">attempts</span>
                </div>
                <div class="setting-row">
                    <div class="setting-label">
                        <strong>Minimum threshold</strong>
                        The threshold stops reducing once it reaches this floor
                    </div>
                    <input type="number" class="setting-input" id="minIpAttempts" min="1" max="10" value="2">
                    <span class="setting-unit">attempts</span>
                </div>
                <div class="info-note">
                    <strong>Example:</strong> With max 5 and min 2, the first ban triggers after 5 failed attempts, the second after 4, then 3, then 2. It stays at 2 for every subsequent ban. Only attempts against non-existent usernames or already-locked accounts count.
                </div>
            </div>

            <div class="save-area">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>

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
                showToast('Security settings saved', 'success');
            } else {
                showToast('Error: ' + data.error, 'error');
            }
        } catch (e) {
            showToast('Failed to save settings', 'error');
        }

        btn.disabled = false;
    });

    loadSettings();
    </script>
</body>
</html>
