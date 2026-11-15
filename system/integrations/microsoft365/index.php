<?php
/**
 * System -> Integrations -> Microsoft 365 (GH #263).
 *
 * What FreeITSM reads from a Microsoft tenant, in one place:
 *
 *   Entra ID - people, groups and who manages whom, imported before anybody signs in.
 *              Each Entra sign-in provider has its own import (system/sso/entra.php);
 *              signing in itself stays under System -> Authentication with the other
 *              sign-in methods.
 *   Intune   - devices, their primary users and installed apps (intune.php here; it
 *              was Assets -> Settings -> Intune).
 *
 * Two connections, as Ed decided on #263 - often the same app registration, but kept
 * apart so nobody's settings had to be migrated. This page says how each stands and
 * opens it.
 *
 * Also from Microsoft (administrators): everything else in FreeITSM that talks to
 * Microsoft 365, listed with how it stands and a button to where it is set up - it
 * stays where it belongs (its module, its permissions); this page is the map.
 *   Microsoft 365 mailboxes - Tickets -> Settings -> Mailboxes (ticket email in and
 *                             out, and the system's own email)
 *   Outlook calendars       - System -> Calendar sync
 *   Teams channel alerts    - System -> Integrations -> Microsoft Teams
 *   Teams chat              - Tickets -> Settings -> Messaging
 *
 * Who may open it: an administrator; or an analyst who may configure Intune
 * (Cap::ASSETS_INTUNE), who sees the Intune half only - the rest is administration.
 */
session_start();
require_once '../../../config.php';
require_once '../../../includes/i18n.php';
require_once '../../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../../includes/functions.php';
require_once '../../../includes/theme.php';
require_once '../../../includes/rbac.php';
$current_page = 'integrations';
$system_page_grant = ['module' => 'assets', 'cap' => Cap::ASSETS_INTUNE];
require_once __DIR__ . '/../../includes/page_gate.php';
$path_prefix  = '../../../';
$translationNamespaces = ['common', 'system'];

$conn = connectToDatabase();
$isAdmin = analystIsAdmin($conn, (int)$_SESSION['analyst_id']);

// Entra ID: every Entra sign-in provider, and whether it imports people.
$entra = [];
if ($isAdmin) {
    require_once '../../../includes/entra_sync.php';
    try {
        $rows = $conn->query("SELECT * FROM auth_providers WHERE protocol = 'oidc' ORDER BY sort_order, display_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!entraIsEntraIssuer((string)$r['issuer_url'])) continue;
            $entra[] = [
                'id'        => (int)$r['id'],
                'name'      => (string)$r['display_name'],
                'signin'    => !empty($r['enabled']),
                'importing' => !empty($r['sync_enabled']),
                'last'      => $r['sync_last_run_datetime'] ?? null,
                'count'     => isset($r['sync_last_count']) ? (int)$r['sync_last_count'] : null,
            ];
        }
    } catch (Exception $e) {
        // Not verified yet: reads as none.
    }
}

