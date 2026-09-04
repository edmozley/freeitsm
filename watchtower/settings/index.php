<?php
/**
 * Watchtower Settings — which cards appear, and what each count is counting.
 *
 * Everything here TRIMS a dashboard that is already correct. Leave the whole
 * screen alone and Watchtower behaves exactly as it does with no rows in either
 * table: every card drawn, every status counted. That is deliberate — if ticking
 * boxes were what made the numbers right, every installation would be wrong
 * until somebody found this page, and an empty dashboard reads as "nothing needs
 * attention", which is the worst thing it could say untruthfully.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../includes/settings_manifest.php';
require_once '../../includes/module-colors.php';
requireModuleAccess('watchtower');

// Everything on this screen belongs to another module, which is easy to lose
// sight of when it is all listed on one page. Each row is tagged with the module
// it affects, in that module's own colour — taken from the shared registry, so a
// module recoloured under System is recoloured here too rather than drifting.
$wtModuleColors = getModuleColors();

// RBAC Layer 2: a tab this analyst lacks is never emitted, so there is nothing
// to un-hide in the browser.
$settingsManifest = settingsManifestFor('watchtower');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'watchtower'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('watchtower.title') . ' ' . t('watchtower.settings.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <style>
        body { display: block; --accent: var(--wt-accent, #0f766e); }
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; margin: 0; padding: 30px; }

        .tab:hover { color: var(--wt-accent, #0f766e); }
        .tab.active { color: var(--wt-accent, #0f766e); border-bottom-color: var(--wt-accent, #0f766e); }

        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .section-header h2 { margin: 0 0 8px; font-size: 18px; color: var(--text, #333); }
        .intro { color: var(--text-muted, #666); margin-bottom: 20px; max-width: 760px; line-height: 1.5; }

        /* Same shape as the Tasks card-field toggles, so the two screens read alike. */
        .wt-opts { display: flex; flex-direction: column; gap: 2px; max-width: 760px; }
        .wt-opt { display: flex; align-items: flex-start; gap: 12px; padding: 11px 14px; border-radius: 8px; cursor: pointer; transition: background 0.12s; }
        .wt-opt:hover { background: var(--surface-2, #f0fdfa); }
        .wt-opt input { margin-top: 1px; accent-color: var(--wt-accent, #0f766e); width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }
        .wt-opt-name { font-weight: 600; font-size: 14px; color: var(--text, #333); }
        .wt-opt-desc { font-size: 13px; color: var(--text-dim, #888); margin-top: 2px; line-height: 1.4; }

        /* The module stripe and pill are the only colour on the page, and both are
           the module's own — so the eye groups the two ticket rows together
           without anything having to say so. The pill tint is a 10%-alpha wash of
           the same hex, which reads on both light and dark grounds. */
        .wt-group { border: 1px solid var(--border, #ddd); border-left-width: 3px; border-radius: 8px; padding: 16px 18px; margin-bottom: 18px; max-width: 760px; }
        .wt-group h3 { margin: 0 0 4px; font-size: 15px; color: var(--text, #333); display: flex; align-items: center; gap: 9px; }
        .wt-mod-pill { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 10px; white-space: nowrap; letter-spacing: 0.02em; }
        .wt-opt { border-left: 3px solid transparent; }
        .wt-group .why { font-size: 13px; color: var(--text-muted, #777); margin: 0 0 12px; line-height: 1.45; }
        .wt-members { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .wt-member { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border: 1px solid var(--border, #ddd); border-radius: 20px; font-size: 13px; cursor: pointer; }
        .wt-member input { accent-color: var(--wt-accent, #0f766e); cursor: pointer; }
        .wt-members.disabled { opacity: 0.45; pointer-events: none; }

        .btn { padding: 10px 20px; border-radius: 4px; font-size: 14px; cursor: pointer; border: none; }
        .btn-primary { background: var(--wt-accent, #0f766e); color: #fff; }
        .btn-primary:hover { background: var(--wt-accent-hover, #0d5f59); }
        .save-bar { margin-top: 22px; }
        .num-input { width: 90px; padding: 7px 9px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: 14px; }

        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #333; color: #fff; padding: 10px 18px; border-radius: 4px; font-size: 14px; opacity: 0; pointer-events: none; transition: opacity 0.3s; z-index: 1100; }
        .toast.show { opacity: 1; }
        .toast.toast-error { background: var(--danger-accent, #c62828); }

        [data-theme-mode="dark"] .wt-opt:hover { background: #14312e; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=130">
</head>
<body data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <?php renderSettingsTabBar($visibleTabs, $activeTabId); ?>

        <?php if (settingsTabVisible($visibleTabs, 'cards')): ?>
        <div class="tab-content<?php echo $activeTabId === 'cards' ? ' active' : ''; ?>" id="cards-tab" data-capability="<?php echo Cap::WATCHTOWER_CARDS; ?>">
            <div class="section-header"><h2><?php echo htmlspecialchars(t('watchtower.settings.cards_heading')); ?></h2></div>
            <p class="intro"><?php echo htmlspecialchars(t('watchtower.settings.cards_intro')); ?></p>
            <div class="wt-opts" id="cardsList"><?php echo htmlspecialchars(t('watchtower.settings.loading')); ?></div>
            <div class="save-bar"><button class="btn btn-primary" onclick="saveSettings()"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'counts')): ?>
        <div class="tab-content<?php echo $activeTabId === 'counts' ? ' active' : ''; ?>" id="counts-tab" data-capability="<?php echo Cap::WATCHTOWER_COUNTS; ?>">
            <div class="section-header"><h2><?php echo htmlspecialchars(t('watchtower.settings.counts_heading')); ?></h2></div>
            <p class="intro"><?php echo htmlspecialchars(t('watchtower.settings.counts_intro')); ?></p>
            <div id="countsList"><?php echo htmlspecialchars(t('watchtower.settings.loading')); ?></div>

            <?php
            // Rendered here rather than by the JS above, so it takes its module
            // colour straight from the registry in PHP.
            $wtPausedColour = $wtModuleColors['tickets'][0] ?? '#0078d4';
            ?>
            <div class="wt-group" style="border-left-color: <?php echo htmlspecialchars($wtPausedColour); ?>;">
                <h3><?php echo htmlspecialchars(t('watchtower.settings.paused_heading')); ?>
                    <span class="wt-mod-pill" style="background: <?php echo htmlspecialchars($wtPausedColour); ?>1a; color: <?php echo htmlspecialchars($wtPausedColour); ?>;"><?php echo htmlspecialchars(t('watchtower.settings.card_tickets')); ?></span>
                </h3>
                <p class="why"><?php echo htmlspecialchars(t('watchtower.settings.paused_why')); ?></p>
                <input type="number" min="1" max="8760" class="num-input" id="pausedHours" value="24">
                <span style="font-size:13px;color:var(--text-muted,#777);margin-left:6px;"><?php echo htmlspecialchars(t('watchtower.settings.paused_unit')); ?></span>
            </div>

            <div class="save-bar"><button class="btn btn-primary" onclick="saveSettings()"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="toast" id="toast"></div>

<script>
const API = '../../api/watchtower/';
let settings = null;

// module key => accent colour, from the shared registry (System can override it).
const MODULE_COLOURS = <?php echo json_encode(array_map(fn($c) => $c[0], $wtModuleColors), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// Watchtower's card keys use underscores and one plural; the colour registry uses
// hyphens and the singular. Mapped explicitly rather than transformed, so a key
// that stops matching shows up here instead of silently losing its colour.
const CARD_MODULE = {
    morning_checks: 'morning-checks', tickets: 'tickets', changes: 'changes',
    calendar: 'calendar', service_status: 'service-status', contracts: 'contracts',
    knowledge: 'knowledge', assets: 'assets', tasks: 'tasks', workflows: 'workflow',
};

function moduleColour(cardKey) {
    return MODULE_COLOURS[CARD_MODULE[cardKey]] || 'var(--text-muted, #666)';
}

// The module's name, reusing the labels the Cards tab already has — no second
// list of module names to keep in step with the first.
function moduleLabel(cardKey) {
    return window.t('watchtower.settings.card_' + cardKey);
}

function modulePill(cardKey) {
    const c = moduleColour(cardKey);
    const tint = /^#[0-9a-fA-F]{6}$/.test(c) ? c + '1a' : 'var(--surface-2, #f1f5f9)';
    return `<span class="wt-mod-pill" style="background:${tint};color:${c};">${escapeHtml(moduleLabel(cardKey))}</span>`;
}

// renderSettingsTabBar() emits onclick="switchTab('<id>')", so every settings
// page has to provide it. Same implementation as the other modules'.
function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
    if (btn) btn.classList.add('active');
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    const pane = document.getElementById(tab + '-tab');
    if (pane) pane.classList.add('active');
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function showToast(msg, type) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast show' + (type === 'error' ? ' toast-error' : '');
    setTimeout(() => { el.className = 'toast'; }, 2600);
}

// Each count names the statuses IT can choose from, read live from the lookup
// tables — so this screen never has to know what anybody's statuses are called.
const COUNT_ITEMS = [
    { key: 'tickets.by_status',     card: 'tickets',        nameKey: 'watchtower.settings.item_tickets_status' },
    { key: 'tickets.high_priority', card: 'tickets',        nameKey: 'watchtower.settings.item_tickets_priority' },
    { key: 'changes.by_status',     card: 'changes',        nameKey: 'watchtower.settings.item_changes_status' },
    { key: 'service.levels',        card: 'service_status', nameKey: 'watchtower.settings.item_service_levels' },
    { key: 'service.serious',       card: 'service_status', nameKey: 'watchtower.settings.item_service_serious' },
    { key: 'tasks.by_status',       card: 'tasks',          nameKey: 'watchtower.settings.item_tasks_status' },
    { key: 'mc.attention',          card: 'morning_checks', nameKey: 'watchtower.settings.item_mc_attention' },
];

async function load() {
    try {
        const res = await fetch(API + 'get_settings.php');
        const data = await res.json();
        if (!data.success) { showToast('Error: ' + (data.error || ''), 'error'); return; }
        settings = data;
        renderCards();
        renderCounts();
        const paused = document.getElementById('pausedHours');
        if (paused) paused.value = data.paused_hours || 24;
    } catch (e) {
        showToast(window.t('watchtower.settings.load_failed'), 'error');
    }
}

function renderCards() {
    const box = document.getElementById('cardsList');
    if (!box || !settings) return;
    box.innerHTML = (settings.card_keys || []).map(key => `
        <label class="wt-opt" style="border-left-color:${moduleColour(key)};">
            <input type="checkbox" data-card="${escapeHtml(key)}" ${settings.cards[key] !== false ? 'checked' : ''}>
            <span>
                <span class="wt-opt-name" style="color:${moduleColour(key)};">${escapeHtml(window.t('watchtower.settings.card_' + key))}</span>
                <span class="wt-opt-desc">${escapeHtml(window.t('watchtower.settings.card_' + key + '_desc'))}</span>
            </span>
        </label>`).join('');
}

function renderCounts() {
    const box = document.getElementById('countsList');
    if (!box || !settings) return;
    box.innerHTML = COUNT_ITEMS.map(item => {
        const cfg = settings.items[item.key];
        if (!cfg) return '';
        // "Everything" vs "only these". The default is deliberately not stored as
        // an empty selection: no selection means "follow the built-in behaviour",
        // which is what keeps an untouched installation correct.
        const opts = (cfg.options || []).map(o => `
            <label class="wt-member">
                <input type="checkbox" data-item="${escapeHtml(item.key)}" value="${o.id}" ${cfg.selected.includes(o.id) ? 'checked' : ''}>
                <span>${escapeHtml(o.name)}</span>
            </label>`).join('');
        return `
        <div class="wt-group" style="border-left-color:${moduleColour(item.card)};">
            <h3>${escapeHtml(window.t(item.nameKey))}${modulePill(item.card)}</h3>
            <p class="why">${escapeHtml(window.t(item.nameKey + '_why'))}</p>
            <label class="wt-opt" style="padding-left:0;">
                <input type="checkbox" data-custom="${escapeHtml(item.key)}" ${cfg.customised ? 'checked' : ''}
                       onchange="toggleCustom('${escapeHtml(item.key)}', this.checked)">
                <span>
                    <span class="wt-opt-name">${escapeHtml(window.t('watchtower.settings.choose_specific'))}</span>
                    <span class="wt-opt-desc">${escapeHtml(window.t('watchtower.settings.choose_specific_desc'))}</span>
                </span>
            </label>
            <div class="wt-members${cfg.customised ? '' : ' disabled'}" data-members="${escapeHtml(item.key)}">${opts}</div>
        </div>`;
    }).join('');
}

function toggleCustom(key, on) {
    const box = document.querySelector(`[data-members="${CSS.escape(key)}"]`);
    if (box) box.classList.toggle('disabled', !on);
}

async function saveSettings() {
    const payload = { cards: {}, items: {} };

    document.querySelectorAll('[data-card]').forEach(cb => {
        payload.cards[cb.dataset.card] = cb.checked;
    });

    COUNT_ITEMS.forEach(item => {
        const custom = document.querySelector(`[data-custom="${CSS.escape(item.key)}"]`);
        if (!custom) return;
        const selected = [...document.querySelectorAll(`[data-item="${CSS.escape(item.key)}"]:checked`)]
            .map(cb => parseInt(cb.value, 10));
        payload.items[item.key] = { customised: custom.checked, selected: selected };
    });

    const paused = document.getElementById('pausedHours');
    if (paused) payload.paused_hours = parseInt(paused.value, 10) || 24;

    try {
        const res = await fetch(API + 'save_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) { showToast(window.t('watchtower.settings.saved')); load(); }
        else showToast('Error: ' + (data.error || ''), 'error');
    } catch (e) {
        showToast(window.t('watchtower.settings.save_failed'), 'error');
    }
}

load();
</script>
    <script src="../../assets/js/mobile.js?v=53"></script>
</body>
</html>
