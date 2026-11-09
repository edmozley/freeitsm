<?php
/**
 * System - Imported people (issue #261).
 *
 * The people a directory source (LDAP, CardDAV, Entra ID) imported, looked
 * after in one place: a tool in System -> Maintenance (#263), beside Orphaned
 * tickets:
 *
 *  - people whose source was deleted, from every source;
 *  - for one source, the people it no longer brings in, and Remove imported
 *    people (?source=<id> opens it on that source - each source's page links here).
 *
 * The lists are drawn by assets/js/directory-maintenance.js; every rule is the
 * server's (includes/services/directory_maintenance.php).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'directory-maintenance';
require_once __DIR__ . '/../includes/page_gate.php';
require_once '../../includes/entra_sync.php';   // entraIsEntraIssuer()
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

// The sources people are imported from: LDAP, CardDAV, and OIDC only when it is Entra.
$conn = connectToDatabase();
$sources = [];
foreach ($conn->query("SELECT id, display_name, protocol, issuer_url FROM auth_providers
                        WHERE protocol IN ('ldap', 'carddav', 'oidc') ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    if ($s['protocol'] === 'oidc' && !entraIsEntraIssuer((string)$s['issuer_url'])) continue;
    $sources[] = ['id' => (int)$s['id'], 'name' => $s['display_name'],
                  'kind' => $s['protocol'] === 'oidc' ? 'Entra ID' : ($s['protocol'] === 'carddav' ? 'CardDAV' : 'LDAP')];
}
$picked = (int)($_GET['source'] ?? 0);
if (!in_array($picked, array_column($sources, 'id'), true)) $picked = $sources[0]['id'] ?? 0;
$e = function (string $k, array $p = []): string { return htmlspecialchars(t('system.sso.' . $k, $p)); };
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.sso.maint_page_title'))); ?></title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css?v=79">
    <style>
        /* System module accent (blue-grey), as on Orphaned tickets. */
        .dmp-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; margin: 0 0 12px; }
        .dmp-back:hover { text-decoration: underline; }
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .dmp-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f7fa); padding: 24px 28px 60px; }
        .dmp-wrap h2 { font-size: 22px; color: var(--text, #333); margin: 0 0 6px; }
        .dmp-sub { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 18px; max-width: 820px; line-height: 1.5; }
        .dmp-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
        .dmp-card h3 { font-size: 15px; margin: 0 0 4px; color: var(--text, #333); }
        .dmp-card h4 { font-size: 13.5px; margin: 18px 0 4px; color: var(--text, #333); }
        .dmp-hint { font-size: 12.5px; color: var(--text-dim, #888); margin: 0 0 6px; line-height: 1.5; max-width: 900px; }
        .dmp-pick { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 6px 0 4px; font-size: 13px; }
        .dmp-pick select { padding: 6px 10px; }
        .dmp-split { border-top: 1px solid var(--border-soft, #f0f0f0); margin-top: 16px; }
        /* The buttons the shared lists draw: the System page buttons. */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; border: 1px solid var(--border, #cfd8dc); background: var(--surface, #fff); color: var(--sys-accent, #546e7a); cursor: pointer; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="directory-maintenance">
    <?php include '../includes/header.php'; ?>

    <div class="dmp-wrap">
        <a class="dmp-back" href="../maintenance/">&larr; <?php echo htmlspecialchars(t('system.maintenance.back')); ?></a>
        <h2><?php echo $e('maint_page_title'); ?></h2>
        <p class="dmp-sub"><?php echo $e('maint_page_sub'); ?></p>

        <div class="dmp-card" id="orphans">
            <h3><?php echo $e('maint_orphans_heading'); ?></h3>
            <p class="dmp-hint"><?php echo $e('maint_orphans_desc'); ?></p>
            <div id="dmOrphans"></div>
        </div>

        <div class="dmp-card" id="source">
            <h3><?php echo $e('maint_source_heading'); ?></h3>
            <?php if (!$sources): ?>
            <p class="dmp-hint"><?php echo $e('maint_no_sources'); ?></p>
            <?php else: ?>
            <div class="dmp-pick">
                <label for="dmSource"><?php echo $e('maint_source_label'); ?></label>
                <select id="dmSource">
                    <?php foreach ($sources as $s): ?>
                    <option value="<?php echo $s['id']; ?>"<?php echo $s['id'] === $picked ? ' selected' : ''; ?>><?php echo htmlspecialchars($s['name'] . ' (' . $s['kind'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <h4><?php echo $e('maint_stale_heading'); ?></h4>
            <p class="dmp-hint"><?php echo $e('maint_stale_hint'); ?></p>
            <div id="dmStale"></div>
            <div class="dmp-split">
                <h4><?php echo $e('maint_remove_heading'); ?></h4>
                <p class="dmp-hint"><?php echo $e('maint_remove_hint'); ?></p>
                <div id="dmImported"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <script src="../../assets/js/directory-maintenance.js?v=3"></script>
    <script>
    DirectoryMaintenance.mount(document.getElementById('dmOrphans'), { kind: 'orphans' });
    // One source at a time; the address keeps it, so a reload or a link opens on it.
    (function () {
        const pick = document.getElementById('dmSource');
        if (!pick) return;
        const show = () => {
            const provider = parseInt(pick.value, 10);
            DirectoryMaintenance.mount(document.getElementById('dmStale'), { kind: 'stale', provider });
            DirectoryMaintenance.mount(document.getElementById('dmImported'), { kind: 'imported', provider });
            history.replaceState(null, '', '?source=' + provider);
        };
        pick.addEventListener('change', show);
        show();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
