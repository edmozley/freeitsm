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
require_once '../../includes/ai_settings_panel.php';   // the shared provider / model / key panel (step 5)
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
    <script src="../../assets/js/i18n.js?v=4"></script>
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
        .ha-panel > p.ha-small { font-size: 12.5px; margin-bottom: 8px; }
        .ha-msg { font-size: 13px; color: var(--text-muted, #666); }
        .ha-msg.ok { color: var(--success-text, #166534); }
        .ha-msg.bad { color: var(--danger-text, #b91c1c); }
        .hv-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px 16px; margin-top: 14px; }
        .hv-field { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--text-muted, #666); min-width: 0; }
        .hv-field input, .hv-field select { padding: 7px 10px; font: inherit; font-size: 13px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); min-width: 0; }
        .hv-model-row { display: flex; gap: 6px; }
        .hv-model-row select { flex: 1; min-width: 0; }
        .hv-status { margin-top: 14px; }
        .hask-ai { margin-top: 14px; }
        .hask-try { display: flex; gap: 8px; }
        .hask-out { margin-top: 12px; font-size: 13.5px; color: var(--text, #333); line-height: 1.55; }
        .hask-out .hask-answer { white-space: pre-wrap; background: var(--surface-hover, #f6f7f8); border-radius: 8px; padding: 12px 14px; }
        .hask-out .hask-meta { font-size: 12px; color: var(--text-muted, #666); margin-top: 6px; }
        .ha-row .ha-named { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted, #666); white-space: nowrap; }
        .ha-panel > p.ha-named-hint { font-size: 12px; margin: 8px 0 0; }
        .ha-count-pill { display: inline-block; min-width: 18px; padding: 1px 7px; border-radius: 999px; background: var(--accent, #546e7a); color: var(--on-accent, #fff); font-size: 11.5px; text-transform: none; letter-spacing: 0; vertical-align: 1px; text-align: center; }
        .hu-filters { display: flex; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
        .hu-filter.hu-on { border-color: var(--accent, #546e7a); color: var(--accent, #546e7a); font-weight: 700; }
        .hu-item { display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--border-soft, #eee); border-radius: 6px; margin-bottom: 6px; }
        .hu-item.hu-done { opacity: .6; }
        .hu-body { flex: 1; min-width: 0; }
        .hu-q { font-size: 14px; color: var(--text, #333); overflow-wrap: anywhere; }
        .hu-meta { font-size: 12px; color: var(--text-muted, #666); margin-top: 3px; line-height: 1.5; }
        .hu-empty { font-size: 13px; color: var(--text-muted, #666); padding: 8px 0; }
        .hv-status .ha-warnbox { background: var(--warning-bg, #fef3c7); border: 1px solid var(--warning-border, #f0d9a8); color: var(--warning-text, #92400e); border-radius: 8px; padding: 12px 14px; margin: 8px 0; }
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
            <p class="ha-named-hint"><?php echo $h('named_hint'); ?></p>
        </div>

        <div style="margin:0 0 26px;">
            <button class="btn btn-primary" id="haSave" onclick="save()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            <span class="ha-saved" id="haSaved" hidden><?php echo $h('saved'); ?></span>
        </div>

        <?php /* Step 4: matching by meaning. Its own embedding key, stored ENCRYPTED by
                 aiSettingsSave() through api/system/help_assistant.php; the key is never
                 sent back - only "Saved (****abcd)". Providers are only those that can
                 embed, and models come from a dropdown, never typed. */ ?>
        <div class="ha-panel">
            <h3 id="meaning"><?php echo $h('vectors_heading'); ?></h3>
            <p><?php echo $h('vectors_intro'); ?></p>
            <p class="ha-small"><?php echo $h('vectors_cost'); ?></p>
            <p class="ha-small"><?php echo $h('vectors_privacy'); ?></p>
            <label class="ha-toggle">
                <input type="checkbox" id="hvEnabled" onchange="syncVectors()">
                <span class="ha-toggle-label"><?php echo $h('vectors_enable'); ?></span>
            </label>
            <div class="hv-fields" id="hvFields">
                <label class="hv-field"><span><?php echo $h('provider'); ?></span>
                    <select id="hvProvider" onchange="syncProvider(); loadModels();">
                        <option value="openai"><?php echo $h('prov_openai'); ?></option>
                        <option value="azure"><?php echo $h('prov_azure'); ?></option>
                        <option value="openai_compatible"><?php echo $h('prov_compatible'); ?></option>
                    </select>
                </label>
                <label class="hv-field hv-azure"><span><?php echo $h('azure_endpoint'); ?></span><input type="text" id="hvAzEndpoint" autocomplete="off" placeholder="https://my-resource.openai.azure.com"></label>
                <label class="hv-field hv-azure"><span><?php echo $h('azure_deployment'); ?></span><input type="text" id="hvAzDeployment" autocomplete="off"></label>
                <label class="hv-field hv-azure"><span><?php echo $h('azure_api_version'); ?></span><input type="text" id="hvAzVersion" autocomplete="off" placeholder="2024-02-01"></label>
                <label class="hv-field hv-compat"><span><?php echo $h('base_url'); ?></span><input type="text" id="hvBaseUrl" autocomplete="off" placeholder="<?php echo $h('base_url_hint'); ?>" onchange="loadModels()"></label>
                <label class="hv-field"><span id="hvKeyLabel"><?php echo $h('key'); ?></span><input type="password" id="hvKey" autocomplete="new-password" onchange="loadModels()"></label>
                <label class="hv-field"><span id="hvModelLabel"><?php echo $h('model'); ?></span>
                    <span class="hv-model-row"><select id="hvModel"></select>
                    <button type="button" class="btn btn-secondary" onclick="loadModels()"><?php echo $h('refresh'); ?></button></span>
                </label>
            </div>
            <div style="margin-top:14px; display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <button class="btn btn-primary" id="hvSave" onclick="saveVectors()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button class="btn btn-secondary" id="hvTest" onclick="testVectors()"><?php echo $h('test'); ?></button>
                <span class="ha-msg" id="hvMsg"></span>
            </div>
            <div class="ha-status hv-status" id="hvStatus"></div>
        </div>

        <?php /* Step 5: written answers. The chat AI is set up with the SAME panel as every
                 other AI feature (namespace help_ai): api/system/ai/save_settings.php saves it,
                 encrypting the key; only an administrator may (analystCanManageAiNamespace()). */ ?>
        <div class="ha-panel">
            <h3 id="answers"><?php echo $h('ask_heading'); ?></h3>
            <p><?php echo $h('ask_intro'); ?></p>
            <p class="ha-small"><?php echo $h('ask_cost'); ?></p>
            <p class="ha-small"><?php echo $h('ask_privacy'); ?></p>
            <label class="ha-toggle">
                <input type="checkbox" id="haskEnabled" onchange="saveAsk()">
                <span>
                    <span class="ha-toggle-label"><?php echo $h('ask_enable'); ?></span>
                    <span class="ha-toggle-hint" id="haskHint"></span>
                </span>
            </label>
            <div class="hask-ai"><?php renderAiSettingsPanel('help_ai'); ?></div>

            <h3 id="try" style="margin-top:22px;"><?php echo $h('try_heading'); ?></h3>
            <p><?php echo $h('try_hint'); ?></p>
            <div class="hask-try">
                <input type="text" id="haskQ" class="ha-filter" placeholder="<?php echo $h('try_placeholder'); ?>" onkeydown="if (event.key === 'Enter') tryAsk()">
                <button type="button" class="btn btn-primary" id="haskGo" onclick="tryAsk()"><?php echo $h('try'); ?></button>
            </div>
            <div class="hask-out" id="haskOut"></div>
        </div>

        <?php /* Step 6: questions the help could not answer - admins only, like this whole page. */ ?>
        <div class="ha-panel">
            <h3 id="unanswered"><?php echo $h('unanswered_heading'); ?> <span class="ha-count-pill" id="huCount" hidden></span></h3>
            <p><?php echo $h('unanswered_intro'); ?></p>
            <div class="hu-filters">
                <button type="button" class="btn btn-secondary hu-filter" data-status="new"><?php echo $h('filter_new'); ?></button>
                <button type="button" class="btn btn-secondary hu-filter" data-status="done"><?php echo $h('filter_done'); ?></button>
                <button type="button" class="btn btn-secondary hu-filter" data-status="all"><?php echo $h('filter_all'); ?></button>
            </div>
            <div class="hu-list" id="huList"></div>
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
                // Record who asked - its own tick (only meaningful when they have the assistant).
                // Not inside the row's label behaviour: a click here must not tick the row.
                const namedWrap = document.createElement('span'); namedWrap.className = 'ha-named';
                const named = document.createElement('input'); named.type = 'checkbox'; named.checked = !!a.named;
                named.onchange = () => { a.named = named.checked; };
                namedWrap.addEventListener('click', e => { e.stopPropagation(); if (e.target !== named) { e.preventDefault(); named.checked = !named.checked; a.named = named.checked; } });
                namedWrap.append(named, document.createTextNode(tr('named_label')));
                row.append(box, name, user, namedWrap);
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
            applyVectors(d.vectors || {}, d.index || {});
            showCount(d.unanswered_count || 0);
            const ask = d.ask || {};
            document.getElementById('haskEnabled').checked = !!ask.enabled;
            document.getElementById('haskHint').textContent = ask.enabled && !ask.ready ? tr('ask_not_ready') : '';
            document.getElementById('haLevel').textContent = tr(d.level === 'ask' ? 'level_ask' : (d.level === 'search_meaning' ? 'level_meaning' : 'level_search'));
        }

        // ── Written answers ──────────────────────────────────────────────
        async function saveAsk() {
            try { apply(await post({ action: 'save_ask', enabled: document.getElementById('haskEnabled').checked })); }
            catch (e) { if (typeof showToast === 'function') showToast(e.message, 'error'); }
        }
        // Try it: the reply as this admin would get it, and what the AI was given. Text only.
        async function tryAsk() {
            const q = document.getElementById('haskQ').value.trim();
            const out = document.getElementById('haskOut');
            if (!q) return;
            const btn = document.getElementById('haskGo');
            btn.disabled = true;
            out.textContent = '…';
            try {
                const d = await post({ action: 'try', question: q });
                const r = d.reply || {};
                out.textContent = '';
                const add = (cls, text) => { const e = document.createElement('div'); e.className = cls; e.textContent = text; out.appendChild(e); return e; };
                add('hask-answer', r.text || '');
                (r.results || []).forEach(x => add('hask-meta', '\u2192 ' + (x.page_title ? x.page_title + ' > ' : '') + x.title));
                if (r.ai) {
                    add('hask-meta', tr('try_given', { list: (r.ai.sections || []).map(x => '[' + x.n + '] ' + x.title).join(' \u00b7 ') }));
                    if ((r.ai.searches || []).length) add('hask-meta', tr('try_searched', { words: r.ai.searches.join(' / ') }));
                    add('hask-meta', tr('try_meta', { model: r.ai.model, ms: r.ai.ms, tin: r.ai.tokens_in, tout: r.ai.tokens_out }));
                } else {
                    add('hask-meta', tr('try_search_only', { ms: d.ms }));
                }
            } catch (e) { out.textContent = e.message; }
            finally { btn.disabled = false; }
        }

        // ── Unanswered questions ─────────────────────────────────────────
        let huStatus = 'new';
        function showCount(n) { const c = document.getElementById('huCount'); c.hidden = !n; c.textContent = n; }
        async function loadUnanswered(status) {
            huStatus = status || huStatus;
            document.querySelectorAll('.hu-filter').forEach(b => b.classList.toggle('hu-on', b.dataset.status === huStatus));
            try {
                const d = await post({ action: 'unanswered', status: huStatus });
                showCount(d.count);
                renderUnanswered(d.items || []);
            } catch (e) { if (typeof showToast === 'function') showToast(e.message, 'error'); }
        }
        // textContent throughout - the questions are whatever people typed.
        function renderUnanswered(items) {
            const list = document.getElementById('huList');
            list.textContent = '';
            if (!items.length) {
                const e = document.createElement('div'); e.className = 'hu-empty';
                e.textContent = tr(huStatus === 'new' ? 'empty_new' : 'empty');
                list.appendChild(e);
                return;
            }
            const when = s => new Date(s.replace(' ', 'T') + 'Z').toLocaleString();
            items.forEach(it => {
                const row = document.createElement('div'); row.className = 'hu-item' + (it.dealt_at ? ' hu-done' : '');
                const body = document.createElement('div'); body.className = 'hu-body';
                const q = document.createElement('div'); q.className = 'hu-q'; q.textContent = it.question;
                const bits = [when(it.at), LANGS[it.locale] || it.locale, it.module, tr(it.kind === 'none' ? 'kind_none' : 'kind_weak')];
                if (it.best_title) bits.push(tr('closest', { title: it.best_title }));
                bits.push(it.asker ? tr('asked_by', { name: it.asker }) : tr('no_name'));
                if (it.dealt_at) bits.push(tr('handled_by', { name: it.dealt_by || '-', when: when(it.dealt_at) }));
                const meta = document.createElement('div'); meta.className = 'hu-meta'; meta.textContent = bits.filter(Boolean).join(' · ');
                body.append(q, meta);
                const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn btn-secondary';
                btn.textContent = tr(it.dealt_at ? 'reopen' : 'mark_done');
                btn.onclick = async () => {
                    btn.disabled = true;
                    try { const d = await post({ action: 'mark', id: it.id, dealt: !it.dealt_at }); showCount(d.count); loadUnanswered(); }
                    catch (e) { btn.disabled = false; if (typeof showToast === 'function') showToast(e.message, 'error'); }
                };
                row.append(body, btn);
                list.appendChild(row);
            });
        }
        document.querySelectorAll('.hu-filter').forEach(b => b.addEventListener('click', () => loadUnanswered(b.dataset.status)));

        // ── Matching by meaning ──────────────────────────────────────────
        const SHIPPED_MODEL = 'text-embedding-3-small';
        let vec = {};
        const $ = id => document.getElementById(id);

        function syncVectors() { $('hvFields').classList.toggle('ha-disabled', !$('hvEnabled').checked); }
        function syncProvider() {
            const p = $('hvProvider').value;
            document.querySelectorAll('.hv-azure').forEach(e => e.style.display = p === 'azure' ? '' : 'none');
            document.querySelectorAll('.hv-compat').forEach(e => e.style.display = p === 'openai_compatible' ? '' : 'none');
            $('hvModelLabel').textContent = tr(p === 'azure' ? 'azure_model' : 'model');
            const saved = vec.has_key && p === vec.provider;
            $('hvKeyLabel').textContent = tr(p === 'openai_compatible' ? 'key_optional' : 'key');
            $('hvKey').placeholder = saved ? tr('key_saved', { hint: vec.key_hint }) : '';
        }
        // The posted settings. The key box is EMPTY unless a new key is typed - blank
        // means "keep the saved one", which never leaves the server.
        function vectorFields() {
            return { provider: $('hvProvider').value, api_key: $('hvKey').value, model: $('hvModel').value,
                     azure_endpoint: $('hvAzEndpoint').value, azure_deployment: $('hvAzDeployment').value,
                     azure_api_version: $('hvAzVersion').value, compatible_base_url: $('hvBaseUrl').value };
        }
        function fillModels(models, keep) {
            const sel = $('hvModel');
            sel.textContent = '';
            const list = models.slice();
            if (keep && !list.includes(keep)) list.unshift(keep);
            list.forEach(m => {
                const o = document.createElement('option');
                o.value = m;
                o.textContent = (m === SHIPPED_MODEL && $('hvProvider').value !== 'openai_compatible') ? m + ' - ' + tr('shipped_tag') : m;
                sel.appendChild(o);
            });
            if (keep) sel.value = keep;
        }
        async function loadModels() {
            const keep = $('hvModel').value || vec.model;
            try {
                const d = await post(Object.assign({ action: 'models' }, vectorFields()));
                fillModels(d.models || [], keep);
                if (d.error) msg(d.error, 'bad');
            } catch (e) { msg(e.message, 'bad'); }
        }
        function msg(text, cls) { const m = $('hvMsg'); m.textContent = text || ''; m.className = 'ha-msg' + (cls ? ' ' + cls : ''); }

        function applyVectors(v, ix) {
            vec = v;
            if (!v || !v.provider) return;
            $('hvEnabled').checked = !!v.enabled;
            $('hvProvider').value = v.provider;
            $('hvAzEndpoint').value = v.azure_endpoint || '';
            $('hvAzDeployment').value = v.azure_deployment || '';
            $('hvAzVersion').value = v.azure_api_version || '';
            $('hvBaseUrl').value = v.compatible_base_url || '';
            $('hvKey').value = '';
            if (!$('hvModel').options.length) fillModels([v.model], v.model); else if (v.model) { if (![...$('hvModel').options].some(o => o.value === v.model)) fillModels([v.model], v.model); $('hvModel').value = v.model; }
            syncProvider();
            syncVectors();
            renderVectorStatus(v, ix);
        }

        function renderVectorStatus(v, ix) {
            const box = $('hvStatus');
            box.textContent = '';
            const line = (text, cls) => { const d = document.createElement('div'); d.textContent = text; if (cls) d.className = cls; box.appendChild(d); return d; };
            const button = (label, fn) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-secondary'; b.textContent = label; b.onclick = fn; b.disabled = !!ix.running; b.style.marginTop = '8px'; box.appendChild(b); };
            if (!v.enabled) { line(tr('status_off')); return; }
            if (ix.running) line(tr('index_running'));
            const cc = v.chosen_counts || { total: 0, current: 0 };
            const switching = v.active && v.active !== v.chosen;
            if (v.in_use) line(tr('status_in_use', { model: v.active, count: (v.active_counts || {}).current || 0 }));
            if (!v.usable) return;
            const shipped = v.shipped && v.shipped.matches && v.shipped.available;
            if (switching) {
                // The old model keeps answering until the new one is complete.
                line(tr('warn_switch', { active: v.active, chosen: v.chosen, count: cc.total }), 'ha-warnbox');
                if (shipped) line(tr('shipped_note'));
                button(shipped ? tr('use_shipped') : tr('rebuild'), rebuild);
            } else if (!v.in_use) {
                line(tr('status_first', { model: v.chosen }));
                button(shipped ? tr('use_shipped') : tr('rebuild'), rebuild);
            } else if (cc.total > cc.current) {
                line(tr('warn_stale', { n: cc.total - cc.current }), 'ha-warn');
                button(tr('rebuild'), rebuild);
            }
        }

        async function saveVectors() {
            const btn = $('hvSave');
            btn.disabled = true;
            try {
                apply(await post(Object.assign({ action: 'save_vectors', enabled: $('hvEnabled').checked }, vectorFields())));
                msg(tr('saved'), 'ok');
            } catch (e) { msg(e.message, 'bad'); }
            finally { btn.disabled = false; }
        }
        async function testVectors() {
            msg('…');
            try {
                const d = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'test_vectors' }) }).then(r => r.json());
                if (d.success) msg(tr('test_ok', { model: d.model, dims: d.dimensions, ms: d.ms }), 'ok'); else msg(d.error, 'bad');
            } catch (e) { msg(e.message, 'bad'); }
        }

        async function load() {
            try {
                const d = await fetch(API).then(r => r.json());
                if (!d.success) { if (typeof showToast === 'function') showToast(d.error, 'error'); return; }
                if (document.getElementById('haList').childElementCount === 0) apply(d);
                else {   // a poll must not undo ticks or typing not yet saved
                    renderIndex(d.index || {});
                    renderVectorStatus(d.vectors || {}, d.index || {});
                    document.getElementById('haLevel').textContent = tr(d.level === 'ask' ? 'level_ask' : (d.level === 'search_meaning' ? 'level_meaning' : 'level_search'));
                }
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
                const d = await post({ action: 'save', enabled: document.getElementById('haEnabled').checked, analysts: analysts.filter(a => a.on).map(a => a.id), named: analysts.filter(a => a.on && a.named).map(a => a.id) });
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
        loadUnanswered('new');
    </script>
    <script src="../../assets/js/ai-settings.js?v=4"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
