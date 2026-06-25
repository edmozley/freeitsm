<?php
/**
 * Self-Service Portal Login Page
 */
session_start();

// Already logged in - redirect to dashboard
if (isset($_SESSION['ss_user_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/tenancy.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

$translationNamespaces = ['common', 'self-service'];

// An SSO sign-in attempt that failed bounces back here with a message.
$sso_error = $_SESSION['sso_error'] ?? null;
unset($_SESSION['sso_error']);

// Work out SSO / local availability for the email-first router (mirrors the
// analyst login). At N=1 with SSO off this all collapses to the local form.
$ssoProviders = [];
$ssoOn = false; $localOn = true; $multiTenant = false;
try {
    $ssoConn = connectToDatabase();
    $cfg = [];
    foreach ($ssoConn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sso_enabled','local_login_enabled')") as $r) {
        $cfg[$r['setting_key']] = $r['setting_value'];
    }
    $ssoOn   = ($cfg['sso_enabled'] ?? '0') === '1';
    $localOn = ($cfg['local_login_enabled'] ?? '1') !== '0';
    if ($ssoOn) {
        // Only GLOBAL providers are shown up front (single-company installs).
        // On a multi-tenant install we never list providers up front — the
        // email-first router reveals only the requester's own company's IdP(s).
        $ssoProviders = $ssoConn->query("SELECT id, display_name FROM auth_providers WHERE enabled = 1 AND tenant_id IS NULL ORDER BY sort_order, display_name")->fetchAll(PDO::FETCH_ASSOC);
    }
    $multiTenant = isMultiTenant($ssoConn);
} catch (Exception $e) { $ssoProviders = []; }
// On a multi-tenant install the router is active whenever SSO is on (companies
// own their own providers, resolved per-email — there may be no global ones).
$ssoActive = $ssoOn && ($multiTenant || !empty($ssoProviders));
// Break-glass: ?local=1 always reveals the local form, even when local login is "off".
$forceLocal   = isset($_GET['local']);
$localAllowed = $localOn || $forceLocal;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('self-service.login.title')); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
            font-size: 24px;
            margin-bottom: 6px;
        }
        .login-header p {
            color: #888;
            font-size: 14px;
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
            display: none;
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
        .login-button:hover { transform: translateY(-2px); }
        .login-button:active { transform: translateY(0); }
        .login-button:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .login-links {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .login-links a {
            color: #667eea;
            text-decoration: none;
        }
        .login-links a:hover { text-decoration: underline; }
        .login-links .divider {
            color: #ccc;
            margin: 0 8px;
        }

        /* MFA challenge */
        .mfa-section { display: none; }
        .mfa-section.active { display: block; }
        .mfa-icon {
            text-align: center;
            margin-bottom: 16px;
        }
        .mfa-icon svg { color: #667eea; }
        .mfa-desc {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .otp-input {
            font-size: 22px !important;
            letter-spacing: 8px;
            text-align: center;
            font-family: 'Consolas', monospace;
        }
        .mfa-back {
            text-align: center;
            margin-top: 16px;
        }
        .mfa-back a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            cursor: pointer;
        }
        .mfa-back a:hover { text-decoration: underline; }

        /* Email-first SSO router */
        .sso-divider {
            display: flex; align-items: center; gap: 10px;
            margin: 20px 0 14px; color: #9aa; font-size: 12px;
        }
        .sso-divider span { flex: 1; height: 1px; background: #ddd; }
        .sso-provider-btn {
            display: block; text-align: center; padding: 11px; margin-bottom: 8px;
            border: 1px solid #cfd8dc; border-radius: 6px; color: #37474f;
            text-decoration: none; font-weight: 600; font-size: 14px; background: #fff;
        }
        .sso-provider-btn:hover { background: #f7f9fa; }
        .ss-text-link {
            display: block; text-align: center; margin-top: 14px;
            color: #999; text-decoration: none; font-size: 13px; cursor: pointer;
        }
        .ss-text-link:hover { color: #666; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="../assets/images/CompanyLogo.png" alt="Company Logo">
            <h1><?php echo htmlspecialchars(t('self-service.login.heading')); ?></h1>
            <p id="loginSubtitle"><?php echo htmlspecialchars(t('self-service.login.subtitle')); ?></p>
        </div>

        <div class="error-message" id="errorMsg"<?php if ($sso_error): ?> style="display:block;"<?php endif; ?>><?php echo $sso_error ? htmlspecialchars($sso_error) : ''; ?></div>

        <!-- Login Form -->
        <div id="loginSection">
            <form id="loginForm" onsubmit="return handleLogin(event)" autocomplete="off">
                <div class="form-group">
                    <label for="email"><?php echo htmlspecialchars(t('self-service.login.email')); ?></label>
                    <input type="email" id="email" required autofocus autocomplete="off">
                </div>
                <div class="form-group" id="passwordGroup"<?php if ($ssoActive): ?> style="display:none;"<?php endif; ?>>
                    <label for="password"><?php echo htmlspecialchars(t('self-service.login.password')); ?></label>
                    <input type="password" id="password" autocomplete="off">
                </div>
                <?php if ($ssoActive): ?>
                    <button type="button" class="login-button" id="continueBtn"><?php echo htmlspecialchars(t('self-service.login.continue')); ?></button>
                <?php endif; ?>
                <button type="submit" class="login-button" id="loginBtn"<?php if ($ssoActive): ?> style="display:none;"<?php endif; ?>><?php echo htmlspecialchars(t('self-service.login.sign_in')); ?></button>
            </form>

            <?php if ($ssoActive): ?>
                <!-- Multi-tenant: provider buttons for the resolved company are injected here. -->
                <div id="ssoPicker"></div>

                <?php if (!$multiTenant): ?>
                    <!-- Single-company: providers are global, so show them up front. -->
                    <div class="sso-divider"><span></span><?php echo htmlspecialchars(t('self-service.login.or')); ?><span></span></div>
                    <?php foreach ($ssoProviders as $p): ?>
                        <a class="sso-provider-btn" href="../api/auth/oidc_login.php?provider=<?php echo (int)$p['id']; ?>&amp;portal=self-service"><?php echo htmlspecialchars($p['display_name']); ?></a>
                    <?php endforeach; ?>
                    <?php if ($localAllowed): ?>
                        <a href="#" id="showLocalLink" class="ss-text-link"><?php echo htmlspecialchars(t('self-service.login.use_local_account')); ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <div class="login-links">
                <a href="register.php"><?php echo htmlspecialchars(t('self-service.login.create_account')); ?></a>
                <span class="divider">|</span>
                <a href="../login.php"><?php echo htmlspecialchars(t('self-service.login.analyst_login')); ?></a>
            </div>
        </div>

        <!-- MFA Challenge -->
        <div id="mfaSection" class="mfa-section">
            <div class="mfa-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </div>
            <div class="mfa-desc"><?php echo htmlspecialchars(t('self-service.login.mfa_desc')); ?></div>
            <form id="mfaForm" onsubmit="return handleMfa(event)" autocomplete="off">
                <div class="form-group">
                    <input type="text" id="otpCode" class="otp-input" maxlength="6" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" required>
                </div>
                <button type="submit" class="login-button" id="mfaBtn"><?php echo htmlspecialchars(t('self-service.login.verify')); ?></button>
            </form>
            <div class="mfa-back">
                <a onclick="backToLogin()"><?php echo htmlspecialchars(t('self-service.login.back_to_login')); ?></a>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <script>
    async function handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        const errEl = document.getElementById('errorMsg');
        errEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = t('self-service.login.signing_in');

        try {
            const resp = await fetch('../api/self-service/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: document.getElementById('email').value.trim(),
                    password: document.getElementById('password').value
                })
            });
            const data = await resp.json();
            if (data.success) {
                if (data.mfa_required) {
                    // Show MFA form
                    document.getElementById('loginSection').style.display = 'none';
                    document.getElementById('mfaSection').classList.add('active');
                    document.getElementById('loginSubtitle').textContent = t('self-service.login.subtitle_mfa');
                    setTimeout(() => document.getElementById('otpCode').focus(), 100);
                } else {
                    window.location.href = 'index.php';
                }
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = t('self-service.login.sign_in');
            }
        } catch (err) {
            errEl.textContent = t('self-service.login.login_failed');
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = t('self-service.login.sign_in');
        }
    }

    async function handleMfa(e) {
        e.preventDefault();
        const btn = document.getElementById('mfaBtn');
        const errEl = document.getElementById('errorMsg');
        errEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = t('self-service.login.verifying');

        try {
            const resp = await fetch('../api/self-service/verify_login_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    code: document.getElementById('otpCode').value.trim()
                })
            });
            const data = await resp.json();
            if (data.success) {
                window.location.href = 'index.php';
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = t('self-service.login.verify');
                document.getElementById('otpCode').value = '';
                document.getElementById('otpCode').focus();
            }
        } catch (err) {
            errEl.textContent = t('self-service.login.verify_failed');
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = t('self-service.login.verify');
        }
    }

    function backToLogin() {
        document.getElementById('mfaSection').classList.remove('active');
        document.getElementById('loginSection').style.display = '';
        document.getElementById('loginSubtitle').textContent = t('self-service.login.subtitle');
        document.getElementById('errorMsg').style.display = 'none';
        document.getElementById('loginBtn').disabled = false;
        document.getElementById('loginBtn').textContent = t('self-service.login.sign_in');
        document.getElementById('password').value = '';
        document.getElementById('otpCode').value = '';
    }
