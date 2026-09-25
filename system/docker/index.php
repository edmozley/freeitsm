<?php
/**
 * System - Docker: turn on HTTPS for the container.
 *
 * The operator types the name people will use, FreeITSM makes a certificate for
 * it (includes/docker_tls.php), and the page walks them through the three things
 * it cannot do for them: DNS, trusting the authority on the PCs, and restarting
 * the container. Everything is worked out on page load; the only request is
 * Generate, after which the page reloads.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
require_once '../../includes/storage_persistence.php';
require_once '../../includes/docker_tls.php';
I18n::initFromSession();
Tz::init();

$current_page = 'docker';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

$inContainer = storagePersistenceInContainer();
$state       = $inContainer ? dockerTlsState() : null;
$browser     = dockerTlsBrowserAddress();
$behindProxy = defined('TRUST_PROXY_HTTPS') && TRUST_PROXY_HTTPS;

// Blocked until the certificate would survive an update: see the API for why.
$notPersisted = $state && $state['persisted'] === 'at_risk';
$canGenerate  = $state && $state['openssl'] && $state['image_supports'] && $state['dir_writable'] && !$notPersisted;

$savedHost = $state['settings']['host'] ?? '';
$savedIp   = $state['settings']['ip'] ?? '';
$formHost  = $savedHost;
$formIp    = $savedIp !== '' && $savedIp !== null ? $savedIp : ($browser['is_ip'] ? $browser['host'] : '');

// The address to open once HTTPS is on. On HTTPS already, this request's port is
// the right one; otherwise the standard docker-compose.yml's 8443.
$httpsPort = $browser['https'] ? ($browser['port'] ?? 443) : 8443;
$httpsUrl  = 'https://' . $savedHost . ($httpsPort === 443 ? '' : ':' . $httpsPort) . '/';

$restartCmd = 'docker compose restart';

/** Escape a translated string, then drop pre-built HTML into its {placeholders}. */
function dk(string $key, array $html = []): string
{
    $out = htmlspecialchars(t('system.docker.' . $key));
    foreach ($html as $k => $v) $out = str_replace('{' . $k . '}', $v, $out);
    return $out;
}
function dkCode(string $s): string { return '<code>' . htmlspecialchars($s) . '</code>'; }

