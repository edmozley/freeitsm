<?php
/**
 * System Help — landing page. A card per system area, linking to its help
 * topic. Cards are driven by the same registry as the System landing
 * (system/includes/areas.php), so the two stay in step.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/i18n.php';
require_once '../includes/areas.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$path_prefix = '../../';
$current_page = 'help';

$areas = getSystemAreas();
$multiTenant = false;
try { $multiTenant = isMultiTenant(connectToDatabase()); } catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Help</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .syshelp-wrap { height: calc(100vh - 48px); overflow-y: auto; background: #f5f6fa; }
        .syshelp-hero { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 50%, #3730a3 100%); color: #fff; padding: 40px 48px 36px; }
        .syshelp-hero h1 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .syshelp-hero p { margin: 0; font-size: 14.5px; opacity: 0.9; max-width: 720px; line-height: 1.5; }
        .syshelp-grid { max-width: 1100px; margin: 0 auto; padding: 28px 48px 56px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .syshelp-tile { display: flex; gap: 14px; padding: 18px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; text-decoration: none; transition: transform 0.12s, box-shadow 0.12s; }
        .syshelp-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
        .syshelp-tile-icon { flex-shrink: 0; width: 44px; height: 44px; border-radius: 10px; background: #eef2ff; color: #6366f1; display: flex; align-items: center; justify-content: center; }
        .syshelp-tile-icon svg { width: 24px; height: 24px; }
        .syshelp-tile h3 { margin: 0 0 4px; font-size: 15px; color: #1f2330; }
        .syshelp-tile p { margin: 0; font-size: 12.5px; color: #6b7280; line-height: 1.5; }
        @media (max-width: 700px) { .syshelp-grid { padding: 22px; } .syshelp-hero { padding: 30px 22px; } }
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
        </div>
        <div class="syshelp-grid">
            <?php foreach ($areas as $a):
                if (($a['requires'] ?? '') === 'multitenant' && !$multiTenant) continue;
                $slug = rtrim($a['url'], '/');
            ?>
                <a class="syshelp-tile" href="<?php echo htmlspecialchars($slug); ?>.php">
                    <div class="syshelp-tile-icon"><?php echo systemAreaIcon($a['icon']); ?></div>
                    <div>
                        <h3><?php echo htmlspecialchars(t($a['title'])); ?></h3>
                        <p><?php echo htmlspecialchars(t($a['desc'])); ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
