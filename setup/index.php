<?php
/**
 * FreeITSM Setup Verification
 * Checks that the system is correctly configured before going live.
 * DELETE THIS FOLDER once your system is in production.
 */

session_start();

// FIRST: this page loads includes/i18n.php before config.php, so the version guard must
// run before that file is read - see includes/php_version_guard.php. This is the page a
// new install opens first; on an old PHP it must say why, not show a parse error.
require_once __DIR__ . '/../includes/php_version_guard.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/setup_state.php';
I18n::initFromSession();

$checks = [];
$dbConnected = false;

// This page used to open with `$_SESSION['setup_access'] = true;` — a privilege flag
// handed to every anonymous visitor, which api/system/db_verify.php then accepted in
// place of a login. Loading this page was therefore enough to run schema migrations
// against a live database. The flag is gone; db_verify now asks the database directly
// whether any analyst exists. See includes/setup_state.php.
//
// What remains here is a disclosure question. The checks below name absolute filesystem
// paths, raw driver errors (which quote the host and username) and the default
// credentials. All of that is exactly what an installer needs, and equally exactly what
// an attacker would like, because this folder stays reachable until someone deletes it
// and plenty of installs never do. So detail is shown only to someone entitled to it:
// a fresh install, where nobody can log in yet and there is nothing to protect, or a
// signed-in administrator. Everyone else gets the same checks with the specifics removed.
$installUnprovisioned = false;
$setupUnlocked        = false;

/**
 * Keep the diagnostic part of a PDO connection error, drop the reconnaissance.
 * PDO reports e.g. "SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'":
 * the code is what tells an installer what to fix, the tail names the account and host.
 *
 * @param string $message  the PDOException message; '' comes back when it has no SQLSTATE prefix
 */
