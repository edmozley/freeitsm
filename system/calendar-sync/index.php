<?php
/**
 * System — Calendar sync (GH discussion #75).
 *
 * Two things an administrator decides, on one screen because they are the same
 * subject:
 *   1. the CONNECTION that writes scheduled work into analysts' own calendars;
 *   2. whether analysts may publish a subscribe (.ics) link at all.
 *
 * The second stands alone: an install with no supported calendar provider still
 * publishes subscribe links, and still has to be able to govern them.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'calendar-sync';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.calsync.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Same shell as the other System pages. */
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        .settings-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; width: 100%; margin: 0;
                           box-sizing: border-box; padding: 30px 24px 24px; }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-muted, #888); margin: 0 0 24px 0; line-height: 1.5; }

        .cs-card { background: var(--surface, #fff); border-radius: 8px; padding: 24px;
                   box-shadow: 0 2px 8px var(--shadow, rgba(0,0,0,0.08)); margin-bottom: 20px; max-width: 820px; }
        .cs-card h2 { font-size: 16px; font-weight: 600; margin: 0 0 6px; color: var(--text, #333); }
        .cs-card > p { font-size: 13px; color: var(--text-muted, #666); margin: 0 0 18px; line-height: 1.55; }

        .cs-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; max-width: 520px; }
        .cs-field > span { font-size: 12px; color: var(--text-muted, #666); }
        .cs-field input, .cs-field select {
            padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            background: var(--surface, #fff); color: var(--text, #333); font-size: 13px; font-family: inherit;
        }
        .cs-radio { display: flex; gap: 18px; margin-bottom: 14px; flex-wrap: wrap; }
        .cs-radio label { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; cursor: pointer; }
        .cs-actions { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; align-items: center; }

        .cs-note { font-size: 12px; color: var(--text-muted, #666); line-height: 1.55; margin-top: 6px; }

        /* The permission warning. It is not a failure state, so it must not look
           like one — but an administrator must not discover the scope of
           Calendars.ReadWrite (application) for themselves, after the fact. */
        .cs-warn {
            background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e);
            border: 1px solid var(--warning-border, #f0d9a8); border-radius: 4px;
            padding: 12px 14px; font-size: 12px; line-height: 1.6; margin-bottom: 18px;
        }
        /* ⚠️ Scoped to the TITLE. A bare `.cs-warn strong` also caught the inline
           <strong> emphasis inside the body text — "Calendars.ReadWrite",
           "Application", "every mailbox in the tenant" — and threw each of them
           onto a line of its own, shredding the paragraph. */
        .cs-warn-title { display: block; font-weight: 600; margin-bottom: 4px; }

        .cs-result { margin-top: 12px; padding: 11px 13px; border-radius: 4px; font-size: 12.5px; line-height: 1.55; display: none; }
        .cs-result.ok   { background: var(--success-bg, #d4edda); color: var(--success-text, #155724); }
        .cs-result.bad  { background: var(--danger-bg, #f8d7da);  color: var(--danger-text, #721c24); }
        .cs-result code { font-family: Consolas, Monaco, monospace; word-break: break-all; }

        .cs-people { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cs-people th {
            text-align: left; font-size: 12px; font-weight: 600; color: var(--text-muted, #888);
            padding: 6px 10px 8px; border-bottom: 1px solid var(--border-soft, #eee);
        }
        .cs-people td { padding: 7px 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); vertical-align: middle; }
        .cs-people tr:last-child td { border-bottom: none; }
        .cs-people input[type="email"] {
            width: 100%; max-width: 280px; padding: 6px 8px; font-size: 12.5px; font-family: inherit;
            border: 1px solid var(--border, #ddd); border-radius: 4px;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        /* An address INHERITED from the analyst's FreeITSM account is shown muted
           and italic, so "this is where it would go" is visibly different from
           "somebody chose this". The difference matters: the inherited one is
           often wrong (a local account, an LDAP import keyed differently). */
        .cs-people input.cs-inherited { color: var(--text-muted, #888); font-style: italic; }
        .cs-people .cs-analyst-name { font-weight: 600; color: var(--text, #333); }
        .cs-people .cs-analyst-email { font-size: 11.5px; color: var(--text-muted, #888); }
        .cs-pill { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; white-space: nowrap; }
        .cs-pill.on   { background: var(--success-bg, #d4edda); color: var(--success-text, #155724); }
        .cs-pill.offp { background: var(--surface-2, #f0f0f0); color: var(--text-muted, #777); }
        .cs-pill.bad  { background: var(--danger-bg, #f8d7da);  color: var(--danger-text, #721c24); }
        .cs-pill.warn { background: var(--warning-bg, #fff3cd); color: var(--warning-text, #856404); }

        /* The health strip. Deliberately at the TOP of the inbound card, above the
           settings rather than below them: "is this working?" is the question an
           admin arrives with, and it should not be answered after a scroll. */
        .cs-health { margin: 0 0 16px; padding: 10px 12px; border-radius: 6px; font-size: 13px;
                     border: 1px solid var(--border, #e0e0e0); background: var(--surface-2, #f8f9fa); }
        .cs-health.warn { border-color: var(--warning-border, #856404); background: var(--warning-bg, #fff3cd); }
        .cs-health.warn strong { color: var(--warning-text, #856404); }
        .cs-health p { margin: 0; }
        .cs-health p + p { margin-top: 6px; }

        .cs-inbound { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border-soft, #eee); }
        .cs-inbound h3 { font-size: 14px; font-weight: 600; margin: 0 0 6px; color: var(--text, #333); }
        .cs-check { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; margin: 10px 0 2px; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=132">
</head>
<body data-mobile-module="system" data-mobile-page="settings" data-mobile-shell="own">
    <div class="settings-shell">
    <?php include '../includes/header.php'; ?>

    <div class="settings-scroll">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.calsync.title')); ?></h1>
        <p class="page-subtitle"><?php echo t('system.calsync.subtitle'); ?></p>

        <div id="csNeedsDb" class="cs-card" style="display:none;">
            <h2><?php echo htmlspecialchars(t('system.calsync.needs_db')); ?></h2>
            <p><?php echo htmlspecialchars(t('system.calsync.needs_db_desc')); ?></p>
        </div>

        <div id="csMain" style="display:none;">
            <div class="cs-card">
                <h2><?php echo htmlspecialchars(t('system.calsync.conn_heading')); ?></h2>
                <p><?php echo t('system.calsync.conn_desc'); ?></p>

                <div class="cs-warn">
                    <span class="cs-warn-title"><?php echo htmlspecialchars(t('system.calsync.perm_title')); ?></span>
                    <?php echo t('system.calsync.perm_body'); ?>
                </div>

                <label class="cs-field">
                    <span><?php echo htmlspecialchars(t('system.calsync.name')); ?></span>
                    <input type="text" id="csName" autocomplete="off" maxlength="100" value="Microsoft 365">
                </label>

                <div class="cs-radio">
                    <label><input type="radio" name="csSource" value="mailbox" checked onchange="csSource()">
                        <?php echo htmlspecialchars(t('system.calsync.source_mailbox')); ?></label>
                    <label><input type="radio" name="csSource" value="own" onchange="csSource()">
                        <?php echo htmlspecialchars(t('system.calsync.source_own')); ?></label>
                </div>

                <div id="csMailboxBlock">
                    <label class="cs-field">
                        <span><?php echo htmlspecialchars(t('system.calsync.mailbox')); ?></span>
                        <select id="csMailbox"></select>
                    </label>
                    <p class="cs-note" id="csNoMailboxes" style="display:none;">
                        <?php echo htmlspecialchars(t('system.calsync.no_mailboxes')); ?>
                    </p>
                </div>

                <div id="csOwnBlock" style="display:none;">
                    <label class="cs-field"><span><?php echo htmlspecialchars(t('system.calsync.tenant_id')); ?></span>
                        <input type="text" id="csTenant" autocomplete="off"></label>
                    <label class="cs-field"><span><?php echo htmlspecialchars(t('system.calsync.client_id')); ?></span>
                        <input type="text" id="csClient" autocomplete="off"></label>
                    <label class="cs-field"><span><?php echo htmlspecialchars(t('system.calsync.client_secret')); ?></span>
                        <input type="password" id="csSecret" autocomplete="new-password"></label>
                    <p class="cs-note"><?php echo htmlspecialchars(t('system.calsync.secret_note')); ?></p>
                </div>

                <div class="cs-actions">
                    <button class="btn btn-primary" onclick="csSave()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                    <button class="btn btn-secondary" onclick="csTest()"><?php echo htmlspecialchars(t('system.calsync.test')); ?></button>
                    <button class="btn btn-secondary" id="csDeleteBtn" onclick="csDelete()" style="display:none;"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                </div>

                <?php /* The probe is what turns "it doesn't work" into a specific
                         answer: the token proves the credentials and the consent,
                         this proves one particular mailbox is reachable. They fail
                         for different reasons and need different fixes. */ ?>
                <label class="cs-field" style="margin-top:16px;">
                    <span><?php echo htmlspecialchars(t('system.calsync.probe')); ?></span>
                    <input type="text" id="csProbe" autocomplete="off" placeholder="someone@yourdomain.com">
                </label>

                <div class="cs-result" id="csResult"></div>

                <?php /* Reading changes back OUT of the calendar. Its own block
                         because it needs the cron running and the others do not,
                         and because it is the only setting here that lets a
                         personal action change shared data. */ ?>
                <div class="cs-inbound">
                    <h3><?php echo htmlspecialchars(t('system.calsync.inbound_heading')); ?></h3>
                    <p class="cs-note"><?php echo t('system.calsync.inbound_desc'); ?></p>

                    <?php /* Health. Everything else on this screen reports a
                             failure that announced itself; this reports the one
                             that does not — a scheduled job that has stopped,
                             which is indistinguishable from a quiet calendar. */ ?>
                    <div class="cs-health" id="csHealth" style="display:none;"></div>

                    <label class="cs-check">
                        <input type="checkbox" id="csAcceptDeletes" onchange="csSavePolicy()">
                        <span><?php echo htmlspecialchars(t('system.calsync.accept_deletes')); ?></span>
                    </label>
                    <p class="cs-note"><?php echo t('system.calsync.accept_deletes_note'); ?></p>

                    <?php /* Optional: near-instant instead of on the next poll.
                             Blank = polling only, which is a perfectly good
                             answer and the only one available to an install the
                             internet cannot reach. */ ?>
                    <label class="cs-field" style="margin-top:16px;">
                        <span><?php echo htmlspecialchars(t('system.calsync.notify_url')); ?></span>
                        <input type="url" id="csNotifyUrl" autocomplete="off" placeholder="https://…/api/calendar/graph_notify.php">
                    </label>
                    <p class="cs-note"><?php echo t('system.calsync.notify_url_note'); ?></p>
                    <div class="cs-actions">
                        <button class="btn btn-secondary" onclick="csSaveNotify()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                        <button class="btn btn-secondary" onclick="csSuggestNotify()"><?php echo htmlspecialchars(t('system.calsync.notify_suggest')); ?></button>
                        <span class="cs-note" id="csSubCount"></span>
                    </div>
                </div>
            </div>

            <?php /* Which mailbox each analyst's work goes to. Admin-only: the
                     permission behind the push reaches every mailbox in the
                     tenant, so an analyst able to set their own target could fill
                     a colleague's calendar. They choose WHETHER; this chooses
                     WHERE. */ ?>
            <div class="cs-card">
                <h2><?php echo htmlspecialchars(t('system.calsync.people_heading')); ?></h2>
                <p><?php echo t('system.calsync.people_desc'); ?></p>
                <table class="cs-people">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('system.calsync.col_analyst')); ?></th>
                            <th><?php echo htmlspecialchars(t('system.calsync.col_mailbox')); ?></th>
                            <th><?php echo htmlspecialchars(t('system.calsync.col_status')); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="csPeople"></tbody>
                </table>
            </div>

            <div class="cs-card">
                <h2><?php echo htmlspecialchars(t('system.calsync.feed_heading')); ?></h2>
                <p><?php echo t('system.calsync.feed_desc'); ?></p>

                <label class="cs-field">
                    <span><?php echo htmlspecialchars(t('system.calsync.feed_label')); ?></span>
                    <select id="csFeedMode" onchange="csSavePolicy()">
                        <option value="full"><?php echo htmlspecialchars(t('system.calsync.feed_full')); ?></option>
                        <option value="ref"><?php echo htmlspecialchars(t('system.calsync.feed_ref')); ?></option>
                        <option value="off"><?php echo htmlspecialchars(t('system.calsync.feed_off')); ?></option>
                    </select>
                </label>
                <p class="cs-note"><?php echo htmlspecialchars(t('system.calsync.feed_note')); ?></p>
            </div>
        </div>
    </div>
    </div><!-- /.settings-shell -->

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
        const CS_API = '../../api/system/calendar_sync.php';
        let csState = null;

        function csSource() {
            const own = document.querySelector('input[name="csSource"]:checked').value === 'own';
            document.getElementById('csOwnBlock').style.display     = own ? '' : 'none';
            document.getElementById('csMailboxBlock').style.display = own ? 'none' : '';
        }

        function csShow(ok, html) {
            const el = document.getElementById('csResult');
            el.className = 'cs-result ' + (ok ? 'ok' : 'bad');
            el.innerHTML = html;
            // 🔴 'block', NOT ''. The .cs-result rule sets display:none, so clearing
            // the inline style hands control straight back to it and the box stays
            // invisible — with the text dutifully written into something nobody can
            // see. Pressing Test appeared to do nothing at all.
            el.style.display = 'block';
        }

        async function csLoad() {
            const d = await (await fetch(CS_API, { credentials: 'same-origin' })).json();
            if (d.needs_db_verify) {
                document.getElementById('csNeedsDb').style.display = '';
                return;
            }
            document.getElementById('csMain').style.display = '';
            csState = d;

            const sel = document.getElementById('csMailbox');
            sel.innerHTML = (d.mailboxes || []).map(m =>
                `<option value="${m.id}">${m.name.replace(/</g, '&lt;')}</option>`).join('');
            // No Microsoft mailbox to borrow from is a normal state, not an error —
            // say so and push them to the manual form rather than showing an empty
            // dropdown that looks broken.
            const none = !(d.mailboxes || []).length;
            document.getElementById('csNoMailboxes').style.display = none ? '' : 'none';
            if (none) {
                document.querySelector('input[name="csSource"][value="own"]').checked = true;
                csSource();
            }

            document.getElementById('csFeedMode').value = d.feed_mode || 'full';
            document.getElementById('csAcceptDeletes').checked = !!d.accept_deletes;
            document.getElementById('csNotifyUrl').value = d.notify_url || '';
            csNotifyDefault = d.notify_default || '';
            document.getElementById('csSubCount').textContent = d.subscriptions
                ? t('system.calsync.notify_active', { n: d.subscriptions }) : '';
            csNotifyOn = !!(d.notify_url || '').trim();
            csRenderHealth(d);
            csRenderPeople(d.analysts || []);

            if (d.connection) {
                document.getElementById('csName').value = d.connection.name;
                document.getElementById('csDeleteBtn').style.display = '';
                if (d.connection.mailbox_id) {
                    document.querySelector('input[name="csSource"][value="mailbox"]').checked = true;
                    sel.value = String(d.connection.mailbox_id);
                } else if (d.connection.has_credentials) {
                    document.querySelector('input[name="csSource"][value="own"]').checked = true;
                    // The secret is never sent back, so show that one IS stored and
                    // let a blank field mean "leave it alone".
                    document.getElementById('csSecret').placeholder = '••••••••  (unchanged)';
                }
                csSource();
                if (d.connection.last_error) {
                    csShow(false, escapeCs(t('system.calsync.last_error')) + ' <code>'
                                + escapeCs(d.connection.last_error) + '</code>');
                }
            }
        }

        function escapeCs(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g,
                c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
        }

        /**
         * Is any of this actually working?
         *
         * 🔑 THE HEADLINE IS HOW LONG SINCE ANYTHING WAS CHECKED, and it is the
         * headline because it is the only failure on this screen that stays
         * quiet. A broken connection says so. A subscription that will not
         * create says so. A scheduled job that has stopped running says
         * NOTHING — it is indistinguishable from a calendar in which nothing
         * has changed, and it will sit there looking healthy for weeks while
         * every change made in Outlook is silently lost.
         *
         * Thirty minutes is the threshold because the documented advice is to
         * run it every five. It is a warning rather than an error: a job that
         * runs hourly is unusual but not wrong, and FreeITSM has no way to know
         * what schedule was chosen. So it reports the fact and lets an admin
         * judge it, rather than calling a deliberate choice a fault.
         */
        const CS_POLL_STALE_MINUTES = 30;
        let csNotifyOn = false;

        function csAgo(mins) {
            if (mins < 1)  return t('system.calsync.health_just_now');
            if (mins < 60) return t('system.calsync.health_mins',  { n: mins });
            if (mins < 60 * 48) return t('system.calsync.health_hours', { n: Math.floor(mins / 60) });
            return t('system.calsync.health_days', { n: Math.floor(mins / 1440) });
        }

        function csRenderHealth(d) {
            const box = document.getElementById('csHealth');

            // Nobody has it switched on, so there is nothing to be healthy or
            // unhealthy about. An empty warning box would be noise.
            if (!d.enrolled) { box.style.display = 'none'; return; }

            const mins  = d.last_poll_minutes;
            const never = (mins === null || mins === undefined);
            const stale = never || mins >= CS_POLL_STALE_MINUTES;
            const lines = [];

            lines.push('<p>' + (stale ? '<strong>' : '') + escapeCs(
                never ? t('system.calsync.health_never')
                      : t('system.calsync.health_checked', { n: csAgo(mins) })
            ) + (stale ? '</strong>' : '') + '</p>');

            // The note carries WHAT TO DO. "Last checked 3 hours ago" tells an
            // admin something is wrong and nothing about where to look, and the
            // answer is nearly always a job that is no longer running.
            if (stale) lines.push('<p class="cs-note">' + t('system.calsync.health_stale_note') + '</p>');

            if (csNotifyOn) {
                lines.push('<p class="cs-note">' + escapeCs(
                    t('system.calsync.health_subs', { n: d.subscriptions || 0, total: d.enrolled })
                ) + '</p>');
            }

            box.className = 'cs-health' + (stale ? ' warn' : '');
            box.innerHTML = lines.join('');
            // ⚠️ '' and not 'block' — the stylesheet owns how this displays, and
            // hard-coding a value here is how the test button's result box ended
            // up invisible.
            box.style.display = '';
        }

        /**
         * One row per active analyst.
         *
         * The mailbox box shows the OVERRIDE where one exists, and otherwise the
         * address inherited from their FreeITSM account — muted, so an admin can
         * see at a glance which are chosen and which are merely assumed. The
         * inherited one is frequently wrong (a local account, an LDAP import
         * keyed on something else), which is the entire reason this screen exists.
         */
        function csRenderPeople(people) {
            const tb = document.getElementById('csPeople');
            tb.innerHTML = people.map(p => {
                const override  = p.calendar_address || '';
                const inherited = !override;
                const shown     = override || p.email || '';
                const mode      = p.mode || 'off';
                let pill = '<span class="cs-pill offp">' + escapeCs(t('system.calsync.mode_off')) + '</span>';
                if (mode === 'push') pill = '<span class="cs-pill on">' + escapeCs(t('system.calsync.mode_push')) + '</span>';
                if (mode === 'feed') pill = '<span class="cs-pill on">' + escapeCs(t('system.calsync.mode_feed')) + '</span>';
                // The failure carries WHAT failed. "Last sync failed" on its own
                // tells an admin there is a problem and nothing about which one —
                // and the answers are entirely different (an expired secret, a
                // mailbox that does not exist, a permission never consented).
                if (p.last_error) {
                    pill += ' <span class="cs-pill bad" title="' + escapeCs(p.last_error) + '">'
                          + escapeCs(t('system.calsync.mode_error')) + '</span>';
                }
                // Tasks (#75). Shown, never SET here: this screen decides what is
                // possible, and the analyst decides what they want — the same
                // division the ticket mode already follows. An admin who cannot
                // see the choice cannot answer "why is my task not in Outlook",
                // which is the only reason it is on this table at all.
                //
                // Only alongside 'push': task events are real appointments, so
                // the choice does nothing on any other mode and showing it would
                // suggest otherwise.
                if (mode === 'push' && p.task_mode && p.task_mode !== 'off') {
                    pill += ' <span class="cs-pill on" title="' + escapeCs(t('system.calsync.tasks_note')) + '">'
                          + escapeCs(t('system.calsync.tasks_' + p.task_mode)) + '</span>';
                }
                // Notifications, per person. Shown only where they could apply —
                // no address configured means nobody is subscribed by design, and
                // flagging that on every row would report a feature not in use as
                // though it were a fault.
                if (csNotifyOn && mode === 'push') {
                    const sh = (p.sub_hours === null || p.sub_hours === undefined) ? null : Number(p.sub_hours);
                    if (!p.subscription_id) {
                        // Enrolled, an address is set, and yet Microsoft has
                        // nothing to call. This is the state that hid a broken
                        // handshake for an hour: everything looked configured.
                        pill += ' <span class="cs-pill warn" title="' + escapeCs(t('system.calsync.sub_none_note')) + '">'
                              + escapeCs(t('system.calsync.sub_none')) + '</span>';
                    } else if (sh === null || sh < 0) {
                        pill += ' <span class="cs-pill bad">' + escapeCs(t('system.calsync.sub_lapsed')) + '</span>';
                    } else {
                        pill += ' <span class="cs-pill on" title="'
                              + escapeCs(t('system.calsync.sub_expires', { n: csAgo(sh * 60) })) + '">'
                              + escapeCs(t('system.calsync.sub_ok')) + '</span>';
                    }
                }
                return `<tr data-analyst="${p.id}">
                    <td><div class="cs-analyst-name">${escapeCs(p.full_name)}</div>
                        <div class="cs-analyst-email">${escapeCs(p.email)}</div></td>
                    <td><input type="email" class="${inherited ? 'cs-inherited' : ''}"
                               value="${escapeCs(shown)}"
                               placeholder="${escapeCs(p.email || '')}"
                               data-original="${escapeCs(shown)}"></td>
                    <td>${pill}</td>
                    <td><button class="btn btn-secondary btn-sm" onclick="csSaveAddress(${p.id}, this)">${escapeCs(t('system.calsync.check_save'))}</button></td>
                </tr>`;
            }).join('');
        }

        async function csSaveAddress(analystId, btn) {
            const row   = btn.closest('tr');
            const input = row.querySelector('input[type="email"]');
            const value = input.value.trim();
            // Typing the inherited address back in is the same as no override, so
            // do not store one — an override that merely repeats the default is a
            // thing to maintain for no benefit.
            const address = (value === input.placeholder) ? '' : value;

            btn.disabled = true;
            const d = await csPost({ action: 'set_address', analyst_id: analystId, calendar_address: address });
            btn.disabled = false;
            if (!d.success) { csShow(false, escapeCs(d.error || '')); return; }

            input.classList.toggle('cs-inherited', address === '');
            if (d.verified === true) {
                csShow(true, escapeCs(t('system.calsync.probe_ok', { addr: value || input.placeholder })));
            } else if (d.verified === false) {
                csShow(false, escapeCs(t('system.calsync.probe_bad', { addr: value || input.placeholder })));
            } else {
                // null = we could not ask. Say that, rather than implying it is fine.
                csShow(true, escapeCs(t('system.calsync.saved_unverified')));
            }
            await csLoad();
        }

        async function csPost(body) {
            const r = await fetch(CS_API, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(body)
            });
            return r.json();
        }

        async function csSave() {
            const source = document.querySelector('input[name="csSource"]:checked').value;
            const d = await csPost({
                action: 'save',
                name: document.getElementById('csName').value,
                source: source,
                mailbox_id: document.getElementById('csMailbox').value || '',
                tenant_id: document.getElementById('csTenant').value,
                client_id: document.getElementById('csClient').value,
                client_secret: document.getElementById('csSecret').value,
                feed_mode: document.getElementById('csFeedMode').value
            });
            if (!d.success) { csShow(false, escapeCs(d.error || '')); return; }
            csShow(true, escapeCs(t('system.calsync.saved')));
            document.getElementById('csSecret').value = '';
            await csLoad();
        }

        let csNotifyDefault = '';

        /** Fill in the URL this page was reached on, for an admin to confirm. */
        function csSuggestNotify() {
            if (!csNotifyDefault) return;
            document.getElementById('csNotifyUrl').value = csNotifyDefault;
            // Said out loud rather than quietly filled in: on a machine behind a
            // proxy or a tunnel this is frequently NOT the address Microsoft can
            // reach, and a wrong one fails at subscription time with a message
            // about validation that gives no hint the URL is the problem.
            csShow(true, escapeCs(t('system.calsync.notify_suggest_note')));
        }

        async function csSaveNotify() {
            const d = await csPost({
                action: 'save', policy_only: '1',
                feed_mode: document.getElementById('csFeedMode').value,
                notify_url: document.getElementById('csNotifyUrl').value.trim()
            });
            if (!d.success) { csShow(false, escapeCs(d.error || '')); return; }
            csShow(true, escapeCs(t('system.calsync.saved')) + ' ' + escapeCs(t('system.calsync.notify_saved')));
            await csLoad();
        }

        /** The feed policy saves on its own — it is not part of the connection. */
        async function csSavePolicy() {
            const d = await csPost({
                action: 'save', policy_only: '1',
                feed_mode: document.getElementById('csFeedMode').value,
                accept_deletes: document.getElementById('csAcceptDeletes').checked ? '1' : '0'
            });
            if (d.success) csShow(true, escapeCs(t('system.calsync.saved')));
        }

        async function csTest() {
            csShow(true, escapeCs(t('system.calsync.testing')));
            const d = await csPost({ action: 'test', probe: document.getElementById('csProbe').value.trim() });
            if (!d.success) {
                csShow(false, '<strong>' + escapeCs(t('system.calsync.test_failed')) + '</strong><br><code>'
                            + escapeCs(d.error || '') + '</code><br><br>'
                            + escapeCs(t('system.calsync.test_failed_hint')));
                return;
            }
            let html = '<strong>' + escapeCs(t('system.calsync.test_ok')) + '</strong>';
            if (d.borrowed) html += '<br>' + escapeCs(t('system.calsync.test_borrowed', { name: d.borrowed }));
            if (d.probe) {
                html += '<br><br>' + (d.probe_ok
                    ? escapeCs(t('system.calsync.probe_ok',  { addr: d.probe }))
                    : escapeCs(t('system.calsync.probe_bad', { addr: d.probe })));
            }
            csShow(!!(d.probe === undefined || d.probe_ok), html);
        }

        async function csDelete() {
            if (!confirm(t('system.calsync.delete_confirm'))) return;
            const d = await csPost({ action: 'delete' });
            if (d.success) location.reload();
        }

        csLoad();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
