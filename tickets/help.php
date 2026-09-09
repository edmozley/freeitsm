<?php
/**
 * Tickets Module Help Guide - Full page with left pane navigation
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/functions.php';
require_once '../includes/tenancy.php';
require_once '../includes/theme.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('tickets');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'tickets'];

// The companies / email-routing section only makes sense once the install serves
// more than one company — keep it invisible on a single-company install, exactly
// like the rest of multi-tenancy (isMultiTenant gate).
$showTenancyHelp = false;
try {
    $conn = connectToDatabase();
    $showTenancyHelp = isMultiTenant($conn);
} catch (Exception $e) {
    $showTenancyHelp = false;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.help.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* Tickets is the one module with no accent of its own — it uses the
           application's brand colour, which help.css already reads from
           --accent. So there is nothing for this page to say. */
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('tickets.help.sidebar_title')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo t('tickets.help.nav.overview'); ?>
            </a>
            <a href="#inbox" class="help-nav-link" data-section="inbox">
                <span class="help-nav-num">2</span>
                <?php echo t('tickets.help.nav.inbox'); ?>
            </a>
            <a href="#working-with-tickets" class="help-nav-link" data-section="working-with-tickets">
                <span class="help-nav-num">3</span>
                <?php echo t('tickets.help.nav.working_with_tickets'); ?>
            </a>
            <a href="#working-faster" class="help-nav-link" data-section="working-faster">
                <span class="help-nav-num">4</span>
                <?php echo t('tickets.help.nav.working_faster'); ?>
            </a>
            <a href="#comments-attachments" class="help-nav-link" data-section="comments-attachments">
                <span class="help-nav-num">5</span>
                <?php echo t('tickets.help.nav.comments_attachments'); ?>
            </a>
            <a href="#ai-tools" class="help-nav-link" data-section="ai-tools">
                <span class="help-nav-num">6</span>
                <?php echo t('tickets.help.nav.ai_tools'); ?>
            </a>
            <a href="#csat" class="help-nav-link" data-section="csat">
                <span class="help-nav-num">7</span>
                <?php echo t('tickets.help.nav.csat'); ?>
            </a>
            <a href="#user-management" class="help-nav-link" data-section="user-management">
                <span class="help-nav-num">8</span>
                <?php echo t('tickets.help.nav.user_management'); ?>
            </a>
            <a href="#dashboard" class="help-nav-link" data-section="dashboard">
                <span class="help-nav-num">9</span>
                <?php echo t('tickets.help.nav.dashboard'); ?>
            </a>
            <a href="#calendar-rota" class="help-nav-link" data-section="calendar-rota">
                <span class="help-nav-num">10</span>
                <?php echo t('tickets.help.nav.calendar_rota'); ?>
            </a>
            <a href="#settings" class="help-nav-link" data-section="settings">
                <span class="help-nav-num">11</span>
                <?php echo t('tickets.help.nav.settings'); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">12</span>
                <?php echo t('tickets.help.nav.tips'); ?>
            </a>
            <?php if ($showTenancyHelp): ?>
            <a href="#companies" class="help-nav-link" data-section="companies">
                <span class="help-nav-num">13</span>
                <?php echo t('tickets.help.nav.companies'); ?>
            </a>
            <?php endif; ?>
            <a href="#whatsapp" class="help-nav-link" data-section="whatsapp">
                <span class="help-nav-num"><?php echo $showTenancyHelp ? 14 : 13; ?></span>
                WhatsApp channel
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo t('tickets.help.hero_title'); ?></h2>
                <p><?php echo t('tickets.help.hero_sub'); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo t('tickets.help.overview.heading'); ?></h3>
                            <p><?php echo t('tickets.help.overview.intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_inbox_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_inbox_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_dashboard_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_dashboard_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_calendar_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_calendar_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4><?php echo t('tickets.help.overview.card_rota_title'); ?></h4>
                            <p><?php echo t('tickets.help.overview.card_rota_body'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Section 2: The Inbox -->
                <div class="help-section" id="inbox">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <div>
                            <h3><?php echo t('tickets.help.inbox.heading'); ?></h3>
                            <p><?php echo t('tickets.help.inbox.intro'); ?></p>
                        </div>
                    </div>
                    <p><?php echo t('tickets.help.inbox.p_folders'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.inbox.field_my'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_unassigned'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_all_open'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_closed'); ?></div>
                        <div><?php echo t('tickets.help.inbox.field_dept'); ?></div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.inbox.switch_heading'); ?></p>
                    <p><?php echo t('tickets.help.inbox.switch_body'); ?></p>

                    <p><?php echo t('tickets.help.inbox.p_actions'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.inbox.tip'); ?></p>
                </div>

                <!-- Section 3: Working with Tickets (highlighted) -->
                <div class="help-section" id="working-with-tickets">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo t('tickets.help.working.heading'); ?></h3>
                    </div>
                    <p><?php echo t('tickets.help.working.intro'); ?></p>

                    <p><?php echo t('tickets.help.working.creating_heading'); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.working.step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.working.step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.working.step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('tickets.help.working.step4'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <?php echo t('tickets.help.working.step5'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.editing_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.editing_body'); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.working.card_status_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_status_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.working.card_priority_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_priority_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.working.card_category_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_category_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.working.card_analyst_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_analyst_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.working.card_enduser_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_enduser_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.working.card_dept_title'); ?></strong>
                            <span><?php echo t('tickets.help.working.card_dept_body'); ?></span>
                        </div>
                    </div>

                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo t('tickets.help.working.flow_new'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('tickets.help.working.flow_in_progress'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('tickets.help.working.flow_resolved'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('tickets.help.working.flow_closed'); ?></div>
                    </div>

                    <p style="margin-top: 24px;"><?php echo t('tickets.help.working.triage_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.triage_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.working.triage_dept'); ?></div>
                        <div><?php echo t('tickets.help.working.triage_analyst'); ?></div>
                        <div><?php echo t('tickets.help.working.triage_spring'); ?></div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.fullscreen_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.fullscreen_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.working.fullscreen_max'); ?></div>
                        <div><?php echo t('tickets.help.working.fullscreen_dbl'); ?></div>
                        <div><?php echo t('tickets.help.working.fullscreen_sticks'); ?></div>
                    </div>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.rightclick_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.rightclick_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.working.rightclick_status'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_priority'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_assign'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_cmdb'); ?></div>
                        <div><?php echo t('tickets.help.working.rightclick_time'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('tickets.help.working.rightclick_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.working.time_heading'); ?></p>
                    <p><?php echo t('tickets.help.working.time_body'); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.working.time_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.working.time_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.working.time_step3'); ?>
                            </div>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('tickets.help.working.time_tip1'); ?></p>

                    <p class="help-note"><?php echo t('tickets.help.working.time_tip2'); ?></p>

                    <h4>Linking the equipment a ticket is about</h4>

                    <p>Equipment is recorded in the <strong>Links</strong> bar at the top of the ticket, alongside problems, changes and related tickets &mdash; each item is a pill marked &#128187;. Choose <strong>Link to&hellip; &rarr; Equipment</strong> and the picker opens already showing whatever is assigned to the person who raised the ticket &mdash; for &ldquo;my monitor is flickering&rdquo;, that is almost always the answer, and it is one click.</p>

                    <p>Start typing and it searches the whole estate instead. As well as hostname, make, model, serial number and asset tag, <strong>it searches the location</strong>. That matters more than it sounds: when somebody reports the screen in a meeting room, nobody involved knows its hostname &mdash; they know where it is. Searching &ldquo;meeting room 3&rdquo; finds it, provided your assets have locations set.</p>

                    <p>Hover a pill to see the make, model, serial number and location, so the device is identified without a round of questions. Click it to open the asset, or use the &times; to unlink. CMDB objects sit in the same bar marked &#128451; and are attached the same way &mdash; they are kept visibly separate because they are different things: a broken mouse is an asset and will never be a configuration item.</p>

                    <p class="help-note">Requesters can link equipment themselves when raising a ticket in the self-service portal, but only equipment assigned to <em>them</em>. Shared items &mdash; meeting room screens, printers, anything nobody holds &mdash; can only be attached by an analyst, so expect to add those yourself when a ticket describes one.</p>

                    <h4>Tasks that come out of a ticket</h4>

                    <p>Plenty of tickets lead to follow-up work &mdash; order the part, book the engineer, write it up afterwards. Choose <strong>Link to&hellip; &rarr; Task</strong> and that work becomes a task attached to the ticket, shown as a pill marked &#9744; while it is outstanding and &#9745; once it is done. A ticket can have as many tasks as it needs.</p>

                    <p><strong>The same box does both jobs.</strong> Type what the work is: as you type, FreeITSM searches the tasks you already have, and offers to <strong>create a task with exactly what you typed</strong> at the top of the list. So if the job is already on somebody's board you attach that one, and if it is not, you make it &mdash; without stopping to decide which of the two you are doing first. Seeing the near-matches as you type is the point: it is what stops a second copy of a task that already exists.</p>

                    <p>A task made this way <strong>belongs to you</strong> and starts on your own task list, because raising a task while working a ticket nearly always means you intend to do it. Reassign it from the task itself if it is really somebody else's. It also takes the ticket's company, so it appears alongside the rest of that client's work.</p>

                    <p>The pill shows the status, and a fraction such as <strong>2/5</strong> if the task has subtasks &mdash; so you can see how far along it is without opening it. Hover for who it is with. Click it to open the task in full.</p>

                    <p>You can attach a task <strong>without opening the ticket at all</strong>: right-click any ticket in the list and choose <strong>Link to task&hellip;</strong>.</p>

                    <p class="help-note warn"><strong>A task belongs to one ticket.</strong> If you attach a task that is already on another ticket, it <em>moves</em> &mdash; the other ticket loses it. FreeITSM will say so before it happens, naming the task and the ticket it would be taken from, and will do nothing if you decline. The picker also marks such a task <strong>currently on TICKET-x</strong> while you are still choosing, so you can usually see it coming.</p>

                    <h4>Seeing what is on the other end of a link</h4>

                    <p>Every linked record carries a small <strong>&#9432;</strong> beside it. Click it and a card opens on the spot with the handful of facts you actually wanted &mdash; without leaving the ticket you are reading and without opening a second tab you will forget to close.</p>

                    <p>What the card shows depends on what you are looking at. A <strong>ticket</strong> gives its status, priority, who it is with and who raised it. A <strong>task</strong> gives its status, who it is assigned to, when it is due and how many subtasks are done. A <strong>change</strong> gives its status, its risk and the planned window. A <strong>problem</strong> gives its status and how many tickets are attached to it. A piece of <strong>equipment</strong> gives its tag, make and model, serial number, who holds it and when the warranty runs out. A <strong>contract</strong> gives its supplier and renewal date. A <strong>knowledge article</strong> gives its opening lines.</p>

                    <p>The card is <strong>read-only</strong>, deliberately. It exists so you can tell whether a link is the one you meant, and being able to change a record you only meant to glance at is a good way to change the wrong one. <strong>Open</strong> at the foot of the card takes you to the record itself when you do want to work on it.</p>

                    <p>Press <strong>Escape</strong> to close it, or click anywhere else. Clicking the same <strong>&#9432;</strong> again closes it too. On a phone the card comes up from the bottom of the screen instead, because there is nowhere sensible to float it.</p>

                    <p>The same <strong>&#9432;</strong> appears wherever records are linked to one another &mdash; on a task's links, on the incidents listed against a problem or a change, on the equipment attached to a contract, and on the contracts and tickets listed against a piece of equipment.</p>

                    <p class="help-note">If a card says the record <strong>cannot be shown</strong>, that is the honest answer rather than a fault: it means the record is not one you have access to, or it is no longer there. FreeITSM deliberately gives the same answer to both, because saying which would tell you something about a record you are not entitled to see.</p>

                    <p class="help-note">The &times; on a task pill <strong>unlinks, it does not delete</strong>. The task carries on living in the Tasks module &mdash; it simply stops being connected to this ticket. Deleting a task is done from the task itself, where you can see what you are throwing away. And if you close a ticket that still has unfinished tasks, FreeITSM will say so and let you decide &mdash; it will not stop you, because the remaining work is often somebody else's now.</p>
                </div>

                <!-- Section 4: Working faster — templates, bulk actions, merge & split -->
                <div class="help-section" id="working-faster">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo t('tickets.help.faster.heading'); ?></h3>
                    </div>
                    <p><?php echo t('tickets.help.faster.intro'); ?></p>

                    <p><strong><?php echo t('tickets.help.faster.templates_heading'); ?></strong></p>
                    <p><?php echo t('tickets.help.faster.templates_body'); ?></p>
                    <ul>
                        <li><?php echo t('tickets.help.faster.templates_team'); ?></li>
                        <li><?php echo t('tickets.help.faster.templates_mine'); ?></li>
                    </ul>
                    <p class="help-note"><?php echo t('tickets.help.faster.templates_tip'); ?></p>

                    <p><strong><?php echo t('tickets.help.faster.select_heading'); ?></strong></p>
                    <p><?php echo t('tickets.help.faster.select_body'); ?></p>
                    <?php /* help-list, not a table: the page already styles this
                             pattern and inventing a new class would be a stylesheet
                             change for one list. */ ?>
                    <div class="help-list">
                        <div><strong><?php echo t('tickets.help.faster.key_click'); ?></strong> &mdash; <?php echo t('tickets.help.faster.key_click_d'); ?></div>
                        <div><strong><?php echo t('tickets.help.faster.key_ctrl'); ?></strong> &mdash; <?php echo t('tickets.help.faster.key_ctrl_d'); ?></div>
                        <div><strong><?php echo t('tickets.help.faster.key_shift'); ?></strong> &mdash; <?php echo t('tickets.help.faster.key_shift_d'); ?></div>
                        <div><strong><?php echo t('tickets.help.faster.key_kb'); ?></strong> &mdash; <?php echo t('tickets.help.faster.key_kb_d'); ?></div>
                    </div>
                    <p><?php echo t('tickets.help.faster.select_actions'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.faster.select_tip'); ?></p>

                    <p><strong><?php echo t('tickets.help.faster.merge_heading'); ?></strong></p>
                    <p><?php echo t('tickets.help.faster.merge_body'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.faster.merge_tip'); ?></p>

                    <p><strong><?php echo t('tickets.help.faster.split_heading'); ?></strong></p>
                    <p><?php echo t('tickets.help.faster.split_body'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.faster.split_tip'); ?></p>

                    <p><strong><?php echo t('tickets.help.faster.undo_heading'); ?></strong></p>
                    <p><?php echo t('tickets.help.faster.undo_body'); ?></p>
                </div>

                <!-- Section 5: Comments & Attachments -->
                <div class="help-section" id="comments-attachments">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <div>
                            <h3><?php echo t('tickets.help.comments.heading'); ?></h3>
                            <p><?php echo t('tickets.help.comments.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.comments.adding_heading'); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.comments.step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.comments.step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.comments.step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.comments.files_heading'); ?></p>
                    <p><?php echo t('tickets.help.comments.files_body'); ?></p>

                    <p><?php echo t('tickets.help.comments.audit_heading'); ?></p>
                    <p><?php echo t('tickets.help.comments.audit_body'); ?></p>

                    <p class="help-note"><?php echo t('tickets.help.comments.tip'); ?></p>
                </div>

                <!-- Section 5: AI tools -->
                <div class="help-section" id="ai-tools">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <div>
                            <h3><?php echo t('tickets.help.ai.heading'); ?></h3>
                            <p><?php echo t('tickets.help.ai.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.ai.cleanup_heading'); ?></p>
                    <p><?php echo t('tickets.help.ai.cleanup_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.ai.cleanup_streams'); ?></div>
                        <div><?php echo t('tickets.help.ai.cleanup_undo'); ?></div>
                        <div><?php echo t('tickets.help.ai.cleanup_tone'); ?></div>
                        <div><?php echo t('tickets.help.ai.cleanup_key'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('tickets.help.ai.cleanup_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.ai.ask_heading'); ?></p>
                    <p><?php echo t('tickets.help.ai.ask_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.ai.ask_context'); ?></div>
                        <div><?php echo t('tickets.help.ai.ask_linked'); ?></div>
                        <div><?php echo t('tickets.help.ai.ask_shared'); ?></div>
                    </div>
                </div>

                <!-- Section 6: CSAT surveys -->
                <div class="help-section" id="csat">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <div>
                            <h3><?php echo t('tickets.help.csat.heading'); ?></h3>
                            <p><?php echo t('tickets.help.csat.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.csat.modes_heading'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.csat.mode_auto'); ?></div>
                        <div><?php echo t('tickets.help.csat.mode_manual'); ?></div>
                        <div><?php echo t('tickets.help.csat.mode_off'); ?></div>
                    </div>
                    <p><?php echo t('tickets.help.csat.modes_choose'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.template_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.template_body'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.csat.template_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.survey_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.survey_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.csat.survey_stars'); ?></div>
                        <div><?php echo t('tickets.help.csat.survey_emojis'); ?></div>
                    </div>
                    <p><?php echo t('tickets.help.csat.survey_store'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.analytics_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.analytics_body'); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.csat.card_kpi_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_kpi_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.csat.card_dist_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_dist_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.csat.card_leader_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_leader_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.csat.card_recent_title'); ?></strong>
                            <span><?php echo t('tickets.help.csat.card_recent_body'); ?></span>
                        </div>
                    </div>
                    <p class="help-note"><?php echo t('tickets.help.csat.analytics_tip'); ?></p>

                    <p style="margin-top: 20px;"><?php echo t('tickets.help.csat.one_heading'); ?></p>
                    <p><?php echo t('tickets.help.csat.one_body'); ?></p>
                </div>

                <!-- Section 7: User management -->
                <div class="help-section" id="user-management">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <div>
                            <h3><?php echo t('tickets.help.users.heading'); ?></h3>
                            <p><?php echo t('tickets.help.users.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.users.p_intro'); ?></p>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.users.add_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.add_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.users.add_email'); ?></div>
                        <div><?php echo t('tickets.help.users.add_names'); ?></div>
                        <div><?php echo t('tickets.help.users.add_password'); ?></div>
                    </div>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.users.edit_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.edit_body'); ?></p>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.users.delete_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.delete_body'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.users.tip'); ?></p>

                    <p style="margin-top: 22px;"><?php echo t('tickets.help.users.groups_heading'); ?></p>
                    <p><?php echo t('tickets.help.users.groups_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.users.groups_make'); ?></div>
                        <div><?php echo t('tickets.help.users.groups_add'); ?></div>
                        <div><?php echo t('tickets.help.users.groups_expiry'); ?></div>
                        <div><?php echo t('tickets.help.users.groups_uses'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('tickets.help.users.groups_admin'); ?></p>
                </div>

                <!-- Section 8: Dashboard -->
                <div class="help-section" id="dashboard">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <div>
                            <h3><?php echo t('tickets.help.dash.heading'); ?></h3>
                            <p><?php echo t('tickets.help.dash.intro'); ?></p>
                        </div>
                    </div>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.dash.step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.dash.step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.dash.step3'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <?php echo t('tickets.help.dash.step4'); ?>
                            </div>
                        </div>
                    </div>
                    <p><?php echo t('tickets.help.dash.examples'); ?></p>
                    <p class="help-note"><?php echo t('tickets.help.dash.tip'); ?></p>
                </div>

                <!-- Section 9: Calendar & Rota (highlighted) -->
                <div class="help-section" id="calendar-rota">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <h3><?php echo t('tickets.help.cal_rota.heading'); ?></h3>
                    </div>
                    <p><?php echo t('tickets.help.cal_rota.intro'); ?></p>

                    <p><?php echo t('tickets.help.cal_rota.cal_heading'); ?></p>
                    <p><?php echo t('tickets.help.cal_rota.cal_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.cal_rota.cal_month'); ?></div>
                        <div><?php echo t('tickets.help.cal_rota.cal_week'); ?></div>
                        <div><?php echo t('tickets.help.cal_rota.cal_day'); ?></div>
                    </div>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.cal_rota.cal_scope'); ?></p>
                    <p><?php echo t('tickets.help.cal_rota.cal_edit'); ?></p>

                    <!-- Prominent Calendar Sync callout -->
                    <a href="help-calendar-sync.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin:24px 0;background:linear-gradient(135deg, #b45309 0%, #7c2d12 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(180,83,9,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(180,83,9,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(180,83,9,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><polyline points="9,16 11,18 15,14"></polyline></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;">Calendar Sync &mdash; full guide</div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;">Put your scheduled tickets in the calendar you actually use. Subscription links vs Microsoft 365, the Azure permission and its blast radius, which mailbox is whose, changes coming back from Outlook, the scheduled job vs notifications, and troubleshooting.</div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

                    <p style="margin-top: 16px;"><?php echo t('tickets.help.cal_rota.rota_heading'); ?></p>
                    <p><?php echo t('tickets.help.cal_rota.rota_body'); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.cal_rota.rota_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.cal_rota.rota_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.cal_rota.rota_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('tickets.help.cal_rota.tip'); ?></p>
                </div>

                <!-- Section 10: Settings -->
                <div class="help-section" id="settings">
                    <div class="help-section-header">
                        <span class="help-section-num">11</span>
                        <div>
                            <h3><?php echo t('tickets.help.settings.heading'); ?></h3>
                            <p><?php echo t('tickets.help.settings.intro'); ?></p>
                        </div>
                    </div>

                    <!-- Prominent SLA Management callout -->
                    <a href="help-sla.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg, #0078d4 0%, #005a9e 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(0,120,212,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(0,120,212,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(0,120,212,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;"><?php echo t('tickets.help.settings.sla_callout_title'); ?></div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;"><?php echo t('tickets.help.settings.sla_callout_body'); ?></div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

                    <!-- Prominent Mailbox Authentication callout -->
                    <a href="help-mailbox-auth.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(79,70,229,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(79,70,229,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(79,70,229,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;">Mailbox Authentication — Admin Guide</div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;">Connect a Microsoft 365 or Google mailbox: delegated vs app-only, the "reading from the right inbox" safeguards, email aliases, OAuth scopes &amp; Azure setup, and troubleshooting.</div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

                    <!-- Prominent Automatic emails & signatures callout -->
                    <a href="help-email-templates.php" style="display:flex;align-items:center;gap:18px;padding:20px 24px;margin-bottom:24px;background:linear-gradient(135deg, #0f766e 0%, #115e59 100%);color:white;border-radius:12px;text-decoration:none;box-shadow:0 4px 12px rgba(15,118,110,0.25);transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(15,118,110,0.35)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(15,118,110,0.25)';">
                        <div style="flex-shrink:0;width:56px;height:56px;border-radius:12px;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:30px;height:30px;"><path d="M3 17c3.5 0 3.5-10 7-10s3.5 10 7 10c1.5 0 2.5-.7 4-2"></path><line x1="3" y1="21" x2="21" y2="21"></line></svg>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:18px;font-weight:700;margin-bottom:4px;">Automatic emails &amp; signatures — Admin Guide</div>
                            <div style="font-size:13px;opacity:0.9;line-height:1.5;">Merge codes and <code style="background:rgba(255,255,255,0.18);padding:1px 5px;border-radius:4px;">[ticket_url]</code>, the public web address links depend on, limiting a template to particular senders, checking who would get what, and per-analyst signatures.</div>
                        </div>
                        <div style="flex-shrink:0;font-size:24px;opacity:0.7;">&rarr;</div>
                    </a>

                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_dept_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_dept_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_types_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_types_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_priorities_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_priorities_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_statuses_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_statuses_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_categories_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_categories_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_sla_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_sla_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_email_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_email_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_custom_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_custom_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_templates_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_templates_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_cleanup_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_cleanup_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.settings.card_csat_title'); ?></strong>
                            <span><?php echo t('tickets.help.settings.card_csat_body'); ?></span>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.settings.email_heading'); ?></p>
                    <p><?php echo t('tickets.help.settings.email_body'); ?></p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <?php echo t('tickets.help.settings.email_step1'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <?php echo t('tickets.help.settings.email_step2'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <?php echo t('tickets.help.settings.email_step3'); ?>
                            </div>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('tickets.help.settings.tip'); ?></p>
                </div>

                <!-- Section 11: Quick Tips -->
                <div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">12</span>
                        <h3><?php echo t('tickets.help.tips.heading'); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128269;</div>
                            <div><strong><?php echo t('tickets.help.tips.search_title'); ?></strong><br><?php echo t('tickets.help.tips.search_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9200;</div>
                            <div><strong><?php echo t('tickets.help.tips.sla_title'); ?></strong><br><?php echo t('tickets.help.tips.sla_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128233;</div>
                            <div><strong><?php echo t('tickets.help.tips.reply_title'); ?></strong><br><?php echo t('tickets.help.tips.reply_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128203;</div>
                            <div><strong><?php echo t('tickets.help.tips.trail_title'); ?></strong><br><?php echo t('tickets.help.tips.trail_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128200;</div>
                            <div><strong><?php echo t('tickets.help.tips.dash_title'); ?></strong><br><?php echo t('tickets.help.tips.dash_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128197;</div>
                            <div><strong><?php echo t('tickets.help.tips.rota_title'); ?></strong><br><?php echo t('tickets.help.tips.rota_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#129032;</div>
                            <div><strong><?php echo t('tickets.help.tips.drag_title'); ?></strong><br><?php echo t('tickets.help.tips.drag_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128471;</div>
                            <div><strong><?php echo t('tickets.help.tips.dbl_title'); ?></strong><br><?php echo t('tickets.help.tips.dbl_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128499;</div>
                            <div><strong><?php echo t('tickets.help.tips.rightclick_title'); ?></strong><br><?php echo t('tickets.help.tips.rightclick_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#10024;</div>
                            <div><strong><?php echo t('tickets.help.tips.ai_title'); ?></strong><br><?php echo t('tickets.help.tips.ai_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#11088;</div>
                            <div><strong><?php echo t('tickets.help.tips.feedback_title'); ?></strong><br><?php echo t('tickets.help.tips.feedback_body'); ?></div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128100;</div>
                            <div><strong><?php echo t('tickets.help.tips.precreate_title'); ?></strong><br><?php echo t('tickets.help.tips.precreate_body'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($showTenancyHelp): ?>
                <!-- Section 12: Companies & email routing (multi-tenancy) -->
                <div class="help-section" id="companies">
                    <div class="help-section-header">
                        <span class="help-section-num">13</span>
                        <div>
                            <h3><?php echo t('tickets.help.companies.heading'); ?></h3>
                            <p><?php echo t('tickets.help.companies.intro'); ?></p>
                        </div>
                    </div>

                    <p><?php echo t('tickets.help.companies.switcher_body'); ?></p>

                    <!-- Two kinds of mailbox -->
                    <p style="margin-top: 8px;"><?php echo t('tickets.help.companies.mailboxes_heading'); ?></p>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            </div>
                            <h4><?php echo t('tickets.help.companies.card_pinned_title'); ?></h4>
                            <p><?php echo t('tickets.help.companies.card_pinned_body'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h4><?php echo t('tickets.help.companies.card_shared_title'); ?></h4>
                            <p><?php echo t('tickets.help.companies.card_shared_body'); ?></p>
                        </div>
                    </div>

                    <!-- How a shared mailbox decides which company -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.routing_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.routing_body'); ?></p>
                    <div class="help-flow">
                        <div class="help-flow-step"><?php echo t('tickets.help.companies.flow_reply'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('tickets.help.companies.flow_sender'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('tickets.help.companies.flow_domain'); ?></div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step"><?php echo t('tickets.help.companies.flow_triage'); ?></div>
                    </div>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.companies.rule_reply'); ?></div>
                        <div><?php echo t('tickets.help.companies.rule_sender'); ?></div>
                        <div><?php echo t('tickets.help.companies.rule_domain'); ?></div>
                        <div><?php echo t('tickets.help.companies.rule_triage'); ?></div>
                    </div>

                    <!-- Domains & specific senders -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.keys_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.keys_body'); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.companies.card_domains_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.card_domains_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.companies.card_senders_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.card_senders_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.companies.card_public_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.card_public_body'); ?></span>
                        </div>
                    </div>

                    <!-- Triage queue -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.triage_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.triage_body'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('tickets.help.companies.triage_create'); ?></div>
                        <div><?php echo t('tickets.help.companies.triage_assign'); ?></div>
                        <div><?php echo t('tickets.help.companies.triage_sweep'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('tickets.help.companies.triage_tip'); ?></p>

                    <!-- Routing test -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.test_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.test_body'); ?></p>

                    <!-- Data separation -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.privacy_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.privacy_body'); ?></p>

                    <!-- Per-company settings -->
                    <p style="margin-top: 20px;"><?php echo t('tickets.help.companies.settings_heading'); ?></p>
                    <p><?php echo t('tickets.help.companies.settings_body'); ?></p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.companies.settings_custom_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.settings_custom_body'); ?></span>
                        </div>
                        <div class="help-card">
                            <strong><?php echo t('tickets.help.companies.settings_global_title'); ?></strong>
                            <span><?php echo t('tickets.help.companies.settings_global_body'); ?></span>
                        </div>
                    </div>

                    <p class="help-note"><?php echo t('tickets.help.companies.tip'); ?></p>
                </div>
                <?php endif; ?>

                <!-- WhatsApp channel -->
                <div class="help-section" id="whatsapp">
                    <div class="help-section-header">
                        <span class="help-section-num"><?php echo $showTenancyHelp ? 14 : 13; ?></span>
                        <div>
                            <h3>WhatsApp channel</h3>
                            <p>Let customers chat with an analyst over WhatsApp — each message becomes a ticket, just like email.</p>
                        </div>
                    </div>
                    <p>
                        Add a channel under <strong>Settings &rarr; Messaging</strong> (provider = Twilio or Meta, plus the
                        WhatsApp number and credentials). Each channel shows a <strong>webhook URL</strong> — paste it into your
                        provider so inbound messages reach this install.
                    </p>
                    <p>
                        An inbound message opens a new ticket (tagged with the <strong>WhatsApp</strong> origin) or threads into
                        the customer's open one. Reply from the <strong>inline composer</strong> in the reading pane — the
                        <strong>Suggest</strong> button drafts a reply with AI and <strong>Summarise</strong> writes a summary
                        into the ticket notes. WhatsApp only allows free-text replies within <strong>24 hours</strong> of the
                        customer's last message; after that the composer offers a <strong>template picker</strong> instead &mdash;
                        pick a pre-approved template (set up under Settings &rarr; Messaging), fill its blanks, and send to
                        re-open the conversation.
                    </p>
                    <p class="help-note">
                        Testing on a laptop? Providers can only reach a public address, so run a tunnel
                        (e.g. <code>ngrok http 80</code>) and use the HTTPS URL it gives you as the webhook host. See the
                        <a href="https://github.com/edmozley/freeitsm/wiki/WhatsApp" target="_blank" rel="noopener">WhatsApp wiki page</a> for a full walkthrough.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=2"></script>
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
</body>
</html>
