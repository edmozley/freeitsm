<?php
/**
 * Tasks Module Help Guide — full page with left-pane scroll-spy navigation
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
requireModuleAccess('tasks');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tasks.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--tsk-accent);
            --accent-hover: var(--tsk-accent-hover);
            --accent-soft:  var(--tsk-accent-soft);
            --on-accent:    var(--tsk-on-accent);
        }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=127">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('tasks.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span> <?php echo htmlspecialchars(t('tasks.help.nav_overview')); ?>
            </a>
            <a href="#board" class="help-nav-link" data-section="board">
                <span class="help-nav-num">2</span> <?php echo htmlspecialchars(t('tasks.help.nav_board')); ?>
            </a>
            <a href="#list" class="help-nav-link" data-section="list">
                <span class="help-nav-num">3</span> <?php echo htmlspecialchars(t('tasks.help.nav_list')); ?>
            </a>
            <a href="#calendar" class="help-nav-link" data-section="calendar">
                <span class="help-nav-num">4</span> <?php echo htmlspecialchars(t('tasks.help.nav_calendar')); ?>
            </a>
            <a href="#timeline" class="help-nav-link" data-section="timeline">
                <span class="help-nav-num">5</span> <?php echo htmlspecialchars(t('tasks.help.nav_timeline')); ?>
            </a>
            <a href="#table" class="help-nav-link" data-section="table">
                <span class="help-nav-num">6</span> <?php echo htmlspecialchars(t('tasks.help.nav_table')); ?>
            </a>
            <a href="#panel" class="help-nav-link" data-section="panel">
                <span class="help-nav-num">7</span> <?php echo htmlspecialchars(t('tasks.help.nav_panel')); ?>
            </a>
            <a href="#repeats" class="help-nav-link" data-section="repeats">
                <span class="help-nav-num">8</span> <?php echo htmlspecialchars(t('tasks.help.nav_repeats')); ?>
            </a>
            <a href="#tags" class="help-nav-link" data-section="tags">
                <span class="help-nav-num">9</span> <?php echo htmlspecialchars(t('tasks.help.nav_tags')); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">10</span> <?php echo htmlspecialchars(t('tasks.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">11</span> <?php echo htmlspecialchars(t('tasks.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('tasks.help.hero_title')); ?></h2>
                <p><?php echo t('tasks.help.hero_subtitle'); ?></p>
            </div>

            <div class="help-content">

                <!-- 1. Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.overview_heading')); ?></h3>
                            <p><?php echo t('tasks.help.overview_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card1_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card2_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card3_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card3_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="14" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="5" y1="18" x2="16" y2="18"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card4_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card4_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line><line x1="15" y1="3" x2="15" y2="21"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card5_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card5_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card6_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card6_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card7_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card7_desc'); ?></p>
                        </div>
                    </div>
                    <p class="help-note"><?php echo htmlspecialchars(t('tasks.help.overview_tip')); ?></p>
                </div>

                <!-- 2. The board -->
                <div class="help-section" id="board">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.board_heading')); ?></h3>
                            <p><?php echo t('tasks.help.board_intro'); ?></p>
                        </div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_columns_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_columns_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_creating_heading')); ?></h4>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><?php echo t('tasks.help.board_creating_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><?php echo t('tasks.help.board_creating_step2'); ?></div>
                        </div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_moving_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_moving_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_rightclick_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_rightclick_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_search_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_search_body'); ?></p>
                    <p class="help-note"><?php echo t('tasks.help.board_tip'); ?></p>
                </div>

                <!-- 3. List view -->
                <div class="help-section" id="list">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.list_heading')); ?></h3>
                            <p><?php echo t('tasks.help.list_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.list_body'); ?></p>
                    <p class="help-note"><?php echo htmlspecialchars(t('tasks.help.list_tip')); ?></p>
                </div>

                <!-- 4. Calendar view -->
                <div class="help-section" id="calendar">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.calendar_heading')); ?></h3>
                            <p><?php echo t('tasks.help.calendar_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.calendar_body'); ?></p>
                    <p><?php echo t('tasks.help.calendar_sidebar'); ?></p>
                    <h4><?php echo htmlspecialchars(t('tasks.help.calendar_click_heading')); ?></h4>
                    <p><?php echo t('tasks.help.calendar_click_body'); ?></p>
                    <h4><?php echo htmlspecialchars(t('tasks.help.calendar_multiday_heading')); ?></h4>
                    <p><?php echo t('tasks.help.calendar_multiday_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.calendar_field_deadline'); ?></div>
                        <div><?php echo t('tasks.help.calendar_field_span'); ?></div>
                        <div><?php echo t('tasks.help.calendar_field_everyday'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.calendar_note'); ?></p>
                </div>

                <!-- 5. Timeline view -->
                <div class="help-section" id="timeline">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.timeline_heading')); ?></h3>
                            <p><?php echo t('tasks.help.timeline_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.timeline_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.timeline_drag_heading')); ?></h4>
                    <p><?php echo t('tasks.help.timeline_drag_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.timeline_field_body'); ?></div>
                        <div><?php echo t('tasks.help.timeline_field_left'); ?></div>
                        <div><?php echo t('tasks.help.timeline_field_right'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.timeline_snap_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.timeline_rightclick_heading')); ?></h4>
                    <p><?php echo t('tasks.help.timeline_rightclick_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.timeline_click_heading')); ?></h4>
                    <p><?php echo t('tasks.help.timeline_click_body'); ?></p>
                </div>

                <!-- 6. Table view -->
                <div class="help-section" id="table">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.table_heading')); ?></h3>
                            <p><?php echo t('tasks.help.table_intro'); ?></p>
                        </div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_edit_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_edit_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_views_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_views_body'); ?></p>
                    <p class="help-note"><?php echo t('tasks.help.table_views_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_columns_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_columns_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_sort_heading')); ?></h4>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.table_field_sort'); ?></div>
                        <div><?php echo t('tasks.help.table_field_filter'); ?></div>
                        <div><?php echo t('tasks.help.table_field_search'); ?></div>
                        <div><?php echo t('tasks.help.table_field_reset'); ?></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_export_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_export_body'); ?></p>

                    <p class="help-note"><?php echo t('tasks.help.table_tip'); ?></p>
                </div>

                <!-- 7. The task panel -->
                <div class="help-section" id="panel">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.panel_heading')); ?></h3>
                            <p><?php echo t('tasks.help.panel_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.panel_field_title'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_status'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_assignee'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_dates'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_tags'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_desc'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_links'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_subtasks'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_comments'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_work'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_time'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.panel_view_note'); ?></p>
                    <p style="margin-top:10px;"><?php echo t('tasks.help.panel_context_note'); ?></p>
                    <p style="margin-top:10px;"><?php echo t('tasks.help.panel_links_note'); ?></p>
                    <p style="margin-top:10px;"><?php echo t('tasks.help.panel_preview_note'); ?></p>
                    <p style="margin-top:10px;"><?php echo t('tasks.help.panel_delete_note'); ?></p>
                </div>

                <!-- 8. Repeating tasks -->
                <div class="help-section" id="repeats">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.repeats_heading')); ?></h3>
                            <p><?php echo t('tasks.help.repeats_intro'); ?></p>
                        </div>
                    </div>

                    <p class="help-note bad"><?php echo t('tasks.help.repeats_cron_warning'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_setup_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_setup_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_mode_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_mode_body'); ?></p>
                    <div class="help-cards">
                        <div class="help-card"><span><?php echo t('tasks.help.repeats_mode_completion'); ?></span></div>
                        <div class="help-card"><span><?php echo t('tasks.help.repeats_mode_schedule'); ?></span></div>
                    </div>
                    <p class="help-note"><?php echo t('tasks.help.repeats_tip'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_pattern_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_pattern_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.repeats_pattern_daily'); ?></div>
                        <div><?php echo t('tasks.help.repeats_pattern_weekly'); ?></div>
                        <div><?php echo t('tasks.help.repeats_pattern_dom'); ?></div>
                        <div><?php echo t('tasks.help.repeats_pattern_nth'); ?></div>
                        <div><?php echo t('tasks.help.repeats_pattern_yearly'); ?></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_ends_heading')); ?></h4>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.repeats_ends_never'); ?></div>
                        <div><?php echo t('tasks.help.repeats_ends_date'); ?></div>
                        <div><?php echo t('tasks.help.repeats_ends_count'); ?></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_carry_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_carry_body'); ?></p>
                    <p class="help-note"><?php echo t('tasks.help.repeats_carry_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_when_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_when_body'); ?></p>
                    <p><?php echo t('tasks.help.repeats_when_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_preview_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_preview_body'); ?></p>
                    <p class="help-note"><?php echo t('tasks.help.repeats_preview_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_finding_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_finding_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.repeats_stop_heading')); ?></h4>
                    <p><?php echo t('tasks.help.repeats_stop_body'); ?></p>
                </div>
                <!-- 9. Tags -->
                <div class="help-section" id="tags">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.tags_heading')); ?></h3>
                            <p><?php echo t('tasks.help.tags_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.tags_body1'); ?></p>
                    <p><?php echo t('tasks.help.tags_body2'); ?></p>
                    <p class="help-note"><?php echo t('tasks.help.tags_tip'); ?></p>
                </div>

                <!-- 10. Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.settings_heading')); ?></h3>
                            <p><?php echo t('tasks.help.settings_intro'); ?></p>
                        </div>
                    </div>

                    <h4><?php echo t('tasks.help.settings_lookups_heading'); ?></h4>
                    <p><?php echo t('tasks.help.settings_lookups_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.settings_field_statuses'); ?></div>
                        <div><?php echo t('tasks.help.settings_field_priorities'); ?></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.settings_card_heading')); ?></h4>
                    <p><?php echo t('tasks.help.settings_card_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.settings_calendar_heading')); ?></h4>
                    <p><?php echo t('tasks.help.settings_calendar_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.settings_calendar_deadline'); ?></div>
                        <div><?php echo t('tasks.help.settings_calendar_span'); ?></div>
                        <div><?php echo t('tasks.help.settings_calendar_everyday'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.settings_calendar_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.settings_tags_heading')); ?></h4>
                    <p><?php echo t('tasks.help.settings_tags_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tasks.help.settings_tags_allow'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_chips'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_filter'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_search'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_calendar'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.settings_tags_note'); ?></p>
                </div>

                <!-- 11. Quick tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">11</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.tips_heading')); ?></h3>
                            <p><?php echo t('tasks.help.tips_intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <span class="help-card-icon">🖱️</span>
                            <div><?php echo t('tasks.help.tip1'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">⌨️</span>
                            <div><?php echo t('tasks.help.tip2'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">🔍</span>
                            <div><?php echo t('tasks.help.tip3'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">🏷️</span>
                            <div><?php echo t('tasks.help.tip4'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">📅</span>
                            <div><?php echo t('tasks.help.tip5'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">🔗</span>
                            <div><?php echo t('tasks.help.tip6'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">✋</span>
                            <div><?php echo t('tasks.help.tip7'); ?></div>
                        </div>
                        <div class="help-card row">
                            <span class="help-card-icon">📊</span>
                            <div><?php echo t('tasks.help.tip8'); ?></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as the user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el });
        });

        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0] && sections[0].id;
            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
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