$overrideFile = "services:\n  app:\n    ports:\n      - \"8443:443\"\n    volumes:\n      - tls:" . DOCKER_TLS_DIR . "\n\nvolumes:\n  tls:\n";
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.docker.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=72">
    <style>
        body { --accent: var(--sys-accent, #546e7a); --accent-hover: var(--sys-accent-hover, #37474f); --on-accent: var(--sys-on-accent, #fff); }

        .main-container { flex: 1; background: var(--app-bg, #f5f7fa); overflow-y: auto; }
        .docker-container { max-width: 800px; margin: 0 auto; padding: 30px 20px; min-width: 0; }
        .panel, .notice { overflow-wrap: anywhere; }

        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 30px 0; }

        .panel {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            margin-bottom: 24px;
            font-size: 13px;
            color: var(--text-muted, #555);
            line-height: 1.6;
        }
        .panel h3 {
            font-size: 15px; font-weight: 600; color: var(--text, #333);
            margin: 0 0 16px 0; padding-bottom: 10px;
            border-bottom: 1px solid var(--border-soft, #eee);
        }
        .panel p { margin: 0 0 10px; }
        .panel p:last-child { margin-bottom: 0; }

        code, .code-block {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 12px;
            background: var(--surface-hover, #f0f0f0);
            border-radius: 3px;
        }
        code { padding: 2px 6px; }
        .code-block {
            display: block; white-space: pre-wrap; overflow-wrap: anywhere;
            padding: 12px 14px; margin: 8px 0 12px; border-radius: 6px;
            color: var(--text, #333);
        }

        /* Status: the border colour is the state, same as Encryption. */
        .status-card { border-left: 4px solid var(--border, #ccc); }
        .status-card.is-on      { border-left-color: #107c10; }
        .status-card.is-restart { border-left-color: #ca5010; }
        .status-card.is-off     { border-left-color: #90a4ae; }
        .status-title { font-size: 16px; font-weight: 600; color: var(--text, #333); margin-bottom: 6px; }

        .notice {
            border-radius: 6px; padding: 14px 16px; margin-bottom: 24px;
            font-size: 13px; line-height: 1.6;
            background: #fff8e1; border: 1px solid #ffe082; color: #6d4c00;
        }
        .notice strong { display: block; margin-bottom: 4px; }
        .notice.is-info { background: #e8f1f8; border-color: #b6d0e6; color: #1e4a6d; }

        .tls-field { margin-bottom: 16px; }
        .tls-field label { display: block; font-weight: 600; color: var(--text, #333); margin-bottom: 6px; }
        .tls-field input {
            width: 100%; max-width: 360px; box-sizing: border-box;
            padding: 9px 12px; font-size: 14px;
            border: 1px solid var(--border, #ccc); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        .tls-field input:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .form-help { font-size: 12px; color: var(--text-dim, #888); margin-top: 5px; }
        .form-warn { font-size: 12px; color: #b45309; margin-top: 5px; display: none; }
        .form-error { color: #d32f2f; margin-top: 10px; display: none; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 6px;
            font-size: 13px; font-weight: 600;
            border: none; cursor: pointer; text-decoration: none;
        }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: #455a64; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .steps { list-style: none; margin: 0; padding: 0; counter-reset: step; }
        .steps > li {
            position: relative; padding: 0 0 20px 40px; counter-increment: step;
        }
        .steps > li:last-child { padding-bottom: 0; }
        .steps > li::before {
            content: counter(step);
            position: absolute; left: 0; top: 0;
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff);
            font-weight: 700; font-size: 13px;
            display: flex; align-items: center; justify-content: center;
        }
        .steps > li.is-done::before { content: '\2713'; background: #107c10; color: #fff; }
        .step-title { font-weight: 600; color: var(--text, #333); margin-bottom: 4px; font-size: 14px; }
        .steps ul { margin: 6px 0 10px; padding-left: 18px; }
        .steps ul li { margin-bottom: 6px; }
        .steps a:not(.btn) { color: var(--sys-accent, #546e7a); font-weight: 600; }

        [data-theme-mode="dark"] .notice { background: #3a2e12; border-color: #5a4a1e; color: #fcd34d; }
        [data-theme-mode="dark"] .notice.is-info { background: #1b2a38; border-color: #2f4a63; color: #b6d4ee; }
        [data-theme-mode="dark"] .form-warn { color: #fbbf24; }
        [data-theme-mode="dark"] .form-error { color: #fca5a5; }
        [data-theme-mode="dark"] .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .steps a:not(.btn) { color: var(--sys-accent, #90a4ae); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=152">
</head>
<body data-mobile-module="system" data-mobile-page="docker">
    <?php include '../includes/header.php'; ?>

    <div class="main-container">
        <div class="docker-container">
            <h1 class="page-title"><?php echo dk('title'); ?></h1>
            <p class="page-subtitle"><?php echo dk('subtitle'); ?></p>

<?php if (!$inContainer): ?>
            <div class="notice is-info"><?php echo dk('not_container'); ?></div>
<?php else: ?>

    <?php if (!$state['openssl']): ?>
            <div class="notice"><?php echo dk('no_openssl'); ?></div>
    <?php endif; ?>

    <?php if (!$state['image_supports']): ?>
            <div class="notice">
                <strong><?php echo dk('old_image_title'); ?></strong>
                <?php echo dk('old_image_body', ['cmd' => dkCode('git pull && docker compose up -d --build')]); ?>
            </div>
    <?php elseif ($notPersisted): ?>
            <div class="notice">
                <strong><?php echo dk('not_persisted_title'); ?></strong>
                <?php echo dk('not_persisted_body', ['file' => dkCode('docker-compose.override.yml')]); ?>
                <span class="code-block"><?php echo htmlspecialchars($overrideFile); ?></span>
                <?php echo dk('not_persisted_then', ['cmd' => dkCode('docker compose up -d')]); ?>
            </div>
    <?php endif; ?>

    <?php if ($behindProxy): ?>
            <div class="notice is-info"><?php echo dk('proxy_note'); ?></div>
    <?php endif; ?>

    <?php if ($state['broken']): ?>
            <div class="panel status-card is-restart">
                <div class="status-title"><?php echo dk('status_broken_title'); ?></div>
                <p><?php echo dk('status_broken_body'); ?></p>
            </div>
    <?php elseif (!$state['server']): ?>
            <div class="panel status-card is-off">
                <div class="status-title"><?php echo dk('status_off_title'); ?></div>
                <p><?php echo dk('status_off_body'); ?></p>
            </div>
    <?php elseif ($state['active']): ?>
            <div class="panel status-card is-on">
                <div class="status-title"><?php echo dk('status_on_title'); ?></div>
                <p><?php echo dk('status_on_body', [
                    'host' => '<strong>' . htmlspecialchars($savedHost) . '</strong>',
                    'date' => htmlspecialchars(gmdate('Y-m-d', $state['server']['valid_to'])),
                ]); ?></p>
            </div>
    <?php else: ?>
            <div class="panel status-card is-restart">
                <div class="status-title"><?php echo dk('status_restart_title'); ?></div>
                <p><?php echo dk('status_restart_body', [
                    'host' => '<strong>' . htmlspecialchars($savedHost) . '</strong>',
                    'cmd'  => dkCode($restartCmd),
                ]); ?></p>
            </div>
    <?php endif; ?>

            <div class="panel">
                <h3><?php echo dk('form_heading'); ?></h3>
                <p><?php echo dk('browsing_at', ['address' => dkCode(($browser['https'] ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''))]); ?></p>

                <form id="tlsForm" autocomplete="off">
                    <div class="tls-field">
                        <label for="tlsHost"><?php echo dk('host_label'); ?></label>
                        <input type="text" id="tlsHost" value="<?php echo htmlspecialchars($formHost); ?>" placeholder="freeitsm.internal" spellcheck="false">
                        <div class="form-help"><?php echo dk('host_help'); ?></div>
                        <div class="form-warn" id="tlsLocalWarn"><?php echo dk('local_warning'); ?></div>
                    </div>
                    <div class="tls-field">
                        <label for="tlsIp"><?php echo dk('ip_label'); ?></label>
                        <input type="text" id="tlsIp" value="<?php echo htmlspecialchars((string) $formIp); ?>" placeholder="192.168.1.50" spellcheck="false">
                        <div class="form-help"><?php echo dk('ip_help'); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="tlsGenerate" <?php echo $canGenerate ? '' : 'disabled'; ?>><?php echo dk('generate'); ?></button>
                    <div class="form-error" id="tlsError"></div>
                </form>
            </div>

    <?php if ($state['server'] && !$state['broken'] && $savedHost !== ''): ?>
            <div class="panel">
                <h3><?php echo dk('steps_heading'); ?></h3>
                <ol class="steps">
                    <li>
                        <div class="step-title"><?php echo dk('step_dns_title'); ?></div>
        <?php if ($savedIp): ?>
                        <p><?php echo dk('step_dns_body', ['host' => dkCode($savedHost), 'ip' => dkCode($savedIp)]); ?></p>
                        <span class="code-block"><?php echo dk('step_dns_record', ['host' => htmlspecialchars($savedHost), 'ip' => htmlspecialchars($savedIp)]); ?></span>
                        <p><?php echo dk('step_dns_hosts', ['path' => dkCode('C:\\Windows\\System32\\drivers\\etc\\hosts'), 'path_mac' => dkCode('/etc/hosts')]); ?></p>
                        <span class="code-block"><?php echo htmlspecialchars($savedIp . '    ' . $savedHost); ?></span>
        <?php else: ?>
                        <p><?php echo dk('step_dns_no_ip'); ?></p>
        <?php endif; ?>
                    </li>
                    <li>
                        <div class="step-title"><?php echo dk('step_ca_title'); ?></div>
                        <p><?php echo dk('step_ca_body'); ?></p>
                        <p><a class="btn btn-primary" href="<?php echo $path_prefix; ?>api/system/docker_tls.php?download=ca"><?php echo dk('download'); ?></a></p>
                        <ul>
                            <li><?php echo dk('step_ca_windows'); ?></li>
                            <li><?php echo dk('step_ca_gpo'); ?></li>
                            <li><?php echo dk('step_ca_mac'); ?></li>
                            <li><?php echo dk('step_ca_firefox'); ?></li>
                        </ul>
                        <p><?php echo dk('step_ca_safe', ['host' => dkCode($savedHost)]); ?></p>
                    </li>
                    <li class="<?php echo $state['active'] ? 'is-done' : ''; ?>">
                        <div class="step-title"><?php echo dk('step_restart_title'); ?></div>
                        <p><?php echo $state['active'] ? dk('step_restart_done') : dk('step_restart_body', ['cmd' => dkCode($restartCmd)]); ?></p>
                    </li>
                    <li>
                        <div class="step-title"><?php echo dk('step_open_title'); ?></div>
                        <p><?php echo dk('step_open_body', ['url' => '<a href="' . htmlspecialchars($httpsUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($httpsUrl) . '</a>']); ?></p>
                    </li>
                </ol>
            </div>
    <?php endif; ?>
<?php endif; ?>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <script>
    (function () {
        const form = document.getElementById('tlsForm');
        if (!form) return;

        const hostInput = document.getElementById('tlsHost');
        const ipInput   = document.getElementById('tlsIp');
        const btn       = document.getElementById('tlsGenerate');
        const errBox    = document.getElementById('tlsError');
        const localWarn = document.getElementById('tlsLocalWarn');

        // What the current authority was made for. It is name-constrained, so a
        // different name or IP means a new one, and every PC has to install it.
        const saved = <?php echo json_encode(['host' => $savedHost, 'ip' => (string) $savedIp, 'has_ca' => (bool) ($state['ca'] ?? false)], JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        function checkLocal() {
            localWarn.style.display = /\.local\.?$/i.test(hostInput.value.trim()) ? 'block' : 'none';
        }
        hostInput.addEventListener('input', checkLocal);
        checkLocal();

        function showError(msg) {
            errBox.textContent = msg;
            errBox.style.display = 'block';
        }

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            errBox.style.display = 'none';

            const host = hostInput.value.trim();
            const ip   = ipInput.value.trim();
            const changesCa = saved.has_ca && (host.toLowerCase() !== saved.host || ip !== saved.ip);
            if (changesCa && !confirm(window.t('system.docker.confirm_new_ca'))) return;

            const label = btn.textContent;
            btn.disabled = true;
            btn.textContent = window.t('system.docker.generating');

            try {
                const resp = await fetch('<?php echo $path_prefix; ?>api/system/docker_tls.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ host: host, ip: ip })
                });
                const data = await resp.json();
                if (data.success) {
                    window.location.reload();
                    return;
                }
                const known = { invalid_host: 'err_invalid_host', invalid_ip: 'err_invalid_ip', not_persisted: 'err_not_persisted' };
                showError(known[data.error]
                    ? window.t('system.docker.' + known[data.error])
                    : window.t('system.docker.err_generic', { error: data.error || '' }));
            } catch (err) {
                showError(window.t('system.docker.err_generic', { error: err.message }));
            }
            btn.disabled = false;
            btn.textContent = label;
        });
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=65"></script>
</body>
</html>
