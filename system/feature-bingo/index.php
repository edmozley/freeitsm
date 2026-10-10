<?php
/**
 * System — Feature Bingo.
 *
 * One card per thing FreeITSM can do, with a star that lights once it is set up
 * on this install, and an explainer of what it is and why it is worth doing.
 * Most people use a fraction of what they have; this is the map of the rest.
 *
 * The cards are code (includes/feature_bingo/cards/), evaluated by
 * includes/feature_bingo.php; api/system/feature_bingo.php serves them and
 * records "Not for us".
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'feature-bingo';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
$b = fn(string $k) => t('system.bingo.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars($b('heading')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=78">
    <style>
        body { --accent: var(--sys-accent, #546e7a); --accent-hover: var(--sys-accent-hover, #37474f); --on-accent: var(--sys-on-accent, #fff);
               --star: #f5b301; --star-soft: rgba(245, 179, 1, .14); }
        .fb-container { height: calc(100vh - 48px); overflow-y: auto; width: 100%; box-sizing: border-box; padding: 24px 32px 48px; }
        .fb-top { display: flex; gap: 24px; align-items: stretch; justify-content: space-between; flex-wrap: wrap; margin-bottom: 18px; }
        .fb-top h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .fb-top p { margin: 6px 0 0; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; max-width: 720px; }
        .fb-score { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 10px; padding: 14px 18px; min-width: 300px; }
        .fb-score-top { display: flex; align-items: center; gap: 12px; }
        .fb-score-star { font-size: 34px; line-height: 1; color: var(--star); }
        .fb-score-num { font-size: 26px; font-weight: 700; color: var(--text, #333); }
        .fb-score-sub { font-size: 12px; color: var(--text-muted, #666); }
        .fb-bar { height: 8px; border-radius: 4px; background: var(--surface-3, #eee); overflow: hidden; margin: 10px 0 8px; }
        .fb-bar > span { display: block; height: 100%; background: var(--star); border-radius: 4px; transition: width .4s ease; }
        .fb-tiers { display: flex; gap: 14px; font-size: 12px; color: var(--text-muted, #666); flex-wrap: wrap; }
        .fb-tiers b { color: var(--text, #333); }
        .fb-verify { margin-bottom: 16px; padding: 12px 14px; border-radius: 8px; font-size: 13px;
            background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); border: 1px solid var(--warning-border, #f2d675); }
        .fb-filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 18px; position: sticky; top: -24px; z-index: 5;
            background: var(--app-bg, #f5f5f5); padding: 10px 0; }
        .fb-filters input, .fb-filters select { padding: 8px 10px; border: 1px solid var(--border, #ccc); border-radius: 6px; font-size: 13px;
            background: var(--surface, #fff); color: var(--text, #333); }
        .fb-filters input { flex: 1 1 260px; min-width: 200px; }
        .fb-btn { text-decoration: none; display: inline-flex; align-items: center; padding: 7px 12px; font-size: 12px; border-radius: 6px; border: 1px solid var(--border, #ccc); background: var(--surface, #fff);
            color: var(--text, #333); cursor: pointer; white-space: nowrap; }
        .fb-btn:hover { background: var(--surface-hover, #f3f3f3); }
        .fb-btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
        .fb-btn.primary:hover { background: var(--accent-hover); }
        .fb-count { font-size: 12px; color: var(--text-muted, #666); margin-left: auto; }
        .fb-group { margin-bottom: 26px; }
        .fb-group-head { display: flex; align-items: baseline; gap: 10px; margin: 0 0 10px; }
        .fb-group-head h3 { margin: 0; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-dim, #888); }
        .fb-group-head span { font-size: 12px; color: var(--text-muted, #666); }
        .fb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
        .fb-card { position: relative; text-align: left; background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 10px;
            padding: 12px 14px 12px; cursor: pointer; font: inherit; color: inherit; display: flex; flex-direction: column; gap: 6px; min-height: 104px;
            transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease; }
        .fb-card:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.08); border-color: var(--accent); }
        .fb-card:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .fb-card.lit { border-color: rgba(245, 179, 1, .55); background: linear-gradient(180deg, var(--star-soft), var(--surface, #fff) 60%); }
        .fb-card.dismissed { opacity: .5; }
        .fb-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .fb-mod { font-size: 11px; font-weight: 600; color: var(--text-muted, #666); text-transform: uppercase; letter-spacing: .4px; }
        .fb-star { font-size: 22px; line-height: 1; color: var(--border, #ccc); }
        .fb-card.lit .fb-star { color: var(--star); text-shadow: 0 0 8px rgba(245, 179, 1, .45); }
        .fb-title { font-size: 14px; font-weight: 600; color: var(--text, #333); line-height: 1.3; }
        .fb-tags { margin-top: auto; display: flex; gap: 6px; flex-wrap: wrap; }
        .fb-tag { font-size: 11px; padding: 2px 7px; border-radius: 10px; background: var(--surface-2, #f4f4f4); color: var(--text-muted, #666); }
        .fb-tag.essential { background: var(--danger-bg, #fde8e8); color: var(--danger-text, #b3261e); }
        .fb-tag.recommended { background: var(--info-bg, #e3f2fd); color: var(--info-text, #0d47a1); }
        .fb-tag.nfu { background: var(--surface-3, #eee); }
        .fb-empty { padding: 40px 0; text-align: center; color: var(--text-muted, #666); font-size: 14px; }
        /* The explainer */
        #fbModal .modal-content { max-width: 620px; padding: 22px 24px 16px; box-sizing: border-box; max-height: 90vh; overflow-y: auto; }
        .fbm-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 14px; }
        .fbm-star { flex: 0 0 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px;
            background: var(--surface-2, #f4f4f4); color: var(--border, #bbb); border: 1px solid var(--border, #e0e0e0); }
        .fbm-star.lit { background: var(--star-soft); color: var(--star); border-color: rgba(245, 179, 1, .5); }
        .fbm-head h3 { margin: 0 0 4px; font-size: 19px; color: var(--text, #333); }
        .fbm-meta { font-size: 12px; color: var(--text-muted, #666); }
        .fbm-state { display: inline-block; margin-top: 6px; font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 10px; }
        .fbm-state.lit { background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); }
        .fbm-state.unlit { background: var(--surface-2, #f4f4f4); color: var(--text-muted, #666); }
        .fbm-state.dismissed { background: var(--surface-3, #eee); color: var(--text-muted, #666); }
        .fbm-sec { margin: 12px 0 0; }
        .fbm-sec h4 { margin: 0 0 3px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-dim, #888); }
        .fbm-sec p { margin: 0; font-size: 14px; line-height: 1.55; color: var(--text, #333); }
        .fbm-done { margin-top: 14px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5;
            background: var(--info-bg, #e3f2fd); color: var(--info-text, #0d47a1); border: 1px solid var(--info-border, #90caf9); }
        .fbm-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; flex-wrap: wrap; }
        .fbm-actions .fb-btn { padding: 8px 14px; font-size: 13px; }
        .fbm-actions .fbm-grow { margin-right: auto; }
        @media (max-width: 700px) { .fb-container { padding: 16px; } .fb-score { min-width: 0; width: 100%; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="fb-container">
        <div class="fb-top">
            <div>
                <h2><?php echo htmlspecialchars($b('heading')); ?></h2>
                <p><?php echo htmlspecialchars($b('intro')); ?></p>
            </div>
            <div class="fb-score" id="fbScore" hidden>
                <div class="fb-score-top">
                    <span class="fb-score-star">&#9733;</span>
                    <div><div class="fb-score-num" id="fbScoreNum"></div><div class="fb-score-sub" id="fbScoreSub"></div></div>
                </div>
                <div class="fb-bar"><span id="fbBar" style="width:0"></span></div>
                <div class="fb-tiers" id="fbTiers"></div>
            </div>
        </div>
        <div id="fbVerify" class="fb-verify" hidden><?php echo htmlspecialchars($b('needs_verify')); ?></div>

        <div class="fb-filters">
            <input type="search" id="fbQ" placeholder="<?php echo htmlspecialchars($b('search')); ?>" autocomplete="off">
            <select id="fbModule"><option value=""><?php echo htmlspecialchars($b('all_modules')); ?></option></select>
            <select id="fbCategory"><option value=""><?php echo htmlspecialchars($b('all_categories')); ?></option></select>
            <select id="fbTier">
                <option value=""><?php echo htmlspecialchars($b('all_tiers')); ?></option>
                <option value="essential"><?php echo htmlspecialchars($b('tier_essential')); ?></option>
                <option value="recommended"><?php echo htmlspecialchars($b('tier_recommended')); ?></option>
                <option value="extra"><?php echo htmlspecialchars($b('tier_extra')); ?></option>
            </select>
            <select id="fbState">
                <option value="counted"><?php echo htmlspecialchars($b('state_counted')); ?></option>
                <option value="unlit"><?php echo htmlspecialchars($b('state_unlit')); ?></option>
                <option value="lit"><?php echo htmlspecialchars($b('state_lit')); ?></option>
                <option value="dismissed"><?php echo htmlspecialchars($b('state_dismissed')); ?></option>
                <option value="all"><?php echo htmlspecialchars($b('state_all')); ?></option>
            </select>
            <button type="button" class="fb-btn" id="fbModuleNfu" hidden></button>
            <span class="fb-count" id="fbCount"></span>
        </div>

        <div id="fbGroups"><div class="fb-empty"><?php echo htmlspecialchars(t('common.loading')); ?></div></div>
    </div>

    <div class="modal" id="fbModal" role="dialog" aria-modal="true" aria-labelledby="fbmTitle">
        <div class="modal-content">
            <div class="fbm-head">
                <div class="fbm-star" id="fbmStar">&#9733;</div>
                <div>
                    <h3 id="fbmTitle"></h3>
                    <div class="fbm-meta" id="fbmMeta"></div>
                    <span class="fbm-state" id="fbmState"></span>
                </div>
            </div>
            <div class="fbm-sec"><h4><?php echo htmlspecialchars($b('what')); ?></h4><p id="fbmWhat"></p></div>
            <div class="fbm-sec"><h4><?php echo htmlspecialchars($b('why')); ?></h4><p id="fbmWhy"></p></div>
            <div class="fbm-done"><strong><?php echo htmlspecialchars($b('lights_when')); ?></strong> <span id="fbmDone"></span></div>
            <div class="fbm-actions">
                <button type="button" class="fb-btn fbm-grow" id="fbmNfu"></button>
                <button type="button" class="fb-btn" id="fbmClose"><?php echo htmlspecialchars(t('common.close')); ?></button>
                <a class="fb-btn primary" id="fbmGo" href="#"><?php echo htmlspecialchars($b('take_me_there')); ?></a>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script>
    (function () {
        const API = '../../api/system/feature_bingo.php';
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        const T = k => window.t('system.bingo.' + k);
        const fmt = (s, p) => String(s).replace(/\{(\w+)\}/g, (_, k) => (p && k in p) ? p[k] : '{' + k + '}');
        const TIER_ORDER = { essential: 0, recommended: 1, extra: 2 };
        let D = null, open = null;

        async function load() {
            const d = await (await fetch(API, { credentials: 'same-origin' })).json();
            if (!d.success) { $('fbGroups').innerHTML = '<div class="fb-empty">' + esc(d.error || '') + '</div>'; return; }
            const first = !D;
            D = d;
            if (first) {
                $('fbModule').insertAdjacentHTML('beforeend', Object.entries(d.modules).map(([k, v]) => '<option value="' + esc(k) + '">' + esc(v) + '</option>').join(''));
                $('fbCategory').insertAdjacentHTML('beforeend', Object.entries(d.categories).map(([k, v]) => '<option value="' + esc(k) + '">' + esc(v) + '</option>').join(''));
            }
            $('fbVerify').hidden = !!d.ready;
            paintScore();
            paint();
        }

        function paintScore() {
            const pct = D.total ? Math.round(D.lit / D.total * 100) : 0;
            $('fbScore').hidden = false;
            $('fbScoreNum').textContent = fmt(T('stars_of'), { lit: D.lit, total: D.total });
            $('fbScoreSub').textContent = fmt(T('percent'), { pct: pct }) + (D.dismissed ? ' · ' + fmt(T('dismissed_count'), { n: D.dismissed }) : '');
            $('fbBar').style.width = pct + '%';
            $('fbTiers').innerHTML = D.tiers.map(tr => {
                const inTier = D.cards.filter(c => c.tier === tr && c.state !== 'dismissed');
                return '<span>' + esc(T('tier_' + tr)) + ' <b>' + inTier.filter(c => c.state === 'lit').length + '/' + inTier.length + '</b></span>';
            }).join('');
        }

        function matches(c) {
            const q = $('fbQ').value.trim().toLowerCase(), m = $('fbModule').value, cat = $('fbCategory').value, tr = $('fbTier').value, st = $('fbState').value;
            if (m && c.module !== m) return false;
            if (cat && c.category !== cat) return false;
            if (tr && c.tier !== tr) return false;
            if (st === 'counted' && c.state === 'dismissed') return false;
            if ((st === 'lit' || st === 'unlit' || st === 'dismissed') && c.state !== st) return false;
            if (q && !(c.title + ' ' + c.what + ' ' + c.why + ' ' + D.modules[c.module] + ' ' + D.categories[c.category]).toLowerCase().includes(q)) return false;
            return true;
        }

        function cardHtml(c) {
            return '<button type="button" class="fb-card ' + c.state + '" data-id="' + esc(c.id) + '">'
                + '<div class="fb-card-top"><span class="fb-mod">' + esc(D.modules[c.module]) + '</span><span class="fb-star">' + (c.state === 'lit' ? '&#9733;' : '&#9734;') + '</span></div>'
                + '<div class="fb-title">' + esc(c.title) + '</div>'
                + '<div class="fb-tags"><span class="fb-tag">' + esc(D.categories[c.category]) + '</span>'
                + (c.tier !== 'extra' ? '<span class="fb-tag ' + c.tier + '">' + esc(T('tier_' + c.tier)) + '</span>' : '')
                + (c.state === 'dismissed' ? '<span class="fb-tag nfu">' + esc(T('not_for_us')) + '</span>' : '')
                + (c.local ? '<span class="fb-tag">' + esc(T('added_here')) + '</span>' : '') + '</div>'
                + '</button>';
        }

        function paint() {
            const shown = D.cards.filter(matches)
                .sort((a, b) => TIER_ORDER[a.tier] - TIER_ORDER[b.tier] || a.title.localeCompare(b.title));
            $('fbCount').textContent = fmt(T('showing'), { n: shown.length });
            // Module "Not for us" - one click for "we don't use Contracts".
            const m = $('fbModule').value, nfu = $('fbModuleNfu');
            if (m) {
                const inMod = D.cards.filter(c => c.module === m);
                const allOut = inMod.length && inMod.every(c => c.state === 'dismissed');
                nfu.hidden = false;
                nfu.dataset.mode = allOut ? 'restore_module' : 'dismiss_module';
                nfu.textContent = fmt(T(allOut ? 'module_put_back' : 'module_not_for_us'), { module: D.modules[m] });
            } else nfu.hidden = true;

            if (!shown.length) { $('fbGroups').innerHTML = '<div class="fb-empty">' + esc(T('none_match')) + '</div>'; return; }
            let h = '';
            Object.keys(D.modules).forEach(mk => {
                const inGroup = shown.filter(c => c.module === mk);
                if (!inGroup.length) return;
                const all = D.cards.filter(c => c.module === mk && c.state !== 'dismissed');
                h += '<div class="fb-group"><div class="fb-group-head"><h3>' + esc(D.modules[mk]) + '</h3><span>'
                   + esc(fmt(T('group_count'), { lit: all.filter(c => c.state === 'lit').length, total: all.length })) + '</span></div>'
                   + '<div class="fb-grid">' + inGroup.map(cardHtml).join('') + '</div></div>';
            });
            $('fbGroups').innerHTML = h;
        }

        function openCard(id) {
            const c = D.cards.find(x => x.id === id);
            if (!c) return;
            open = c;
            $('fbmStar').className = 'fbm-star' + (c.state === 'lit' ? ' lit' : '');
            $('fbmStar').innerHTML = c.state === 'lit' ? '&#9733;' : '&#9734;';
            $('fbmTitle').textContent = c.title;
            $('fbmMeta').textContent = [D.modules[c.module], D.categories[c.category], T('tier_' + c.tier)].join(' · ');
            $('fbmState').className = 'fbm-state ' + c.state;
            $('fbmState').textContent = T('state_is_' + c.state);
            $('fbmWhat').textContent = c.what;
            $('fbmWhy').textContent = c.why;
            $('fbmDone').textContent = c.done;
            $('fbmGo').href = '../../' + (c.link || '');
            $('fbmGo').hidden = !c.link;
            $('fbmNfu').textContent = T(c.state === 'dismissed' ? 'put_back' : 'not_for_us');
            $('fbmNfu').hidden = !D.ready;
            $('fbModal').classList.add('active');
        }
        function closeCard() { $('fbModal').classList.remove('active'); open = null; }

        async function post(body) {
            const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
            if (!d.success) { if (window.showToast) window.showToast(d.error || 'Failed', 'error'); return false; }
            await load();
            return true;
        }

        $('fbGroups').addEventListener('click', e => { const b = e.target.closest('.fb-card'); if (b) openCard(b.dataset.id); });
        $('fbmClose').addEventListener('click', closeCard);
        $('fbModal').addEventListener('click', e => { if (e.target.id === 'fbModal') closeCard(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && open) closeCard(); });
        $('fbmNfu').addEventListener('click', async () => {
            if (!open) return;
            const id = open.id;
            if (await post({ action: open.state === 'dismissed' ? 'restore' : 'dismiss', card_id: id })) openCard(id);
        });
        $('fbModuleNfu').addEventListener('click', () => post({ action: $('fbModuleNfu').dataset.mode, module: $('fbModule').value }));
        ['fbModule', 'fbCategory', 'fbTier', 'fbState'].forEach(id => $(id).addEventListener('change', paint));
        $('fbQ').addEventListener('input', paint);
        load();
    })();
    </script>
</body>
</html>
