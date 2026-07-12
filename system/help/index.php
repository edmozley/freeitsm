<?php
/**
 * System Help — landing page. A searchable card per system area.
 *
 * Cards come from helpCards() (system/help/_registry.php), which joins the
 * System area registry (icons + translated titles) to the help topic registry
 * (sections + search terms). Two consequences worth knowing:
 *
 *   - An area with no help page yet gets NO card, rather than a card linking to
 *     a page that doesn't exist. It's listed as a gap at the foot instead.
 *   - Search matches a card's title, description, the area's keyword synonyms,
 *     the page's standfirst, its section headings and its extra search terms —
 *     and a section-heading hit deep-links straight to that section.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/i18n.php';
require_once '../includes/areas.php';
require_once __DIR__ . '/_registry.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$path_prefix = '../../';
$current_page = 'help';

$multiTenant = false;
try { $multiTenant = isMultiTenant(connectToDatabase()); } catch (Exception $e) {}

$cards   = helpCards($multiTenant);
$missing = helpTopicsMissing($multiTenant);
$query   = trim($_GET['q'] ?? '');   // lets you link someone straight to a search
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Help</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Pin the shared --accent (header/inbox.css primitives) to the System accent. */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .syshelp-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f6fa); }
        .syshelp-hero { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 50%, #3730a3 100%); color: #fff; padding: 40px 48px 36px; }
        .syshelp-hero h1 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .syshelp-hero p { margin: 0; font-size: 14.5px; opacity: 0.9; max-width: 720px; line-height: 1.5; }

        /* Search. The icon and clear button are centred against the INPUT, so the
           hint line must live outside .syshelp-search — inside it, the wrapper
           would be as tall as input+hint and `top: 50%` would sit them low. */
        .syshelp-searchbar { margin: 20px 0 0; max-width: 520px; }
        .syshelp-search { position: relative; }
        .syshelp-search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: rgba(255,255,255,0.75); pointer-events: none; }
        .syshelp-search input { width: 100%; box-sizing: border-box; padding: 11px 40px 11px 40px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.28); background: rgba(255,255,255,0.14); color: #fff; font-size: 14px; }
        .syshelp-search input::placeholder { color: rgba(255,255,255,0.7); }
        .syshelp-search input:focus { outline: none; border-color: rgba(255,255,255,0.7); background: rgba(255,255,255,0.2); }
        /* Hide the browser's native clear "x" — we render our own, in the same spot. */
        .syshelp-search input::-webkit-search-cancel-button { -webkit-appearance: none; appearance: none; }
        .syshelp-search-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); display: none; padding: 4px 8px; border: none; background: transparent; color: rgba(255,255,255,0.8); font-size: 16px; cursor: pointer; border-radius: 5px; }
        .syshelp-search-clear:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .syshelp-search.has-value .syshelp-search-clear { display: block; }
        .syshelp-search-hint { margin-top: 8px; font-size: 12.5px; opacity: 0.75; min-height: 16px; }

        .syshelp-grid { max-width: 1100px; margin: 0 auto; padding: 28px 48px 56px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .syshelp-tile { display: flex; flex-direction: column; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; transition: transform 0.12s, box-shadow 0.12s; }
        .syshelp-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 18px var(--shadow, rgba(0,0,0,0.08)); }
        .syshelp-tile[hidden] { display: none; }
        .syshelp-tile-main { display: flex; gap: 14px; padding: 18px; text-decoration: none; }
        .syshelp-tile-icon { flex-shrink: 0; width: 44px; height: 44px; border-radius: 10px; background: #eef2ff; color: #6366f1; display: flex; align-items: center; justify-content: center; }
        .syshelp-tile-icon svg { width: 24px; height: 24px; }
        .syshelp-tile h3 { margin: 0 0 4px; font-size: 15px; color: var(--text, #1f2330); }
        .syshelp-tile p { margin: 0; font-size: 12.5px; color: var(--text-muted, #6b7280); line-height: 1.5; }

        /* Section deep-links: hidden until a search matches a heading inside the page. */
        .syshelp-hits { display: none; padding: 0 18px 14px 76px; flex-wrap: wrap; gap: 6px; }
        .syshelp-tile.has-hits .syshelp-hits { display: flex; }
        .syshelp-hit { font-size: 12px; padding: 3px 9px; border-radius: 20px; background: #eef2ff; color: #3730a3; text-decoration: none; }
        .syshelp-hit:hover { background: #e0e7ff; }
        .syshelp-hit[hidden] { display: none; }

        .syshelp-empty { display: none; max-width: 1100px; margin: 0 auto; padding: 10px 48px 40px; }
        .syshelp-empty p { font-size: 14px; color: var(--text-muted, #6b7280); line-height: 1.6; }
        .syshelp-gaps { max-width: 1100px; margin: 0 auto; padding: 0 48px 48px; font-size: 12.5px; color: var(--text-dim, #9ca3af); }

        @media (max-width: 700px) { .syshelp-grid { padding: 22px; } .syshelp-hero { padding: 30px 22px; } .syshelp-hits { padding-left: 18px; } }

        /* ---- Dark mode: indigo hero + pale indigo icon tiles ---- */
        [data-theme-mode="dark"] .syshelp-hero { filter: brightness(0.82); }
        [data-theme-mode="dark"] .syshelp-tile-icon { background: #2b2f4a; color: #a5b4fc; }
        [data-theme-mode="dark"] .syshelp-hit { background: #2b2f4a; color: #c7d2fe; }
        [data-theme-mode="dark"] .syshelp-hit:hover { background: #363b5e; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="syshelp-wrap">
        <div class="syshelp-hero">
            <h1>System help</h1>
            <p>Guides for every area of the System admin. Start with whichever you're setting up — each page is written to be followed top to bottom.</p>
            <div class="syshelp-searchbar">
                <div class="syshelp-search" id="searchWrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="search" id="helpSearch" placeholder="Search the guides — try &ldquo;locked out&rdquo;, &ldquo;slack&rdquo;, &ldquo;wrong company&rdquo;"
                           value="<?php echo htmlspecialchars($query); ?>" autocomplete="off" autofocus>
                    <button type="button" class="syshelp-search-clear" id="searchClear" aria-label="Clear search">&times;</button>
                </div>
                <div class="syshelp-search-hint" id="searchHint"><?php echo count($cards); ?> guides</div>
            </div>
        </div>

        <div class="syshelp-grid" id="helpGrid">
            <?php foreach ($cards as $card): ?>
                <div class="syshelp-tile" data-search="<?php echo htmlspecialchars(helpCardHaystack($card)); ?>">
                    <a class="syshelp-tile-main" href="<?php echo htmlspecialchars($card['href']); ?>">
                        <div class="syshelp-tile-icon"><?php echo systemAreaIcon($card['icon']); ?></div>
                        <div>
                            <h3><?php echo htmlspecialchars($card['title']); ?></h3>
                            <p><?php echo htmlspecialchars($card['desc']); ?></p>
                        </div>
                    </a>
                    <div class="syshelp-hits">
                        <?php foreach ($card['sections'] as $s): ?>
                            <a class="syshelp-hit"
                               href="<?php echo htmlspecialchars($card['href'] . '#' . $s['id']); ?>"
                               data-label="<?php echo htmlspecialchars(strtolower($s['label'])); ?>"><?php echo htmlspecialchars($s['label']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="syshelp-empty" id="helpEmpty">
            <p>No guide matches that. Try a plainer word — the guides are indexed by what you'd <em>call</em> the problem (&ldquo;locked out&rdquo;, &ldquo;not sending&rdquo;, &ldquo;can't see tickets&rdquo;) as well as by feature name.</p>
        </div>

        <?php if ($missing): ?>
            <div class="syshelp-gaps">
                Not yet documented: <?php echo htmlspecialchars(implode(', ', $missing)); ?>.
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        const input = document.getElementById('helpSearch');
        const wrap  = document.getElementById('searchWrap');
        const hint  = document.getElementById('searchHint');
        const empty = document.getElementById('helpEmpty');
        const tiles = Array.from(document.querySelectorAll('.syshelp-tile'));
        const total = tiles.length;

        function apply() {
            const q = input.value.trim().toLowerCase();
            wrap.classList.toggle('has-value', q !== '');

            let shown = 0;
            tiles.forEach(tile => {
                const match = q === '' || tile.dataset.search.includes(q);
                tile.hidden = !match;
                if (match) shown++;

                // A hit on a section heading deep-links into the page. Only surface
                // these while searching — otherwise every card sprouts a chip list.
                let hits = 0;
                tile.querySelectorAll('.syshelp-hit').forEach(hit => {
                    const on = q !== '' && hit.dataset.label.includes(q);
                    hit.hidden = !on;
                    if (on) hits++;
                });
                tile.classList.toggle('has-hits', match && hits > 0);
            });

            empty.style.display = shown === 0 ? 'block' : 'none';
            hint.textContent = q === ''
                ? total + ' guides'
                : shown + (shown === 1 ? ' guide matches' : ' guides match') + ' “' + input.value.trim() + '”';
        }

        input.addEventListener('input', apply);
        document.getElementById('searchClear').addEventListener('click', () => {
            input.value = '';
            input.focus();
            apply();
        });
        input.addEventListener('keydown', e => {
            if (e.key === 'Escape') { input.value = ''; apply(); }
        });
        // "/" from anywhere on the page jumps to the search box.
        document.addEventListener('keydown', e => {
            if (e.key === '/' && document.activeElement !== input) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });

        apply();   // honours any ?q= in the URL
    })();
    </script>
</body>
</html>
