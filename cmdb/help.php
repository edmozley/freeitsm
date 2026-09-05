<?php
/**
 * CMDB Module Help Guide — full page with left pane navigation
 *
 * Presentation comes from assets/css/help.css, the shared house style for
 * every module help guide. This page sets the CMDB accent and nothing else —
 * if you find yourself adding layout CSS here, it probably belongs in help.css
 * so every module gets it.
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

requireModuleAccess('cmdb');

// The company section only makes sense once the install serves more than one
// company — invisible on a single-company install, like the rest of
// multi-tenancy (mirrors tickets/help.php).
require_once '../includes/tenancy.php';
$showTenancyHelp = false;
try {
    $conn = connectToDatabase();
    $showTenancyHelp = isMultiTenant($conn);
} catch (Exception $e) {
    $showTenancyHelp = false;
}

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - <?php echo htmlspecialchars(t('cmdb.help.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--cmdb-accent);
            --accent-hover: var(--cmdb-accent-hover);
            --accent-soft:  var(--cmdb-accent-soft);
            --on-accent:    var(--cmdb-on-accent);
        }
    </style>
    <!-- Mobile layer: after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="cmdb" data-mobile-page="cmdb-help">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('cmdb.help.sidebar_label')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span> <?php echo t('cmdb.help.nav_overview'); ?>
            </a>
            <a href="#concepts" class="help-nav-link" data-section="concepts">
                <span class="help-nav-num">2</span> <?php echo t('cmdb.help.nav_concepts'); ?>
            </a>
            <a href="#classes" class="help-nav-link" data-section="classes">
                <span class="help-nav-num">3</span> <?php echo t('cmdb.help.nav_classes'); ?>
            </a>
            <a href="#ai-suggest" class="help-nav-link" data-section="ai-suggest">
                <span class="help-nav-num">4</span> <?php echo t('cmdb.help.nav_ai_suggest'); ?>
            </a>
            <a href="#objects" class="help-nav-link" data-section="objects">
                <span class="help-nav-num">5</span> <?php echo t('cmdb.help.nav_objects'); ?>
            </a>
            <a href="#hierarchy" class="help-nav-link" data-section="hierarchy">
                <span class="help-nav-num">6</span> <?php echo t('cmdb.help.nav_hierarchy'); ?>
            </a>
            <a href="#relationships" class="help-nav-link" data-section="relationships">
                <span class="help-nav-num">7</span> <?php echo t('cmdb.help.nav_relationships'); ?>
            </a>
            <a href="#when-to-use" class="help-nav-link" data-section="when-to-use">
                <span class="help-nav-num">8</span> <?php echo t('cmdb.help.nav_when_to_use'); ?>
            </a>
            <a href="#synthesis" class="help-nav-link" data-section="synthesis">
                <span class="help-nav-num">9</span> <?php echo t('cmdb.help.nav_synthesis'); ?>
            </a>
            <a href="#tickets" class="help-nav-link" data-section="tickets">
                <span class="help-nav-num">10</span> <?php echo t('cmdb.help.nav_tickets'); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">11</span> <?php echo t('cmdb.help.nav_settings'); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">12</span> <?php echo t('cmdb.help.nav_tips'); ?>
            </a>
            <a href="#dataquality" class="help-nav-link" data-section="dataquality">
                <span class="help-nav-num">13</span> <?php echo t('cmdb.help.nav_dataquality'); ?>
            </a>
            <?php if ($showTenancyHelp): ?>
            <a href="#companies" class="help-nav-link" data-section="companies">
                <span class="help-nav-num">14</span> <?php echo t('cmdb.help.nav_companies'); ?>
            </a>
            <?php endif; ?>

        </div>

        <!-- Main content -->
        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('cmdb.help.hero_title')); ?></h2>
                <p><?php echo t('cmdb.help.hero_intro'); ?></p>
            </div>

            <div class="help-content">

                <!-- 1. Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('cmdb.help.overview_heading')); ?></h3>
                            <p><?php echo t('cmdb.help.overview_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 22V8l10-6 10 6v14"></path><path d="M2 12h20"></path><path d="M2 17h20"></path><line x1="12" y1="2" x2="12" y2="22"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card1_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card2_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card3_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('cmdb.help.overview_card4_title')); ?></h4>
                            <p><?php echo t('cmdb.help.overview_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 2. Core concepts -->
                <div class="help-section" id="concepts">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('cmdb.help.concepts_heading')); ?></h3>
                            <p><?php echo t('cmdb.help.concepts_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-defs">
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('cmdb.help.concept_class_name'); ?></div>
                            <div class="help-def-desc"><?php echo t('cmdb.help.concept_class_desc'); ?></div>
                        </div>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('cmdb.help.concept_object_name'); ?></div>
                            <div class="help-def-desc"><?php echo t('cmdb.help.concept_object_desc'); ?></div>
                        </div>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('cmdb.help.concept_property_name'); ?></div>
                            <div class="help-def-desc"><?php echo t('cmdb.help.concept_property_desc'); ?></div>
                        </div>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('cmdb.help.concept_hierarchy_name'); ?></div>
                            <div class="help-def-desc"><?php echo t('cmdb.help.concept_hierarchy_desc'); ?></div>
                        </div>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo t('cmdb.help.concept_relationship_name'); ?></div>
                            <div class="help-def-desc"><?php echo t('cmdb.help.concept_relationship_desc'); ?></div>
                        </div>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.concepts_tip'); ?>
                    </div>
                </div>

                <!-- 3. Classes & properties -->
                <div class="help-section" id="classes">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <div>
                            <h3><?php echo t('cmdb.help.classes_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.classes_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div><?php echo t('cmdb.help.classes_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div><?php echo t('cmdb.help.classes_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div><?php echo t('cmdb.help.classes_step3'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">4</span>
                            <div><?php echo t('cmdb.help.classes_step4'); ?></div>
                        </div>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.classes_tip1'); ?>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.classes_tip2'); ?>
                    </div>
                </div>

                <!-- 4. AI Suggest Properties -->
                <div class="help-section" id="ai-suggest">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <div>
                            <h3><?php echo t('cmdb.help.ai_suggest_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.ai_suggest_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.ai_suggest_lead'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div><?php echo t('cmdb.help.ai_suggest_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div><?php echo t('cmdb.help.ai_suggest_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div><?php echo t('cmdb.help.ai_suggest_step3'); ?></div>
                        </div>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.ai_suggest_tip'); ?>
                    </div>
                </div>

                <!-- 5. Adding objects -->
                <div class="help-section" id="objects">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo t('cmdb.help.objects_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.objects_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div><?php echo t('cmdb.help.objects_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div><?php echo t('cmdb.help.objects_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div><?php echo t('cmdb.help.objects_step3'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">4</span>
                            <div><?php echo t('cmdb.help.objects_step4'); ?></div>
                        </div>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.objects_tip'); ?>
                    </div>
                </div>

                <!-- 6. Hierarchy -->
                <div class="help-section" id="hierarchy">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo t('cmdb.help.hierarchy_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.hierarchy_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.hierarchy_body'); ?></p>

                    <div class="help-diagram">
                        <span class="help-diagram-node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n1')); ?></span><br>
                        <span class="help-diagram-line">&#9492;&#9472;&#9472;</span> <span class="help-diagram-node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n2')); ?></span><br>
                        <span class="help-diagram-line">&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="help-diagram-node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n3')); ?></span><br>
                        <span class="help-diagram-line">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="help-diagram-node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n4')); ?></span><br>
                        <span class="help-diagram-line">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&#9492;&#9472;&#9472;</span> <span class="help-diagram-node"><?php echo htmlspecialchars(t('cmdb.help.hierarchy_diagram_n5')); ?></span>
                    </div>

                    <p><?php echo t('cmdb.help.hierarchy_body2'); ?></p>

                    <div class="help-note">
                        <?php echo t('cmdb.help.hierarchy_tip'); ?>
                    </div>
                </div>

                <!-- 7. Relationships -->
                <div class="help-section" id="relationships">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo t('cmdb.help.relationships_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.relationships_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.relationships_body'); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div><?php echo t('cmdb.help.relationships_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div><?php echo t('cmdb.help.relationships_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div><?php echo t('cmdb.help.relationships_step3'); ?></div>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.relationships_body2'); ?></p>

                    <div class="help-note">
                        <?php echo t('cmdb.help.relationships_tip'); ?>
                    </div>
                </div>

                <!-- 8. When to use which -->
                <div class="help-section" id="when-to-use">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <div>
                            <h3><?php echo t('cmdb.help.when_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.when_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.when_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.when_card1_body'); ?></p>
                            <p class="help-card-eg"><?php echo t('cmdb.help.when_card1_ex'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.when_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.when_card2_body'); ?></p>
                            <p class="help-card-eg"><?php echo t('cmdb.help.when_card2_ex'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.when_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.when_card3_body'); ?></p>
                            <p class="help-card-eg"><?php echo t('cmdb.help.when_card3_ex'); ?></p>
                        </div>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.when_tip'); ?>
                    </div>
                </div>

                <!-- 9. Synthesis layer -->
                <div class="help-section" id="synthesis">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <div>
                            <h3><?php echo t('cmdb.help.synthesis_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.synthesis_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10l-5 4.5L16.5 21 12 17.8 7.5 21 9 14.5 4 10l6.1-1.2z"/></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo t('cmdb.help.synthesis_card4_title'); ?></h4>
                            <p><?php echo t('cmdb.help.synthesis_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 10. Linking tickets -->
                <div class="help-section" id="tickets">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <div>
                            <h3><?php echo t('cmdb.help.tickets_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.tickets_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-steps">
                        <div class="help-step">
                            <span class="help-step-num">1</span>
                            <div><?php echo t('cmdb.help.tickets_step1'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">2</span>
                            <div><?php echo t('cmdb.help.tickets_step2'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">3</span>
                            <div><?php echo t('cmdb.help.tickets_step3'); ?></div>
                        </div>
                        <div class="help-step">
                            <span class="help-step-num">4</span>
                            <div><?php echo t('cmdb.help.tickets_step4'); ?></div>
                        </div>
                    </div>

                    <div class="help-note">
                        <?php echo t('cmdb.help.tickets_tip'); ?>
                    </div>
                </div>

                <!-- 11. Settings tour -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">11</span>
                        <div>
                            <h3><?php echo t('cmdb.help.settings_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.settings_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.settings_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.settings_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.settings_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.settings_card4_title'); ?></h4>
                            <p><?php echo t('cmdb.help.settings_card4_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 12. Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">12</span>
                        <div>
                            <h3><?php echo t('cmdb.help.tips_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.tips_intro'); ?></p>
                        </div>
                    </div>

                    <div class="help-cards">
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card1_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card1_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card2_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card2_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card3_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card3_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card4_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card4_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card5_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card5_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card6_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card6_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card7_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card7_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <h4><?php echo t('cmdb.help.tips_card8_title'); ?></h4>
                            <p><?php echo t('cmdb.help.tips_card8_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 13. Data quality -->
                <div class="help-section" id="dataquality">
                    <div class="help-section-header">
                        <span class="help-section-num">13</span>
                        <div>
                            <h3><?php echo t('cmdb.help.dataquality_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.dataquality_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.dataquality_what'); ?></p>
                    <p><?php echo t('cmdb.help.dataquality_checks'); ?></p>
                    <p><?php echo t('cmdb.help.dataquality_advisory'); ?></p>
                </div>

                <?php if ($showTenancyHelp): ?>
                <!-- 14. Companies (multi-company installs only) -->
                <div class="help-section" id="companies">
                    <div class="help-section-header">
                        <span class="help-section-num">14</span>
                        <div>
                            <h3><?php echo t('cmdb.help.companies_heading'); ?></h3>
                            <p><?php echo t('cmdb.help.companies_intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('cmdb.help.companies_scope'); ?></p>
                    <p><?php echo t('cmdb.help.companies_links'); ?></p>
                    <p><?php echo t('cmdb.help.companies_shared'); ?></p>
                    <p><?php echo t('cmdb.help.companies_move'); ?></p>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the active section in the sidebar as user scrolls
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
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Smooth-scroll within the help container, not the page
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
    <script src="../assets/js/mobile.js?v=55"></script>
</body>
</html>
