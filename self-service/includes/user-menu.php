<?php
/**
 * Self-Service Portal - User Menu (Avatar + Dropdown + Modals)
 * Include this in the portal-header of every authenticated page.
 * Requires $ss_user_name to be set (from auth.php).
 */

// Extract initials from user name
$_um_parts = explode(' ', trim($ss_user_name));
$_um_initials = strtoupper(substr($_um_parts[0], 0, 1));
if (count($_um_parts) > 1) {
    $_um_initials .= strtoupper(substr(end($_um_parts), 0, 1));
}
?>
<style>
    /* Avatar & User Menu */
    .portal-user { position: relative; }

    .ss-user-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #1565c0;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: 2px solid rgba(255,255,255,0.3);
        transition: border-color 0.15s;
        user-select: none;
    }
    .ss-user-avatar:hover { border-color: rgba(255,255,255,0.6); }

    .ss-menu-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 1099;
        display: none;
    }
    .ss-menu-overlay.active { display: block; }

    .ss-user-menu {
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 8px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 6px 30px rgba(0,0,0,0.25);
        min-width: 240px;
        z-index: 1100;
        display: none;
        overflow: hidden;
    }
    .ss-user-menu.active { display: block; }

    .ss-menu-header {
        padding: 16px;
        border-bottom: 1px solid #eee;
    }
    .ss-menu-name {
        font-size: 14px;
        font-weight: 600;
        color: #333;
    }
    .ss-menu-email {
        font-size: 12px;
        color: #999;
        margin-top: 2px;
    }

    .ss-menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 16px;
        cursor: pointer;
        font-size: 13px;
        color: #333;
        transition: background 0.15s;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
    }
    .ss-menu-item:hover { background: #f5f5f5; }
    .ss-menu-item svg { width: 16px; height: 16px; color: #666; flex-shrink: 0; }
    .ss-menu-divider { height: 1px; background: #eee; margin: 0; }
    .ss-menu-item.logout-item { color: #d32f2f; }
    .ss-menu-item.logout-item svg { color: #d32f2f; }

    .ss-mfa-badge {
        margin-left: auto;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 3px;
    }
    .ss-mfa-badge.enabled { background: #e8f5e9; color: #2e7d32; }
    .ss-mfa-badge.disabled { background: #f5f5f5; color: #999; }

    /* Account modals */
    .ss-modal {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 2000;
        display: none;
        align-items: center;
        justify-content: center;
    }
    .ss-modal.active { display: flex; }
    .ss-modal-box {
        background: #fff;
        border-radius: 8px;
        width: 90%;
        max-width: 460px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
    }
    .ss-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e0e0e0;
        font-size: 18px;
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .ss-modal-close {
        background: none;
        border: none;
        cursor: pointer;
        padding: 4px;
        color: #999;
        font-size: 20px;
        line-height: 1;
    }
    .ss-modal-close:hover { color: #333; }
    .ss-modal-body { padding: 24px; }
    .ss-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e0e0e0;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    .ss-form-group { margin-bottom: 16px; }
    .ss-form-label {
        display: block;
        margin-bottom: 6px;
        font-weight: 500;
        color: #333;
        font-size: 13px;
    }
    .ss-form-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 13px;
        font-family: inherit;
    }
    .ss-form-input:focus { outline: none; border-color: #0078d4; }
    .ss-form-hint {
        font-size: 11px;
        color: #999;
        margin-top: 4px;
    }

    .ss-btn {
        padding: 9px 18px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.15s;
    }
    .ss-btn-primary { background: #0078d4; color: #fff; }
    .ss-btn-primary:hover { background: #005a9e; }
    .ss-btn-secondary { background: #e0e0e0; color: #333; }
    .ss-btn-secondary:hover { background: #d0d0d0; }
    .ss-btn-danger { background: #fff; color: #d32f2f; border: 1px solid #d32f2f; }
    .ss-btn-danger:hover { background: #ffebee; }
    .ss-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    .ss-msg {
        padding: 10px 14px;
        border-radius: 4px;
        font-size: 13px;
        margin-bottom: 16px;
        display: none;
    }
    .ss-msg.success { display: block; background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
    .ss-msg.error { display: block; background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

    /* MFA specific */
    .ss-mfa-status-card {
        padding: 16px;
        border-radius: 6px;
        margin-bottom: 16px;
    }
    .ss-mfa-status-card.enabled { background: #e8f5e9; border: 1px solid #c8e6c9; }
    .ss-mfa-status-card.not-enabled { background: #f5f5f5; border: 1px solid #e0e0e0; }
    .ss-mfa-status-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
    .ss-mfa-status-desc { font-size: 12px; color: #666; }

    .ss-qr-container {
        text-align: center;
        padding: 16px;
        background: #fff;
        border: 1px solid #eee;
        border-radius: 6px;
        margin-bottom: 16px;
    }
    .ss-qr-container img { image-rendering: pixelated; }
    .ss-secret-display { text-align: center; margin-bottom: 16px; }
    .ss-secret-display code {
        background: #f5f5f5;
        padding: 8px 14px;
        border-radius: 4px;
        font-size: 14px;
        font-family: 'Consolas', monospace;
        letter-spacing: 2px;
        user-select: all;
    }
    .ss-secret-display p { font-size: 11px; color: #999; margin-top: 6px; }
    .ss-verify-row { display: flex; gap: 10px; align-items: flex-end; }
    .ss-verify-row .ss-form-group { flex: 1; margin-bottom: 0; }
    .ss-otp-input {
        font-size: 18px;
        letter-spacing: 6px;
        text-align: center;
        font-family: 'Consolas', monospace;
    }
</style>

<div class="portal-user">
    <div class="ss-menu-overlay" id="ssMenuOverlay" onclick="ssCloseMenu()"></div>
    <div class="ss-user-avatar" onclick="ssToggleMenu()" title="<?php echo htmlspecialchars($ss_user_name); ?>">
        <?php echo htmlspecialchars($_um_initials); ?>
    </div>
    <div class="ss-user-menu" id="ssUserMenu">
        <div class="ss-menu-header">
            <div class="ss-menu-name"><?php echo htmlspecialchars($ss_user_name); ?></div>
            <div class="ss-menu-email"><?php echo htmlspecialchars($ss_user_email); ?></div>
        </div>
        <button class="ss-menu-item" onclick="ssOpenAccountModal()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <span>My Account</span>
        </button>
        <button class="ss-menu-item" onclick="ssOpenMfaModal()">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <span>Multi-Factor Auth</span>
            <span class="ss-mfa-badge disabled" id="ssMfaBadge">Off</span>
        </button>
        <div class="ss-menu-divider"></div>
        <button class="ss-menu-item logout-item" onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php';">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- My Account Modal -->
<div class="ss-modal" id="ssAccountModal">
    <div class="ss-modal-box">
        <div class="ss-modal-header">
            My Account
            <button class="ss-modal-close" onclick="ssCloseAccountModal()">&times;</button>
        </div>
        <div class="ss-modal-body">
            <div id="ssAcctMsg" class="ss-msg"></div>

            <div class="ss-form-group">
                <label class="ss-form-label">Preferred Name</label>
                <input type="text" class="ss-form-input" id="ssPreferredName" placeholder="e.g. Ed" autocomplete="off">
                <div class="ss-form-hint">How you would like to be addressed (leave blank to use your full name)</div>
            </div>

            <div style="margin-bottom:24px;">
                <button class="ss-btn ss-btn-primary" id="ssNameSaveBtn" onclick="ssSavePreferredName()">Save</button>
            </div>

            <div style="border-top:1px solid #e0e0e0; padding-top:20px;">
                <div style="font-size:15px;font-weight:600;color:#333;margin-bottom:16px;">Change Password</div>
                <div id="ssPwMsg" class="ss-msg"></div>
                <div class="ss-form-group">
                    <label class="ss-form-label">Current Password</label>
                    <input type="password" class="ss-form-input" id="ssPwCurrent" autocomplete="current-password">
                </div>
                <div class="ss-form-group">
                    <label class="ss-form-label">New Password</label>
                    <input type="password" class="ss-form-input" id="ssPwNew" autocomplete="new-password">
                </div>
                <div class="ss-form-group">
                    <label class="ss-form-label">Confirm New Password</label>
                    <input type="password" class="ss-form-input" id="ssPwConfirm" autocomplete="new-password">
                </div>
                <button class="ss-btn ss-btn-primary" id="ssPwSaveBtn" onclick="ssSavePassword()">Change</button>
            </div>
        </div>
    </div>
</div>

<!-- MFA Modal -->
<div class="ss-modal" id="ssMfaModal">
    <div class="ss-modal-box">
        <div class="ss-modal-header">
            Multi-Factor Authentication
            <button class="ss-modal-close" onclick="ssCloseMfaModal()">&times;</button>
        </div>
        <div class="ss-modal-body">
            <div id="ssMfaMsg" class="ss-msg"></div>
            <div id="ssMfaContent">Loading...</div>
        </div>
    </div>
</div>

<script src="../assets/js/qrcode.min.js"></script>
<script>
const _ssApi = '../api/self-service/';
const _mfaApi = '../api/myaccount/';

/* --- User Menu --- */
function ssToggleMenu() {
    const menu = document.getElementById('ssUserMenu');
    const overlay = document.getElementById('ssMenuOverlay');
    if (menu.classList.contains('active')) {
        ssCloseMenu();
    } else {
        menu.classList.add('active');
        overlay.classList.add('active');
        ssLoadMfaBadge();
    }
}

function ssCloseMenu() {
    document.getElementById('ssUserMenu').classList.remove('active');
    document.getElementById('ssMenuOverlay').classList.remove('active');
}

/* --- MFA Badge --- */
let _ssMfaEnabled = false;

async function ssLoadMfaBadge() {
    try {
        const resp = await fetch(_mfaApi + 'get_mfa_status.php');
        const data = await resp.json();
        const badge = document.getElementById('ssMfaBadge');
        _ssMfaEnabled = data.success && data.mfa_enabled;
        if (_ssMfaEnabled) {
            badge.className = 'ss-mfa-badge enabled';
            badge.textContent = 'On';
        } else {
            badge.className = 'ss-mfa-badge disabled';
            badge.textContent = 'Off';
        }
    } catch (e) {}
}

/* --- Account Modal --- */
function ssOpenAccountModal() {
    ssCloseMenu();
    document.getElementById('ssAcctMsg').className = 'ss-msg';
    document.getElementById('ssPwMsg').className = 'ss-msg';
    document.getElementById('ssPwCurrent').value = '';
    document.getElementById('ssPwNew').value = '';
    document.getElementById('ssPwConfirm').value = '';
    document.getElementById('ssAccountModal').classList.add('active');
    ssLoadProfile();
}

function ssCloseAccountModal() {
    document.getElementById('ssAccountModal').classList.remove('active');
}

async function ssLoadProfile() {
    try {
        const resp = await fetch(_ssApi + 'get_profile.php');
        const data = await resp.json();
        if (data.success) {
            document.getElementById('ssPreferredName').value = data.preferred_name || '';
        }
    } catch (e) {}
}

function ssShowAcctMsg(msg, type) {
    const el = document.getElementById('ssAcctMsg');
    el.className = 'ss-msg ' + type;
    el.textContent = msg;
}

async function ssSavePreferredName() {
    const btn = document.getElementById('ssNameSaveBtn');
    btn.disabled = true;
    document.getElementById('ssAcctMsg').className = 'ss-msg';

    try {
        const resp = await fetch(_ssApi + 'update_profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ preferred_name: document.getElementById('ssPreferredName').value.trim() })
        });
        const data = await resp.json();
        if (data.success) {
            ssShowAcctMsg('Preferred name saved. Refresh the page to see the change.', 'success');
        } else {
            ssShowAcctMsg(data.error, 'error');
        }
    } catch (e) {
        ssShowAcctMsg('Failed to save', 'error');
    }
    btn.disabled = false;
}

async function ssSavePassword() {
    const btn = document.getElementById('ssPwSaveBtn');
    const msgEl = document.getElementById('ssPwMsg');
    msgEl.className = 'ss-msg';
    btn.disabled = true;

    const current = document.getElementById('ssPwCurrent').value;
    const newPw = document.getElementById('ssPwNew').value;
    const confirm = document.getElementById('ssPwConfirm').value;

    if (!current || !newPw || !confirm) {
        msgEl.className = 'ss-msg error';
        msgEl.textContent = 'All fields are required';
        btn.disabled = false;
        return;
    }

    try {
        const resp = await fetch(_ssApi + 'change_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: current, new_password: newPw, confirm_password: confirm })
        });
        const data = await resp.json();
        if (data.success) {
            msgEl.className = 'ss-msg success';
            msgEl.textContent = 'Password changed successfully';
            document.getElementById('ssPwCurrent').value = '';
            document.getElementById('ssPwNew').value = '';
            document.getElementById('ssPwConfirm').value = '';
        } else {
            msgEl.className = 'ss-msg error';
            msgEl.textContent = data.error;
        }
    } catch (e) {
        msgEl.className = 'ss-msg error';
        msgEl.textContent = 'Failed to change password';
    }
    btn.disabled = false;
}

/* --- MFA Modal --- */
function ssOpenMfaModal() {
    ssCloseMenu();
    document.getElementById('ssMfaMsg').className = 'ss-msg';
    document.getElementById('ssMfaContent').innerHTML = 'Loading...';
    document.getElementById('ssMfaModal').classList.add('active');
    ssLoadMfaContent();
}

function ssCloseMfaModal() {
    document.getElementById('ssMfaModal').classList.remove('active');
}

function ssShowMfaMsg(msg, type) {
    const el = document.getElementById('ssMfaMsg');
    el.className = 'ss-msg ' + type;
    el.textContent = msg;
}

async function ssLoadMfaContent() {
    try {
        const resp = await fetch(_mfaApi + 'get_mfa_status.php');
        const data = await resp.json();
        _ssMfaEnabled = data.success && data.mfa_enabled;
        ssRenderMfaContent();
    } catch (e) {
        document.getElementById('ssMfaContent').innerHTML = '<p>Failed to load MFA status</p>';
    }
}

function ssRenderMfaContent() {
    const container = document.getElementById('ssMfaContent');
    if (_ssMfaEnabled) {
        container.innerHTML =
            '<div class="ss-mfa-status-card enabled">' +
                '<div class="ss-mfa-status-title" style="color:#2e7d32;">MFA is enabled</div>' +
                '<div class="ss-mfa-status-desc">Your account is protected with a time-based one-time password (TOTP). You will be asked for a code from your authenticator app each time you log in.</div>' +
            '</div>' +
            '<div>' +
                '<p style="font-size:13px;color:#666;margin:0 0 12px 0;">To disable MFA, enter your password below:</p>' +
                '<div class="ss-form-group">' +
                    '<input type="password" class="ss-form-input" id="ssMfaDisablePw" placeholder="Enter your password">' +
                '</div>' +
                '<button class="ss-btn ss-btn-danger" onclick="ssDisableMfa()">Disable</button>' +
            '</div>';
    } else {
        container.innerHTML =
            '<div class="ss-mfa-status-card not-enabled">' +
                '<div class="ss-mfa-status-title">MFA is not enabled</div>' +
                '<div class="ss-mfa-status-desc">Add an extra layer of security by setting up a time-based one-time password (TOTP) with an authenticator app like Google Authenticator or Microsoft Authenticator.</div>' +
            '</div>' +
            '<button class="ss-btn ss-btn-primary" onclick="ssStartMfaSetup()">Set Up MFA</button>';
    }
}

async function ssStartMfaSetup() {
    const container = document.getElementById('ssMfaContent');
    container.innerHTML = '<p style="color:#888;">Generating secret...</p>';

    try {
        const resp = await fetch(_mfaApi + 'setup_mfa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const data = await resp.json();
        if (!data.success) {
            ssShowMfaMsg(data.error, 'error');
            ssRenderMfaContent();
            return;
        }

        let qrHtml = '';
        try {
            const qr = qrcode(0, 'M');
            qr.addData(data.uri);
            qr.make();
            qrHtml = qr.createImgTag(5, 0);
        } catch (e) {
            qrHtml = '<p style="color:#c62828;">QR generation failed. Use the manual key below.</p>';
        }

        container.innerHTML =
            '<p style="font-size:13px;color:#333;margin:0 0 16px 0;"><strong>Step 1:</strong> Scan this QR code with your authenticator app</p>' +
            '<div class="ss-qr-container">' + qrHtml + '</div>' +
            '<div class="ss-secret-display">' +
                '<code>' + data.secret + '</code>' +
                '<p>Or enter this key manually in your authenticator app</p>' +
            '</div>' +
            '<p style="font-size:13px;color:#333;margin:0 0 12px 0;"><strong>Step 2:</strong> Enter the 6-digit code from your app to verify</p>' +
            '<div class="ss-verify-row">' +
                '<div class="ss-form-group">' +
                    '<input type="text" class="ss-form-input ss-otp-input" id="ssMfaVerifyCode" maxlength="6" placeholder="000000" inputmode="numeric" autocomplete="one-time-code">' +
                '</div>' +
                '<button class="ss-btn ss-btn-primary" id="ssMfaVerifyBtn" onclick="ssVerifyMfaSetup()" style="margin-bottom:0;height:40px;">Verify</button>' +
            '</div>';
        setTimeout(() => document.getElementById('ssMfaVerifyCode').focus(), 100);
    } catch (e) {
        ssShowMfaMsg('Failed to start MFA setup', 'error');
        ssRenderMfaContent();
    }
}

async function ssVerifyMfaSetup() {
    const code = document.getElementById('ssMfaVerifyCode').value.trim();
    if (!code || code.length !== 6) {
        ssShowMfaMsg('Please enter a 6-digit code', 'error');
        return;
    }

    const btn = document.getElementById('ssMfaVerifyBtn');
    btn.disabled = true;

    try {
        const resp = await fetch(_mfaApi + 'verify_mfa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code })
        });
        const data = await resp.json();
        if (data.success) {
            ssShowMfaMsg('MFA has been enabled successfully', 'success');
            _ssMfaEnabled = true;
            ssLoadMfaBadge();
            setTimeout(() => {
                document.getElementById('ssMfaMsg').className = 'ss-msg';
                ssRenderMfaContent();
            }, 2000);
        } else {
            ssShowMfaMsg(data.error, 'error');
            btn.disabled = false;
        }
    } catch (e) {
        ssShowMfaMsg('Verification failed', 'error');
        btn.disabled = false;
    }
}

async function ssDisableMfa() {
    const pw = document.getElementById('ssMfaDisablePw').value;
    if (!pw) {
        ssShowMfaMsg('Password is required', 'error');
        return;
    }

    try {
        const resp = await fetch(_mfaApi + 'disable_mfa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pw })
        });
        const data = await resp.json();
        if (data.success) {
            ssShowMfaMsg('MFA has been disabled', 'success');
            _ssMfaEnabled = false;
            ssLoadMfaBadge();
            setTimeout(() => {
                document.getElementById('ssMfaMsg').className = 'ss-msg';
                ssRenderMfaContent();
            }, 2000);
        } else {
            ssShowMfaMsg(data.error, 'error');
        }
    } catch (e) {
        ssShowMfaMsg('Failed to disable MFA', 'error');
    }
}

/* --- Keyboard & click handlers --- */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ssCloseMenu();
        ssCloseAccountModal();
        ssCloseMfaModal();
    }
});

document.getElementById('ssAccountModal').addEventListener('click', function(e) {
    if (e.target === this) ssCloseAccountModal();
});

document.getElementById('ssMfaModal').addEventListener('click', function(e) {
    if (e.target === this) ssCloseMfaModal();
});
</script>
