<?php
/**
 * System - Debug Tools landing.
 *
 * A searchable catalogue of diagnostics (same live-filter pattern as the System
 * landing). Each card opens that tool's own page at <slug>/index.php. The list
 * is driven by the registry in includes/tools.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once 'includes/tools.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'debug-tools';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}

$debugTools = getDebugTools();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.debug.heading')); ?></title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css">
    <style>
        .debug-landing { flex: 1; display: flex; justify-content: center; align-items: flex-start; background: #f5f7fa; overflow-y: auto; }
        .landing-content { width: 100%; margin: 0 auto; padding: 36px 40px; box-sizing: border-box; }
        .landing-head { text-align: center; }
        .landing-head h2 { font-size: 24px; color: #333; margin: 0 0 8px; }
        .landing-head .subtitle { font-size: 14px; color: #888; margin: 0 auto 22px; max-width: 720px; line-height: 1.5; }

        .intro-card { max-width: 720px; margin: 0 auto 26px; background: #e8f4fd; border: 1px solid #90caf9; border-radius: 8px; padding: 12px 16px; display: flex; align-items: flex-start; gap: 12px; text-align: left; }
        .intro-card svg { color: #1976d2; flex-shrink: 0; margin-top: 2px; }
        .intro-card .intro-text { font-size: 12.5px; color: #1565c0; line-height: 1.5; }
        .intro-card .intro-text strong { color: #0d47a1; }

        .debug-search { position: relative; max-width: 420px; margin: 0 auto 30px; }
        .debug-search input { width: 100%; padding: 11px 14px 11px 40px; border: 1px solid #d6dde3; border-radius: 8px; font-size: 14px; background: #fff; box-sizing: border-box; transition: border-color 0.15s, box-shadow 0.15s; }
        .debug-search input:focus { outline: none; border-color: #546e7a; box-shadow: 0 0 0 3px rgba(84,110,122,0.12); }
        .debug-search svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: #9aa7b0; pointer-events: none; }

        .debug-no-results { display: none; text-align: center; color: #888; font-size: 14px; margin-top: 8px; }

        .debug-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .debug-card { background: #fff; border-radius: 10px; padding: 20px 18px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); text-decoration: none; color: inherit; transition: transform 0.15s, box-shadow 0.15s; border: 2px solid transparent; display: flex; flex-direction: column; }
        .debug-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); border-color: #546e7a; }
        .debug-card.is-hidden { display: none; }
        .debug-card-top { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .debug-card-top svg { width: 26px; height: 26px; color: #546e7a; flex-shrink: 0; }
        .debug-card-id { background: #546e7a; color: #fff; font-size: 10.5px; font-weight: 700; letter-spacing: 0.5px; padding: 3px 8px; border-radius: 4px; font-family: 'Consolas', monospace; }
        .debug-card-cat { margin-left: auto; font-size: 10px; text-transform: uppercase; letter-spacing: 0.8px; color: #888; background: #f5f5f5; padding: 3px 7px; border-radius: 4px; }
        .debug-card h3 { margin: 0 0 5px; font-size: 15.5px; color: #333; }
        .debug-card p { margin: 0; font-size: 12.5px; color: #888; line-height: 1.45; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container debug-landing">
        <div class="landing-content">
            <div class="landing-head">
                <h2><?php echo htmlspecialchars(t('system.debug.heading')); ?></h2>
                <p class="subtitle"><?php echo htmlspecialchars(t('system.debug.intro')); ?></p>
            </div>

            <div class="intro-card">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <div class="intro-text">
                    <strong><?php echo htmlspecialchars(t('system.debug.how_label')); ?></strong> <?php echo t('system.debug.how_text', ['run' => '<strong>' . htmlspecialchars(t('system.debug.run')) . '</strong>', 'copy' => '<strong>' . htmlspecialchars(t('system.debug.copy')) . '</strong>']); ?>
                </div>
            </div>

            <div class="debug-search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="debugSearch" autocomplete="off" autofocus
                       placeholder="<?php echo htmlspecialchars(t('system.debug.search_placeholder')); ?>"
                       aria-label="<?php echo htmlspecialchars(t('system.debug.search_placeholder')); ?>">
            </div>

            <div class="debug-cards" id="debugCards">
                <?php foreach ($debugTools as $tool):
                    $haystack = mb_strtolower(trim($tool['id'] . ' ' . $tool['title'] . ' ' . $tool['category'] . ' ' . $tool['desc'] . ' ' . ($tool['keywords'] ?? '')));
                ?>
                    <a href="<?php echo htmlspecialchars($tool['slug']); ?>/" class="debug-card" data-search="<?php echo htmlspecialchars($haystack); ?>">
                        <div class="debug-card-top">
                            <?php echo debugToolIcon($tool['icon'] ?? ''); ?>
                            <span class="debug-card-id"><?php echo htmlspecialchars($tool['id']); ?></span>
                            <span class="debug-card-cat"><?php echo htmlspecialchars($tool['category']); ?></span>
                        </div>
                        <h3><?php echo htmlspecialchars($tool['title']); ?></h3>
                        <p><?php echo htmlspecialchars($tool['desc']); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="debug-no-results" id="debugNoResults"><?php echo htmlspecialchars(t('system.debug.no_results')); ?></p>
        </div>
    </div>

    <script>
    (function () {
        var input = document.getElementById('debugSearch');
        var cards = Array.prototype.slice.call(document.querySelectorAll('#debugCards .debug-card'));
        var noResults = document.getElementById('debugNoResults');
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
        // Enter on a single remaining match jumps straight into it.
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var visible = cards.filter(function (c) { return !c.classList.contains('is-hidden'); });
            if (visible.length === 1) window.location.href = visible[0].getAttribute('href');
        });
    })();
    </script>
</body>
</html>
