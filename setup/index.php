<?php
/**
 * FreeITSM Setup Verification
 * Checks that the system is correctly configured before going live.
 * DELETE THIS FOLDER once your system is in production.
 */

session_start();
$_SESSION['setup_access'] = true;

$checks = [];
$adminCreated = false;
$adminError = null;
$analystCount = null;
$dbConnected = false;

// 1. Check config.php exists
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    $checks[] = ['name' => 'config.php', 'status' => 'pass', 'detail' => 'Found'];

    // Extract $db_config_path by reading config.php as text (don't include it yet — require_once would fatal)
    $db_config_path = null;
    $configContents = file_get_contents($configPath);
    if (preg_match('/\$db_config_path\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContents, $matches)) {
        $db_config_path = $matches[1];
    }

    // 2. Check db_config.php exists
    if ($db_config_path) {
        if (file_exists($db_config_path)) {
            $checks[] = ['name' => 'db_config.php', 'status' => 'pass', 'detail' => $db_config_path];

            // Safe to include config.php now (require_once inside it will succeed)
            require_once $configPath;
            require_once __DIR__ . '/../includes/functions.php';
        } else {
            $checks[] = ['name' => 'db_config.php', 'status' => 'fail', 'detail' => "Not found at: $db_config_path"];
        }
    } else {
        $checks[] = ['name' => 'db_config.php', 'status' => 'fail', 'detail' => '$db_config_path variable not set in config.php'];
    }

    // 3. Database connection
    if (function_exists('connectToDatabase') && defined('DB_SERVER')) {
        try {
            $conn = connectToDatabase();
            $dbConnected = true;
            // Identify which driver connected
            $driverInfo = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $checks[] = ['name' => 'Database connection', 'status' => 'pass', 'detail' => "Connected (driver: $driverInfo)"];

            // Check if analysts table has any rows
            try {
                $countStmt = $conn->query("SELECT COUNT(*) FROM analysts");
                $analystCount = (int) $countStmt->fetchColumn();
            } catch (Exception $e) {
                // Table may not exist yet — leave $analystCount as null
            }

            // Handle admin account creation POST
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
                if ($analystCount === 0) {
                    try {
                        $hash = password_hash('freeitsm', PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, created_datetime) VALUES (?, ?, ?, ?, 1, GETUTCDATE())");
                        $stmt->execute(['admin', $hash, 'Administrator', 'admin@localhost']);
                        $adminCreated = true;
                        $analystCount = 1;
                    } catch (Exception $e) {
                        $adminError = $e->getMessage();
                    }
                }
            }
        } catch (Exception $e) {
            $checks[] = ['name' => 'Database connection', 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    } else {
        $checks[] = ['name' => 'Database connection', 'status' => 'fail', 'detail' => 'Database constants not defined — check db_config.php'];
    }

    // 4. Encryption key
    $encryptionPath = __DIR__ . '/../includes/encryption.php';
    if (file_exists($encryptionPath)) {
        require_once $encryptionPath;
    }
    if (defined('ENCRYPTION_KEY_PATH')) {
        if (file_exists(ENCRYPTION_KEY_PATH)) {
            $checks[] = ['name' => 'Encryption key', 'status' => 'pass', 'detail' => ENCRYPTION_KEY_PATH];
        } else {
            $checks[] = ['name' => 'Encryption key', 'status' => 'warn', 'detail' => 'Not found at: ' . ENCRYPTION_KEY_PATH . ' — needed for encrypting sensitive settings'];
        }
    } else {
        $checks[] = ['name' => 'Encryption key', 'status' => 'warn', 'detail' => 'ENCRYPTION_KEY_PATH not defined in includes/encryption.php'];
    }

    // 5. SSL verify peer
    if (defined('SSL_VERIFY_PEER')) {
        if (SSL_VERIFY_PEER) {
            $checks[] = ['name' => 'SSL verify peer', 'status' => 'pass', 'detail' => 'Enabled'];
        } else {
            $checks[] = ['name' => 'SSL verify peer', 'status' => 'warn', 'detail' => 'Disabled — enable for production (set SSL_VERIFY_PEER to true in config.php)'];
        }
    } else {
        $checks[] = ['name' => 'SSL verify peer', 'status' => 'warn', 'detail' => 'SSL_VERIFY_PEER not defined in config.php'];
    }

    // 5. Display errors
    if (ini_get('display_errors') && ini_get('display_errors') !== 'Off') {
        $checks[] = ['name' => 'Display errors', 'status' => 'warn', 'detail' => 'Enabled — disable for production (set display_errors to 0 in config.php)'];
    } else {
        $checks[] = ['name' => 'Display errors', 'status' => 'pass', 'detail' => 'Disabled'];
    }

} else {
    $checks[] = ['name' => 'config.php', 'status' => 'fail', 'detail' => 'Not found — copy config.php to the application root'];
}

// 6. PHP extensions (always check, regardless of config)
$requiredExtensions = ['pdo', 'curl', 'openssl', 'mbstring'];
$odbcLoaded = extension_loaded('pdo_odbc') || extension_loaded('odbc');
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        $checks[] = ['name' => "PHP extension: $ext", 'status' => 'pass', 'detail' => 'Loaded'];
    } else {
        $checks[] = ['name' => "PHP extension: $ext", 'status' => 'fail', 'detail' => 'Not loaded — enable in php.ini'];
    }
}
if ($odbcLoaded) {
    $checks[] = ['name' => 'PHP extension: pdo_odbc', 'status' => 'pass', 'detail' => 'Loaded'];
} else {
    $checks[] = ['name' => 'PHP extension: pdo_odbc', 'status' => 'fail', 'detail' => 'Not loaded — enable pdo_odbc in php.ini'];
}

