<?php
/**
 * System → Integrations → Apache Tika (discussion #53, tier 2).
 *
 * Points FreeITSM at a document-reading service so that PDFs, older Office
 * formats and scanned documents become searchable. Everything the built-in tier
 * already reads — Word, Excel, PowerPoint, plain text — keeps working without
 * this and is unaffected by anything on this page.
 *
 * ⚠️ FREEITSM HOSTS NOTHING. This is an address and a timeout. Starting,
 * stopping and updating the service is the administrator's business, which is
 * why the page explains how to run one rather than offering to do it.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/integrations/integrations.php';

$current_page = 'integrations';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

$meta = integrationsProviderMeta('tika');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Apache Tika</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../../assets/css/integrations.css?v=1">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Full width, like every other settings screen. No max-width, and
           margin:0 so an inherited `auto` cannot re-centre it. */
        .tika-wrap { width:100%; max-width:none; margin:0; box-sizing:border-box;
                     padding:24px 32px 48px; height:calc(100vh - 48px); overflow-y:auto; }
        .tika-head h2 { margin:0; font-size:22px; color:var(--text,#333); }
        .tika-head p  { margin:5px 0 18px; font-size:13px; color:var(--text-dim,#888); }

        .tika-panel { background:var(--surface,#fff); border:1px solid var(--border,#e0e0e0);
                      border-radius:8px; padding:20px; margin-bottom:18px; }
        .tika-panel h3 { margin:0 0 12px; font-size:13px; text-transform:uppercase;
                         letter-spacing:.5px; color:var(--text-dim,#888); }

        .tika-field { margin-bottom:14px; max-width:640px; }
        .tika-field label { display:block; font-size:13px; font-weight:600;
                            color:var(--text,#333); margin-bottom:5px; }
        .tika-field input { width:100%; padding:9px 12px; font-size:13px;
                            border:1px solid var(--border,#d1d5db); border-radius:6px;
                            background:var(--surface,#fff); color:var(--text,#111827); }
        .tika-field input:focus { outline:none; border-color:var(--accent,#546e7a); }
        .tika-hint { font-size:12px; color:var(--text-faint,#9ca3af); margin-top:5px; }

        /* The System button pair — inbox.css's .btn-primary sets only background
           and colour, so it needs these to look like a button at all. */
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px;
               border-radius:6px; font-size:13px; font-weight:600; border:none;
               cursor:pointer; transition:all .15s; }
        .btn-primary { background:var(--sys-accent,#546e7a); color:var(--sys-on-accent,#fff); }
        .btn-primary:hover:not(:disabled) { background:#455a64; }
        .btn-secondary { background:var(--sys-accent-soft,#eceff1); color:#455a64; }
        .btn:disabled { opacity:.55; cursor:progress; }
        .tika-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

        .tika-result { margin-top:12px; font-size:13px; padding:10px 12px; border-radius:6px; display:none; }
        .tika-result.on { display:block; }
        .tika-result.ok   { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46);
                            border:1px solid var(--success-border,#a7f3d0); }
        .tika-result.bad  { background:var(--danger-bg,#fef2f2); color:var(--danger-text,#991b1b);
                            border:1px solid var(--danger-border,#fecaca); }

        .tika-warn { background:var(--warning-bg,#fef3c7); color:var(--warning-text,#92400e);
                     border:1px solid var(--warning-border,#fcd34d); border-radius:6px;
                     padding:12px 14px; font-size:13px; margin-bottom:18px; }
        .tika-warn strong { display:block; margin-bottom:4px; }
        pre.tika-code { background:var(--surface-2,#f6f8fa); border:1px solid var(--border,#e5e7eb);
                        border-radius:6px; padding:12px; font-size:12.5px; overflow-x:auto; margin:8px 0 0; }
        .tika-formats { display:flex; gap:28px; flex-wrap:wrap; font-size:13px; }
        .tika-formats ul { margin:6px 0 0; padding-left:18px; color:var(--text-dim,#6b7280); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="integrations-tika">
    <?php include '../includes/header.php'; ?>

    <div class="tika-wrap">
        <div class="tika-head">
            <h2>Apache Tika</h2>
            <p><?php echo htmlspecialchars(t('system.integrations.tika_intro')); ?></p>
        </div>

        <div class="tika-warn">
            <strong><?php echo htmlspecialchars(t('system.integrations.tika_security_title')); ?></strong>
            <?php echo htmlspecialchars(t('system.integrations.tika_security_body')); ?>
        </div>

        <div class="tika-panel">
            <h3><?php echo htmlspecialchars(t('system.integrations.tika_connection')); ?></h3>

            <div class="tika-field">
                <label for="tikaUrl"><?php echo htmlspecialchars(t('system.integrations.tika_url_label')); ?></label>
                <input type="text" id="tikaUrl" placeholder="http://127.0.0.1:9998" autocomplete="off">
                <div class="tika-hint"><?php echo htmlspecialchars(t('system.integrations.tika_url_hint')); ?></div>
            </div>

            <div class="tika-field" style="max-width:220px">
                <label for="tikaTimeout"><?php echo htmlspecialchars(t('system.integrations.tika_timeout_label')); ?></label>
                <input type="number" id="tikaTimeout" min="5" max="300">
                <div class="tika-hint"><?php echo htmlspecialchars(t('system.integrations.tika_timeout_hint')); ?></div>
            </div>

            <div class="tika-actions">
                <button class="btn btn-primary" id="saveBtn" onclick="saveTika()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button class="btn btn-secondary" id="testBtn" onclick="testTika()"><?php echo htmlspecialchars(t('system.integrations.tika_test')); ?></button>
            </div>
            <div class="tika-result" id="result"></div>
        </div>

        <div class="tika-panel">
            <h3><?php echo htmlspecialchars(t('system.integrations.tika_formats')); ?></h3>
            <div class="tika-formats">
                <div>
                    <strong><?php echo htmlspecialchars(t('system.integrations.tika_without')); ?></strong>
                    <ul><li>Word, Excel, PowerPoint (.docx, .xlsx, .pptx)</li>
                        <li>Plain text, CSV, logs, Markdown</li></ul>
                </div>
                <div>
                    <strong><?php echo htmlspecialchars(t('system.integrations.tika_with')); ?></strong>
                    <ul><li>PDF</li>
                        <li><?php echo htmlspecialchars(t('system.integrations.tika_legacy_office')); ?></li>
                        <li><?php echo htmlspecialchars(t('system.integrations.tika_scanned')); ?></li></ul>
                </div>
            </div>
        </div>

        <div class="tika-panel">
            <h3><?php echo htmlspecialchars(t('system.integrations.tika_running')); ?></h3>
            <p style="font-size:13px;color:var(--text-dim,#6b7280);margin:0">
                <?php echo htmlspecialchars(t('system.integrations.tika_running_body')); ?>
            </p>
<pre class="tika-code">docker run -d --name tika --restart unless-stopped \
  -p 127.0.0.1:9998:9998 apache/tika:latest-full</pre>
            <div class="tika-hint"><?php echo htmlspecialchars(t('system.integrations.tika_image_note')); ?></div>
        </div>
    </div>

    <script>
        var API = '../../api/system/tika_settings.php';

        function say(ok, msg) {
            var el = document.getElementById('result');
            el.className = 'tika-result on ' + (ok ? 'ok' : 'bad');
            el.textContent = msg;
        }

        async function load() {
            try {
                var d = await (await fetch(API + '?action=get')).json();
                if (!d.success) return;
                document.getElementById('tikaUrl').value = d.url || '';
                document.getElementById('tikaTimeout').value = d.timeout || d.defaults.timeout;
                if (d.pending > 0) {
                    say(true, <?php echo json_encode(t('system.integrations.tika_pending')); ?>
                        .replace('{n}', d.pending));
                }
            } catch (e) { /* leave the form empty */ }
        }

        async function testTika() {
            var btn = document.getElementById('testBtn');
            btn.disabled = true;
            try {
                var d = await (await fetch(API, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'test', url: document.getElementById('tikaUrl').value.trim() })
                })).json();
                say(!!d.ok, d.ok
                    ? <?php echo json_encode(t('system.integrations.tika_test_ok')); ?>.replace('{detail}', d.detail)
                    : <?php echo json_encode(t('system.integrations.tika_test_bad')); ?>.replace('{detail}', d.detail || ''));
            } catch (e) {
                say(false, 'Could not reach FreeITSM to run the test.');
            } finally { btn.disabled = false; }
        }

        async function saveTika() {
            var btn = document.getElementById('saveBtn');
            btn.disabled = true;
            try {
                var d = await (await fetch(API, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save',
                        url: document.getElementById('tikaUrl').value.trim(),
                        timeout: parseInt(document.getElementById('tikaTimeout').value, 10)
                    })
                })).json();
                if (!d.success) { say(false, d.error || 'Could not save.'); return; }
                var msg = d.url === ''
                    ? <?php echo json_encode(t('system.integrations.tika_saved_off')); ?>
                    : <?php echo json_encode(t('system.integrations.tika_saved')); ?>;
                if (d.requeued > 0) {
                    msg += ' ' + <?php echo json_encode(t('system.integrations.tika_requeued')); ?>
                        .replace('{n}', d.requeued);
                }
                say(true, msg);
            } catch (e) {
                say(false, 'Could not save.');
            } finally { btn.disabled = false; }
        }

        load();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