// Intune: set up when it has a tenant, an app and its secret; and how its two syncs last ran.
// Intune brings devices in as assets: with Assets switched off it has no panel here.
$assetsOn = moduleIsEnabled($conn, 'assets');
$intuneSet = false;
$intuneLast = [];
if ($assetsOn) try {
    $st = $conn->query("SELECT setting_key, setting_value FROM system_settings
                         WHERE setting_key IN ('intune_tenant_id', 'intune_client_id', 'intune_client_secret')");
    $vals = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $vals[$r['setting_key']] = trim((string)$r['setting_value']);
    $intuneSet = ($vals['intune_tenant_id'] ?? '') !== '' && ($vals['intune_client_id'] ?? '') !== '' && ($vals['intune_client_secret'] ?? '') !== '';
    require_once '../../../includes/intune.php';
    $intuneLast = intuneJobsLastRun($conn);
} catch (Throwable $e) {
    // Reads as not set up.
}
$translationNamespaces[] = 'reporting';   // the jobs' names and outcomes

// Also from Microsoft: how each stands, counted where it is kept. Each guarded on
// its own, so one table an install has not verified yet costs only its own row.
$more = [];
if ($isAdmin) {
    $count = function (string $sql) use ($conn): ?array {
        try { return $conn->query($sql)->fetch(PDO::FETCH_NUM) ?: null; } catch (Throwable $e) { return null; }
    };
    // A mailbox with no provider is Microsoft - MailboxProvider::fromValue().
    require_once '../../../includes/mailbox_auth.php';
    $mb = $count("SELECT COUNT(*), COALESCE(SUM(" . mailboxAuthenticatedSql() . "), 0) FROM target_mailboxes
                   WHERE is_active = 1 AND (provider = 'microsoft' OR provider IS NULL OR provider = '')");
    $more[] = ['name' => t('system.integrations.ms365_mail_name'),
               'on'   => $mb && (int)$mb[0] > 0,
               'meta' => $mb && (int)$mb[0] > 0 ? t('system.integrations.ms365_mail_meta', ['n' => (int)$mb[0], 'a' => (int)$mb[1]]) : t('system.integrations.ms365_mail_none'),
               'where'=> t('system.integrations.ms365_mail_where'),
               'url'  => BASE_URL . 'tickets/settings/#mailboxes'];
    $cal = $count("SELECT COUNT(*) FROM calendar_connections WHERE is_active = 1 AND provider = 'microsoft'");
    $more[] = ['name' => t('system.integrations.ms365_cal_name'),
               'on'   => $cal && (int)$cal[0] > 0,
               'meta' => $cal && (int)$cal[0] > 0 ? t('system.integrations.ms365_cal_meta', ['n' => (int)$cal[0]]) : t('system.integrations.ms365_cal_none'),
               'where'=> t('system.integrations.ms365_cal_where'),
               'url'  => '../../calendar-sync/'];
    $alerts = null;
    try {
        require_once '../../../includes/integrations/msteams.php';
        $alerts = msteamsChannelCount($conn);   // as its card on Integrations counts them
    } catch (Throwable $e) { $alerts = null; }
    $more[] = ['name' => t('system.integrations.ms365_alerts_name'),
               'on'   => (int)$alerts > 0,
               'meta' => (int)$alerts > 0 ? t('system.integrations.ms365_alerts_meta', ['n' => (int)$alerts]) : t('system.integrations.ms365_alerts_none'),
               'where'=> t('system.integrations.ms365_alerts_where'),
               'url'  => '../msteams'];
    $bot = $count("SELECT COUNT(*) FROM messaging_channels WHERE is_active = 1 AND channel_type = 'teams'");
    $more[] = ['name' => t('system.integrations.ms365_chat_name'),
               'on'   => $bot && (int)$bot[0] > 0,
               'meta' => $bot && (int)$bot[0] > 0 ? t('system.integrations.ms365_chat_meta', ['n' => (int)$bot[0]]) : t('system.integrations.ms365_chat_none'),
               'where'=> t('system.integrations.ms365_chat_where'),
               'url'  => BASE_URL . 'tickets/settings/#messaging'];
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), 'Microsoft 365')); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/integrations.css?v=3">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Gutter, width and title come from .sys-page (assets/css/system-page.css). */
        .ms-wrap { height: calc(100vh - 48px); overflow-y: auto; }
        .ms-crumb { font-size: 13px; margin: 0 0 6px; }
        .ms-crumb a { color: var(--sys-accent, #546e7a); text-decoration: none; }
        .ms-crumb a:hover { text-decoration: underline; }
        .ms-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 20px 22px; margin-bottom: 18px; }
        .ms-panel h2 { margin: 0 0 4px; font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .ms-panel > p { margin: 0 0 14px; font-size: 13px; color: var(--text-muted, #666); line-height: 1.5; }
        .ms-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
                  padding: 12px 0; border-top: 1px solid var(--border-soft, #eee); }
        .ms-row:first-of-type { border-top: none; }
        .ms-name { font-weight: 600; font-size: 14px; color: var(--text, #333); }
        .ms-meta { font-size: 12.5px; color: var(--text-muted, #666); margin-top: 3px; }
        .ms-pill { display: inline-block; font-size: 11.5px; font-weight: 600; padding: 2px 9px; border-radius: 10px; margin-right: 6px;
                   background: var(--surface-2, #f1f3f5); color: var(--text-muted, #666); }
        .ms-pill.on { background: var(--success-bg, #dcfce7); color: var(--success-text, #166534); }
        .ms-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
               border: none; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: var(--sys-accent-hover, #455a64); }
        .ms-note { font-size: 12.5px; color: var(--text-faint, #999); margin: 10px 0 0; }
    </style>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=196">
</head>
<body>
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="ms-wrap sys-page">
        <?php if ($isAdmin): ?><p class="ms-crumb"><a href="../"><?php echo htmlspecialchars(t('system.integrations.title')); ?></a></p><?php endif; ?>
        <div class="sys-page-head"><div>
            <h1 class="sys-title">Microsoft 365</h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars(t('system.integrations.ms365_subtitle')); ?></p>
            <?php /* The guide (help.php?provider=microsoft365) is an administrators' page like every System guide. */ ?>
            <?php if ($isAdmin): ?>
            <a class="help-link" href="./help">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true"><circle cx="12" cy="12" r="10"></circle>
                     <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <?php echo htmlspecialchars(t('system.integrations.help_link', ['name' => 'Microsoft 365'])); ?>
            </a>
            <?php endif; ?>
        </div></div>

        <?php if ($isAdmin): ?>
        <section class="ms-panel">
            <h2><?php echo htmlspecialchars(t('system.integrations.ms365_entra_heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('system.integrations.ms365_entra_intro')); ?></p>
            <?php if (!$entra): ?>
                <div class="ms-row">
                    <div class="ms-meta"><?php echo htmlspecialchars(t('system.integrations.ms365_entra_none')); ?></div>
                    <div class="ms-actions"><a class="btn btn-primary" href="../../sso/"><?php echo htmlspecialchars(t('system.integrations.ms365_open_signin')); ?></a></div>
                </div>
            <?php else: foreach ($entra as $e): ?>
                <div class="ms-row">
                    <div>
                        <div class="ms-name"><?php echo htmlspecialchars($e['name']); ?></div>
                        <div class="ms-meta">
                            <span class="ms-pill<?php echo $e['importing'] ? ' on' : ''; ?>"><?php echo htmlspecialchars(t($e['importing'] ? 'system.integrations.ms365_importing' : 'system.integrations.ms365_not_importing')); ?></span>
                            <span class="ms-pill<?php echo $e['signin'] ? ' on' : ''; ?>"><?php echo htmlspecialchars(t($e['signin'] ? 'system.integrations.ms365_signin_on' : 'system.integrations.ms365_signin_off')); ?></span>
                            <?php echo htmlspecialchars($e['last']
                                ? t('system.integrations.ms365_last_import', ['when' => fmt_datetime($e['last']), 'count' => (int)$e['count']])
                                : t('system.integrations.ms365_never_imported')); ?>
                        </div>
                    </div>
                    <div class="ms-actions">
                        <a class="btn btn-primary" href="../../sso/entra.php?id=<?php echo (int)$e['id']; ?>"><?php echo htmlspecialchars(t('system.integrations.ms365_open_import')); ?></a>
                        <a class="btn btn-secondary" href="../../sso/"><?php echo htmlspecialchars(t('system.integrations.ms365_open_signin')); ?></a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
            <p class="ms-note"><?php echo htmlspecialchars(t('system.integrations.ms365_signin_note')); ?></p>
        </section>
        <?php endif; ?>

        <?php if ($assetsOn): ?>
        <section class="ms-panel">
            <h2><?php echo htmlspecialchars(t('system.integrations.ms365_intune_heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('system.integrations.ms365_intune_intro')); ?></p>
            <div class="ms-row">
                <div>
                    <div class="ms-name">Microsoft Intune</div>
                    <div class="ms-meta">
                        <span class="ms-pill<?php echo $intuneSet ? ' on' : ''; ?>"><?php echo htmlspecialchars(t($intuneSet ? 'system.integrations.configured' : 'system.integrations.not_configured')); ?></span>
                        <?php foreach (['intune_sync', 'intune_app_sync'] as $jk): $lr = $intuneLast[$jk] ?? null; ?>
                            <br><?php echo htmlspecialchars($lr
                                ? t('system.integrations.ms365_job_last', ['job' => t('reporting.jobs.name_' . $jk), 'when' => fmt_datetime($lr['started']), 'outcome' => t('reporting.jobs.outcome_' . $lr['outcome'])])
                                : t('system.integrations.ms365_job_never', ['job' => t('reporting.jobs.name_' . $jk)])); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php /* The two halves, as Entra ID has them: what it imports, and the connection. */ ?>
                <div class="ms-actions">
                    <a class="btn btn-primary" href="intune_import.php"><?php echo htmlspecialchars(t('system.integrations.ms365_open_intune_import')); ?></a>
                    <a class="btn btn-secondary" href="intune.php"><?php echo htmlspecialchars(t('system.integrations.ms365_open_intune_conn')); ?></a>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($more): ?>
        <?php /* Also from Microsoft: set up where each belongs; this is the map. */ ?>
        <section class="ms-panel">
            <h2><?php echo htmlspecialchars(t('system.integrations.ms365_more_heading')); ?></h2>
            <p><?php echo htmlspecialchars(t('system.integrations.ms365_more_intro')); ?></p>
            <?php foreach ($more as $m): ?>
                <div class="ms-row">
                    <div>
                        <div class="ms-name"><?php echo htmlspecialchars($m['name']); ?></div>
                        <div class="ms-meta">
                            <span class="ms-pill<?php echo $m['on'] ? ' on' : ''; ?>"><?php echo htmlspecialchars(t($m['on'] ? 'system.integrations.configured' : 'system.integrations.not_configured')); ?></span>
                            <?php echo htmlspecialchars($m['meta']); ?>
                        </div>
                        <div class="ms-meta"><?php echo htmlspecialchars($m['where']); ?></div>
                    </div>
                    <div class="ms-actions"><a class="btn btn-secondary" href="<?php echo htmlspecialchars($m['url']); ?>"><?php echo htmlspecialchars(t('system.integrations.ms365_open')); ?></a></div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </div>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=83"></script>
</body>
</html>
