<?php
/**
 * System — Integrations landing.
 *
 * The provider grid. One card today (Jira); GitHub, GitLab and Azure DevOps land
 * here as soon as their connectors exist, with no change to this page — the grid
 * is rendered from integrationsAvailableProviders().
 *
 * Deliberately NOT showing "coming soon" cards for unbuilt providers. A page that
 * grows beats a page of promises, especially in a repo people read before they
 * install.
 *
 * Each card links to /system/integrations/<key>, which .htaccess rewrites to
 * provider.php.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/integrations/integrations.php';

$current_page = 'integrations';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

$conn = connectToDatabase();

$providers  = integrationsAvailableProviders();
$schemaOk   = integrationsSchemaReady($conn);

// Connection counts per provider, so a card can say "2 connections" rather than
// making the admin click in to find out.
//
// ⚠️ Not every provider stores its connections in the same table. Trackers use
// `integration_connections`; a messaging-kind provider (Slack) is a channel and
// lives in `messaging_channels`. Counting only the first table would show Slack
// as permanently "Not set up" however many workspaces were connected.
$counts = [];
if ($schemaOk) {
    try {
        foreach ($conn->query("SELECT provider, COUNT(*) c FROM integration_connections GROUP BY provider") as $r) {
            $counts[$r['provider']] = (int) $r['c'];
        }
    } catch (Exception $e) {
        $counts = [];
    }
}
foreach ($providers as $pk => $pmeta) {
    if (($pmeta['kind'] ?? 'tracker') !== 'messaging') {
        continue;
    }
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM messaging_channels WHERE provider = ?");
        $st->execute([$pk]);
        $counts[$pk] = (int) $st->fetchColumn();
    } catch (Exception $e) {
        // messaging_channels absent on a part-migrated install → leave it unset,
        // which the card renders as "Not set up".
    }
}

// An extractor has no "connections" to count — there is one endpoint and it is
// either pointed somewhere or it is not.
require_once dirname(__DIR__, 2) . '/includes/search/tika.php';
foreach ($providers as $pk => $pmeta) {
    if (($pmeta['kind'] ?? 'tracker') !== 'extractor') continue;
    try { $counts[$pk] = tikaConfigured($conn) ? 1 : 0; }
    catch (Exception $e) { $counts[$pk] = 0; }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.integrations.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../../assets/css/integrations.css?v=1">
    <style>
        /* Only the card grid — the container, title and warning come from
           integrations.css, shared with the provider and Slack pages. */
        .provider-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
            /* The breathing room under the subtitle belongs here, not on the
               subtitle — the provider page puts a help pill in this space
               instead and wants it tight. 8px + 18px = the original 26px. */
            margin-top: 18px;
        }
        .provider-card {
            display: block;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            text-decoration: none;
            box-shadow: var(--shadow);
            transition: border-color .15s ease, transform .15s ease;
        }
        .provider-card:hover { border-color: var(--sys-accent); transform: translateY(-2px); }
        .provider-card h3 { margin: 0 0 6px; font-size: 17px; font-weight: 600; color: var(--text); }
        .provider-card p  { margin: 0; font-size: 13px; color: var(--text-muted); line-height: 1.5; }
        .provider-icon {
            width: 34px; height: 34px; margin-bottom: 12px;
            color: var(--sys-accent);
        }
        .provider-count {
            display: inline-block; margin-top: 12px;
            font-size: 12px; font-weight: 600;
            padding: 3px 9px; border-radius: 20px;
            background: var(--sys-accent-soft); color: var(--sys-accent);
        }
        .provider-count.is-none { background: var(--surface-2); color: var(--text-faint); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="integrations">
    <?php include '../includes/header.php'; ?>

    <div class="int-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.integrations.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.integrations.subtitle')); ?></p>

        <?php if (!$schemaOk): ?>
            <div class="setup-warning">
                <?php echo htmlspecialchars(t('system.integrations.needs_db_verify')); ?>
            </div>
        <?php endif; ?>

        <div class="provider-grid">
            <?php foreach ($providers as $key => $meta): ?>
                <?php $n = $counts[$key] ?? 0; ?>
                <a class="provider-card" href="<?php echo htmlspecialchars($key); ?>">
                    <svg class="provider-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                    </svg>
                    <h3><?php echo htmlspecialchars($meta['name']); ?></h3>
                    <p><?php echo htmlspecialchars(t($meta['blurb'])); ?></p>
                    <span class="provider-count <?php echo $n === 0 ? 'is-none' : ''; ?>">
                        <?php
                        // "3 connections" is the tracker/messaging shape. An
                        // extractor is one endpoint, so it reads as a state.
                        if (($meta['kind'] ?? 'tracker') === 'extractor') {
                            echo htmlspecialchars($n > 0
                                ? t('system.integrations.configured')
                                : t('system.integrations.not_configured'));
                        } else {
                            echo htmlspecialchars($n === 1
                                ? t('system.integrations.one_connection')
                                : str_replace('{n}', (string)$n, t('system.integrations.n_connections')));
                        }
                        ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
