<?php
/**
 * Index - ITSM Module Selection
 * Landing page showing available modules when logged in
 */
session_start();
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/branding.php';   // the organisation's logo (GH #87)

/* The landing page's appearance. Same rules as the two sign-in screens.
   ⚠️ This one defaults to `bg_style = theme`, meaning it emits NO background
   and the page keeps its own — which has a dark-mode variant. Overriding
   that by default would have broken dark mode for every install as a side
   effect of adding a setting. */
$brandNone    = isset($_GET['nobranding']);
$brandPreview = isset($_GET['preview']) && !empty($_SESSION['is_admin']);
$brand        = $brandNone ? null : brandingLoginDesign(null, 'home'); // sessionIsAdmin() — gates the System card below
require_once 'includes/landing.php';   // where an unauthenticated visitor goes (discussion #63)

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    // Which front door? An analyst's own preference (cached in a cookie, because
    // there is no session to read it from yet) beats the install-wide default.
    // Both login URLs still work when typed directly — this only decides where
    // "/" sends somebody who has expressed no opinion by choosing a URL.
    try {
        $target = landingResolve(connectToDatabase());
    } catch (Exception $e) {
        // Never let a database blip lock people out. The real path, not the rewritten
        // one: this is the branch that runs when the database is unreachable, which is
        // exactly the state a brand new install is in before db_config.php is filled
        // in — so it must not depend on a rewrite rule being read. (#68)
        $target = 'auth/login.php';
    }
    header('Location: ' . $target);
    exit;
}

// Signed in on the way to an emailed report's download link (5.3.0): the link set
// this cookie before sending them to sign in, so take them back to it. Only a
// 64-hex token is accepted, and the destination is fixed - the cookie cannot send
// anyone anywhere else. The link itself still checks it is theirs.
if (isset($_COOKIE['freeitsm_report_return'])) {
    $reportReturn = (string)$_COOKIE['freeitsm_report_return'];
    setcookie('freeitsm_report_return', '', ['expires' => time() - 3600, 'path' => defined('BASE_URL') ? BASE_URL : '/']);
    if (preg_match('/^[0-9a-f]{64}$/', $reportReturn)) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'api/system/report_download.php?t=' . $reportReturn);
        exit;
    }
}

require_once 'includes/waffle-menu.php';

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$allowed_modules = $_SESSION['allowed_modules'] ?? null;

