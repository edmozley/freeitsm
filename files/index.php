<?php
/**
 * Files — the desktop.
 *
 * A Windows-like desktop for sharing files securely: desktop icons, a taskbar
 * with live window previews, a start menu, and Explorer windows (two panes,
 * icons/details views, a filter box in each). Everything is drawn by
 * assets/js/files-wm.js (windows, taskbar, menus) and assets/js/files-desktop.js
 * (Explorer, uploads, permissions, the apps). The server side is api/files/;
 * who-may-do-what is includes/files/acl.php and nowhere else.
 *
 * Phones get no desktop - a plain list of the same folders (LAYER 45 in
 * mobile.css), because a desktop metaphor at 390px is a worse file manager.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/rbac.php';
require_once '../includes/files/storage.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('files');

$current_page = 'desktop';
$path_prefix = '../';
$translationNamespaces = ['common', 'files'];

$conn = connectToDatabase();
$me   = (int)$_SESSION['analyst_id'];
$prefs = filesGetPrefs($conn, $me);
$logo  = filesDesktopLogo($conn, $me);
$hasCap = function (string $cap) use ($conn, $me): bool {
    try { return analystHasCapability($conn, $me, $cap); } catch (Throwable $e) { return false; }
};
$capFolders = $hasCap(Cap::FILES_FOLDERS);
$capStorage = $hasCap(Cap::FILES_STORAGE);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('files.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="../assets/css/files.css?v=3">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=193">
</head>
<body class="fd-body" data-navbar="<?php echo htmlspecialchars($prefs['navbar']); ?>" data-mobile-module="files" data-mobile-page="files-desktop">
    <div class="fd-navwrap" id="fdNavwrap">
        <?php include 'includes/header.php'; ?>
    </div>
    <div class="fd-navsensor" id="fdNavsensor" aria-hidden="true"></div>

    <div class="fd-desktop" id="fdDesktop" style="--fd-bg: <?php echo htmlspecialchars($prefs['desktop_colour']); ?>">
        <div class="fd-icons" id="fdIcons" role="list"></div>
        <?php if ($logo['url']): ?>
        <img class="fd-logo fd-logo-<?php echo $logo['position']; ?>" src="<?php echo htmlspecialchars($logo['url']); ?>" alt="">
        <?php endif; ?>
        <div class="fd-snap-ghost" id="fdSnapGhost" hidden></div>
    </div>

    <div class="fd-taskbar" id="fdTaskbar">
        <button type="button" class="fd-start" id="fdStart" aria-haspopup="menu" title="<?php echo htmlspecialchars(t('files.start.title')); ?>">
            <svg viewBox="0 0 24 24" width="22" height="22"><rect x="2" y="2" width="9" height="9" rx="1.5"/><rect x="13" y="2" width="9" height="9" rx="1.5"/><rect x="2" y="13" width="9" height="9" rx="1.5"/><rect x="13" y="13" width="9" height="9" rx="1.5"/></svg>
        </button>
        <div class="fd-tasks" id="fdTasks" role="toolbar"></div>
        <div class="fd-tray">
            <button type="button" class="fd-tray-transfers" id="fdTrayTransfers" hidden></button>
            <div class="fd-clock" id="fdClock"></div>
        </div>
    </div>

    <div class="fd-startmenu" id="fdStartMenu" hidden role="menu"></div>
    <div class="fd-ctx" id="fdCtx" hidden role="menu"></div>
    <div class="fd-preview" id="fdPreview" hidden></div>
    <input type="file" id="fdFileInput" multiple hidden>

    <!-- Phones: the same folders as a plain list. Filled by files-desktop.js. -->
    <div class="fd-mobile" id="fdMobile"></div>

    <?php
    // The modules this person may open, for the start menu's switcher - the same
    // filter the waffle uses, so the two can never disagree.
    $allowed = $_SESSION['allowed_modules'] ?? null;
    $startModules = [];
    foreach ($modules as $key => $m) {
        if ($key === 'system' ? !sessionIsAdmin() : ($allowed !== null && !in_array($key, $allowed))) continue;
        $startModules[] = ['key' => $key, 'name' => $m['name'], 'url' => BASE_URL . $m['path'], 'icon' => $m['icon']];
    }
    $boot = [
        'api'        => BASE_URL . 'api/files/',
        'base'       => BASE_URL,
        'me'         => ['id' => $me, 'name' => $_SESSION['analyst_name'] ?? ''],
        'prefs'      => $prefs,
        'logo'       => $logo,
        'caps'       => ['folders' => $capFolders, 'storage' => $capStorage],
        'chunk'      => FILES_CHUNK_BYTES,
        'maxUpload'  => filesMaxUploadBytes($conn),
        'modules'    => $startModules,
        'urls'       => [
            'help'     => BASE_URL . 'files/help.php?embed=1',
            'settings' => BASE_URL . 'files/settings/',
            'logout'   => BASE_URL . 'analyst_logout.php',
            'home'     => BASE_URL,
        ],
    ];
    ?>
    <script>window.FILES_BOOT = <?php echo json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/files-icons.js?v=1"></script>
    <script src="../assets/js/files-wm.js?v=3"></script>
    <script src="../assets/js/files-viewer.js?v=2"></script>
    <script src="../assets/js/files-desktop.js?v=3"></script>
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