<?php if ($ssoActive): ?>
    // --- Email-first SSO router: type email -> routed to your provider, or fall
    // back to the local password form. Mirrors the analyst login page. ---
    (function () {
        var localAllowed  = <?php echo $localAllowed ? 'true' : 'false'; ?>;
        var emailEl       = document.getElementById('email');
        var pwGroup       = document.getElementById('passwordGroup');
        var continueBtn   = document.getElementById('continueBtn');
        var loginBtn      = document.getElementById('loginBtn');
        var showLocalLink = document.getElementById('showLocalLink');
        var picker        = document.getElementById('ssoPicker');
        var errEl         = document.getElementById('errorMsg');
        var revealed      = false;

        function clearPicker() { if (picker) picker.innerHTML = ''; }

        function revealLocal() {
            revealed = true;
            clearPicker();
            if (pwGroup) pwGroup.style.display = '';
            if (continueBtn) continueBtn.style.display = 'none';
            if (loginBtn) loginBtn.style.display = '';
            var pw = document.getElementById('password');
            if (pw) pw.focus();
        }

        // Company has 2+ IdPs and we can't tell which is yours → let you pick.
        function renderPicker(providers) {
            if (!picker) return;
            if (continueBtn) continueBtn.style.display = 'none';
            picker.innerHTML = '';
            var div = document.createElement('div');
            div.className = 'sso-divider';
            div.appendChild(document.createElement('span'));
            div.appendChild(document.createTextNode(t('self-service.login.choose_method')));
            div.appendChild(document.createElement('span'));
            picker.appendChild(div);
            providers.forEach(function (p) {
                var a = document.createElement('a');
                a.className = 'sso-provider-btn';
                a.href = '../api/auth/oidc_login.php?provider=' + encodeURIComponent(p.id) + '&portal=self-service';
                a.textContent = p.name;
                picker.appendChild(a);
            });
            if (localAllowed) {
                var l = document.createElement('a');
                l.href = '#';
                l.className = 'ss-text-link';
                l.textContent = t('self-service.login.use_local_account');
                l.addEventListener('click', function (e) { e.preventDefault(); revealLocal(); });
                picker.appendChild(l);
            }
        }

        async function resolve() {
            var email = (emailEl.value || '').trim();
            if (!email) {
                errEl.textContent = t('self-service.login.enter_email');
                errEl.style.display = 'block';
                return;
            }
            errEl.style.display = 'none';
            clearPicker();
            if (continueBtn) continueBtn.disabled = true;
            try {
                var r = await fetch('../api/auth/resolve_login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: email, portal: 'self-service' })
                });
                var d = await r.json();
                if (d && d.mode === 'sso' && d.provider_id) {
                    window.location = '../api/auth/oidc_login.php?provider=' + d.provider_id + '&portal=self-service';
                    return;
                }
                if (d && d.mode === 'choose' && Array.isArray(d.providers) && d.providers.length) {
                    renderPicker(d.providers);
                    if (continueBtn) continueBtn.disabled = false;
                    return;
                }
            } catch (e) { /* fall through to local */ }
            if (localAllowed) {
                revealLocal();
            } else {
                errEl.textContent = t('self-service.login.no_sso_for_email');
                errEl.style.display = 'block';
            }
            if (continueBtn) continueBtn.disabled = false;
        }

        if (continueBtn) continueBtn.addEventListener('click', resolve);
        if (showLocalLink) showLocalLink.addEventListener('click', function (e) { e.preventDefault(); revealLocal(); });
        // Enter in the email field continues, rather than submitting an empty password.
        if (emailEl) emailEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !revealed) { e.preventDefault(); resolve(); }
        });
        <?php if ($forceLocal): ?>
        // Break-glass ?local=1 → straight to the local form.
        revealLocal();
        <?php endif; ?>
    })();
<?php endif; ?>
    </script>
</body>
</html>
