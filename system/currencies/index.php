<?php
/**
 * System - Currencies (GH #189)
 *
 * The install's default currency, the favourites shown first in every currency
 * dropdown (up to Money::MAX_FAVOURITES, the default always among them), the
 * install-wide format for writing amounts, and per-currency exceptions to it.
 * Analysts choose their own FORMAT in Preferences; the default and the
 * favourites are the install's alone.
 *
 * Same rule as system/date-formats: the current values reach the page inside it
 * (window.CUR_SETTINGS) rather than over a fetch, so a failed request can never
 * show a plausible default that Save then writes back as fact.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
require_once '../../includes/money.php';
I18n::initFromSession();
Tz::init();

$current_page = 'currencies';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// Codes stored in records that the registry does not know - typed into a free-text
// box before #189. Listed so an administrator can find them; each one blocks a
// save of its record until a currency is picked. Every table is optional: a module
// whose tables do not exist yet simply contributes nothing.
$unknown = [];
try {
    $conn = connectToDatabase();
    Money::init($conn);
    $sources = [
        'contracts'         => t('common.modules.contracts.name'),
        'software_licences' => t('common.modules.software.name'),
        'domains'           => t('common.modules.domains.name'),
        'lease_schedules'   => t('common.modules.assets.name'),
        'projects'          => t('common.modules.projects.name'),
    ];
    foreach ($sources as $table => $label) {
        try {
            foreach ($conn->query("SELECT UPPER(TRIM(currency)) c, COUNT(*) n FROM `$table` WHERE currency IS NOT NULL AND TRIM(currency) <> '' GROUP BY UPPER(TRIM(currency))") as $r) {
                if (!Money::isCode($r['c'])) $unknown[] = ['code' => $r['c'], 'count' => (int)$r['n'], 'where' => $label];
            }
        } catch (Throwable $e) {
            // That module's table is not there yet.
        }
    }
} catch (Throwable $e) {
    // No database: the defaults stand and the list is empty.
}
$installSettings = Money::installSettings();
$installSettings['formats'] = (object)$installSettings['formats'];   // {} not [] when there are none
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.currencies.title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        body {
            /* System's DARK accent is a LIGHT colour, so --on-accent is pinned with it (as the other System pages). */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .cur-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title    { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 24px 0; max-width: 760px; line-height: 1.6; }

        .settings-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            margin-bottom: 24px;
            max-width: 760px;
        }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 18px 0; line-height: 1.5; }

        .cur-select {
            padding: 7px 10px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-size: 13px;
            max-width: 100%;
        }

        /* Favourites: one row each - the Default radio, the currency, a remove cross. */
        .fav-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .fav-row {
            display: flex; align-items: center; gap: 12px;
            padding: 9px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
        }
        .fav-row.is-default { border-color: var(--sys-accent, #546e7a); background: var(--surface-2, #f7f8fa); }
        .fav-row label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-dim, #888); cursor: pointer; flex-shrink: 0; }
        .fav-name { flex: 1; font-size: 14px; color: var(--text, #333); min-width: 0; }
        .fav-name code { font-weight: 600; margin-right: 6px; }
        .fav-eg { font-size: 13px; color: var(--text-muted, #666); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .icon-btn {
            border: none; background: none; cursor: pointer;
            color: var(--text-dim, #888); font-size: 18px; line-height: 1; padding: 2px 6px; border-radius: 4px;
        }
        .icon-btn:hover:not(:disabled) { color: var(--danger, #c62828); background: var(--surface-2, #f7f8fa); }
        .icon-btn:disabled { opacity: .3; cursor: default; }
        .add-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .add-row .cur-select { flex: 1; min-width: 220px; }
        .hint { font-size: 12px; color: var(--text-dim, #888); margin: 10px 0 0; }

        .picker { display: flex; gap: 8px; flex-wrap: wrap; }
        .picker .cur-select { flex: 1; min-width: 150px; }

        .df-preview {
            display: flex; flex-wrap: wrap; gap: 8px 22px;
            padding: 14px 18px; margin-top: 16px;
            border-radius: 6px;
            background: var(--surface-2, #f7f8fa);
            border: 1px solid var(--border, #ddd);
        }
        .df-preview-label { width: 100%; font-size: 12px; color: var(--text-dim, #888); }
        .df-preview-value { font-size: 18px; font-weight: 600; color: var(--text, #333); font-variant-numeric: tabular-nums; }

        .exc-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
        .exc-row { border: 1px solid var(--border, #ddd); border-radius: 6px; padding: 12px; }
        .exc-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .exc-head .fav-name { font-size: 14px; }
        .exc-empty { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 14px; }

        .unknown-list { margin: 0; padding-left: 18px; font-size: 13px; color: var(--text, #333); line-height: 1.8; }

        .df-actions { display: flex; align-items: center; gap: 14px; max-width: 760px; }

        [data-theme-mode="dark"] .btn-primary:hover { background: var(--sys-accent-hover, #b0bec5); }
        [data-theme-mode="dark"] .df-preview,
        [data-theme-mode="dark"] .fav-row.is-default,
        [data-theme-mode="dark"] .icon-btn:hover:not(:disabled) { background: var(--surface-2, #232830); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="currencies">
    <?php include '../includes/header.php'; ?>

    <div class="cur-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.currencies.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.currencies.subtitle')); ?></p>

        <form id="curForm">
            <div class="settings-card">
                <h3 id="favourites"><?php echo htmlspecialchars(t('system.currencies.fav_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.currencies.fav_desc', ['max' => Money::MAX_FAVOURITES])); ?></p>
                <div class="fav-list" id="favList"></div>
                <div class="add-row">
                    <select class="cur-select" id="favAdd" aria-label="<?php echo htmlspecialchars(t('system.currencies.fav_add')); ?>"></select>
                    <button type="button" class="btn btn-secondary" id="favAddBtn"><?php echo htmlspecialchars(t('system.currencies.fav_add')); ?></button>
                </div>
                <p class="hint" id="favFull" hidden><?php echo htmlspecialchars(t('system.currencies.fav_full')); ?></p>
                <p class="hint"><?php echo htmlspecialchars(t('system.currencies.fav_existing')); ?></p>
            </div>

            <div class="settings-card">
                <h3 id="format"><?php echo htmlspecialchars(t('system.currencies.format_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.currencies.format_desc')); ?></p>
                <div class="picker" id="fmtAll"></div>
                <div class="df-preview" id="fmtPreview"></div>
            </div>

            <div class="settings-card">
                <h3 id="exceptions"><?php echo htmlspecialchars(t('system.currencies.exc_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.currencies.exc_desc')); ?></p>
                <div class="exc-list" id="excList"></div>
                <div class="add-row">
                    <select class="cur-select" id="excAdd" aria-label="<?php echo htmlspecialchars(t('system.currencies.exc_add')); ?>"></select>
                    <button type="button" class="btn btn-secondary" id="excAddBtn"><?php echo htmlspecialchars(t('system.currencies.exc_add')); ?></button>
                </div>
            </div>

            <?php if ($unknown): ?>
            <div class="settings-card">
                <h3 id="unknown"><?php echo htmlspecialchars(t('system.currencies.unknown_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.currencies.unknown_desc')); ?></p>
                <ul class="unknown-list">
                    <?php foreach ($unknown as $u): ?>
                        <li><?php echo htmlspecialchars(t('system.currencies.unknown_row', $u)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="df-actions">
                <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </form>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script>window.CUR_SETTINGS = <?php echo json_encode($installSettings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <?php echo Money::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <script src="../../assets/js/money.js?v=1"></script>
    <script>
    (function () {
        const API_BASE = '<?php echo $path_prefix; ?>api/settings/';
        const T = (k, p) => window.t('system.currencies.' + k, p);
        const S = window.CUR_SETTINGS;
        const MAX = Money.max();
        const SAMPLE = 1234567.891;
        const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        // The page's working copy - written back only on Save.
        const state = {
            def: S.default,
            favs: S.favourites.slice(),
            all: S.format,
            per: Object.assign({}, Array.isArray(S.formats) ? {} : S.formats),
        };

        /** Every current currency NOT in the given list, as <option>s by code (for the two "add" dropdowns). */
        function addOptions(exclude) {
            return Money.current().filter(c => !exclude.includes(c))
                .map(c => '<option value="' + esc(c) + '">' + esc(c + ' - ' + Money.name(c)) + '</option>').join('');
        }

        /** The format that applies to a currency on THIS page, before saving. */
        const fmtOf = code => state.per[code] || state.all;

        function renderFavourites() {
            document.getElementById('favList').innerHTML = state.favs.map(c =>
                '<div class="fav-row' + (c === state.def ? ' is-default' : '') + '">'
                + '<label><input type="radio" name="cur_default" value="' + esc(c) + '"' + (c === state.def ? ' checked' : '') + '> ' + esc(T('fav_default')) + '</label>'
                + '<span class="fav-name"><code>' + esc(c) + '</code>' + esc(Money.name(c)) + '</span>'
                + '<span class="fav-eg">' + esc(Money.format(SAMPLE, c, fmtOf(c))) + '</span>'
                // The default cannot be removed - pick another default first.
                + '<button type="button" class="icon-btn" data-fav-remove="' + esc(c) + '" title="' + esc(T('fav_remove', { code: c })) + '" aria-label="' + esc(T('fav_remove', { code: c })) + '"' + (c === state.def ? ' disabled' : '') + '>&times;</button>'
                + '</div>').join('');
            const full = state.favs.length >= MAX;
            document.getElementById('favAdd').innerHTML = addOptions(state.favs);
            document.getElementById('favAdd').disabled = full;
            document.getElementById('favAddBtn').disabled = full;
            document.getElementById('favFull').hidden = !full;
        }

        function renderFormat() {
            document.getElementById('fmtAll').innerHTML = Money.formatPickerHtml(state.def, state.all, 'cur-select');
            renderPreview();
        }

        function renderPreview() {
            document.getElementById('fmtPreview').innerHTML = '<span class="df-preview-label">' + esc(T('preview_label')) + '</span>'
                + state.favs.map(c => '<span class="df-preview-value">' + esc(Money.format(SAMPLE, c, fmtOf(c))) + '</span>').join('');
        }

        function renderExceptions() {
            const codes = Object.keys(state.per).sort();
            document.getElementById('excList').innerHTML = codes.length
                ? codes.map(c => '<div class="exc-row" data-exc="' + esc(c) + '">'
                    + '<div class="exc-head"><span class="fav-name"><code>' + esc(c) + '</code>' + esc(Money.name(c)) + '</span>'
                    + '<span class="fav-eg">' + esc(Money.format(SAMPLE, c, state.per[c])) + '</span>'
                    + '<button type="button" class="icon-btn" data-exc-remove="' + esc(c) + '" title="' + esc(T('exc_remove')) + '" aria-label="' + esc(T('exc_remove')) + '">&times;</button></div>'
                    + '<div class="picker">' + Money.formatPickerHtml(c, state.per[c], 'cur-select') + '</div>'
                    + '</div>').join('')
                : '<p class="exc-empty">' + esc(T('exc_none')) + '</p>';
            document.getElementById('excAdd').innerHTML = addOptions(codes);
        }

        function renderAll() { renderFavourites(); renderFormat(); renderExceptions(); }

        document.getElementById('favList').addEventListener('change', e => {
            if (e.target.name === 'cur_default') { state.def = e.target.value; renderAll(); }
        });
        document.getElementById('favList').addEventListener('click', e => {
            const b = e.target.closest('[data-fav-remove]');
            if (!b || b.disabled) return;
            state.favs = state.favs.filter(c => c !== b.dataset.favRemove);
            renderFavourites(); renderPreview();
        });
        document.getElementById('favAddBtn').addEventListener('click', () => {
            const c = document.getElementById('favAdd').value;
            if (!c || state.favs.length >= MAX || state.favs.includes(c)) return;
            state.favs.push(c);
            renderFavourites(); renderPreview();
        });

        document.getElementById('fmtAll').addEventListener('change', () => {
            state.all = Money.readPicker(document.getElementById('fmtAll'));
            // Redraw so each option's example follows the other two choices.
            renderAll();
        });

        document.getElementById('excList').addEventListener('change', e => {
            const row = e.target.closest('[data-exc]');
            if (!row) return;
            state.per[row.dataset.exc] = Money.readPicker(row.querySelector('.picker'));
            renderAll();
        });
        document.getElementById('excList').addEventListener('click', e => {
            const b = e.target.closest('[data-exc-remove]');
            if (!b) return;
            delete state.per[b.dataset.excRemove];
            renderAll();
        });
        document.getElementById('excAddBtn').addEventListener('click', () => {
            const c = document.getElementById('excAdd').value;
            if (!c || state.per[c]) return;
            state.per[c] = state.all;   // starts as a copy of the install format, ready to change
            renderAll();
        });

        document.getElementById('curForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            try {
                const favs = [state.def].concat(state.favs.filter(c => c !== state.def)).slice(0, MAX);
                const resp = await fetch(API_BASE + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: {
                        currency_default: state.def,
                        currency_favourites: favs.join(','),
                        currency_format: state.all,
                        currency_formats: JSON.stringify(state.per),
                    } })
                });
                const data = await resp.json();
                if (data.success) showToast(T('saved'), 'success');
                else showToast(T('error', { error: data.error }), 'error');
            } catch (err) {
                showToast(T('save_failed'), 'error');
            }
            btn.disabled = false;
        });

        renderAll();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
