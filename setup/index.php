<?php
/**
 * FreeITSM Setup Verification
 * Checks that the system is correctly configured before going live.
 * DELETE THIS FOLDER once your system is in production.
 */

$checks = [];

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
            // Identify which driver connected
            $driverInfo = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $checks[] = ['name' => 'Database connection', 'status' => 'pass', 'detail' => "Connected (driver: $driverInfo)"];
        } catch (Exception $e) {
            $checks[] = ['name' => 'Database connection', 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    } else {
        $checks[] = ['name' => 'Database connection', 'status' => 'fail', 'detail' => 'Database constants not defined — check db_config.php'];
    }

    // 4. SSL verify peer
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

        <div class="footer-warning">
            Once your system is in production, delete the <strong>/setup</strong> folder for security.
        </div>

        <div class="php-version">PHP <?= phpversion() ?></div>
    </div>
</body>
</html>
