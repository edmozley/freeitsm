<?php
/**
 * System - Landing page with links to system areas
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/functions.php';
require_once '../includes/theme.php';
require_once '../includes/tenancy.php';
require_once 'includes/areas.php';
require_once '../includes/timezone.php';
require_once '../includes/storage_persistence.php';
require_once '../includes/version.php';
I18n::initFromSession();
Tz::init();

// Issue #109. Under Docker, updating rebuilds the container and destroys anything
// inside it that is not on a volume — and the loss is unrecoverable once it has
// happened, because the old container is removed with the files still in it. So
// the only useful place to say anything is here, on a screen an administrator is
// already looking at, BEFORE they run the update. A wiki page reaches nobody who
// has not already lost the data.
//
// ⚠️ Not dismissible. FreeITSM's convention is that warnings can be dismissed and
// errors cannot (#1112), and this is the second kind: dismissing it would not make
// the files any safer, and the next update would still take them.
$storageReport = ['applicable' => false, 'at_risk' => 0];
try { $storageReport = storagePersistenceReport(); } catch (Throwable $e) { /* never break the page over a diagnostic */ }
$storageAtRisk = !empty($storageReport['applicable']) && $storageReport['at_risk'] > 0;

// Some areas are gated on a runtime condition the registry can't evaluate.
// 'multitenant' (e.g. the email routing test) stays invisible at N=1.
$isMultiTenant = false;
try { $isMultiTenant = isMultiTenant(connectToDatabase()); } catch (Exception $e) { $isMultiTenant = false; }

// Filter the registry down to the areas this install should show.
$systemAreas = array_filter(getSystemAreas(), function ($area) use ($isMultiTenant) {
    if (($area['requires'] ?? '') === 'multitenant') return $isMultiTenant;
    return true;
});