// Count results
$passCount = count(array_filter($checks, fn($c) => $c['status'] === 'pass'));
$warnCount = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
$failCount = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
$totalCount = count($checks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }

        .setup-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 650px;
        }

        .setup-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .setup-header img {
            width: 200px;
            height: auto;
            margin-bottom: 15px;
        }

        .setup-header h1 {
            color: #333;
            font-size: 22px;
            font-weight: 600;
        }

        .summary {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-bottom: 25px;
        }

        .summary-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .summary-pass { background: #d4edda; color: #155724; }
        .summary-warn { background: #fff3cd; color: #856404; }
        .summary-fail { background: #f8d7da; color: #721c24; }

        .check-list {
            list-style: none;
        }

        .check-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .check-item:last-child { border-bottom: none; }

        .check-icon {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: bold;
            margin-top: 1px;
        }

        .icon-pass { background: #d4edda; color: #28a745; }
        .icon-warn { background: #fff3cd; color: #d39e00; }
        .icon-fail { background: #f8d7da; color: #dc3545; }

        .check-info { flex-grow: 1; }

        .check-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .check-detail {
            color: #666;
            font-size: 13px;
            margin-top: 2px;
            word-break: break-word;
        }

        .check-detail.fail { color: #dc3545; }
        .check-detail.warn { color: #b8860b; }

        .admin-section {
            margin-top: 25px;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
        }

        .admin-section h2 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .admin-section p {
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
        }

        .admin-btn {
            display: inline-block;
            padding: 8px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .admin-btn:hover {
            background: #5a6fd6;
        }

        .admin-success {
            margin-top: 25px;
            padding: 20px;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 6px;
        }

        .admin-success h2 {
            font-size: 16px;
            font-weight: 600;
            color: #155724;
            margin-bottom: 8px;
        }

        .admin-success p {
            font-size: 13px;
            color: #155724;
            margin-bottom: 4px;
        }

        .admin-success .credentials {
            margin-top: 10px;
            padding: 10px 15px;
            background: white;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            color: #333;
        }

        .next-steps {
            margin-top: 12px;
        }

        .next-steps p {
            margin-bottom: 6px;
        }

        .next-steps ol {
            margin: 0;
            padding-left: 20px;
        }

        .next-steps li {
            font-size: 13px;
            color: #155724;
            padding: 2px 0;
        }

        .admin-error {
            margin-top: 25px;
            padding: 15px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            font-size: 13px;
            color: #721c24;
        }

        .footer-warning {
            margin-top: 25px;
            padding: 15px;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            font-size: 13px;
            color: #856404;
            text-align: center;
        }

        .php-version {
            text-align: center;
            color: #999;
            font-size: 12px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <img src="../assets/images/CompanyLogo.png" alt="FreeITSM">
            <h1>Setup Verification</h1>
        </div>

        <div class="summary">
            <span class="summary-badge summary-pass"><?= $passCount ?> passed</span>
            <?php if ($warnCount > 0): ?>
                <span class="summary-badge summary-warn"><?= $warnCount ?> warning<?= $warnCount > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if ($failCount > 0): ?>
                <span class="summary-badge summary-fail"><?= $failCount ?> failed</span>
            <?php endif; ?>
        </div>

        <ul class="check-list">
            <?php foreach ($checks as $check): ?>
                <li class="check-item">
                    <div class="check-icon icon-<?= $check['status'] ?>">
                        <?php if ($check['status'] === 'pass'): ?>&#10003;<?php elseif ($check['status'] === 'warn'): ?>!<?php else: ?>&#10007;<?php endif; ?>
                    </div>
                    <div class="check-info">
                        <div class="check-name"><?= htmlspecialchars($check['name']) ?></div>
                        <div class="check-detail <?= $check['status'] ?>"><?= htmlspecialchars($check['detail']) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($adminCreated): ?>
            <div class="admin-success">
                <h2>Admin account created</h2>
                <div class="credentials">
                    Username: <strong>admin</strong><br>
                    Password: <strong>freeitsm</strong>
                </div>
                <div class="next-steps">
                    <p><strong>Next steps:</strong></p>
                    <ol>
                        <li>Log in with the credentials above</li>
                        <li>Go to Tickets &rarr; Settings &rarr; Analysts</li>
                        <li>Create a new account for yourself</li>
                        <li>Delete the admin account</li>
                        <li>Log out and log back in with your new account</li>
                    </ol>
                </div>
                <a href="../login.php" target="_blank" class="admin-btn" style="margin-top: 15px; text-decoration: none;">Log in</a>
            </div>
        <?php elseif ($adminError): ?>
            <div class="admin-error">
                Failed to create admin account: <?= htmlspecialchars($adminError) ?>
            </div>
        <?php elseif ($dbConnected && $analystCount === 0): ?>
            <div class="admin-section">
                <h2>No user accounts found</h2>
                <p>The database has no analyst accounts. Create a default admin account to get started.</p>
                <form method="POST">
                    <button type="submit" name="create_admin" value="1" class="admin-btn">Create</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($dbConnected): ?>
        <div class="admin-section" id="dbVerifySection">
            <h2>Database Verify</h2>
            <p>Check and auto-create any missing tables or columns in the database.</p>
            <button type="button" class="admin-btn" id="dbVerifyBtn" onclick="runDbVerify()">Run</button>
            <div id="dbVerifyResult" style="margin-top: 12px; display: none;"></div>
        </div>
        <?php endif; ?>

        <div class="footer-warning">
            Once your system is in production, delete the <strong>/setup</strong> folder for security.
        </div>

        <div class="php-version">PHP <?= phpversion() ?></div>
    </div>

    <script>
    async function runDbVerify() {
        const btn = document.getElementById('dbVerifyBtn');
        const result = document.getElementById('dbVerifyResult');
        btn.disabled = true;
        btn.textContent = 'Running...';
        result.style.display = 'none';

        try {
            const resp = await fetch('../api/system/db_verify.php');
            const data = await resp.json();

            if (data.success) {
                let html = '<div style="font-size:13px;">';
                let created = 0, updated = 0, ok = 0, errors = 0;
                data.results.forEach(r => {
                    if (r.status === 'created') created++;
                    else if (r.status === 'updated') updated++;
                    else if (r.status === 'error') errors++;
                    else ok++;
                });
                html += '<strong>' + data.total_tables + ' tables checked:</strong> ';
                html += ok + ' OK';
                if (created) html += ', ' + created + ' created';
                if (updated) html += ', ' + updated + ' updated';
                if (errors) html += ', <span style="color:#dc3545">' + errors + ' errors</span>';

                // Show details for non-ok tables
                const changed = data.results.filter(r => r.status !== 'ok');
                if (changed.length > 0) {
                    html += '<ul style="margin-top:8px;padding-left:18px;">';
                    changed.forEach(r => {
                        const color = r.status === 'error' ? '#dc3545' : '#28a745';
                        html += '<li style="color:' + color + '">' + r.table + ': ' + r.details.join('; ') + '</li>';
                    });
                    html += '</ul>';
                }
                html += '</div>';
                result.innerHTML = html;
                result.style.display = 'block';
            } else {
                result.innerHTML = '<div style="color:#dc3545;font-size:13px;">' + (data.error || 'Unknown error') + '</div>';
                result.style.display = 'block';
            }
        } catch (e) {
            result.innerHTML = '<div style="color:#dc3545;font-size:13px;">Failed to run DB verify: ' + e.message + '</div>';
            result.style.display = 'block';
        }

        btn.disabled = false;
        btn.textContent = 'Run';
    }
    </script>
</body>
</html>
