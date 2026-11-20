<?php
/**
 * Assets -> Missing information (GH #404): every asset still lacking a field its type
 * expects, with the fields named. Filling a field in - anywhere - takes it off the list;
 * Not applicable puts one aside for that asset, and can be undone on the asset itself.
 *
 * Read with Assets access; Not applicable needs write access. The list is worked out
 * live by includes/services/asset_completeness.php through api/assets/missing_info.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/asset_completeness.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

$conn = connectToDatabase();
$current_page = 'missing';
$path_prefix = '../../';
$translationNamespaces = ['common', 'asset-management'];
$ready = AssetCompleteness::ready($conn);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.missing.title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=97">
    <style>
        .mi-wrap { height: calc(100vh - 48px); overflow-y: auto; width: 100%; box-sizing: border-box; padding: 24px 32px 40px; }
        .mi-intro { color: var(--text-muted, #666); margin: 0 0 16px; max-width: none; }
        .mi-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        .mi-bar select { padding: 7px 10px; border: 1px solid var(--border, #ccc); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        .mi-count { color: var(--text-muted, #666); font-size: 13px; margin-left: auto; }
        .mi-table { width: 100%; border-collapse: collapse; background: var(--surface, #fff); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); }
        .mi-table th, .mi-table td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--border-soft, #eee); vertical-align: top; font-size: 13px; }
        .mi-table th { background: var(--surface-2, #f7f7f7); font-weight: 600; color: var(--text, #333); }
        .mi-asset a { font-weight: 600; color: var(--accent, #1565c0); text-decoration: none; }
        .mi-asset .mi-tag { display: block; color: var(--text-dim, #888); font-size: 12px; }
        .mi-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .mi-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 4px 3px 10px; border-radius: 12px; background: #fff4e5; color: #8a4b00; border: 1px solid #ffd8a8; font-size: 12px; }
        .mi-chip button { border: none; background: transparent; color: inherit; font-size: 11px; cursor: pointer; padding: 1px 6px; border-radius: 10px; text-decoration: underline; }
        .mi-chip button:hover { background: rgba(0,0,0,0.06); }
        .mi-empty { padding: 30px; text-align: center; color: var(--text-muted, #666); background: var(--surface, #fff); border-radius: 8px; }
        :root[data-theme="dark"] .mi-chip, :root[data-theme-mode="dark"] .mi-chip { background: #3a2a12; color: #ffcf8a; border-color: #5c4120; }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="mi-wrap">
        <p class="mi-intro"><?php echo htmlspecialchars(t('asset-management.missing.intro')); ?></p>
<?php if (!$ready): ?>
        <div class="mi-empty"><?php echo htmlspecialchars(t('asset-management.missing.needs_verify')); ?></div>
<?php else: ?>
        <div class="mi-bar">
            <select id="miType" aria-label="<?php echo htmlspecialchars(t('asset-management.missing.filter_type')); ?>"><option value=""><?php echo htmlspecialchars(t('asset-management.missing.all_types')); ?></option></select>
            <select id="miSection" aria-label="<?php echo htmlspecialchars(t('asset-management.missing.filter_section')); ?>">
                <option value=""><?php echo htmlspecialchars(t('asset-management.missing.all_sections')); ?></option>
                <?php foreach (AssetCompleteness::SECTIONS as $s): ?>
                <option value="<?php echo $s; ?>"><?php echo htmlspecialchars(t('asset-management.missing.section_' . $s)); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="miField" aria-label="<?php echo htmlspecialchars(t('asset-management.missing.filter_field')); ?>"><option value=""><?php echo htmlspecialchars(t('asset-management.missing.all_fields')); ?></option></select>
            <span class="mi-count" id="miCount"></span>
        </div>
        <div id="miHost"></div>
<?php endif; ?>
    </div>
<?php if ($ready): ?>
    <script>
    (function () {
        const T = (k, p) => window.t('asset-management.missing.' + k, p);
        const api = '<?php echo $path_prefix; ?>api/assets/missing_info.php';
        const host = document.getElementById('miHost');
        const sel = { type: document.getElementById('miType'), section: document.getElementById('miSection'), field: document.getElementById('miField') };
        let filled = false;

        /** Fill a dropdown once, keeping its first "All" option. */
        function fillSelect(el, entries) {
            entries.forEach(([value, label]) => { const o = document.createElement('option'); o.value = value; o.textContent = label; el.appendChild(o); });
        }

        async function load() {
            const q = new URLSearchParams();
            if (sel.type.value) q.set('type_id', sel.type.value);
            if (sel.section.value) q.set('section', sel.section.value);
            if (sel.field.value) q.set('field', sel.field.value);
            host.textContent = window.t('common.loading');
            let d;
            try { d = await (await fetch(api + '?' + q, { credentials: 'same-origin', cache: 'no-store' })).json(); } catch (e) { d = { success: false }; }
            if (!d.success) { host.textContent = d.error || T('failed'); return; }
            if (!filled) {
                fillSelect(sel.type, Object.entries(d.types || {}));
                fillSelect(sel.field, Object.entries(d.labels || {}).sort((a, b) => a[1].localeCompare(b[1])));
                filled = true;
            }
            render(d);
        }

        function render(d) {
            host.textContent = '';
            const rows = d.rows || [];
            const total = rows.reduce((n, r) => n + r.missing.length, 0);
            document.getElementById('miCount').textContent = T('count', { assets: rows.length, fields: total });
            if (!rows.length) {
                const e = document.createElement('div'); e.className = 'mi-empty';
                e.textContent = Object.keys(d.types || {}).length ? T('none_missing') : T('nothing_expected');
                host.appendChild(e);
                return;
            }
            const table = document.createElement('table'); table.className = 'mi-table';
            const head = table.createTHead().insertRow();
            [T('col_asset'), T('col_type'), T('col_missing')].forEach(h => { const th = document.createElement('th'); th.textContent = h; head.appendChild(th); });
            const body = table.createTBody();
            rows.forEach(r => {
                const tr = body.insertRow();
                const a = tr.insertCell(); a.className = 'mi-asset';
                const link = document.createElement('a'); link.href = '../?asset_id=' + encodeURIComponent(r.asset_id); link.textContent = r.name || ('#' + r.asset_id);
                a.appendChild(link);
                if (r.asset_tag) { const t = document.createElement('span'); t.className = 'mi-tag'; t.textContent = r.asset_tag; a.appendChild(t); }
                tr.insertCell().textContent = r.type;
                const m = tr.insertCell(); const chips = document.createElement('div'); chips.className = 'mi-chips';
                r.missing.forEach(key => {
                    const c = document.createElement('span'); c.className = 'mi-chip';
                    c.appendChild(document.createTextNode(d.labels[key] || key));
                    if (d.can_write) {
                        const b = document.createElement('button'); b.type = 'button'; b.textContent = T('not_applicable');
                        b.title = T('not_applicable_tip');
                        b.addEventListener('click', () => markNa(r.asset_id, key, b));
                        c.appendChild(b);
                    }
                    chips.appendChild(c);
                });
                m.appendChild(chips);
            });
            host.appendChild(table);
        }

        async function markNa(assetId, key, btn) {
            btn.disabled = true;
            try {
                const res = await fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ asset_id: assetId, field: key, not_applicable: true }) });
                const d = await res.json();
                if (!d.success) throw new Error(d.error || '');
                if (window.showToast) window.showToast(T('marked_na'), 'success');
                load();
            } catch (e) {
                btn.disabled = false;
                if (window.showToast) window.showToast(e.message || T('failed'), 'error');
            }
        }

        Object.values(sel).forEach(s => s.addEventListener('change', load));
        load();
    })();
    </script>
<?php endif; ?>
</body>
</html>