function setupSafeDbError(string $message): string {
    return preg_match('/^SQLSTATE\[[^\]]+\](?:\s*\[\d+\])?/', $message, $m) ? $m[0] : '';
}

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
            $checks[] = ['name' => t('setup.checks.db_config'), 'status' => 'pass', 'detail' => $db_config_path, 'safe_detail' => t('setup.detail.found')];

            // Safe to include config.php now (require_once inside it will succeed)
            require_once $configPath;
            require_once __DIR__ . '/../includes/functions.php';
        } else {
            $checks[] = ['name' => t('setup.checks.db_config'), 'status' => 'fail', 'detail' => t('setup.detail.db_config_not_found', ['path' => $db_config_path]), 'safe_detail' => t('setup.detail.db_config_not_found_masked')];
        }
    } else {
        $checks[] = ['name' => t('setup.checks.db_config'), 'status' => 'fail', 'detail' => t('setup.detail.db_config_path_unset')];
    }

    // 3. Database connection
    if (function_exists('connectToDatabase') && defined('DB_SERVER')) {
        try {
            $conn = connectToDatabase();
            $dbConnected = true;

            // Now that we can reach the database we can settle who is looking. Note the
            // deliberate asymmetry: no connection means we cannot prove this is a fresh
            // install, so both flags stay false and the page shows the redacted view.
            $installUnprovisioned = installIsUnprovisioned($conn);
            $setupUnlocked = $installUnprovisioned
                || (isset($_SESSION['analyst_id']) && analystIsAdmin($conn, (int)$_SESSION['analyst_id']));

            // Identify which driver connected
            $driverInfo = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $checks[] = ['name' => t('setup.checks.db_connection'), 'status' => 'pass', 'detail' => t('setup.detail.db_connected', ['driver' => $driverInfo])];

        } catch (Exception $e) {
            $safeError = setupSafeDbError($e->getMessage());
            $checks[] = [
                'name'        => t('setup.checks.db_connection'),
                'status'      => 'fail',
                'detail'      => $e->getMessage(),
                // The SQLSTATE/driver code alone is the actionable half (1045 = bad
                // credentials, 2002 = unreachable host) and names nobody.
                'safe_detail' => trim($safeError . ' ' . t('setup.detail.db_error_masked')),
            ];
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
            $checks[] = ['name' => t('setup.checks.encryption_key'), 'status' => 'pass', 'detail' => ENCRYPTION_KEY_PATH, 'safe_detail' => t('setup.detail.found')];
        } else {
            $checks[] = ['name' => t('setup.checks.encryption_key'), 'status' => 'warn', 'detail' => t('setup.detail.encryption_key_missing', ['path' => ENCRYPTION_KEY_PATH]), 'safe_detail' => t('setup.detail.encryption_key_missing_masked')];
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
        // {bundle} is an absolute path and {error} is a raw curl string that can carry
        // one, so all three branches need a path-free twin for the redacted view.
        if ($sslOk) {
            $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'pass', 'detail' => t('setup.detail.ssl_verified', ['bundle' => $bundle]), 'safe_detail' => t('setup.detail.ssl_verified_masked')];
        } elseif (in_array($sslErrno, $certErrnos, true) || stripos($sslErr, 'certificate') !== false) {
            $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'fail', 'detail' => t('setup.detail.ssl_broken', ['error' => $sslErr]), 'safe_detail' => t('setup.detail.ssl_broken_masked'), 'help' => $sslHelp];
        } else {
            // DNS / connection refused / timeout — inconclusive, don't cry wolf.
            $checks[] = ['name' => t('setup.checks.ssl_verify'), 'status' => 'warn', 'detail' => t('setup.detail.ssl_untested', ['error' => $sslErr]), 'safe_detail' => t('setup.detail.ssl_untested_masked'), 'help' => $sslHelp];
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
    // php.ini paths are filesystem disclosure too, so each of these carries a
    // path-free twin for the redacted view.
    if (($curlCa && !$curlCaOk) || ($osslCa && !$osslCaOk)) {
        // Set, but pointing at a file that isn't there — a real misconfiguration.
        $checks[] = ['name' => t('setup.checks.ca_bundle_ini'), 'status' => 'warn', 'detail' => $caIniDetail . t('setup.detail.ca_ini_note_fix'), 'safe_detail' => t('setup.detail.ca_ini_masked_broken'), 'help' => $sslHelp];
    } elseif ($curlCaOk && $osslCaOk) {
        $checks[] = ['name' => t('setup.checks.ca_bundle_ini'), 'status' => 'pass', 'detail' => $caIniDetail, 'safe_detail' => t('setup.detail.ca_ini_masked_ok')];
    } else {
        // Not set — fine, the app falls back to its bundled cert / the OS store.
        $checks[] = ['name' => t('setup.checks.ca_bundle_ini'), 'status' => 'pass', 'detail' => $caIniDetail . t('setup.detail.ca_ini_note_fallback'), 'safe_detail' => t('setup.detail.ca_ini_masked_ok')];
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

// 5b. Storage persistence (issue #109).
//
// 🔑 THIS IS THE MOST VALUABLE PLACE THIS CHECK APPEARS, precisely because there is
// nothing to lose yet. Everywhere else it warns somebody who already has files at
// stake; here it reaches them before the first attachment exists, when correcting
// docker-compose.yml costs nothing at all and no copying-out is needed. A problem
// prevented at install time never becomes a problem.
//
// ⚠️ Silent unless it is BOTH a container AND something is genuinely exposed. Adding
// a green "storage is fine" line for the WAMP majority, to whom the question does
// not apply, would be noise on a screen whose whole job is to be scannable.
require_once __DIR__ . '/../includes/storage_persistence.php';
$storageReport = ['applicable' => false, 'at_risk' => 0, 'directories' => [], 'critical_at_risk' => false];
try {
    $storageReport = storagePersistenceReport();
    if ($storageReport['applicable']) {
        if ($storageReport['at_risk'] > 0) {
            $names = [];
            foreach ($storageReport['directories'] as $d) {
                if ($d['status'] === 'at_risk') $names[] = $d['rel'];
            }
            // 'fail', not 'warn'. A warning invites "I will look at that later", and
            // later is after the first update, which is exactly too late.
            $checks[] = [
                'name'        => t('setup.checks.storage_persistence'),
                'status'      => 'fail',
                'detail'      => t('setup.detail.storage_at_risk', [
                    'n'    => $storageReport['at_risk'],
                    'dirs' => implode(', ', $names),
                ]),
                'safe_detail' => t('setup.detail.storage_at_risk_masked', ['n' => $storageReport['at_risk']]),
            ];
        } else {
            $checks[] = [
                'name'   => t('setup.checks.storage_persistence'),
                'status' => 'pass',
                'detail' => t('setup.detail.storage_persisted'),
            ];
        }
    }
} catch (Throwable $e) {
    // A diagnostic must never be the reason the setup screen fails to render.
}

// 6. PHP version
// PHP_VERSION_ID, not (float)phpversion(): the float cast reads "8.10.0" as 8.1,
// so it silently mis-orders the moment PHP ships an x.10.
// ⚠️ The version NUMBER is withheld from anonymous visitors (safe_detail), the
// verdict is not. setup/ is reachable without signing in — it has to be, or a fresh
// install could never be checked — and it printed the exact build ("8.2.7") to
// anybody who asked. That single string is the difference between "there is a PHP
// app here" and a list of published vulnerabilities to try, and it survived the F2
// round because that pass was looking for paths and account names, not build
// numbers. Reported by Erlend Volden.
//
// The masked twins keep the pass/warn/fail verdict, so somebody mid-install still
// learns their PHP is too old — they just have to sign in to learn which release.
$phpVersion = phpversion();
// PHP 8.1 is the floor (2026-10-25). Below it this page never gets this far -
// includes/php_version_guard.php stops first - so the fail row is for completeness.
// 8.1 and 8.2 run, but 8.1 has had no upstream security fix since Dec 2025:
// warn below 8.2 so the recommendation (8.3/8.4) is visible.
if (PHP_VERSION_ID < 80100) {
    $checks[] = ['name' => t('setup.checks.php_version'), 'status' => 'fail', 'detail' => t('setup.detail.php_version_too_low', ['version' => $phpVersion]), 'safe_detail' => t('setup.detail.php_version_too_low_masked')];
} elseif (PHP_VERSION_ID < 80200) {
    $checks[] = ['name' => t('setup.checks.php_version'), 'status' => 'warn', 'detail' => t('setup.detail.php_version_eol', ['version' => $phpVersion]), 'safe_detail' => t('setup.detail.php_version_eol_masked')];
} else {
    $checks[] = ['name' => t('setup.checks.php_version'), 'status' => 'pass', 'detail' => t('setup.detail.php_version_ok', ['version' => $phpVersion]), 'safe_detail' => t('setup.detail.php_version_ok_masked')];
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
// needs one fails silently otherwise.
// TRAP: there is no imap check any more. Basic IMAP mailboxes read through
// includes/imap_client.php, which needs no extension (GH #257, #259); before
// that, imap was the case this block was written for.
// The extensions a feature needs (GH #207 - the Docker image had none of them until
// 4.0.0). docker/php-extensions.txt lists what each one is for.
foreach (['ldap', 'zip', 'intl'] as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = ['name' => t('setup.checks.php_extension_optional', ['ext' => $ext]),
                 'status' => $loaded ? 'pass' : 'warn',
                 'detail' => t($loaded ? 'setup.detail.extension_loaded' : 'setup.detail.' . $ext . '_not_loaded')];
}

// Apply the redaction decided above. Done as a single pass over the finished list
// rather than at each call site, because the entitlement isn't known until the
// database connection has been attempted — which is itself one of the checks.
// Statuses are untouched: an anonymous visitor still sees WHICH check failed, just
// not the path, account or host involved.
if (!$setupUnlocked) {
    foreach ($checks as &$check) {
        if (isset($check['safe_detail'])) {
            $check['detail'] = $check['safe_detail'];
        }
    }
    unset($check);
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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('setup.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=4"></script>
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

        /* Shown when the checks are running in redacted form, so nobody mistakes a
           path-free "Found" for the whole answer. Informational blue, not a warning:
           nothing is wrong, there is just less on screen than an administrator sees. */
        .locked-notice {
            margin-bottom: 20px;
            padding: 12px 15px;
            background: #e7f1ff;
            border: 1px solid #b6d4fe;
            border-radius: 6px;
            font-size: 13px;
            color: #084298;
            text-align: center;
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

        /* Issue #109 — the one item on this page that costs you data if ignored. */
        .storage-danger {
            text-align: left;
            margin-bottom: 22px;
            padding: 18px 20px;
            background: #fdecea;
            border: 1px solid #f5c2c0;
            border-left: 4px solid #c0392b;
            border-radius: 6px;
            color: #7d1f16;
            font-size: 13px;
            line-height: 1.55;
        }
        .storage-danger__title { font-weight: 700; font-size: 15px; margin-bottom: 8px; }
        .storage-danger p { margin: 0 0 10px; }
        .storage-danger__critical { font-weight: 600; }
        .storage-danger__step { font-weight: 600; margin-top: 14px; margin-bottom: 6px; }
        .storage-danger__code {
            margin: 0;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid #f0cdcb;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            overflow-x: auto;
            white-space: pre;
        }
        .storage-danger__foot { margin-top: 14px; margin-bottom: 0; font-style: italic; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <img src="../assets/images/CompanyLogo.png" alt="FreeITSM">
            <h1><?= htmlspecialchars(t('setup.heading')) ?></h1>
        </div>

        <?php if (!$setupUnlocked): ?>
        <div class="locked-notice"><?= htmlspecialchars(t('setup.locked.notice')) ?></div>
        <?php endif; ?>

        <div class="summary">
            <span class="summary-badge summary-pass"><?= htmlspecialchars(t('setup.summary.passed', ['n' => $passCount])) ?></span>
            <?php if ($warnCount > 0): ?>
                <span class="summary-badge summary-warn"><?= htmlspecialchars(t($warnCount > 1 ? 'setup.summary.warnings' : 'setup.summary.warning', ['n' => $warnCount])) ?></span>
            <?php endif; ?>
            <?php if ($failCount > 0): ?>
                <span class="summary-badge summary-fail"><?= htmlspecialchars(t('setup.summary.failed', ['n' => $failCount])) ?></span>
            <?php endif; ?>
        </div>

        <?php /* Issue #109. The check above contributes a red row to the list, which is
                 enough to be noticed and nowhere near enough to be acted on: a new
                 operator has just run one command and is not expected to know what a
                 volume is, let alone where to declare one. So the fix itself is put on
                 screen, ready to paste, at the moment it costs nothing to apply.

                 Above the check list rather than below it. This is the only item on
                 this page that destroys data if ignored, and the list is long enough
                 that a red row two thirds of the way down is genuinely missable.

                 ⚠️ DELIBERATELY NOT GATED ON $setupUnlocked, which every other detail
                 on this page is. Two reasons, and the first is decisive:

                 1. $setupUnlocked is FALSE for the very person this exists for. It is
                    true only for a fresh install or a signed-in administrator, and
                    "fresh" means installIsUnprovisioned() — no analyst rows. But the
                    shipped schema SEEDS an `admin` account, so an installation is
                    never unprovisioned once its database exists. A brand-new Docker
                    operator, who has not signed in yet, is therefore shown the
                    redacted view — and the redacted view cannot tell them what to do.
                    Gating this block would switch the feature off for its whole
                    audience while appearing to work.

                 2. The disclosure rule exists to keep the HOST's filesystem layout
                    private. These paths are not that. They are the container's own
                    paths, fixed by the Dockerfile in the public repository, and this
                    block is only ever rendered inside a container. Publishing
                    /var/www/html/uploads to somebody who can read the Dockerfile on
                    GitHub tells them nothing they did not already have.

                 The absolute paths shown here come from the same report either way,
                 so nothing about the operator's own machine is revealed. */ ?>
        <?php if (!empty($storageReport['applicable']) && $storageReport['at_risk'] > 0):
                  // Which advice: nothing stored yet = just add them. Anything stored
                  // = copy it out first, or adding the volume destroys it.
                  $storageInUse = storagePersistenceAnythingToLose($storageReport); ?>
        <div class="storage-danger" role="alert">
            <div class="storage-danger__title"><?= htmlspecialchars(t('setup.storage.heading')) ?></div>
            <p><?= htmlspecialchars(t('setup.storage.explain')) ?></p>

            <?php if (!empty($storageReport['critical_at_risk'])): ?>
                <p class="storage-danger__critical"><?= htmlspecialchars(t('setup.storage.encryption_key')) ?></p>
            <?php endif; ?>

            <?php /* Nothing stored yet means the advice is simply "add these". Files
                     already in those folders means they must be copied out FIRST,
                     because a new volume is filled from the image and not from the
                     container it replaces. Two different situations, and giving
                     somebody the wrong one is how they lose files while carefully
                     following the instructions. */ ?>
            <?php if ($storageInUse): ?>
                <p class="storage-danger__critical"><?= htmlspecialchars(t('setup.storage.existing_install')) ?></p>
            <?php endif; ?>

            <?php /* A NEW file rather than an edit to docker-compose.yml. That file is
                     tracked in git, so an edit to it makes a future `git pull` abort,
                     and the usual way out of a blocked pull silently reverts the
                     volumes — putting the operator straight back here having done
                     everything right. Compose reads the override automatically and
                     merges it, and it is gitignored. */ ?>
            <p class="storage-danger__step"><?= htmlspecialchars(t('setup.storage.step1')) ?></p>
            <pre class="storage-danger__code"><?= htmlspecialchars(storagePersistenceOverrideFile($storageReport)) ?></pre>

            <p class="storage-danger__step"><?= htmlspecialchars(t('setup.storage.step2')) ?></p>
            <pre class="storage-danger__code"><?= htmlspecialchars('docker compose up -d') ?></pre>

            <p class="storage-danger__foot"><?= htmlspecialchars(t($storageInUse ? 'setup.storage.foot_in_use' : 'setup.storage.foot')) ?></p>
        </div>
        <?php endif; ?>

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

        <?php /* Offered only to whoever the endpoint itself would accept — a fresh
                 install or a signed-in administrator — so the button and the API agree. */ ?>
        <?php if ($dbConnected && $setupUnlocked): ?>
        <div class="admin-section" id="dbVerifySection">
            <h2><?= htmlspecialchars(t('setup.db_verify.heading')) ?></h2>
            <p><?= htmlspecialchars(t('setup.db_verify.intro')) ?></p>
            <button type="button" class="admin-btn" id="dbVerifyBtn" onclick="runDbVerify()"><?= htmlspecialchars(t('setup.db_verify.run')) ?></button>
            <div id="dbVerifyResult" style="margin-top: 12px; display: none;"></div>
        </div>
        <?php endif; ?>

        <?php /* The seeded password, in plain text. Shown only while the account it
                 describes might still exist unclaimed — once any analyst has been
                 created this block is no longer information, only a hint. */ ?>
        <?php if ($dbConnected && $installUnprovisioned): ?>
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
            // POST: it changes the schema, so the endpoint refuses a GET (csrf.php).
            const resp = await fetch('../api/system/db_verify.php', { method: 'POST' });
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