// This page is already translated server-side, but it was the ONE page that
// never shipped the client-side half — so window.t did not exist here, and the
// notification bell (rendered from renderHeaderRight on every page, this one
// included) threw on its first line and never made its request. GH #78.
// 'common' is what the shared header needs; the page's own strings live there too.
$translationNamespaces = ['common'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle()); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="assets/js/i18n.js?v=4"></script>
    <link rel="stylesheet" href="assets/css/theme.css?v=27">
    <link rel="stylesheet" href="assets/css/inbox.css?v=79">
    <style>
        body {
            background: var(--login-bg, linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%));
            min-height: 100vh;
            height: auto;
            overflow: auto;
            display: flex;
            flex-direction: column;
        }

        /* Dark palettes: swap the light-grey wash for the dark app surfaces
           (light mode keeps its original gradient, pixel-identical). */
        [data-theme-mode="dark"] body {
            background: var(--login-bg, linear-gradient(135deg, var(--app-bg, #14171c) 0%, var(--surface-2, #232830) 100%));
        }

        .landing-header {
            background: linear-gradient(135deg, #0078d4, #106ebe);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        /* Same translucent-black wash the module headers get in dark, so the
           blue landing header tones down while keeping a hint of brand blue. */
        [data-theme="dark"] .landing-header {
            box-shadow: inset 0 0 0 2000px rgba(0, 0, 0, 0.55), 0 2px 4px rgba(0, 0, 0, 0.4);
        }

        .landing-header h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }
        /* ---- branding designer hooks (#1427) ----
           ⚠️ `--login-bg` is NOT set on this page unless an administrator has
           chosen a background, so the theme-aware default below — including its
           dark-mode variant — is what renders out of the box. */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, var(--login-dim, 0));
            pointer-events: none;
            z-index: 0;
        }
        .login-strip {
            position: fixed;
            left: 0;
            right: 0;
            z-index: 5;
            padding: 10px 16px;
            text-align: center;
            font-size: 13.5px;
            line-height: 1.4;
        }
        .login-strip-banner { background: var(--login-banner-bg, #111827); color: var(--login-banner-fg, #fff); font-weight: 600; }
        .login-strip-banner[data-at="top"]    { top: 0; }
        .login-strip-banner[data-at="bottom"] { bottom: 0; }
        .login-strip-footer {
            bottom: 0;
            color: var(--login-footer-fg, #fff);
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.45);
            font-size: 12.5px;
        }
        body:has(.login-strip-banner[data-at="bottom"]) .login-strip-footer { bottom: 42px; }
        body[data-logo-pos="hidden"] .company-logo { display: none; }
        /* 🔴 These were TWO rules, and the second one — a plain `width: 300px`
           that predates the designer — sat after the first with the same
           specificity, so it won every time and the logo size setting did
           NOTHING on this screen. One rule now, so there is nothing left to
           override it. Found while adding the height setting.
           Both settings are MAXIMA and the logo keeps its own proportions. */
        .company-logo {
            width: auto;
            height: auto;
            max-width: min(var(--login-logo-size, 300px), 100%);
            max-height: var(--login-logo-height, none);
            margin-bottom: 30px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .landing-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .welcome-text {
            text-align: center;
            margin-bottom: 50px;
        }

        .welcome-text h2 {
            font-size: 32px;
            font-weight: 300;
            color: var(--text, #333);
            margin: 0 0 10px 0;
        }

        .welcome-text p {
            font-size: 16px;
            color: var(--text-muted, #666);
            margin: 0;
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 14px;
            max-width: 1180px;
            width: 100%;
            justify-content: center;
        }

        .module-card {
            background: var(--surface, white);
            border-radius: 14px;
            padding: 16px 8px;
            text-align: center;
            text-decoration: none;
            color: var(--text, #333);
            box-shadow: 0 4px 20px var(--shadow, rgba(0, 0, 0, 0.08));
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .module-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 40px var(--shadow, rgba(0, 0, 0, 0.15));
        }

        <?php foreach (getModuleColors() as $key => $c): ?>
        .module-card.<?php echo $key; ?>:hover { border-color: <?php echo $c[0]; ?>; }
        <?php endforeach; ?>

        .module-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
        }

        .module-icon svg {
            width: 24px;
            height: 24px;
            color: white;
        }

        <?php foreach (getModuleColors() as $key => $c): ?>
        .module-icon.<?php echo $key; ?> { background: linear-gradient(135deg, <?php echo $c[0]; ?>, <?php echo $c[1]; ?>); }
        <?php endforeach; ?>

        .module-name {
            font-size: 14px;
            font-weight: 600;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-faint, #999);
            font-size: 12px;
        }

        /* ====================================================================
           Mobile (on-the-go analyst) — landing page reflow.
           Self-contained @media block rather than linking assets/css/mobile.css:
           that file carries tickets-inbox body/pane rules (body{height:100dvh},
           .main-container{overflow:hidden}) that would clip this scrolling page.
           Above 768px nothing here applies, so the desktop render is unchanged.
           ==================================================================== */
        @media (max-width: 768px) {
            .landing-header { padding: 12px 14px; }
            .landing-header h1 { font-size: 16px; }

            .landing-container { padding: 24px 14px 32px; justify-content: flex-start; }

            /* A cap, not a size — setting `width` here would override the
               administrator's choice AND fix a dimension the logo needs free to
               keep its shape. */
            .company-logo { max-width: min(220px, 70%); margin-bottom: 20px; }

            .welcome-text { margin-bottom: 28px; }
            .welcome-text h2 { font-size: 24px; }
            .welcome-text p { font-size: 14px; }

            /* Fixed 7 columns → an adaptive icon grid that fits any phone width
               (~3 across on a 360px screen, 4 on a larger phone). */
            .modules-grid {
                grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
                gap: 10px;
            }
            .module-card { padding: 14px 6px; border-radius: 12px; }
            .module-icon { width: 42px; height: 42px; margin-bottom: 8px; }
            .module-name { font-size: 12px; line-height: 1.25; }

            /* Keep the account dropdown from overflowing off a narrow screen. */
            .user-menu { right: 8px; max-width: calc(100vw - 16px); }
        }
    </style>
</head>
<body<?php if ($brand): ?> data-logo-pos="<?php echo htmlspecialchars($brand['logo_position']); ?>" style="<?php echo htmlspecialchars(brandingLoginCss($brand)); ?>"<?php endif; ?>>
<?php if ($brand && $brand['banner_position'] !== 'off' && $brand['banner_text'] !== ''): ?>
    <div class="login-strip login-strip-banner" data-at="<?php echo htmlspecialchars($brand['banner_position']); ?>"><?php echo htmlspecialchars($brand['banner_text']); ?></div>
<?php endif; ?>
    <div class="landing-header">
        <h1><?php echo htmlspecialchars(t('common.home.header_title')); ?></h1>
        <?php renderHeaderRight($analyst_name, ''); ?>
    </div>
    <script>function closeWaffleMenu() {}</script>

    <div class="landing-container">
        <img src="<?php echo htmlspecialchars(brandingLogoUrl()); ?>" alt="Company Logo" class="company-logo">
        <div class="welcome-text">
            <h2><?php echo htmlspecialchars($brand && $brand['heading'] !== '' ? $brand['heading'] : t('common.home.welcome_heading')); ?></h2>
            <p data-tagline="1" data-default="<?php echo htmlspecialchars(t('common.home.welcome_subtitle')); ?>"><?php echo htmlspecialchars($brand && $brand['subheading'] !== '' ? $brand['subheading'] : t('common.home.welcome_subtitle')); ?></p>
        </div>

        <div class="modules-grid">
            <?php
            // Every module this analyst may open, in their own order (Preferences,
            // GH #187) - the same list, filter and order as the waffle menu, so a new
            // module appears here without this page being edited. This grid used to
            // be 27 hand-written blocks that each repeated the waffle's icon and link.
            foreach (analystVisibleModules($modules) as $key => $module): ?>
            <a href="<?php echo htmlspecialchars($module['path']); ?>" class="module-card <?php echo htmlspecialchars($key); ?>" title="<?php echo htmlspecialchars(t('common.modules.' . $key . '.description')); ?>">
                <div class="module-icon <?php echo htmlspecialchars($key); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?php echo $module['icon']; ?>
                    </svg>
                </div>
                <div class="module-name"><?php echo htmlspecialchars($module['name']); ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="footer">
        <?php echo htmlspecialchars(t('common.home.footer')); ?>
    </div>

    <!-- The landing page draws the header-right user menu (renderHeaderRight) but
         not the waffle-menu JS, so it must load the shared toast/confirm helpers
         itself — the logout button relies on showConfirm. (Both self-guard against
         double-loading.) -->
    <script src="assets/js/toast.js"></script>
    <script src="assets/js/confirm.js?v=7"></script>
<?php if ($brand && $brand['footer_text'] !== ''): ?>
    <div class="login-strip login-strip-footer"><?php echo htmlspecialchars($brand['footer_text']); ?></div>
<?php endif; ?>
<?php if ($brandPreview) { $previewDefaultHeading = t('common.home.welcome_heading'); require __DIR__ . '/includes/branding_preview.php'; } ?>
</body>
</html>
