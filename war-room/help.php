<?php
/**
 * War Room Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('war-room');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'war-room'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('war-room.help.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--war-room-accent);
            --accent-hover: var(--war-room-accent-hover);
            --accent-soft:  var(--war-room-accent-soft);
            --on-accent:    var(--war-room-on-accent);
        }

        /* Module-specific: the command list is the thing somebody will come back
           to this page for, so it is set as code rather than prose. */
        .help-cmds {
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 6px 18px;
            margin-top: 4px;
            font-size: 14px;
        }
        .help-cmds code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            color: var(--accent);
            white-space: nowrap;
        }
        @media (max-width: 768px) {
            /* Two columns of code and prose do not fit a phone; stack them and
               let the command sit above what it does. */
            .help-cmds { grid-template-columns: 1fr; gap: 2px 0; }
            .help-cmds > span { margin-bottom: 10px; }
        }
    </style>
    <!-- Mobile: LAYER 16h — the guide body is already responsive; this adds the app shell. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">

        <div class="help-sidebar">
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_overview')); ?>
            </a>
            <a href="#channels" class="help-nav-link" data-section="channels">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_channels')); ?>
            </a>
            <a href="#talking" class="help-nav-link" data-section="talking">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_talking')); ?>
            </a>
            <a href="#finding" class="help-nav-link" data-section="finding">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_finding')); ?>
            </a>
            <a href="#warbot" class="help-nav-link" data-section="warbot">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_warbot')); ?>
            </a>
            <a href="#sitrep" class="help-nav-link" data-section="sitrep">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_sitrep')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">7</span>
                <?php echo htmlspecialchars(t('war-room.help.nav_settings')); ?>
            </a>
        </div>

        <div class="help-main" id="helpMain">

            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('war-room.help.hero_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('war-room.help.hero_intro')); ?></p>
            </div>

            <div class="help-content">

                <!-- 1 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.overview_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.card_chat_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.card_chat_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path><line x1="12" y1="2" x2="12" y2="12"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.card_offline_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.card_offline_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.card_who_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.card_who_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.card_private_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.card_private_desc')); ?></p>
                        </div>
                    </div>
                    <div class="help-note">
                        <strong><?php echo htmlspecialchars(t('war-room.help.overview_note_title')); ?></strong>
                        <p><?php echo htmlspecialchars(t('war-room.help.overview_note_body')); ?></p>
                    </div>
                </div>

                <!-- 2 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="channels">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.channels_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.channels_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.channels_everyone_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.channels_everyone_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.channels_team_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.channels_team_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.channels_own_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.channels_own_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">4</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.channels_dm_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.channels_dm_desc')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="help-note">
                        <strong><?php echo htmlspecialchars(t('war-room.help.channels_note_title')); ?></strong>
                        <p><?php echo htmlspecialchars(t('war-room.help.channels_note_body')); ?></p>
                    </div>
                </div>

                <!-- 3 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="talking">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.talking_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.talking_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.talking_send_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.talking_send_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.talking_mention_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.talking_mention_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.talking_files_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.talking_files_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">4</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.talking_edit_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.talking_edit_desc')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="finding">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.finding_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.finding_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.finding_search_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.finding_search_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.finding_bell_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.finding_bell_desc')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 5 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="warbot">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.warbot_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.warbot_intro')); ?></p>
                        </div>
                    </div>

                    <div class="help-note">
                        <strong><?php echo htmlspecialchars(t('war-room.help.warbot_offline_title')); ?></strong>
                        <p><?php echo htmlspecialchars(t('war-room.help.warbot_offline_body')); ?></p>
                    </div>

                    <h4><?php echo htmlspecialchars(t('war-room.help.warbot_cmds_heading')); ?></h4>
                    <div class="help-cmds">
                        <code>/p1</code>                <span><?php echo htmlspecialchars(t('war-room.help.cmd_p1')); ?></span>
                        <code>/open</code>              <span><?php echo htmlspecialchars(t('war-room.help.cmd_open')); ?></span>
                        <code>/spike [mins]</code>      <span><?php echo htmlspecialchars(t('war-room.help.cmd_spike')); ?></span>
                        <code>/status</code>            <span><?php echo htmlspecialchars(t('war-room.help.cmd_status')); ?></span>
                        <code>/changes [days]</code>    <span><?php echo htmlspecialchars(t('war-room.help.cmd_changes')); ?></span>
                        <code>/checks [date]</code>     <span><?php echo htmlspecialchars(t('war-room.help.cmd_checks')); ?></span>
                        <code>/oncall</code>            <span><?php echo htmlspecialchars(t('war-room.help.cmd_oncall')); ?></span>
                        <code>/known &lt;words&gt;</code>     <span><?php echo htmlspecialchars(t('war-room.help.cmd_known')); ?></span>
                        <code>/kb &lt;words&gt;</code>        <span><?php echo htmlspecialchars(t('war-room.help.cmd_kb')); ?></span>
                        <code>/find &lt;words&gt;</code>      <span><?php echo htmlspecialchars(t('war-room.help.cmd_find')); ?></span>
                        <code>/asset &lt;name&gt;</code>      <span><?php echo htmlspecialchars(t('war-room.help.cmd_asset')); ?></span>
                        <code>/impact &lt;name&gt;</code>     <span><?php echo htmlspecialchars(t('war-room.help.cmd_impact')); ?></span>
                        <code>/linked &lt;ref&gt;</code>      <span><?php echo htmlspecialchars(t('war-room.help.cmd_linked')); ?></span>
                        <code>/supplier &lt;name&gt;</code>   <span><?php echo htmlspecialchars(t('war-room.help.cmd_supplier')); ?></span>
                        <code>/help</code>              <span><?php echo htmlspecialchars(t('war-room.help.cmd_help')); ?></span>
                    </div>

                    <div class="help-note">
                        <strong><?php echo htmlspecialchars(t('war-room.help.warbot_limits_title')); ?></strong>
                        <p><?php echo htmlspecialchars(t('war-room.help.warbot_limits_body')); ?></p>
                    </div>
                </div>

                <!-- 6 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="sitrep">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.sitrep_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.sitrep_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.sitrep_open_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.sitrep_open_desc')); ?></p>
                            </div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div>
                                <h4><?php echo htmlspecialchars(t('war-room.help.sitrep_read_title')); ?></h4>
                                <p><?php echo htmlspecialchars(t('war-room.help.sitrep_read_desc')); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="help-note">
                        <strong><?php echo htmlspecialchars(t('war-room.help.sitrep_check_title')); ?></strong>
                        <p><?php echo htmlspecialchars(t('war-room.help.sitrep_check_body')); ?></p>
                    </div>
                </div>

                <!-- 7 ─────────────────────────────────────────────────────── -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('war-room.help.settings_heading')); ?></h3>
                            <p><?php echo htmlspecialchars(t('war-room.help.settings_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.settings_retention_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.settings_retention_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.settings_ai_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.settings_ai_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M12 1v6m0 6v6"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.settings_personal_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.settings_personal_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('war-room.help.settings_check_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('war-room.help.settings_check_desc')); ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const id = link.dataset.section;
            const el = document.getElementById(id);
            if (el) sections.push({ id, el });
        });

        helpMain.addEventListener('scroll', function() {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;

            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) {
                    current = s.id;
                }
            }

            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const el = document.getElementById(this.dataset.section);
                if (el) {
                    const containerTop = helpMain.getBoundingClientRect().top;
                    const elTop = el.getBoundingClientRect().top;
                    helpMain.scrollTo({ top: helpMain.scrollTop + (elTop - containerTop) - 20, behavior: 'smooth' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
    <script src="../assets/js/mobile.js?v=50"></script>
</body>
</html>
