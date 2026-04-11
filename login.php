<?php
/**
 * Login page for Service Desk Ticketing System
 */
session_start();
require_once 'config.php';
require_once 'includes/functions.php';

/**
 * Get a security setting from system_settings (returns string or null)
 */
function getSecuritySetting($conn, $key) {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['setting_value'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check if password is expired based on system setting
 */
function isPasswordExpired($conn, $passwordChangedDatetime) {
    $days = (int)(getSecuritySetting($conn, 'password_expiry_days') ?? 0);
    if ($days <= 0) return false;
    if (empty($passwordChangedDatetime)) return true; // never changed
    $changed = new DateTime($passwordChangedDatetime);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    return $now->diff($changed)->days >= $days;
}

/**
 * Log login attempt to system_logs
 */
function logLoginAttempt($conn, $analystId, $username, $success) {
    try {
        $details = json_encode([
            'username' => $username,
            'success' => $success,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        $sql = "INSERT INTO system_logs (log_type, analyst_id, details, created_datetime)
                VALUES ('login', ?, ?, UTC_TIMESTAMP())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId, $details]);
    } catch (Exception $e) {
        // Silently fail - don't break login if logging fails
        error_log('Failed to log login attempt: ' . $e->getMessage());
    }
}

// If already logged in, redirect to inbox
if (isset($_SESSION['analyst_id'])) {
    header('Location: index.php');
    exit;
}

// Handle MFA cancellation
if (isset($_GET['cancel_mfa'])) {
    unset($_SESSION['mfa_pending_analyst_id']);
    unset($_SESSION['mfa_pending_username']);
    unset($_SESSION['mfa_pending_name']);
    unset($_SESSION['mfa_pending_email']);
    unset($_SESSION['mfa_pending_allowed_modules']);
    header('Location: login.php');
    exit;
}

$error = '';
$mfa_required = isset($_SESSION['mfa_pending_analyst_id']);
$ip_banned = false;

/**
 * Check if an IP is currently banned. Returns minutes remaining or 0.
 */
function checkIpBan($conn, $ip) {
    try {
        $stmt = $conn->prepare("SELECT banned_until FROM ip_login_bans WHERE ip_address = ? AND banned_until > UTC_TIMESTAMP()");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $until = new DateTime($row['banned_until']);
            $now = new DateTime('now', new DateTimeZone('UTC'));
            return max(1, (int)ceil(($until->getTimestamp() - $now->getTimestamp()) / 60));
        }
    } catch (Exception $e) { /* table may not exist yet */ }
    return 0;
}

/**
 * Record a suspicious IP attempt (non-existent user or locked account).
 * Bans the IP if the threshold is reached.
 */
function recordIpAttempt($conn, $ip) {
    try {
        $maxAttempts = (int)(getSecuritySetting($conn, 'max_ip_attempts') ?? 0);
        if ($maxAttempts <= 0) return; // Feature disabled

        $minAttempts = (int)(getSecuritySetting($conn, 'min_ip_attempts') ?? 2);

        // Upsert the IP record
        $stmt = $conn->prepare("SELECT id, attempt_count, ban_count, banned_until FROM ip_login_bans WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $conn->prepare("INSERT INTO ip_login_bans (ip_address, attempt_count, ban_count, last_attempt) VALUES (?, 1, 0, UTC_TIMESTAMP())")
                ->execute([$ip]);
            return;
        }

        // If previously banned but ban expired, keep ban_count but reset attempt_count
        $attempts = $row['attempt_count'] + 1;
        $banCount = $row['ban_count'];

        // Calculate current threshold: max_attempts - ban_count, floored at min_attempts
        $threshold = max($minAttempts, $maxAttempts - $banCount);

        if ($attempts >= $threshold) {
            // Ban for 24 hours
            $conn->prepare("UPDATE ip_login_bans SET attempt_count = 0, ban_count = ban_count + 1, banned_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), last_attempt = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$row['id']]);
        } else {
            $conn->prepare("UPDATE ip_login_bans SET attempt_count = ?, last_attempt = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$attempts, $row['id']]);
        }
    } catch (Exception $e) { /* table may not exist yet */ }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // Connect to database
            $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Check IP ban
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $banMinutes = checkIpBan($conn, $clientIp);
            if ($banMinutes > 0) {
                http_response_code(429);
                $ip_banned = true;
                if ($banMinutes >= 60) {
                    $hours = floor($banMinutes / 60);
                    $error = 'Too many failed attempts. Try again in ' . $hours . ' hour' . ($hours > 1 ? 's' : '') . '.';
                } else {
                    $error = 'Too many failed attempts. Try again in ' . $banMinutes . ' minute' . ($banMinutes !== 1 ? 's' : '') . '.';
                }
                logLoginAttempt($conn, null, $username, false);
                throw new Exception('__ip_banned__');
            }

            // Query for user (include MFA, lockout, trust, and password fields)
            // Falls back to basic query if security columns don't exist yet (pre db-verify)
            try {
                $sql = "SELECT id, username, password_hash, full_name, email, totp_enabled,
                               locked_until, failed_login_count, trust_device_enabled, password_changed_datetime
                        FROM analysts WHERE username = ? AND is_active = 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$username]);
            } catch (Exception $colEx) {
                $sql = "SELECT id, username, password_hash, full_name, email, totp_enabled
                        FROM analysts WHERE username = ? AND is_active = 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$username]);
            }
            $analyst = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check account lockout
            if ($analyst && !empty($analyst['locked_until'])) {
                $lockedUntil = new DateTime($analyst['locked_until']);
                $now = new DateTime('now', new DateTimeZone('UTC'));
                if ($now < $lockedUntil) {
                    $remaining = $now->diff($lockedUntil);
                    $mins = $remaining->i + ($remaining->h * 60);
                    if ($mins < 1) $mins = 1;
                    $error = 'Account locked. Try again in ' . $mins . ' minute' . ($mins !== 1 ? 's' : '') . '.';
                    logLoginAttempt($conn, $analyst['id'], $username, false);
                    recordIpAttempt($conn, $clientIp);
                } else {
                    // Lockout expired — reset
                    $resetStmt = $conn->prepare("UPDATE analysts SET failed_login_count = 0, locked_until = NULL WHERE id = ?");
                    $resetStmt->execute([$analyst['id']]);
                    $analyst['failed_login_count'] = 0;
                    $analyst['locked_until'] = null;
                }
            }

            if (empty($error) && $analyst && password_verify($password, $analyst['password_hash'])) {
                // Reset failed login counter on success
                if (!empty($analyst['failed_login_count'])) {
                    try {
                        $resetStmt = $conn->prepare("UPDATE analysts SET failed_login_count = 0, locked_until = NULL WHERE id = ?");
                        $resetStmt->execute([$analyst['id']]);
                    } catch (Exception $e) { /* columns may not exist yet */ }
                }

                // Check if MFA is enabled
                if (!empty($analyst['totp_enabled'])) {
                    // Check for trusted device cookie — skip MFA if valid
                    $trustedDeviceValid = false;
                    if (!empty($_COOKIE['trusted_device']) && !empty($analyst['trust_device_enabled'])) {
                        $tokenHash = hash('sha256', hex2bin($_COOKIE['trusted_device']));
                        $tdStmt = $conn->prepare("SELECT id FROM trusted_devices WHERE device_token_hash = ? AND analyst_id = ? AND expires_datetime > UTC_TIMESTAMP()");
                        $tdStmt->execute([$tokenHash, $analyst['id']]);
                        if ($tdStmt->fetch()) {
                            $trustedDeviceValid = true;
                        }
                    }

                    if ($trustedDeviceValid) {
                        // Trusted device — skip MFA, complete login directly
                        $_SESSION['analyst_id'] = $analyst['id'];
                        $_SESSION['analyst_username'] = $analyst['username'];
                        $_SESSION['analyst_name'] = $analyst['full_name'];
                        $_SESSION['analyst_email'] = $analyst['email'];

                        $updateSql = "UPDATE analysts SET last_login_datetime = UTC_TIMESTAMP() WHERE id = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->execute([$analyst['id']]);

                        $_SESSION['allowed_modules'] = getAnalystAllowedModules($conn, $analyst['id']);
                        logLoginAttempt($conn, $analyst['id'], $username, true);

                        // Check password expiry
                        if (isset($analyst['password_changed_datetime']) && isPasswordExpired($conn, $analyst['password_changed_datetime'])) {
                            $_SESSION['password_expired'] = true;
                            header('Location: force_password_change.php');
                        } else {
                            header('Location: index.php');
                        }
                        exit;
                    }

                    // MFA required - store pending state, don't complete login yet
                    $_SESSION['mfa_pending_analyst_id'] = $analyst['id'];
                    $_SESSION['mfa_pending_username'] = $analyst['username'];
                    $_SESSION['mfa_pending_name'] = $analyst['full_name'];
                    $_SESSION['mfa_pending_email'] = $analyst['email'];
                    $_SESSION['mfa_pending_allowed_modules'] = getAnalystAllowedModules($conn, $analyst['id']);

                    // Log successful password step
                    logLoginAttempt($conn, $analyst['id'], $username, true);

                    // Flag so the HTML below renders the MFA form on this same request
                    $mfa_required = true;
                } else {
                    // No MFA - complete login directly
                    $_SESSION['analyst_id'] = $analyst['id'];
                    $_SESSION['analyst_username'] = $analyst['username'];
                    $_SESSION['analyst_name'] = $analyst['full_name'];
                    $_SESSION['analyst_email'] = $analyst['email'];

                    // Update last login time
                    $updateSql = "UPDATE analysts SET last_login_datetime = UTC_TIMESTAMP() WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute([$analyst['id']]);

                    // Load module permissions
                    $_SESSION['allowed_modules'] = getAnalystAllowedModules($conn, $analyst['id']);

                    // Log successful login
                    logLoginAttempt($conn, $analyst['id'], $username, true);

                    // Check password expiry
                    if (isset($analyst['password_changed_datetime']) && isPasswordExpired($conn, $analyst['password_changed_datetime'])) {
                        $_SESSION['password_expired'] = true;
                        header('Location: force_password_change.php');
                    } else {
                        header('Location: index.php');
                    }
                    exit;
                }
            } else if (empty($error)) {
                // Failed login — track attempts and possibly lock
                if ($analyst && array_key_exists('failed_login_count', $analyst)) {
                    $newCount = ($analyst['failed_login_count'] ?? 0) + 1;
                    $maxFailed = (int)(getSecuritySetting($conn, 'max_failed_logins') ?? 0);
                    $lockoutMins = (int)(getSecuritySetting($conn, 'lockout_duration_minutes') ?? 30);

                    if ($maxFailed > 0 && $newCount >= $maxFailed) {
                        $lockStmt = $conn->prepare("UPDATE analysts SET failed_login_count = ?, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?");
                        $lockStmt->execute([(int)$newCount, (int)$lockoutMins, (int)$analyst['id']]);
                    } else {
                        $incStmt = $conn->prepare("UPDATE analysts SET failed_login_count = ? WHERE id = ?");
                        $incStmt->execute([(int)$newCount, (int)$analyst['id']]);
                    }
                }

                logLoginAttempt($conn, $analyst ? $analyst['id'] : null, $username, false);
                $error = 'Invalid username or password';

                // Track IP for non-existent accounts (brute force enumeration)
                if (!$analyst) {
                    recordIpAttempt($conn, $clientIp);
                }
            }

        } catch (Exception $e) {
            if ($e->getMessage() !== '__ip_banned__') {
                $error = 'Login error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header img {
            width: 250px;
            height: auto;
            margin-bottom: 25px;
        }

        .login-header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }

        .login-button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .login-button:hover {
            transform: translateY(-2px);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .forgot-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #999;
            text-decoration: none;
            font-size: 13px;
        }

        .forgot-link:hover { color: #666; }

        /* MFA challenge styles */
        .mfa-icon {
            text-align: center;
            margin-bottom: 20px;
        }

        .mfa-icon svg {
            width: 48px;
            height: 48px;
            color: #667eea;
        }

        .mfa-subtitle {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .otp-input-field {
            width: 100%;
            padding: 14px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
            font-family: 'Consolas', 'Courier New', monospace;
            transition: border-color 0.3s;
        }

        .otp-input-field:focus {
            outline: none;
            border-color: #667eea;
        }

        .mfa-cancel {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #999;
            text-decoration: none;
            font-size: 13px;
        }

        .mfa-cancel:hover { color: #666; }

        .mfa-error {
            background: #fee;
            color: #c33;
            padding: 10px 14px;
            border-radius: 5px;
            margin-bottom: 16px;
            font-size: 13px;
            border-left: 4px solid #c33;
            display: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="assets/images/CompanyLogo.png" alt="Company Logo">
            <?php if ($mfa_required): ?>
                <h1>Verification</h1>
            <?php else: ?>
                <h1>ITSM Login</h1>
            <?php endif; ?>
        </div>

        <?php if ($mfa_required): ?>
            <!-- MFA Challenge Form -->
            <div class="mfa-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                </svg>
            </div>
            <p class="mfa-subtitle">Enter the 6-digit code from your authenticator app</p>
            <div id="mfaError" class="mfa-error"></div>
            <div class="form-group">
                <input type="text" id="otpCode" class="otp-input-field" maxlength="6" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="------">
            </div>
            <button type="button" class="login-button" id="verifyBtn" onclick="verifyOtp()">Verify</button>
            <a href="login.php?cancel_mfa=1" class="mfa-cancel">Cancel and return to login</a>

            <script>
            // Auto-submit when 6 digits entered
            document.getElementById('otpCode').addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length === 6) {
                    verifyOtp();
                }
            });

            // Enter key submits
            document.getElementById('otpCode').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') verifyOtp();
            });

            async function verifyOtp() {
                const code = document.getElementById('otpCode').value.trim();
                if (code.length !== 6) return;

                const btn = document.getElementById('verifyBtn');
                const errEl = document.getElementById('mfaError');
                btn.disabled = true;
                btn.textContent = 'Verifying...';
                errEl.style.display = 'none';

                try {
                    const resp = await fetch('api/myaccount/verify_login_otp.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: code })
                    });
                    const data = await resp.json();
                    if (data.success) {
                        window.location.href = data.redirect || 'index.php';
                    } else {
                        errEl.textContent = data.error;
                        errEl.style.display = 'block';
                        document.getElementById('otpCode').value = '';
                        document.getElementById('otpCode').focus();
                        btn.disabled = false;
                        btn.textContent = 'Verify';
                    }
                } catch (e) {
                    errEl.textContent = 'Verification failed. Please try again.';
                    errEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Verify';
                }
            }
            </script>
        <?php else: ?>
            <!-- Standard Login Form -->
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="off">
                </div>

                <button type="submit" class="login-button">Sign In</button>
            </form>
            <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
        <?php endif; ?>
    </div>
</body>
</html>
