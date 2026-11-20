<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal — My equipment.
 *
 * The kit assigned to the signed-in person, so they can read a serial number off
 * the screen instead of raising a ticket to ask what it is.
 *
 * ⚠️ NOTHING HERE DECIDES WHAT SOMEBODY MAY SEE. It draws what
 * api/self-service/get_my_assets.php returns, and that endpoint takes the user
 * from the session, has no id parameter and offers no search — a portal user can
 * only ever reach equipment assigned to them. That endpoint predates this page
 * (it feeds the asset picker on a new ticket); this is a second, read-only view
 * of the same answer, so the two cannot disagree about who has what.
 *
 * 🔑 Reached only when an administrator has turned it on (System → Self-service
 * portal). Off by default, so an install that never asked for it does not get a
 * new tab. The nav hides the link and this page re-checks — a hidden link is not
 * a permission.
 */
/**
 * 🔴 THE GUARD RUNS BEFORE THE HEADER, AND THAT ORDER IS THE WHOLE POINT.
 *
 * It was originally after `require header.php`, which reads as the natural
 * place — every other page does its work there. But the header has already
 * echoed the doctype by then, so `header('Location: …')` had nothing to send:
 * the redirect silently did nothing and the page returned **200 with the full
 * contents** to anybody who typed the URL, whatever the setting said. A direct
 * request is what found it; the nav was hiding the link correctly all along,
 * which is exactly how a guard like this looks fine from the outside.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/self_service_settings.php';

if (empty($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}
// A hidden nav link is not a permission.
if (!selfServicePortalSettings(connectToDatabase())['show_my_assets']) {
    header('Location: index.php');
    exit;
}

$pageTitleKey = 'self-service.equipment.title';
$activeNav    = 'equipment';
$translationNamespaces = ['common', 'self-service'];

$pageStyles = <<<'CSS'
.eq-wrap { width: 100%; box-sizing: border-box; padding: 24px 28px 48px; }
.eq-wrap h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; color: var(--text, #333); }
.eq-sub { color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); margin: 0 0 22px; max-width: 640px; line-height: 1.55; }

.eq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }

.eq-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px;
    padding: 16px 18px;
}
.eq-card h2 { font-size: 15px; font-weight: 600; margin: 0 0 2px; color: var(--text, #333); word-break: break-word; }
.eq-type { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin: 0 0 12px; }

.eq-facts { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; font-size: var(--font-size-md, 13px); }
.eq-facts dt { color: var(--text-muted, #666); }
.eq-facts dd { margin: 0; color: var(--text, #333); word-break: break-word; }
/* A serial gets typed into a form or read down a phone, so it wants to be
   unambiguous rather than pretty: 0 against O and 1 against l is the whole job. */
.eq-facts dd.mono { font-family: Consolas, "SF Mono", Menlo, monospace; font-size: 12.5px; }

.eq-empty {
    background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);
    border-radius: 8px; padding: 28px; text-align: center;
    color: var(--text-muted, #666); font-size: var(--font-size-lg, 14px); line-height: 1.6;
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
    <div class="eq-wrap">
        <h1><?php echo htmlspecialchars(t('self-service.equipment.title')); ?></h1>
        <p class="eq-sub"><?php echo htmlspecialchars(t('self-service.equipment.subtitle')); ?></p>
        <?php /* ⚠️ DELIBERATELY EMPTY, for the reason training.php records: a
                 "loading" box is on screen for a couple of hundred milliseconds,
                 which is long enough to see as a flicker and not long enough to
                 read, so it reads as a fault. Nothing is drawn until there is
                 something to say, and "you have no equipment" is only said once
                 it is known to be true. */ ?>
        <div id="eqList"></div>
    </div>
<?php
$pageScripts = <<<'JS'
const eqEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };

async function eqLoad() {
    try {
        const res  = await fetch('../api/self-service/get_my_assets.php', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { eqEmpty(window.t('self-service.equipment.load_failed')); return; }
        eqRender(data.assets || []);
    } catch (e) {
        eqEmpty(window.t('self-service.equipment.load_failed'));
    }
}

function eqEmpty(msg) {
    document.getElementById('eqList').innerHTML = '<div class="eq-empty">' + eqEsc(msg) + '</div>';
}

function eqRender(rows) {
    if (!rows.length) { eqEmpty(window.t('self-service.equipment.none')); return; }

    document.getElementById('eqList').innerHTML =
        '<div class="eq-grid">' + rows.map(function (a) {
            // Named in the order somebody would actually recognise the thing:
            // what it is called on the network, then the label stuck to it, then
            // the make and model. Never a bare id.
            const name = a.hostname || a.asset_tag
                       || [a.manufacturer, a.model].filter(Boolean).join(' ')
                       || window.t('self-service.equipment.unnamed');

            const facts = [];
            if (a.asset_tag)   facts.push([window.t('self-service.equipment.asset_tag'), a.asset_tag, false]);
            if (a.service_tag) facts.push([window.t('self-service.equipment.serial'),    a.service_tag, true]);
            const make = [a.manufacturer, a.model].filter(Boolean).join(' ');
            if (make)          facts.push([window.t('self-service.equipment.make'),      make, false]);

            return '<article class="eq-card">' +
                '<h2>' + eqEsc(name) + '</h2>' +
                (a.type_name ? '<p class="eq-type">' + eqEsc(a.type_name) + '</p>' : '') +
                (facts.length
                    ? '<dl class="eq-facts">' + facts.map(function (f) {
                          return '<dt>' + eqEsc(f[0]) + '</dt><dd' + (f[2] ? ' class="mono"' : '') + '>' + eqEsc(f[1]) + '</dd>';
                      }).join('') + '</dl>'
                    : '') +
            '</article>';
        }).join('') + '</div>';
}

eqLoad();
JS;

require_once __DIR__ . '/includes/footer.php';