$current_page = 'system';
$path_prefix = '../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <style>
        /* System module accent (blue-grey) — shared primitives pick this up. */
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

        .system-landing {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            background: #f5f7fa;
            overflow-y: auto;
        }

        .landing-content {
            text-align: center;
            width: 100%;
            margin: 0 auto;
            padding: 40px 40px;
            box-sizing: border-box;
        }

        .landing-content h2 {
            font-size: 24px;
            color: var(--text, #333);
            margin: 0 0 8px 0;
        }

        .landing-content .subtitle {
            font-size: 14px;
            color: var(--text-dim, #888);
            margin: 0 0 24px 0;
        }

        .landing-version {
            font-size: 13px;
            color: var(--text-dim, #888);
            margin: -16px 0 24px 0;
        }

        .landing-version a {
            color: inherit;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .landing-version a:hover { color: var(--sys-accent, #546e7a); }

        .system-search {
            position: relative;
            max-width: 420px;
            margin: 0 auto 32px;
        }

        .system-search input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1px solid #d6dde3;
            border-radius: 8px;
            font-size: 14px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .system-search input:focus {
            outline: none;
            border-color: var(--sys-accent, #546e7a);
            box-shadow: 0 0 0 3px rgba(84,110,122,0.12);
        }

        .system-search svg {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #9aa7b0;
            pointer-events: none;
        }

        .system-no-results {
            display: none;
            color: var(--text-dim, #888);
            font-size: 14px;
            margin-top: 8px;
        }

        .system-card.is-hidden {
            display: none;
        }

        .system-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 16px;
        }

        .system-card {
            background: var(--surface, #fff);
            border-radius: 10px;
            padding: 20px 18px;
            box-shadow: 0 2px 12px var(--shadow, rgba(0,0,0,0.08));
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s, box-shadow 0.15s;
            border: 2px solid transparent;
        }

        .system-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: var(--sys-accent, #546e7a);
        }

        .system-card svg {
            width: 30px;
            height: 30px;
            color: var(--sys-accent, #546e7a);
            margin-bottom: 10px;
        }

        .system-card h3 {
            margin: 0 0 6px 0;
            font-size: 16px;
            color: var(--text, #333);
        }

        .system-card p {
            margin: 0;
            font-size: 12px;
            color: var(--text-dim, #888);
            line-height: 1.45;
        }

        /* Help card — visually distinct from the admin areas. The indigo is the
           help area's identity colour, so it stays put in both modes. */
        .system-card--help {
            background: linear-gradient(135deg, #ffffff 0%, #eef2ff 100%);
            border-color: #c7d2fe;
        }
        .system-card--help svg { color: #6366f1; }
        .system-card--help:hover { border-color: #6366f1; }

        /* ---- Dark mode: pale washes / off-token greys that would glow ---- */
        [data-theme-mode="dark"] .system-landing {
            background: var(--app-bg, #14171c);
        }
        [data-theme-mode="dark"] .system-search input {
            border-color: var(--border, #343b45);
        }
        [data-theme-mode="dark"] .system-search input:focus {
            box-shadow: 0 0 0 3px rgba(144,164,174,0.20);
        }
        [data-theme-mode="dark"] .system-search svg {
            color: var(--text-faint, #79818b);
        }
        [data-theme-mode="dark"] .system-card {
            border-color: var(--border, #343b45);
        }
        [data-theme-mode="dark"] .system-card--help {
            background: linear-gradient(135deg, var(--surface, #1e2228) 0%, #262a3d 100%);
            border-color: #4b4f78;
        }
        [data-theme-mode="dark"] .system-card--help svg { color: #a5b4fc; }
        [data-theme-mode="dark"] .system-card--help:hover { border-color: #a5b4fc; }

        /* Issue #109 — files that an update will destroy. Literal colours with an
           explicit dark override, matching the rest of this page: the theme has no
           bare --danger token and a phantom var() renders as nothing at all. */
        .storage-alert {
            text-align: left;
            margin: 0 0 28px;
            padding: 16px 18px;
            background: #fdecea;
            border: 1px solid #f5c2c0;
            border-left: 4px solid #c0392b;
            border-radius: 6px;
            color: #7d1f16;
            font-size: 13px;
            line-height: 1.55;
        }
        .storage-alert__title { font-weight: 700; font-size: 14px; margin-bottom: 6px; }
        .storage-alert p { margin: 0 0 8px; }
        .storage-alert__list { margin: 0 0 10px; padding-left: 18px; }
        .storage-alert__list li { margin-bottom: 3px; }
        .storage-alert__list code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            background: rgba(192, 57, 43, 0.10);
            padding: 1px 5px;
            border-radius: 3px;
            margin-right: 6px;
        }
        .storage-alert__critical { font-weight: 600; }
        .storage-alert__act { font-weight: 600; margin-bottom: 10px; }
        .storage-alert__link {
            display: inline-block;
            padding: 7px 14px;
            background: #c0392b;
            color: #fff;
            border-radius: 5px;
            font-weight: 600;
            text-decoration: none;
        }
        .storage-alert__link:hover { background: #a93226; }

        [data-theme-mode="dark"] .storage-alert {
            background: #3b1512;
            border-color: #7d2e26;
            border-left-color: #e74c3c;
            color: #f3c7c2;
        }
        [data-theme-mode="dark"] .storage-alert__list code { background: rgba(231, 76, 60, 0.18); }
        [data-theme-mode="dark"] .storage-alert__link { background: #c0392b; color: #fff; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=133">
    <?php echo Tz::scriptTag(); ?>
    <!-- $translationNamespaces was being prepared above and then never shipped, so
         window.t did not exist on this page and the notification bell threw on its
         first line rather than fetching anything. Same fault as GH #78. -->
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script src="../assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="system-landing">
    <?php include 'includes/header.php'; ?>

    <div class="main-container system-landing">
        <div class="landing-content">

            <?php if ($storageAtRisk): ?>
            <?php /* The count and the folder names are the whole message: "some files"
                     is ignorable, "6 folders, including your screen recordings" is not. */ ?>
            <div class="storage-alert" role="alert">
                <div class="storage-alert__title">
                    <?php echo htmlspecialchars(t('system.storage_alert.title', ['n' => $storageReport['at_risk']])); ?>
                </div>
                <p><?php echo htmlspecialchars(t('system.storage_alert.body')); ?></p>
                <ul class="storage-alert__list">
                    <?php foreach ($storageReport['directories'] as $d): ?>
                        <?php if ($d['status'] !== 'at_risk') continue; ?>
                        <li>
                            <code><?php echo htmlspecialchars($d['rel']); ?></code>
                            <span><?php echo htmlspecialchars($d['label']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($storageReport['critical_at_risk'])): ?>
                    <p class="storage-alert__critical"><?php echo htmlspecialchars(t('system.storage_alert.encryption_key')); ?></p>
                <?php endif; ?>
                <p class="storage-alert__act"><?php echo htmlspecialchars(t('system.storage_alert.act_first')); ?></p>
                <a class="storage-alert__link" href="debug-tools/d013/"><?php echo htmlspecialchars(t('system.storage_alert.run_d013')); ?></a>
            </div>
            <?php endif; ?>

            <h2><?php echo htmlspecialchars(t('system.landing.heading')); ?></h2>
            <p class="subtitle"><?php echo htmlspecialchars(t('system.landing.subtitle')); ?></p>

            <?php /* Discussion #92: the version an operator is running, on a screen they
                     already open, linked to what changed. Plain text, not a badge — it is
                     reference information, not something to act on. */ ?>
            <p class="landing-version">
                <?php echo htmlspecialchars(t('system.landing.version')); ?>
                <a href="<?php echo htmlspecialchars(freeitsmReleaseUrl()); ?>"
                   target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(freeitsmVersion()); ?></a>
            </p>

            <div class="system-search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="systemSearch" autocomplete="off" autofocus
                       placeholder="<?php echo htmlspecialchars(t('system.landing.search_placeholder')); ?>"
                       aria-label="<?php echo htmlspecialchars(t('system.landing.search_placeholder')); ?>">
            </div>

            <div class="system-cards" id="systemCards">
                <!-- Help is rendered directly (not from the areas registry) so it
                     appears here but never recurses onto the help landing. -->
                <a href="help/" class="system-card system-card--help"
                   data-search="help guides guide documentation how to setup instructions manual sso single sign-on">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    <h3><?php echo htmlspecialchars(t('system.landing.help_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('system.landing.help_desc')); ?></p>
                </a>
                <?php foreach ($systemAreas as $area): ?>
                    <?php
                    $title = t($area['title']);
                    $desc  = t($area['desc']);
                    // Keywords are i18n keys; if a synonym key isn't defined the
                    // resolver returns the key itself — strip that so it never
                    // pollutes the search haystack.
                    $kw = t($area['keywords']);
                    if ($kw === $area['keywords']) $kw = '';
                    $haystack = mb_strtolower(trim($title . ' ' . $desc . ' ' . $kw));
                    ?>
                    <a href="<?php echo htmlspecialchars($area['url']); ?>" class="system-card"
                       data-search="<?php echo htmlspecialchars($haystack); ?>">
                        <?php echo systemAreaIcon($area['icon']); ?>
                        <h3><?php echo htmlspecialchars($title); ?></h3>
                        <p><?php echo htmlspecialchars($desc); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="system-no-results" id="systemNoResults"><?php echo htmlspecialchars(t('system.landing.no_results')); ?></p>
        </div>
    </div>

    <script>
    (function () {
        var input = document.getElementById('systemSearch');
        var cards = Array.prototype.slice.call(document.querySelectorAll('#systemCards .system-card'));
        var noResults = document.getElementById('systemNoResults');
        if (!input) return;

        function filter() {
            var q = input.value.trim().toLowerCase();
            var shown = 0;
            cards.forEach(function (card) {
                var match = q === '' || (card.getAttribute('data-search') || '').indexOf(q) !== -1;
                card.classList.toggle('is-hidden', !match);
                if (match) shown++;
            });
            noResults.style.display = shown === 0 ? 'block' : 'none';
        }

        input.addEventListener('input', filter);
        // Pressing Enter on a single remaining match jumps straight into it.
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var visible = cards.filter(function (c) { return !c.classList.contains('is-hidden'); });
            if (visible.length === 1) window.location.href = visible[0].getAttribute('href');
        });
    })();
    </script>
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
