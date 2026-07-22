<?php
/**
 * FreeITSM Setup Verification
 * Checks that the system is correctly configured before going live.
 * DELETE THIS FOLDER once your system is in production.
 */

session_start();
$_SESSION['setup_access'] = true;

require_once __DIR__ . '/../includes/i18n.php';
I18n::initFromSession();

$checks = [];
$dbConnected = false;

// 1. Check config.php exists
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    $checks[] = ['name' => t('setup.checks.config'), 'status' => 'pass', 'detail' => t('setup.detail.found')];

    // Extract $db_config_path by reading config.php as text (don't include it yet — require_once would fatal)
    $db_config_path = null;
    $configContents = file_get_contents($configPath);
    if (preg_match('/\$db_config_path\s*=\s*[\'"](.+?)[\'"]\s*;/', $configContents, $matches)) {
        $db_config_path = $matches[1];
    }

    // 2. Check db_config.php exists
    if ($db_config_path) {
        if (file_exists($db_config_path)) {
            $checks[] = ['name' => t('setup.checks.db_config'), 'status' => 'pass', 'detail' => $db_config_path];

            // Safe to include config.php now (require_once inside it will succeed)
            require_once $configPath;
            require_once __DIR__ . '/../includes/functions.php';
        } else {
            $checks[] = ['name' => t('setup.checks.db_config'), 'status' => 'fail', 'detail' => t('setup.detail.db_config_not_found', ['path' => $db_config_path])];
        }
    } else {
        $checks[] = ['name' => t('setup.checks.db_config'), 'status' => 'fail', 'detail' => t('setup.detail.db_config_path_unset')];
    }

    // 3. Database connection
    if (function_exists('connectToDatabase') && defined('DB_SERVER')) {
        try {
            $conn = connectToDatabase();
            $dbConnected = true;
            // Identify which driver connected
            $driverInfo = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $checks[] = ['name' => t('setup.checks.db_connection'), 'status' => 'pass', 'detail' => t('setup.detail.db_connected', ['driver' => $driverInfo])];

        } catch (Exception $e) {
            $checks[] = ['name' => t('setup.checks.db_connection'), 'status' => 'fail', 'detail' => $e->getMessage()];
        }
    } else {
        $checks[] = ['name' => t('setup.checks.db_connection'), 'status' => 'fail', 'detail' => t('setup.detail.db_constants_undefined')];
    }

    // 4. Encryption key
    $encryptionPath = __DIR__ . '/../includes/encryption.php';
    if (file_exists($encryptionPath)) {
        require_once $encryptionPath;
    }
    if (defined('ENCRYPTION_KEY_PATH')) {
        if (file_exists(ENCRYPTION_KEY_PATH)) {
            $checks[] = ['name' => t('setup.checks.encryption_key'), 'status' => 'pass', 'detail' => ENCRYPTION_KEY_PATH];
        } else {
            $checks[] = ['name' => t('setup.checks.encryption_key'), 'status' => 'warn', 'detail' => t('setup.detail.encryption_key_missing', ['path' => ENCRYPTION_KEY_PATH])];
        }
    } else {
        $checks[] = ['name' => t('setup.checks.encryption_key'), 'status' => 'warn', 'detail' => t('setup.detail.encryption_key_undefined')];
    }

    // 5. HTTPS certificate verification — is it on, AND can the server actually do it?
    // A "pass" for the setting alone is hollow now that verification ships ON: the
    // real failure is verify-on with no working CA bundle, which silently breaks
    // every outbound call (mail, AI, webhooks, sign-in). So we run a live probe
    // through the same sslApplyCurl() path the app uses, and tell cert failures
    // (actionable — configure a bundle) apart from no-network (inconclusive).
    // Any problem state links to the HTTPS-certificates wiki page for the fix.
    $sslHelp = 'https://github.com/edmozley/freeitsm/wiki/HTTPS-Certificates-and-CA-Bundles';
    if (!defined('SSL_VERIFY_PEER')) {
        $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'warn', 'detail' => t('setup.detail.ssl_undefined'), 'help' => $sslHelp];
    } elseif (!SSL_VERIFY_PEER) {
        $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'warn', 'detail' => t('setup.detail.ssl_disabled'), 'help' => $sslHelp];
    } elseif (function_exists('curl_init') && function_exists('sslApplyCurl')) {
        $ch = curl_init('https://curl.se/');
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        sslApplyCurl($ch);
        $sslOk    = curl_exec($ch) !== false;
        $sslErrno = curl_errno($ch);
        $sslErr   = curl_error($ch);
        curl_close($ch);
        $bundle = (defined('SSL_CA_BUNDLE') && SSL_CA_BUNDLE !== '') ? SSL_CA_BUNDLE : t('setup.detail.ssl_bundle_system');
        // cert-trust failures: no/invalid CA bundle, or the peer failed verification.
        // Build from constants that actually exist — not every CURLE_* is defined in
        // every PHP build (CURLE_PEER_FAILED_VERIFICATION isn't). The message match
        // below is the real workhorse; the errno list is belt-and-braces.
        $certErrnos = [];
        foreach (['CURLE_SSL_CACERT', 'CURLE_SSL_CACERT_BADFILE', 'CURLE_SSL_CONNECT_ERROR', 'CURLE_PEER_FAILED_VERIFICATION'] as $ce) {
            if (defined($ce)) { $certErrnos[] = constant($ce); }
        }
        if ($sslOk) {
            $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'pass', 'detail' => t('setup.detail.ssl_verified', ['bundle' => $bundle])];
        } elseif (in_array($sslErrno, $certErrnos, true) || stripos($sslErr, 'certificate') !== false) {
            $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'fail', 'detail' => t('setup.detail.ssl_broken', ['error' => $sslErr]), 'help' => $sslHelp];
        } else {
            // DNS / connection refused / timeout — inconclusive, don't cry wolf.
            $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'warn', 'detail' => t('setup.detail.ssl_untested', ['error' => $sslErr]), 'help' => $sslHelp];
        }
    } else {
        $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'pass', 'detail' => t('setup.detail.ssl_enabled')];
    }

    // 5b. CA bundle configured in php.ini (curl.cainfo / openssl.cafile). Now that
    // FreeITSM ships its own bundle this is optional, but it's useful to see the
    // state — especially a path that IS set but points at a missing file, the
    // classic silent misconfiguration. Reflects the WEB SERVER's PHP; the
    // background worker runs under a separate CLI php.ini that this can't see.
    $curlCa   = ini_get('curl.cainfo');
    $osslCa   = ini_get('openssl.cafile');
    $curlCaOk = $curlCa && is_readable($curlCa);
    $osslCaOk = $osslCa && is_readable($osslCa);
    $curlDisp = $curlCa ? ($curlCaOk ? $curlCa : t('setup.detail.ca_ini_missing', ['path' => $curlCa])) : t('setup.detail.ca_ini_none');
    $osslDisp = $osslCa ? ($osslCaOk ? $osslCa : t('setup.detail.ca_ini_missing', ['path' => $osslCa])) : t('setup.detail.ca_ini_none');
    $caIniDetail = t('setup.detail.ca_ini_status', ['curl' => $curlDisp, 'ossl' => $osslDisp]);
    if (($curlCa && !$curlCaOk) || ($osslCa && !$osslCaOk)) {
        // Set, but pointing at a file that isn't there — a real misconfiguration.
        $checks[] = ['name' => t('setup.checks.ca_bundle_ini'), 'status' => 'warn', 'detail' => $caIniDetail . t('setup.detail.ca_ini_note_fix'), 'help' => $sslHelp];
    } elseif ($curlCaOk && $osslCaOk) {
        $checks[] = ['name' => t('setup.checks.ca_bundle_ini'), 'status' => 'pass', 'detail' => $caIniDetail];
    } else {
        // Not set — fine, the app falls back to its bundled cert / the OS store.
        $checks[] = ['name' => t('setup.checks.ca_bundle_ini'), 'status' => 'pass', 'detail' => $caIniDetail . t('setup.detail.ca_ini_note_fallback')];
    }

    // 5. Display errors
    if (ini_get('display_errors') && ini_get('display_errors') !== 'Off') {
        $checks[] = ['name' => t('setup.checks.display_errors'), 'status' => 'warn', 'detail' => t('setup.detail.display_errors_enabled')];
    } else {
        $checks[] = ['name' => t('setup.checks.display_errors'), 'status' => 'pass', 'detail' => t('setup.detail.display_errors_disabled')];
    }

} else {
    $checks[] = ['name' => t('setup.checks.config'), 'status' => 'fail', 'detail' => t('setup.detail.config_not_found')];
}

