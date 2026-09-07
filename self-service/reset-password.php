<?php
/**
 * Self-Service Portal — set a password from an emailed link (GH #134).
 *
 * ⚠️ The token is NOT validated here. This page only decides whether to draw a
 * form; api/self-service/reset_password.php is what accepts or refuses it, in one
 * statement, at the moment it is spent. Checking here as well would mean two
 * places that can disagree about what a valid token is, and the page is the half
 * an attacker can see. All this does is refuse to render a form for something
 * that is not even token-shaped.
 */
session_start();

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/branding.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

$translationNamespaces = ['common', 'self-service'];
$token = $_GET['token'] ?? '';
$tokenLooksValid = (bool)preg_match('/^[a-f0-9]{64}$/', $token);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('self-service.reset.title')); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header img { width: 250px; max-width: 100%; height: auto; margin-bottom: 25px; }
        .login-header h1 { color: #333; font-size: 24px; margin-bottom: 6px; }
        .login-header p { color: #888; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            /* 16px, ungated — see forgot-password.php. */
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #667eea; }
        .form-hint { font-size: 12px; color: #999; margin-top: 4px; }
        .error-message {
            background: #fee; color: #c33; padding: 12px; border-radius: 5px;
            margin-bottom: 20px; font-size: 14px; border-left: 4px solid #c33; display: none;
        }
        .success-message {
            background: #d4edda; color: #155724; padding: 12px; border-radius: 5px;
            margin-bottom: 20px; font-size: 14px; border-left: 4px solid #155724; display: none;
        }
        .login-button {
            width: 100%; padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border: none; border-radius: 5px;
            font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s;
        }
        .login-button:hover { transform: translateY(-2px); }
        .login-button:active { transform: translateY(0); }
        .login-button:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .login-links { text-align: center; margin-top: 20px; font-size: 14px; }
        .login-links a { color: #667eea; text-decoration: none; }
        .login-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="<?php echo htmlspecialchars(brandingLogoUrl()); ?>" alt="Company Logo">
            <h1><?php echo htmlspecialchars(t('self-service.reset.heading')); ?></h1>
            <p><?php echo htmlspecialchars(t('self-service.reset.subtitle')); ?></p>
        </div>

        <div class="error-message" id="errorMsg"<?php if (!$tokenLooksValid) echo ' style="display:block"'; ?>><?php
            if (!$tokenLooksValid) echo htmlspecialchars(t('self-service.reset.no_token'));
        ?></div>
        <div class="success-message" id="successMsg"></div>

        <?php if ($tokenLooksValid): ?>
        <form id="resetForm" onsubmit="return handleReset(event)">
            <input type="hidden" id="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
            <div class="form-group">
                <label for="password"><?php echo htmlspecialchars(t('self-service.reset.password')); ?></label>
                <input type="password" id="password" required minlength="8" autofocus autocomplete="new-password">
                <div class="form-hint"><?php echo htmlspecialchars(t('self-service.reset.password_hint')); ?></div>
            </div>
            <div class="form-group">
                <label for="confirmPassword"><?php echo htmlspecialchars(t('self-service.reset.confirm_password')); ?></label>
                <input type="password" id="confirmPassword" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="login-button" id="resetBtn"><?php echo htmlspecialchars(t('self-service.reset.submit')); ?></button>
        </form>
        <?php endif; ?>

        <div class="login-links">
            <?php if (!$tokenLooksValid): ?>
                <a href="forgot-password.php"><?php echo htmlspecialchars(t('self-service.reset.request_new')); ?></a>
            <?php else: ?>
                <a href="login.php"><?php echo htmlspecialchars(t('self-service.reset.back_to_login')); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>
    async function handleReset(e) {
        e.preventDefault();
        const btn = document.getElementById('resetBtn');
        const errEl = document.getElementById('errorMsg');
        const okEl = document.getElementById('successMsg');
        errEl.style.display = 'none';
        okEl.style.display = 'none';

        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        if (password !== confirmPassword) {
            errEl.textContent = t('self-service.reset.passwords_mismatch');
            errEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.textContent = t('self-service.reset.saving');

        try {
            const resp = await fetch('../api/self-service/reset_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: document.getElementById('token').value,
                    password: password,
                    confirm_password: confirmPassword
                })
            });
            const data = await resp.json();
            if (data.success) {
                // The token is spent, so the form must not come back — a second
                // press could only ever fail, and would read as the reset failing.
                document.getElementById('resetForm').style.display = 'none';
                okEl.textContent = data.message || '';
                okEl.style.display = 'block';
                setTimeout(function () { window.location.href = 'login.php'; }, 2500);
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = t('self-service.reset.submit');
            }
        } catch (err) {
            errEl.textContent = t('self-service.reset.failed');
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = t('self-service.reset.submit');
        }
    }
    </script>
</body>
</html>
