<?php
/**
 * Tasks Module Help Guide — full page with left-pane scroll-spy navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tasks.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <style>
        body { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        .header { flex-shrink: 0; }

        .thp-container { display: flex; flex: 1; min-height: 0; background: #f5f5f5; }

        /* Left sidebar navigation */
        .thp-sidebar {
            width: 260px;
            background: #fff;
            border-right: 1px solid #ddd;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
            overflow-y: auto;
        }
        .thp-sidebar h3 {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 12px;
        }
        .thp-nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .thp-nav-link:hover { background: #f5f5f5; color: #333; }
        .thp-nav-link.active { background: #f3f0ff; color: #6d28d9; font-weight: 600; }
        .thp-nav-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #eee;
            color: #888;
            font-weight: 700;
            font-size: 11px;
            flex-shrink: 0;
        }
        .thp-nav-link.active .thp-nav-num { background: #7c3aed; color: #fff; }

        /* Main content */
        .thp-main { flex: 1; overflow-y: auto; }

        .thp-hero {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%);
            color: #fff;
            padding: 40px 48px 36px;
            text-align: center;
        }
        .thp-hero h2 { margin: 0 0 8px; font-size: 26px; font-weight: 700; }
        .thp-hero p { margin: 0; font-size: 15px; opacity: 0.85; }

        .thp-content { max-width: 1120px; margin: 0 auto; padding: 10px 48px 48px; }

        /* Sections */
        .thp-section { padding: 28px 0; border-bottom: 1px solid #eee; scroll-margin-top: 20px; }
        .thp-section:last-child { border-bottom: none; padding-bottom: 0; }
        .thp-section-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 16px; }
        .thp-section-header h3 { margin: 0; font-size: 18px; color: #333; }
        .thp-section-header p { margin: 6px 0 0; font-size: 14px; color: #666; line-height: 1.6; }
        .thp-section > p { font-size: 14px; color: #555; line-height: 1.7; margin: 0 0 14px; }
        .thp-section-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f3f0ff;
            color: #6d28d9;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }
        .thp-section h4 { margin: 22px 0 8px; font-size: 14.5px; color: #333; }

        /* Feature cards grid */
        .thp-features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
        .thp-feature-card {
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: #fff;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .thp-feature-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .thp-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }
        .thp-feature-icon.purple { background: #f3e8ff; color: #7c3aed; }
        .thp-feature-icon.blue   { background: #e3f2fd; color: #0078d4; }
        .thp-feature-icon.green  { background: #e8f5e9; color: #2e7d32; }
        .thp-feature-icon.orange { background: #fff3e0; color: #e65100; }
        .thp-feature-icon.indigo { background: #e0e7ff; color: #4338ca; }
        .thp-feature-icon.teal   { background: #e0f2f1; color: #00695c; }
        .thp-feature-icon.red    { background: #fce4ec; color: #c62828; }
        .thp-feature-card h4 { margin: 0 0 6px; font-size: 15px; color: #333; }
        .thp-feature-card p { margin: 0; font-size: 12.5px; color: #666; line-height: 1.5; }

        /* Numbered steps */
        .thp-steps { display: flex; flex-direction: column; gap: 12px; margin-left: 46px; }
        .thp-step-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            background: #fafafa;
            font-size: 14px;
            color: #444;
            line-height: 1.5;
        }
        .thp-step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #7c3aed;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Definition-style list */
        .thp-fields { display: flex; flex-direction: column; gap: 8px; }
        .thp-fields > div {
            font-size: 13.5px;
            color: #555;
            line-height: 1.6;
            padding: 8px 12px;
            background: #fafafa;
            border-radius: 6px;
        }
        .thp-fields strong { color: #333; }

        /* Tip callout */
        .thp-tip {
            font-size: 13px !important;
            color: #6d28d9 !important;
            background: #f3f0ff;
            padding: 10px 14px;
            border-radius: 8px;
            border-left: 3px solid #7c3aed;
            margin-top: 12px !important;
        }

        /* Quick tips grid */
        .thp-tips-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .thp-tip-card {
            display: flex;
            gap: 12px;
            padding: 14px;
            background: #fafafa;
            border-radius: 8px;
            font-size: 13px;
            color: #555;
            line-height: 1.5;
        }
        .thp-tip-icon { font-size: 22px; flex-shrink: 0; }
        .thp-tip-card strong { color: #333; }

        @media (max-width: 900px) {
            .thp-sidebar { display: none; }
            .thp-content { padding: 10px 24px 40px; }
            .thp-hero { padding: 30px 24px; }
        }
        @media (max-width: 700px) {
            .thp-features-grid { grid-template-columns: 1fr; }
            .thp-tips-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="thp-container">
        <!-- Left pane navigation -->
        <div class="thp-sidebar">
            <h3><?php echo htmlspecialchars(t('tasks.help.guide')); ?></h3>
            <a href="#overview" class="thp-nav-link active" data-section="overview">
                <span class="thp-nav-num">1</span> <?php echo htmlspecialchars(t('tasks.help.nav_overview')); ?>
            </a>
            <a href="#board" class="thp-nav-link" data-section="board">
                <span class="thp-nav-num">2</span> <?php echo htmlspecialchars(t('tasks.help.nav_board')); ?>
            </a>
            <a href="#list" class="thp-nav-link" data-section="list">
                <span class="thp-nav-num">3</span> <?php echo htmlspecialchars(t('tasks.help.nav_list')); ?>
            </a>
            <a href="#calendar" class="thp-nav-link" data-section="calendar">
                <span class="thp-nav-num">4</span> <?php echo htmlspecialchars(t('tasks.help.nav_calendar')); ?>
            </a>
            <a href="#timeline" class="thp-nav-link" data-section="timeline">
                <span class="thp-nav-num">5</span> <?php echo htmlspecialchars(t('tasks.help.nav_timeline')); ?>
            </a>
            <a href="#table" class="thp-nav-link" data-section="table">
                <span class="thp-nav-num">6</span> <?php echo htmlspecialchars(t('tasks.help.nav_table')); ?>
            </a>
            <a href="#panel" class="thp-nav-link" data-section="panel">
                <span class="thp-nav-num">7</span> <?php echo htmlspecialchars(t('tasks.help.nav_panel')); ?>
            </a>
            <a href="#tags" class="thp-nav-link" data-section="tags">
                <span class="thp-nav-num">8</span> <?php echo htmlspecialchars(t('tasks.help.nav_tags')); ?>
            </a>
            <a href="#settings" class="thp-nav-link" data-section="settings">
                <span class="thp-nav-num">9</span> <?php echo htmlspecialchars(t('tasks.help.nav_settings')); ?>
            </a>
            <a href="#tips" class="thp-nav-link" data-section="tips">
                <span class="thp-nav-num">10</span> <?php echo htmlspecialchars(t('tasks.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="thp-main" id="helpMain">
            <div class="thp-hero">
                <h2><?php echo htmlspecialchars(t('tasks.help.hero_title')); ?></h2>
                <p><?php echo t('tasks.help.hero_subtitle'); ?></p>
            </div>

            <div class="thp-content">

                <!-- 1. Overview -->
                <div class="thp-section" id="overview">
                    <div class="thp-section-header">
                        <span class="thp-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.overview_heading')); ?></h3>
                            <p><?php echo t('tasks.help.overview_intro'); ?></p>
                        </div>
                    </div>
                    <div class="thp-features-grid">
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card1_desc'); ?></p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card2_desc'); ?></p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card3_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card3_desc'); ?></p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="14" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="5" y1="18" x2="16" y2="18"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card4_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card4_desc'); ?></p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon indigo">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line><line x1="15" y1="3" x2="15" y2="21"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card5_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card5_desc'); ?></p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon teal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card6_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card6_desc'); ?></p>
                        </div>
                        <div class="thp-feature-card">
                            <div class="thp-feature-icon red">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('tasks.help.overview_card7_title')); ?></h4>
                            <p><?php echo t('tasks.help.overview_card7_desc'); ?></p>
                        </div>
                    </div>
                    <p class="thp-tip"><?php echo htmlspecialchars(t('tasks.help.overview_tip')); ?></p>
                </div>

                <!-- 2. The board -->
                <div class="thp-section" id="board">
                    <div class="thp-section-header">
                        <span class="thp-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.board_heading')); ?></h3>
                            <p><?php echo t('tasks.help.board_intro'); ?></p>
                        </div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_columns_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_columns_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_creating_heading')); ?></h4>
                    <div class="thp-steps">
                        <div class="thp-step-item">
                            <div class="thp-step-num">1</div>
                            <div><?php echo t('tasks.help.board_creating_step1'); ?></div>
                        </div>
                        <div class="thp-step-item">
                            <div class="thp-step-num">2</div>
                            <div><?php echo t('tasks.help.board_creating_step2'); ?></div>
                        </div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_moving_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_moving_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_rightclick_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_rightclick_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.board_search_heading')); ?></h4>
                    <p><?php echo t('tasks.help.board_search_body'); ?></p>
                    <p class="thp-tip"><?php echo t('tasks.help.board_tip'); ?></p>
                </div>

                <!-- 3. List view -->
                <div class="thp-section" id="list">
                    <div class="thp-section-header">
                        <span class="thp-section-num">3</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.list_heading')); ?></h3>
                            <p><?php echo t('tasks.help.list_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.list_body'); ?></p>
                    <p class="thp-tip"><?php echo htmlspecialchars(t('tasks.help.list_tip')); ?></p>
                </div>

                <!-- 4. Calendar view -->
                <div class="thp-section" id="calendar">
                    <div class="thp-section-header">
                        <span class="thp-section-num">4</span>
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
                    <div class="thp-fields">
                        <div><?php echo t('tasks.help.calendar_field_deadline'); ?></div>
                        <div><?php echo t('tasks.help.calendar_field_span'); ?></div>
                        <div><?php echo t('tasks.help.calendar_field_everyday'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.calendar_note'); ?></p>
                </div>

                <!-- 5. Timeline view -->
                <div class="thp-section" id="timeline">
                    <div class="thp-section-header">
                        <span class="thp-section-num">5</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.timeline_heading')); ?></h3>
                            <p><?php echo t('tasks.help.timeline_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.timeline_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.timeline_drag_heading')); ?></h4>
                    <p><?php echo t('tasks.help.timeline_drag_body'); ?></p>
                    <div class="thp-fields">
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
                <div class="thp-section" id="table">
                    <div class="thp-section-header">
                        <span class="thp-section-num">6</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.table_heading')); ?></h3>
                            <p><?php echo t('tasks.help.table_intro'); ?></p>
                        </div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_edit_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_edit_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_columns_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_columns_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_sort_heading')); ?></h4>
                    <div class="thp-fields">
                        <div><?php echo t('tasks.help.table_field_sort'); ?></div>
                        <div><?php echo t('tasks.help.table_field_filter'); ?></div>
                        <div><?php echo t('tasks.help.table_field_search'); ?></div>
                        <div><?php echo t('tasks.help.table_field_reset'); ?></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.table_export_heading')); ?></h4>
                    <p><?php echo t('tasks.help.table_export_body'); ?></p>

                    <p class="thp-tip"><?php echo t('tasks.help.table_tip'); ?></p>
                </div>

                <!-- 7. The task panel -->
                <div class="thp-section" id="panel">
                    <div class="thp-section-header">
                        <span class="thp-section-num">7</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.panel_heading')); ?></h3>
                            <p><?php echo t('tasks.help.panel_intro'); ?></p>
                        </div>
                    </div>
                    <div class="thp-fields">
                        <div><?php echo t('tasks.help.panel_field_title'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_status'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_assignee'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_dates'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_tags'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_desc'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_links'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_subtasks'); ?></div>
                        <div><?php echo t('tasks.help.panel_field_comments'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.panel_delete_note'); ?></p>
                </div>

                <!-- 8. Tags -->
                <div class="thp-section" id="tags">
                    <div class="thp-section-header">
                        <span class="thp-section-num">8</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.tags_heading')); ?></h3>
                            <p><?php echo t('tasks.help.tags_intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tasks.help.tags_body1'); ?></p>
                    <p><?php echo t('tasks.help.tags_body2'); ?></p>
                    <p class="thp-tip"><?php echo t('tasks.help.tags_tip'); ?></p>
                </div>

                <!-- 9. Settings -->
                <div class="thp-section" id="settings">
                    <div class="thp-section-header">
                        <span class="thp-section-num">9</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.settings_heading')); ?></h3>
                            <p><?php echo t('tasks.help.settings_intro'); ?></p>
                        </div>
                    </div>

                    <h4><?php echo t('tasks.help.settings_lookups_heading'); ?></h4>
                    <p><?php echo t('tasks.help.settings_lookups_body'); ?></p>
                    <div class="thp-fields">
                        <div><?php echo t('tasks.help.settings_field_statuses'); ?></div>
                        <div><?php echo t('tasks.help.settings_field_priorities'); ?></div>
                    </div>

                    <h4><?php echo htmlspecialchars(t('tasks.help.settings_card_heading')); ?></h4>
                    <p><?php echo t('tasks.help.settings_card_body'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.settings_calendar_heading')); ?></h4>
                    <p><?php echo t('tasks.help.settings_calendar_body'); ?></p>
                    <div class="thp-fields">
                        <div><?php echo t('tasks.help.settings_calendar_deadline'); ?></div>
                        <div><?php echo t('tasks.help.settings_calendar_span'); ?></div>
                        <div><?php echo t('tasks.help.settings_calendar_everyday'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.settings_calendar_note'); ?></p>

                    <h4><?php echo htmlspecialchars(t('tasks.help.settings_tags_heading')); ?></h4>
                    <p><?php echo t('tasks.help.settings_tags_body'); ?></p>
                    <div class="thp-fields">
                        <div><?php echo t('tasks.help.settings_tags_allow'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_chips'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_filter'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_search'); ?></div>
                        <div><?php echo t('tasks.help.settings_tags_calendar'); ?></div>
                    </div>
                    <p style="margin-top:14px;"><?php echo t('tasks.help.settings_tags_note'); ?></p>
                </div>

                <!-- 10. Quick tips -->
                <div class="thp-section" id="tips">
                    <div class="thp-section-header">
                        <span class="thp-section-num">10</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('tasks.help.tips_heading')); ?></h3>
                            <p><?php echo t('tasks.help.tips_intro'); ?></p>
                        </div>
                    </div>
                    <div class="thp-tips-grid">
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🖱️</span>
                            <div><?php echo t('tasks.help.tip1'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">⌨️</span>
                            <div><?php echo t('tasks.help.tip2'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🔍</span>
                            <div><?php echo t('tasks.help.tip3'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🏷️</span>
                            <div><?php echo t('tasks.help.tip4'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">📅</span>
                            <div><?php echo t('tasks.help.tip5'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">🔗</span>
                            <div><?php echo t('tasks.help.tip6'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">✋</span>
                            <div><?php echo t('tasks.help.tip7'); ?></div>
                        </div>
                        <div class="thp-tip-card">
                            <span class="thp-tip-icon">📊</span>
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
        const navLinks = document.querySelectorAll('.thp-nav-link');
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
</body>
</html>