// 6. PHP version
// PHP_VERSION_ID, not (float)phpversion(): the float cast reads "8.10.0" as 8.1,
// so it silently mis-orders the moment PHP ships an x.10.
$phpVersion = phpversion();
if (PHP_VERSION_ID < 70400) {
    $checks[] = ['name' => t('setup.checks.php_version'), 'status' => 'fail', 'detail' => t('setup.detail.php_version_too_low', ['version' => $phpVersion])];
} elseif (PHP_VERSION_ID < 80100) {
    // 7.4 and 8.0 still run, but neither has had an upstream security fix since
    // Nov 2022 / Nov 2023. Warn rather than fail — we still support them.
    $checks[] = ['name' => t('setup.checks.php_version'), 'status' => 'warn', 'detail' => t('setup.detail.php_version_eol', ['version' => $phpVersion])];
} else {
    $checks[] = ['name' => t('setup.checks.php_version'), 'status' => 'pass', 'detail' => t('setup.detail.php_version_ok', ['version' => $phpVersion])];
}

// 7. PHP extensions (always check, regardless of config)
$requiredExtensions = ['pdo', 'curl', 'openssl', 'mbstring'];
$mysqlLoaded = extension_loaded('pdo_mysql');
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        $checks[] = ['name' => t('setup.checks.php_extension', ['ext' => $ext]), 'status' => 'pass', 'detail' => t('setup.detail.extension_loaded')];
    } else {
        $checks[] = ['name' => t('setup.checks.php_extension', ['ext' => $ext]), 'status' => 'fail', 'detail' => t('setup.detail.extension_not_loaded')];
    }
}
if ($mysqlLoaded) {
    $checks[] = ['name' => t('setup.checks.php_extension', ['ext' => 'pdo_mysql']), 'status' => 'pass', 'detail' => t('setup.detail.extension_loaded')];
} else {
    $checks[] = ['name' => t('setup.checks.php_extension', ['ext' => 'pdo_mysql']), 'status' => 'fail', 'detail' => t('setup.detail.pdo_mysql_not_loaded')];
}

