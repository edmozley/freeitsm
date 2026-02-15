<?php
/**
 * Login page for Service Desk Ticketing System
 */
session_start();
require_once 'config.php';
require_once 'includes/functions.php';

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
                VALUES ('login', ?, ?, GETUTCDATE())";
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

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // Connect to database
            $drivers = [
                'ODBC Driver 17 for SQL Server',
                'ODBC Driver 18 for SQL Server',
                'SQL Server Native Client 11.0',
                'SQL Server'
            ];

            $conn = null;
            $lastException = null;
            foreach ($drivers as $driver) {
                try {
                    $dsn = "odbc:Driver={{$driver}};Server=" . DB_SERVER . ";Database=" . DB_NAME;
                    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
                    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                    continue;
                }
            }

            if (!$conn) {
                throw new Exception('Database connection failed: ' . $lastException->getMessage());
            }

            // Query for user (include MFA fields)
            $sql = "SELECT id, username, password_hash, full_name, email, totp_enabled FROM analysts WHERE username = ? AND is_active = 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$username]);
            $analyst = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($analyst && password_verify($password, $analyst['password_hash'])) {
                // Check if MFA is enabled
                if (!empty($analyst['totp_enabled'])) {
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
                    $updateSql = "UPDATE analysts SET last_login_datetime = GETUTCDATE() WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute([$analyst['id']]);

                    // Load module permissions
                    $_SESSION['allowed_modules'] = getAnalystAllowedModules($conn, $analyst['id']);

                    // Log successful login
                    logLoginAttempt($conn, $analyst['id'], $username, true);

                    header('Location: index.php');
                    exit;
                }
            } else {
                // Log failed login
                logLoginAttempt($conn, null, $username, false);
                $error = 'Invalid username or password';
            }

        } catch (Exception $e) {
            $error = 'Login error: ' . $e->getMessage();
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
                        window.location.href = 'index.php';
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
        <?php endif; ?>
    </div>
</body>
</html>
