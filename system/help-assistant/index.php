<?php
/**
 * System — Help assistant (wiki Help-Assistant-Design).
 *
 * The install-wide switch, who has it (per analyst - nobody until an admin ticks
 * them), and the help index it searches. The page opens by saying the assistant
 * works WITHOUT any AI, before anything about keys: most admins will assume an
 * "AI assistant" does nothing without one, and it does - it searches the help.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'help-assistant';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}
requireModuleAccess('system');
$h = fn(string $k, array $p = []) => htmlspecialchars(t('system.help_assistant.' . $k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.help_assistant.heading'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Full width, edge to edge - the house style for settings screens. */
        .ha-container { height: calc(100vh - 48px); overflow-y: auto; width: 100%; box-sizing: border-box; padding: 24px 32px 40px; }
        .ha-header { margin-bottom: 18px; }
        .ha-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .ha-header p  { margin: 5px 0 0; font-size: 13px; color: var(--text-dim, #888); max-width: 820px; line-height: 1.55; }

        /* The good news, first: it works with no AI at all. */
        .ha-good {
            background: var(--success-bg, #ecfdf5); border: 1px solid var(--success-border, #a7f3d0); color: var(--text, #333);
            border-radius: 8px; padding: 16px 18px; margin-bottom: 18px; max-width: 820px; font-size: 13.5px; line-height: 1.6;
        }
        .ha-good-title { font-size: 15px; font-weight: 700; color: var(--success-text, #166534); margin-bottom: 4px; }
        .ha-good p { margin: 0 0 8px; }
        .ha-good .ha-later { color: var(--text-muted, #666); font-size: 13px; }
        .ha-now { display: inline-flex; align-items: center; gap: 8px; margin-top: 4px; font-size: 13px; }
        .ha-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; background: var(--surface, #fff); border: 1px solid var(--success-border, #a7f3d0); color: var(--success-text, #166534); font-weight: 600; font-size: 12.5px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all .15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: #455a64; }
        .btn-secondary { background: var(--surface, #fff); color: var(--text, #333); border: 1px solid var(--border, #ddd); padding: 6px 14px; }
        .btn-secondary:hover:not(:disabled) { background: var(--surface-hover, #f5f5f5); }
        .btn:disabled { opacity: .55; cursor: progress; }

        .ha-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 20px; margin-bottom: 18px; max-width: 820px; }
        .ha-panel h3 { margin: 0 0 4px; font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-dim, #888); }
        .ha-panel > p { margin: 0 0 14px; font-size: 13px; color: var(--text-muted, #666); line-height: 1.55; }
        .ha-toggle { display: flex; align-items: flex-start; gap: 10px; }
        .ha-toggle input { margin-top: 3px; }
        .ha-toggle-label { font-size: 14px; font-weight: 600; color: var(--text, #333); }
        .ha-toggle-hint { display: block; font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; margin-top: 3px; }

        .ha-who-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; }
        .ha-filter { flex: 1 1 220px; min-width: 0; padding: 7px 10px; font: inherit; font-size: 13px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        .ha-count { font-size: 12.5px; color: var(--text-muted, #666); margin-left: auto; }
        .ha-list { max-height: 340px; overflow-y: auto; border: 1px solid var(--border-soft, #eee); border-radius: 6px; }
        .ha-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--border-soft, #f1f1f1); cursor: pointer; font-size: 13.5px; color: var(--text, #333); }
        .ha-row:last-child { border-bottom: none; }
        .ha-row:hover { background: var(--surface-hover, #f7f7f7); }
        .ha-row .ha-user { color: var(--text-faint, #999); font-size: 12px; }
        /* Dimmed rather than hidden while the switch is off - so somebody deciding
           can still see what they would be turning on, as Status portal does. */
        .ha-disabled { opacity: .45; pointer-events: none; }

        .ha-status { font-size: 13.5px; color: var(--text, #333); line-height: 1.6; }
        .ha-status .ha-warn { color: var(--warning-text, #92400e); }
        .ha-status .ha-fail { color: var(--danger-text, #b91c1c); }
        .ha-langs { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 12px; }
        .ha-lang { font-size: 12px; padding: 3px 9px; border-radius: 999px; background: var(--accent-soft, #eceff1); color: var(--text, #333); }
        .ha-saved { font-size: 13px; color: var(--success-text, #166534); margin-left: 12px; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="help-assistant">
    <?php include '../includes/header.php'; ?>

    <div class="ha-container">
        <div class="ha-header">
            <h2><?php echo $h('heading'); ?></h2>
            <p><?php echo $h('intro'); ?></p>
        </div>

        <div class="ha-good">
            <div class="ha-good-title">✅ <?php echo $h('no_ai_title'); ?></div>
            <p><?php echo $h('no_ai_body'); ?></p>
            <p class="ha-later"><?php echo t('system.help_assistant.no_ai_later'); ?></p>
            <div class="ha-now"><?php echo $h('right_now'); ?> <span class="ha-badge" id="haLevel"><?php echo $h('level_search'); ?></span></div>
        </div>

        <div class="ha-panel">
            <h3 id="switch"><?php echo $h('switch_heading'); ?></h3>
            <label class="ha-toggle">
                <input type="checkbox" id="haEnabled" onchange="syncEnabled()">
                <span>
                    <span class="ha-toggle-label"><?php echo $h('enable_label'); ?></span>
                    <span class="ha-toggle-hint"><?php echo $h('enable_hint'); ?></span>
                </span>
            </label>
        </div>

        <div class="ha-panel" id="haWhoPanel">
            <h3 id="who"><?php echo $h('who_heading'); ?></h3>
            <p><?php echo $h('who_hint'); ?></p>
            <div class="ha-who-bar">
                <button type="button" class="btn btn-secondary" onclick="tickAll(true)"><?php echo $h('all'); ?></button>
                <button type="button" class="btn btn-secondary" onclick="tickAll(false)"><?php echo $h('none'); ?></button>
                <input type="search" class="ha-filter" id="haFilter" placeholder="<?php echo $h('filter'); ?>" oninput="renderList()">
                <span class="ha-count" id="haCount"></span>
            </div>
            <div class="ha-list" id="haList"></div>
        </div>

        <div style="margin:0 0 26px;">
            <button class="btn btn-primary" id="haSave" onclick="save()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            <span class="ha-saved" id="haSaved" hidden><?php echo $h('saved'); ?></span>
        </div>

        <div class="ha-panel">
            <h3 id="index"><?php echo $h('index_heading'); ?></h3>
            <p><?php echo $h('index_hint'); ?></p>
            <div class="ha-status" id="haIndex"></div>
            <button class="btn btn-secondary" id="haRebuild" onclick="rebuild()"><?php echo $h('rebuild'); ?></button>
        </div>
    </div>

    <script>
        const API = '../../api/system/help_assistant.php';
        const LANGS = <?php echo json_encode(I18n::getSupportedLocales(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
        const tr = (k, p) => t('system.help_assistant.' + k, p);
        let analysts = [];
        let poll = null;

        function syncEnabled() {
            document.getElementById('haWhoPanel').classList.toggle('ha-disabled', !document.getElementById('haEnabled').checked);
        }

        // The list is drawn with textContent, never innerHTML - names are data.
        function renderList() {
            const list = document.getElementById('haList');
            const f = document.getElementById('haFilter').value.trim().toLowerCase();
            list.textContent = '';
            analysts.forEach(a => {
                if (f && !(a.name + ' ' + a.username).toLowerCase().includes(f)) return;
                const row = document.createElement('label');
                row.className = 'ha-row';
                const box = document.createElement('input');
                box.type = 'checkbox'; box.checked = a.on;
                box.onchange = () => { a.on = box.checked; renderCount(); };
                const name = document.createElement('span'); name.textContent = a.name || a.username;
                const user = document.createElement('span'); user.className = 'ha-user'; user.textContent = a.username;
                row.append(box, name, user);
                list.appendChild(row);
            });
            renderCount();
        }
        function renderCount() {
            document.getElementById('haCount').textContent = tr('count', { on: analysts.filter(a => a.on).length, total: analysts.length });
        }
        function tickAll(on) {
            const f = document.getElementById('haFilter').value.trim().toLowerCase();
            analysts.forEach(a => { if (!f || (a.name + ' ' + a.username).toLowerCase().includes(f)) a.on = on; });
            renderList();
        }

        function renderIndex(ix) {
            const box = document.getElementById('haIndex');
            box.textContent = '';
            const line = (text, cls) => { const d = document.createElement('div'); d.textContent = text; if (cls) d.className = cls; box.appendChild(d); };
            if (ix.running) line(tr('index_running'));
            else if (!ix.built) line(tr('index_never'));
            else line(tr('index_built', { when: new Date(ix.built.replace(' ', 'T') + 'Z').toLocaleString() }));
            if (!ix.running && ix.stale) line(tr('index_stale'), 'ha-warn');
            if (!ix.running && ix.last && ix.last.state === 'failed') line(tr('index_failed', { error: ix.last.error || (ix.last.stats && ix.last.stats.failed || []).join(', ') }), 'ha-fail');
            const langs = document.createElement('div'); langs.className = 'ha-langs';
            Object.entries(ix.entries || {}).forEach(([code, n]) => {
                const s = document.createElement('span'); s.className = 'ha-lang';
                s.textContent = (LANGS[code] || code) + ' - ' + tr('index_entries', { count: n });
                langs.appendChild(s);
            });
            box.appendChild(langs);
            document.getElementById('haRebuild').disabled = !!ix.running;
            // While a build runs, look again every few seconds.
            clearTimeout(poll);
            if (ix.running) poll = setTimeout(load, 4000);
        }

        function apply(d) {
            document.getElementById('haEnabled').checked = !!d.enabled;
            analysts = d.analysts || [];
            syncEnabled();
            renderList();
            renderIndex(d.index || {});
        }

        async function load() {
            try {
                const d = await fetch(API).then(r => r.json());
                if (!d.success) { if (typeof showToast === 'function') showToast(d.error, 'error'); return; }
                if (document.getElementById('haList').childElementCount === 0) apply(d);
                else renderIndex(d.index || {});   // a poll must not undo ticks not yet saved
            } catch (e) { /* the page keeps its safe defaults: off */ }
        }

        async function post(body) {
            const d = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(r => r.json());
            if (!d.success) throw new Error(d.error || 'Could not save');
            return d;
        }

        async function save() {
            const btn = document.getElementById('haSave');
            btn.disabled = true;
            try {
                const d = await post({ action: 'save', enabled: document.getElementById('haEnabled').checked, analysts: analysts.filter(a => a.on).map(a => a.id) });
                apply(d);   // show what was STORED
                const saved = document.getElementById('haSaved');
                saved.hidden = false;
                setTimeout(() => { saved.hidden = true; }, 2500);
            } catch (e) {
                if (typeof showToast === 'function') showToast(e.message, 'error');
            } finally {
                btn.disabled = false;
            }
        }

        async function rebuild() {
            document.getElementById('haRebuild').disabled = true;
            try { renderIndex((await post({ action: 'rebuild' })).index); }
            catch (e) { if (typeof showToast === 'function') showToast(e.message, 'error'); document.getElementById('haRebuild').disabled = false; }
        }

        load();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