// 8. Optional extensions — absent is fine, but say so, because the feature that
// needs one fails silently otherwise. imap is the live case: PHP 8.4 unbundled
// it, so a basic IMAP/SMTP mailbox on 8.4 simply never collects mail and nothing
// on screen explains why (includes/mailbox_imap.php degrades on function_exists).
if (extension_loaded('imap')) {
    $checks[] = ['name' => t('setup.checks.php_extension_optional', ['ext' => 'imap']), 'status' => 'pass', 'detail' => t('setup.detail.extension_loaded')];
} else {
    $checks[] = ['name' => t('setup.checks.php_extension_optional', ['ext' => 'imap']), 'status' => 'warn', 'detail' => t('setup.detail.imap_not_loaded')];
}

// Count results
$passCount = count(array_filter($checks, fn($c) => $c['status'] === 'pass'));
$warnCount = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
$failCount = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
$totalCount = count($checks);

$translationNamespaces = ['common', 'setup'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('setup.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
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

        .credentials {
            margin-top: 10px;
            padding: 10px 15px;
            background: white;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            color: #333;
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

        .check-help {
            display: inline-block;
            margin-top: 4px;
            font-size: 12px;
            color: #667eea;
            text-decoration: none;
        }

        .check-help:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <img src="../assets/images/CompanyLogo.png" alt="FreeITSM">
            <h1><?= htmlspecialchars(t('setup.heading')) ?></h1>
        </div>

        <div class="summary">
            <span class="summary-badge summary-pass"><?= htmlspecialchars(t('setup.summary.passed', ['n' => $passCount])) ?></span>
            <?php if ($warnCount > 0): ?>
                <span class="summary-badge summary-warn"><?= htmlspecialchars(t($warnCount > 1 ? 'setup.summary.warnings' : 'setup.summary.warning', ['n' => $warnCount])) ?></span>
            <?php endif; ?>
            <?php if ($failCount > 0): ?>
                <span class="summary-badge summary-fail"><?= htmlspecialchars(t('setup.summary.failed', ['n' => $failCount])) ?></span>
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
                        <?php if (!empty($check['help'])): ?>
                            <a class="check-help" href="<?= htmlspecialchars($check['help']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(t('setup.detail.help_link')) ?></a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($dbConnected): ?>
        <div class="admin-section" id="dbVerifySection">
            <h2><?= htmlspecialchars(t('setup.db_verify.heading')) ?></h2>
            <p><?= htmlspecialchars(t('setup.db_verify.intro')) ?></p>
            <button type="button" class="admin-btn" id="dbVerifyBtn" onclick="runDbVerify()"><?= htmlspecialchars(t('setup.db_verify.run')) ?></button>
            <div id="dbVerifyResult" style="margin-top: 12px; display: none;"></div>
        </div>
        <?php endif; ?>

        <?php if ($dbConnected): ?>
        <div class="admin-section">
            <h2><?= htmlspecialchars(t('setup.login.heading')) ?></h2>
            <p><?= htmlspecialchars(t('setup.login.intro')) ?></p>
            <div class="credentials">
                <?= htmlspecialchars(t('setup.login.username')) ?> <strong>admin</strong><br>
                <?= htmlspecialchars(t('setup.login.password')) ?> <strong>freeitsm</strong>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer-warning">
            <?= t('setup.footer.warning', ['folder' => '<strong>/setup</strong>']) ?>
        </div>

        <div class="php-version"><?= htmlspecialchars(t('setup.footer.signature')) ?></div>
    </div>

    <script>
    async function runDbVerify() {
        const btn = document.getElementById('dbVerifyBtn');
        const result = document.getElementById('dbVerifyResult');
        btn.disabled = true;
        btn.textContent = window.t('setup.js.running');
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
                html += '<strong>' + window.t('setup.js.tables_checked', { n: data.total_tables }) + '</strong> ';
                html += window.t('setup.js.ok', { n: ok });
                if (created) html += ', ' + window.t('setup.js.created', { n: created });
                if (updated) html += ', ' + window.t('setup.js.updated', { n: updated });
                if (errors) html += ', <span style="color:#dc3545">' + window.t('setup.js.errors', { n: errors }) + '</span>';

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
                result.innerHTML = '<div style="color:#dc3545;font-size:13px;">' + (data.error || window.t('setup.js.unknown_error')) + '</div>';
                result.style.display = 'block';
            }
        } catch (e) {
            result.innerHTML = '<div style="color:#dc3545;font-size:13px;">' + window.t('setup.js.verify_failed', { error: e.message }) + '</div>';
            result.style.display = 'block';
        }

        btn.disabled = false;
        btn.textContent = window.t('setup.js.run');
    }
    </script>
</body>
</html>
