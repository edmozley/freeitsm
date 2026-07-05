<?php
/**
 * System - Landing page with links to system areas
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/functions.php';
require_once '../includes/tenancy.php';
require_once 'includes/areas.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

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
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
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
            color: #333;
            margin: 0 0 8px 0;
        }

        .landing-content .subtitle {
            font-size: 14px;
            color: #888;
            margin: 0 0 24px 0;
        }

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
            background: #fff;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .system-search input:focus {
            outline: none;
            border-color: #546e7a;
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
            color: #888;
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
            background: #fff;
            border-radius: 10px;
            padding: 20px 18px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s, box-shadow 0.15s;
            border: 2px solid transparent;
        }

        .system-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: #546e7a;
        }

        .system-card svg {
            width: 30px;
            height: 30px;
            color: #546e7a;
            margin-bottom: 10px;
        }

        .system-card h3 {
            margin: 0 0 6px 0;
            font-size: 16px;
            color: #333;
        }

        .system-card p {
            margin: 0;
            font-size: 12px;
            color: #888;
            line-height: 1.45;
        }

        /* Help card — visually distinct from the admin areas. */
        .system-card--help {
            background: linear-gradient(135deg, #ffffff 0%, #eef2ff 100%);
            border-color: #c7d2fe;
        }
        .system-card--help svg { color: #6366f1; }
        .system-card--help:hover { border-color: #6366f1; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container system-landing">
        <div class="landing-content">
            <h2><?php echo htmlspecialchars(t('system.landing.heading')); ?></h2>
            <p class="subtitle"><?php echo htmlspecialchars(t('system.landing.subtitle')); ?></p>

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
</body>
</html>
