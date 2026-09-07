<?php
/**
 * Self-Service Portal — ask for a link to set a password (GH #134).
 *
 * ⚠️ NOT gated on selfServiceRegistrationEnabled(). That setting decides whether
 * strangers may create accounts; this page is for people who already HAVE one.
 * Gating it would recreate the reported bug exactly — a service desk that creates
 * its customers by hand keeps self-registration off, and those are precisely the
 * accounts with no password and no way to get one.
 */
session_start();

if (isset($_SESSION['ss_user_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/branding.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

$translationNamespaces = ['common', 'self-service'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('self-service.forgot.title')); ?></title>
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
            /* 16px, ungated — iOS zooms on any field under 16px and stays zoomed,
               and it depends on the DEVICE rather than the viewport width. */
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #667eea; }
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
            <h1><?php echo htmlspecialchars(t('self-service.forgot.heading')); ?></h1>
            <p><?php echo htmlspecialchars(t('self-service.forgot.subtitle')); ?></p>
        </div>

        <div class="error-message" id="errorMsg"></div>
        <div class="success-message" id="successMsg"></div>

        <form id="forgotForm" onsubmit="return handleForgot(event)">
            <div class="form-group">
                <label for="email"><?php echo htmlspecialchars(t('self-service.forgot.email')); ?></label>
                <input type="email" id="email" required autofocus autocomplete="email">
            </div>
            <button type="submit" class="login-button" id="forgotBtn"><?php echo htmlspecialchars(t('self-service.forgot.submit')); ?></button>
        </form>

        <div class="login-links">
            <a href="login.php"><?php echo htmlspecialchars(t('self-service.forgot.back_to_login')); ?></a>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>
    async function handleForgot(e) {
        e.preventDefault();
        const btn = document.getElementById('forgotBtn');
        const errEl = document.getElementById('errorMsg');
        const okEl = document.getElementById('successMsg');
        errEl.style.display = 'none';
        okEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = t('self-service.forgot.sending');

        try {
            const resp = await fetch('../api/self-service/request_password_reset.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: document.getElementById('email').value.trim() })
            });
            const data = await resp.json();
            if (data.success) {
                // The form goes away on success. Leaving it would invite a second
                // press, which invalidates the link in the email already sent.
                document.getElementById('forgotForm').style.display = 'none';
                okEl.textContent = data.message || '';
                okEl.style.display = 'block';
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = t('self-service.forgot.submit');
            }
        } catch (err) {
            errEl.textContent = t('self-service.forgot.failed');
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = t('self-service.forgot.submit');
        }
    }
    </script>
</body>
</html>
