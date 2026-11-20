<?php
/**
 * SSO Fallback Confirmation Page
 * Shown when an end-user without an IT Analyst account signs in at the Analyst login screen.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branding.php';

if (empty($_SESSION['sso_pending_portal']) || empty($_SESSION['sso_portal_csrf'])) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'auth/login.php');
    exit;
}

$pending = $_SESSION['sso_pending_portal'];
$email   = $pending['email'] ?? '';
$csrf    = $_SESSION['sso_portal_csrf'];

$brandNone = isset($_GET['nobranding']);
$brand     = $brandNone ? null : brandingLoginDesign();
$brandLogo = $brandNone ? ((defined('BASE_URL') ? BASE_URL : '/') . BRANDING_DEFAULT_LOGO) : brandingLogoUrl();

$i18nReady = false;
try {
    if (is_file(__DIR__ . '/../includes/i18n.php')) {
        require_once __DIR__ . '/../includes/i18n.php';
        I18n::initFromSession();
        $i18nReady = class_exists('I18n');
        if (!headers_sent()) header('Vary: Accept-Language');
    }
} catch (Throwable $e) {
    $i18nReady = false;
}

function tr(string $key, string $default, array $params = []): string {
    global $i18nReady;
    if ($i18nReady && class_exists('I18n')) {
        $trans = t('auth.' . $key, $params);
        if ($trans !== 'auth.' . $key) return $trans;
    }
    $res = $default;
    foreach ($params as $k => $v) {
        $res = str_replace('{' . $k . '}', (string)$v, $res);
    }
    return $res;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($i18nReady ? I18n::getLocale() : 'en'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle(null, tr('sso_fallback_title', 'No analyst account'))); ?></title>
    <link rel="icon" type="image/png" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>assets/img/favicon.png">
    <link rel="stylesheet" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>assets/css/theme.css">
    <link rel="stylesheet" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>assets/css/branding.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--login-bg, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 440px;
            text-align: center;
        }
        .login-header {
            margin-bottom: 24px;
        }
        .login-header img {
            max-width: 160px;
            max-height: 60px;
            height: auto;
            margin-bottom: 16px;
            object-fit: contain;
        }
        .fallback-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background: #eef2ff;
            color: #4f46e5;
            border-radius: 50%;
            margin-bottom: 16px;
        }
        .fallback-icon svg {
            width: 28px;
            height: 28px;
        }
        h1 {
            color: #1f2937;
            font-size: var(--font-size-3xl, 20px);
            font-weight: 700;
            margin-bottom: 12px;
        }
        .fallback-notice {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 20px;
            font-size: var(--font-size-md, 13px);
            color: #4b5563;
            line-height: 1.5;
            text-align: left;
        }
        .fallback-notice strong {
            color: #111827;
            word-break: break-all;
        }
        .fallback-prompt {
            color: #4b5563;
            font-size: var(--font-size-lg, 14px);
            line-height: 1.5;
            margin-bottom: 24px;
            text-align: left;
        }
        .login-button {
            width: 100%;
            padding: 12px;
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: var(--font-size-xl, 16px);
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            display: inline-block;
            text-decoration: none;
        }
        .login-button:hover {
            background: #4338ca;
        }
        .cancel-link {
            display: inline-block;
            margin-top: 16px;
            color: #6b7280;
            text-decoration: none;
            font-size: var(--font-size-md, 13px);
            transition: color 0.15s;
        }
        .cancel-link:hover {
            color: #111827;
            text-decoration: underline;
        }
    </style>
</head>
<body<?php if ($brand): ?> data-form-pos="<?php echo htmlspecialchars($brand['form_position']); ?>" data-card="<?php echo htmlspecialchars($brand['card_style']); ?>" data-logo-pos="<?php echo htmlspecialchars($brand['logo_position']); ?>" style="<?php echo htmlspecialchars(brandingLoginCss($brand)); ?>"<?php endif; ?>>
    <div class="login-container">
        <div class="login-header">
            <img src="<?php echo htmlspecialchars($brandLogo); ?>" alt="Company Logo">

            <h1><?php echo htmlspecialchars(tr('sso_fallback_heading', 'No analyst account')); ?></h1>
        </div>

        <div class="fallback-notice">
            <?php echo tr('sso_fallback_desc', 'You signed in as <strong>{email}</strong>. There is no analyst account for this address.', ['email' => htmlspecialchars($email)]); ?>
        </div>

        <p class="fallback-prompt">
            <?php echo htmlspecialchars(tr('sso_fallback_prompt', 'Would you like to proceed to the Self-Service Portal to submit or view support tickets, access assigned equipment, or complete training?')); ?>
        </p>

        <form method="POST" action="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>api/auth/oidc_callback.php">
            <input type="hidden" name="action" value="confirm_portal_proceed">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <button type="submit" class="login-button"><?php echo htmlspecialchars(tr('sso_fallback_btn_continue', 'Continue')); ?></button>
        </form>

        <a href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>auth/login.php?cancel_sso=1" class="cancel-link"><?php echo htmlspecialchars(tr('sso_fallback_btn_signout', 'Cancel')); ?></a>
    </div>
</body>
</html>
