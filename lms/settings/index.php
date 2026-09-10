<?php
/**
 * LMS settings — currently just the AI provider used by the course editor.
 *
 * Follows the tickets/settings shell (pinned header, scrolling full-width
 * container, shared inbox.css primitives) so it reads like every other module's
 * settings page rather than inventing its own.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_settings_panel.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('lms');
// The LMS settings page held only a module-access check, so a LEARNER could open it by
// typing the URL — the AI provider settings for the authoring helpers. Managing the LMS
// is what this page is for. (Found while deriving the registry from the manifests.)
require_once __DIR__ . '/../../includes/rbac.php';
requireCapability(Cap::LMS_MANAGE);

$current_page = 'settings';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('lms.settings.heading')); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=23">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=66">
    <style>
        /* Pin the shared accent to the LMS blue so the tabs, buttons and focus
           rings read on-brand, as every other settings page does. */
        body { --accent: var(--lms-accent, #2563eb); --accent-hover: var(--lms-accent-hover, #1d4ed8); }

        /* Same shell as tickets/settings: header pinned, .container scrolls, full width.
           margin:0 is essential — inbox.css gives .container `margin: 30px auto`, and auto
           side margins on a flex item suppress the stretch, so it would shrink and centre. */
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .container { flex: 1 1 auto; min-height: 0; overflow-y: auto; max-width: none; width: 100%; margin: 0; padding: 24px 32px 40px; box-sizing: border-box; }
        .container > h1 { font-size: 1.5rem; margin: 0 0 18px; }
        .tab-content > p { margin-bottom: 14px; max-width: 720px; line-height: 1.6; }

        /* ---- Reminders tab ---- */
        .rem-row { max-width: 720px; margin-bottom: 20px; }
        /* Stacked, or the field sits alongside its own label and reads as part
           of the sentence. The checkbox rows opt back out below — there the
           control and its words belong on one line. */
        .rem-row > label { display: block; margin-bottom: 6px; }
        .rem-row small { display: block; margin-top: 6px; color: var(--text-muted, #666); line-height: 1.5; }
        .rem-check { display: flex; align-items: center; gap: 9px; cursor: pointer; margin-bottom: 0; }
        .rem-check input { width: 16px; height: 16px; }

        /* --warning-*, not --warning: theme.css has no bare --warning token. */
        .rem-warn {
            max-width: 720px;
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 6px;
            background: var(--warning-bg, #fff8e1);
            border: 1px solid var(--warning-border, #ffe082);
            color: var(--warning-text, #8d6e00);
            line-height: 1.5;
        }

        .rem-preview {
            max-width: 720px;
            margin-top: 6px;
            padding: 14px 16px;
            border-radius: 6px;
            background: var(--surface-3, #f8f9fa);
            border: 1px solid var(--border, #e0e0e0);
            color: var(--text, #333);
            line-height: 1.6;
        }
        .rem-preview strong { font-size: 1.05em; }
    </style>
    <!-- Mobile layer: linked AFTER this page's own CSS so its @media rules win on ties. -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=138">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=2"></script>
</head>
<body data-mobile-page="settings" data-mobile-module="lms">
    <div class="settings-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="container">
        <h1><?php echo htmlspecialchars(t('lms.settings.heading')); ?></h1>

        <div class="tabs">
            <button class="tab active" data-tab="ai" onclick="lmsSettingsTab('ai')"><?php echo htmlspecialchars(t('lms.settings.tab_ai')); ?></button>
            <button class="tab" data-tab="reminders" onclick="lmsSettingsTab('reminders')"><?php echo htmlspecialchars(t('lms.settings.tab_reminders')); ?></button>
        </div>

        <div class="tab-content active" id="tab-ai">
            <h2 style="margin-top:0;"><?php echo htmlspecialchars(t('lms.settings.tab_ai')); ?></h2>
            <p style="color: var(--text-muted, #555);"><?php echo htmlspecialchars(t('lms.settings.ai_intro')); ?></p>
            <?php renderAiSettingsPanel('lms_ai'); ?>
        </div>

        <div class="tab-content" id="tab-reminders" style="display:none;">
            <h2 style="margin-top:0;"><?php echo htmlspecialchars(t('lms.settings.tab_reminders')); ?></h2>
            <p style="color: var(--text-muted, #555);"><?php echo htmlspecialchars(t('lms.settings.reminders_intro')); ?></p>

            <?php /* Said before the switch, not after it. Somebody who has no
                     sending mailbox needs to know that here, rather than
                     switching reminders on and waiting for email that cannot
                     leave the building. */ ?>
            <div id="remNoMailbox" class="rem-warn" style="display:none;">
                <?php echo htmlspecialchars(t('lms.settings.reminders_no_mailbox')); ?>
            </div>

            <div class="rem-row">
                <label class="rem-check">
                    <input type="checkbox" id="remEnabled">
                    <span><strong><?php echo htmlspecialchars(t('lms.settings.reminders_enabled')); ?></strong></span>
                </label>
                <small><?php echo htmlspecialchars(t('lms.settings.reminders_enabled_help')); ?></small>
            </div>

            <div class="rem-row">
                <label for="remDaysBefore"><strong><?php echo htmlspecialchars(t('lms.settings.reminders_days')); ?></strong></label>
                <input type="text" id="remDaysBefore" style="max-width: 220px;" placeholder="7, 1">
                <small><?php echo htmlspecialchars(t('lms.settings.reminders_days_help')); ?></small>
            </div>

            <div class="rem-row">
                <label class="rem-check">
                    <input type="checkbox" id="remChase">
                    <span><strong><?php echo htmlspecialchars(t('lms.settings.reminders_chase')); ?></strong></span>
                </label>
                <div style="margin-top: 8px;">
                    <label for="remChaseEvery" style="font-weight: normal;"><?php echo htmlspecialchars(t('lms.settings.reminders_chase_every')); ?></label>
                    <input type="number" id="remChaseEvery" min="1" max="365" style="max-width: 100px;">
                </div>
                <small><?php echo htmlspecialchars(t('lms.settings.reminders_chase_help')); ?></small>
            </div>

            <div class="rem-row">
                <label class="rem-check">
                    <input type="checkbox" id="remOpportunistic">
                    <span><strong><?php echo htmlspecialchars(t('lms.settings.reminders_opportunistic')); ?></strong></span>
                </label>
                <small><?php echo htmlspecialchars(t('lms.settings.reminders_opportunistic_help')); ?></small>
            </div>

            <?php /* The dry run. The whole reason it is here is that nobody
                     should have to switch on a mail merge to find out how big
                     it is. */ ?>
            <div class="rem-preview" id="remPreview"><?php echo htmlspecialchars(t('lms.settings.reminders_loading')); ?></div>

            <div style="display: flex; gap: 10px; align-items: center; margin-top: 18px; flex-wrap: wrap;">
                <button class="btn btn-primary" onclick="lmsSaveReminders()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button class="btn btn-secondary" onclick="lmsTestReminder()"><?php echo htmlspecialchars(t('lms.settings.reminders_test')); ?></button>
                <button class="btn btn-secondary" onclick="lmsRunReminders()"><?php echo htmlspecialchars(t('lms.settings.reminders_run')); ?></button>
                <span id="remLastRun" style="color: var(--text-muted, #666); font-size: 13px;"></span>
            </div>
        </div>
    </div>
    </div><!-- /.settings-shell -->

    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/confirm.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/ai-settings.js?v=2"></script>
    <script>
    /* The page had one tab and therefore no tab switching at all. */
    function lmsSettingsTab(name) {
        document.querySelectorAll('.tabs .tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        document.querySelectorAll('.tab-content').forEach(p => {
            const on = p.id === 'tab-' + name;
            p.classList.toggle('active', on);
            p.style.display = on ? '' : 'none';
        });
        if (name === 'reminders') lmsLoadReminders();
    }

    const REM_API = '<?php echo BASE_URL; ?>api/lms/reminder_settings.php';

    function remEl(id) { return document.getElementById(id); }

    async function lmsLoadReminders() {
        try {
            const r = await fetch(REM_API);
            const d = await r.json();
            if (!d.success) { remEl('remPreview').textContent = d.error || 'Could not load'; return; }

            const s = d.settings;
            remEl('remEnabled').checked       = s.enabled;
            remEl('remDaysBefore').value      = s.days_before;
            remEl('remChase').checked         = s.chase;
            remEl('remChaseEvery').value      = s.chase_every;
            remEl('remOpportunistic').checked = s.opportunistic;

            remEl('remNoMailbox').style.display = d.can_send ? 'none' : '';

            remEl('remLastRun').textContent = s.last_run
                ? window.t('lms.settings.reminders_last_run', { when: fmtDateTime(s.last_run) })
                : window.t('lms.settings.reminders_never_run');

            renderRemPreview(d.preview);
        } catch (e) {
            remEl('remPreview').textContent = 'Could not load';
        }
    }

    /* The dry run, in words. "0 would be sent" is a perfectly good answer and is
       said plainly rather than left as an empty panel that looks like a failure. */
    function renderRemPreview(p) {
        const parts = [];
        parts.push('<strong>' + escHtml(window.t('lms.settings.reminders_preview', { count: p.would })) + '</strong>');
        if (p.skipped)     parts.push(escHtml(window.t('lms.settings.reminders_preview_sent', { count: p.skipped })));
        if (p.unreachable) parts.push(escHtml(window.t('lms.settings.reminders_preview_noemail', { count: p.unreachable })));
        remEl('remPreview').innerHTML = parts.join('<br>');
    }

    function escHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    async function lmsSaveReminders() {
        try {
            const r = await fetch(REM_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save',
                    enabled:       remEl('remEnabled').checked,
                    days_before:   remEl('remDaysBefore').value,
                    chase:         remEl('remChase').checked,
                    chase_every:   +remEl('remChaseEvery').value,
                    opportunistic: remEl('remOpportunistic').checked
                })
            });
            const d = await r.json();
            if (!d.success) { showToast(d.error, 'error'); return; }
            // Show what was actually stored — the box is tidied on the way in
            // (sorted, de-duplicated, nonsense dropped) and hiding that would
            // leave somebody believing they had saved something they had not.
            remEl('remDaysBefore').value = d.days_before;
            showToast(window.t('lms.settings.reminders_saved'), 'success');
            lmsLoadReminders();
        } catch (e) { showToast(window.t('lms.toast.failed'), 'error'); }
    }

    async function lmsTestReminder() {
        try {
            const r = await fetch(REM_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'test' })
            });
            const d = await r.json();
            showToast(d.success ? window.t('lms.settings.reminders_test_sent', { email: d.sent_to }) : d.error,
                      d.success ? 'success' : 'error');
        } catch (e) { showToast(window.t('lms.toast.failed'), 'error'); }
    }

    async function lmsRunReminders() {
        if (!(await showConfirm({
            title: window.t('lms.settings.reminders_run'),
            message: window.t('lms.settings.reminders_run_confirm'),
            okLabel: window.t('lms.settings.reminders_run'),
            okClass: 'primary'
        }))) return;

        try {
            const r = await fetch(REM_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'run' })
            });
            const d = await r.json();
            if (!d.success) { showToast(d.error, 'error'); return; }
            showToast(window.t('lms.settings.reminders_ran', { count: d.result.sent }), 'success');
            lmsLoadReminders();
        } catch (e) { showToast(window.t('lms.toast.failed'), 'error'); }
    }
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=57"></script>
</body>
</html>
