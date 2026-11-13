<?php
/**
 * System - Maintenance landing (GH #263).
 *
 * The tools that find data left behind and put it right, as a searchable
 * catalogue like Debug tools. Each card says how many items its tool has found,
 * so the page says where to look; each opens the tool's own page. The list is
 * driven by the registry in includes/tools.php.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once 'includes/tools.php';
require_once '../includes/areas.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'maintenance';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

$conn = connectToDatabase();
$tools = [];
foreach (getMaintenanceTools() as $tool) {
    $tools[] = $tool + ['found' => maintenanceToolCount($conn, $tool)];
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.maintenance.heading'))); ?></title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css?v=79">
    <style>
        body {
            /* System's dark accent is a LIGHT colour, so --on-accent is pinned too
               (see system/debug-tools/index.php). */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .maint-landing { flex: 1; display: flex; justify-content: center; align-items: flex-start; background: var(--app-bg, #f5f7fa); overflow-y: auto; }
        .landing-content { width: 100%; margin: 0 auto; padding: 36px 40px; box-sizing: border-box; }
        .landing-head { text-align: center; }
        .landing-head h2 { font-size: 24px; color: var(--text, #333); margin: 0 0 8px; }
        .landing-head .subtitle { font-size: 14px; color: var(--text-dim, #888); margin: 0 auto 22px; max-width: 720px; line-height: 1.5; }

        .maint-search { position: relative; max-width: 420px; margin: 0 auto 30px; }
        .maint-search input { width: 100%; padding: 11px 14px 11px 40px; border: 1px solid var(--border, #d6dde3); border-radius: 8px; font-size: 14px; background: var(--surface, #fff); color: var(--text, #333); box-sizing: border-box; transition: border-color 0.15s, box-shadow 0.15s; }
        .maint-search input::placeholder { color: var(--text-faint, #999); }
        .maint-search input:focus { outline: none; border-color: var(--sys-accent, #546e7a); box-shadow: 0 0 0 3px rgba(84,110,122,0.12); }
        .maint-search svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--text-faint, #9aa7b0); pointer-events: none; }

        .maint-no-results { display: none; text-align: center; color: var(--text-dim, #888); font-size: 14px; margin-top: 8px; }

        .maint-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .maint-card { background: var(--surface, #fff); border-radius: 10px; padding: 20px 18px; box-shadow: 0 2px 12px var(--shadow, rgba(0,0,0,0.08)); text-decoration: none; color: inherit; transition: transform 0.15s, box-shadow 0.15s; border: 2px solid transparent; display: flex; flex-direction: column; }
        .maint-card:hover { transform: translateY(-4px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); border-color: var(--sys-accent, #546e7a); }
        .maint-card.is-hidden { display: none; }
        .maint-card-top { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .maint-card-top svg { width: 26px; height: 26px; color: var(--sys-accent, #546e7a); flex-shrink: 0; }
        .maint-card h3 { margin: 0 0 5px; font-size: 15.5px; color: var(--text, #333); }
        .maint-card p { margin: 0; font-size: 12.5px; color: var(--text-dim, #888); line-height: 1.45; }

        /* The count: amber when there is something to look at, quiet when not. */
        .maint-count { margin-left: auto; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 10px; white-space: nowrap; }
        .maint-count.has-items { background: #fff4e5; color: #9a5b00; }
        .maint-count.is-clear  { background: var(--surface-3, #f2f4f5); color: var(--text-dim, #888); font-weight: 500; }

        [data-theme-mode="dark"] .maint-count.has-items { background: var(--warning-bg, #3a2c12); color: var(--warning-text, #fcd38a); }
        /* The header is taller than the 48px inbox.css assumes (calc(100vh - 48px)), which put the
           bottom of this page below the window. As the tickets inbox does: the body is a column,
           the header takes its real height and the page fills the rest. Desktop only;
           mobile.css has its own layout. */
        @media (min-width: 769px) {
            body { display: flex; flex-direction: column; }
            body > .header { flex-shrink: 0; }
            body > .main-container { height: auto; flex: 1 1 auto; min-height: 0; }
        }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="maintenance">
    <?php include '../includes/header.php'; ?>

    <div class="main-container maint-landing">
        <div class="landing-content">
            <div class="landing-head">
                <h2><?php echo htmlspecialchars(t('system.maintenance.heading')); ?></h2>
                <p class="subtitle"><?php echo htmlspecialchars(t('system.maintenance.intro')); ?></p>
            </div>

            <div class="maint-search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="maintSearch" autocomplete="off" autofocus
                       placeholder="<?php echo htmlspecialchars(t('system.maintenance.search_placeholder')); ?>"
                       aria-label="<?php echo htmlspecialchars(t('system.maintenance.search_placeholder')); ?>">
            </div>

            <div class="maint-cards" id="maintCards">
                <?php foreach ($tools as $tool):
                    $title = t($tool['title']);
                    $desc  = t($tool['desc']);
                    $haystack = mb_strtolower(trim($title . ' ' . $desc . ' ' . systemAreaKeywords($tool)));
                    $found = $tool['found'];
                ?>
                    <a href="../<?php echo htmlspecialchars($tool['url']); ?>" class="maint-card" data-search="<?php echo htmlspecialchars($haystack); ?>">
                        <div class="maint-card-top">
                            <?php echo systemAreaIcon($tool['icon']); ?>
                            <?php if ($found !== null): ?>
                                <span class="maint-count <?php echo $found > 0 ? 'has-items' : 'is-clear'; ?>">
                                    <?php echo htmlspecialchars($found > 0 ? t('system.maintenance.count_found', ['n' => $found]) : t('system.maintenance.count_none')); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($title); ?></h3>
                        <p><?php echo htmlspecialchars($desc); ?></p>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="maint-no-results" id="maintNoResults"><?php echo htmlspecialchars(t('system.maintenance.no_results')); ?></p>
        </div>
    </div>

    <script>
    (function () {
        var input = document.getElementById('maintSearch');
        var cards = Array.prototype.slice.call(document.querySelectorAll('#maintCards .maint-card'));
        var noResults = document.getElementById('maintNoResults');
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
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
