<?php
/**
 * Tickets — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. See includes/capabilities.php.
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE MODULE THE WHOLE DESIGN WAS ARGUED FOR
 * ---------------------------------------------------------------------------
 * Fourteen tabs, and they are emphatically not the same kind of thing:
 *
 *   Mailboxes       OAuth credentials and inbound mail routing. Whoever holds this can
 *                   point the service desk's mailbox somewhere else — that is, redirect
 *                   or read the company's email.
 *   Messaging       the WhatsApp channel's Twilio/Meta credentials.
 *   Reply cleanup   an AI provider's API key.
 *   SLA             the targets the whole service desk is measured against.
 *   ...
 *   Ticket types    a list of words.
 *
 * A single "manage Tickets settings" permission would have made those one grant. It is
 * why the unit of permission is the TAB.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT HERE
 * ---------------------------------------------------------------------------
 * Working tickets. Replying, assigning, closing, adding notes, checking the mailbox for
 * new mail, sending a WhatsApp reply — all of that is the everyday job and stays on plain
 * module access. Gate any of it and you have not tightened security, you have broken the
 * service desk.
 *
 * Teams and analysts are NOT here either: they moved to the System module (#769-771) and
 * are already administrator-only. The department/team MAPPING lives on the Departments tab
 * and belongs to Tickets; creating the team itself does not.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'tickets',
    'label'  => 'Tickets',

    'umbrella' => [
        'cap'       => Cap::TICKETS_MANAGE,
        'grant'     => 'Manage everything in Tickets settings',
        'sensitive' => true,   // implies the mailbox, messaging and AI credentials
    ],

    'tabs' => [
        [
            'id'        => 'departments',
            'cap'       => Cap::TICKETS_DEPARTMENTS,
            'label_key' => 'tickets.settings.tabs.departments',
            'grant'     => 'Manage departments, and which teams see them',
        ],
        [
            'id'        => 'ticket-types',
            'cap'       => Cap::TICKETS_TICKET_TYPES,
            'label_key' => 'tickets.settings.tabs.ticket_types',
            'grant'     => 'Manage ticket types',
        ],
        [
            'id'        => 'ticket-origins',
            'cap'       => Cap::TICKETS_TICKET_ORIGINS,
            'label_key' => 'tickets.settings.tabs.ticket_origins',
            'grant'     => 'Manage ticket origins',
        ],
        [
            // Categories, sub-categories, resolution codes, AND the three switches
            // deciding whether each of those fields shows on a ticket at all (#1540).
            //
            // 🔑 ONE tab, not three. The permission argument this module exists to
            // make is about DIFFERENT KINDS of thing sharing a tab bar — mailbox
            // credentials next to a list of words. These three are the same kind of
            // thing as each other, so splitting them would add tabs without adding
            // any decision an administrator would actually make differently.
            'id'        => 'categories',
            'cap'       => Cap::TICKETS_CATEGORIES,
            'label_key' => 'tickets.settings.tabs.categories',
            'grant'     => 'Manage ticket categories and resolution codes, and whether those fields appear on a ticket',
        ],
        [
            'id'        => 'statuses',
            'cap'       => Cap::TICKETS_STATUSES,
            'label_key' => 'tickets.settings.tabs.statuses',
            'grant'     => 'Manage ticket statuses',
        ],
        [
            'id'        => 'priorities',
            'cap'       => Cap::TICKETS_PRIORITIES,
            'label_key' => 'tickets.settings.tabs.priorities',
            'grant'     => 'Manage ticket priorities',
        ],
        [
            // The targets the whole service desk is measured against, plus the calendars
            // and the breach/warning notifications.
            'id'        => 'sla',
            'cap'       => Cap::TICKETS_SLA,
            'label_key' => 'tickets.settings.tabs.sla',
            'grant'     => 'Manage SLA targets, calendars and breach notifications',
        ],
        [
            'id'        => 'rota-locations',
            'cap'       => Cap::TICKETS_ROTA_LOCATIONS,
            'label_key' => 'tickets.settings.tabs.rota_locations',
            'grant'     => 'Manage rota locations',
        ],
        [
            // OAuth credentials and inbound mail routing. The most dangerous tab in the
            // product: whoever holds this can redirect or read the company's email.
            'id'        => 'mailboxes',
            'cap'       => Cap::TICKETS_MAILBOXES,
            'label_key' => 'tickets.settings.tabs.mailboxes',
            'grant'     => 'Manage the mailboxes tickets are raised from, including their credentials and mail routing',
            'sensitive' => true,
        ],
        [
            // The WhatsApp channel's Twilio / Meta credentials.
            'id'        => 'messaging',
            'cap'       => Cap::TICKETS_MESSAGING,
            'label_key' => 'tickets.settings.tab_messaging',
            'grant'     => 'Manage messaging channels and templates, including their credentials',
            'sensitive' => true,
        ],
        [
            // Embeddable website chat widgets. Not sensitive: the widget key is public
            // (it ships in the customer's page source) and there are no stored secrets —
            // abuse is contained by each widget's origin allowlist + rate limiting.
            'id'        => 'webchat',
            'cap'       => Cap::TICKETS_WEBCHAT,
            'label_key' => 'tickets.settings.tab_webchat',
            'grant'     => 'Manage the website chat widgets that raise tickets',
        ],
        [
            'id'        => 'email-templates',
            'cap'       => Cap::TICKETS_EMAIL_TEMPLATES,
            'label_key' => 'tickets.settings.tabs.email_templates',
            'grant'     => 'Manage the email templates sent to requesters',
        ],
        [
            // The team's shared canned responses. Note the scope in the grant: an
            // analyst's OWN private templates are deliberately NOT behind this cap —
            // they are saved from the reply box and managed in its picker. Gating
            // those here would have meant only settings administrators could have a
            // personal template, which is most of the feature gone.
            'id'        => 'reply-templates',
            'cap'       => Cap::TICKETS_REPLY_TEMPLATES,
            'label_key' => 'tickets.settings.tabs.reply_templates',
            'grant'     => 'Manage the shared reply templates the whole team can insert',
        ],
        [
            // Whether time recording appears at all (discussion #72).
            //
            // 🔑 IN TICKETS SETTINGS, NOT SYSTEM, because every surface time
            // tracking has is a ticket surface — the panel in the reading pane
            // and the two endpoints behind it. There is no time report, no time
            // menu and no dashboard widget, so nothing about it is install-wide
            // in character.
            'id'        => 'time-tracking',
            'cap'       => Cap::TICKETS_MANAGE,
            'label_key' => 'tickets.settings.tabs.time_tracking',
            'grant'     => 'Turn time recording on or off, per company',
        ],
        [
            // How merging behaves, install-wide. Deliberately NOT a per-analyst
            // preference like the multi-select pane: whether a merge keeps the
            // requester's reference alive decides what the CUSTOMER sees, so two
            // analysts answering the same mailbox must not be able to disagree
            // about it.
            'id'           => 'merge-behaviour',
            'cap'          => Cap::TICKETS_MERGE,
            'label_key'    => 'tickets.settings.tabs.merge_behaviour',
            'grant'        => 'Decide what happens to ticket references and conversations when tickets are merged',
            'setting_keys' => ['merge_reference_mode', 'merge_originals_mode', 'merge_ai_summary'],
        ],
        [
            // How the text inside attachments gets read (discussion #53). Only
            // the two DRAINING switches live here — where the extraction service
            // itself lives is System → Integrations → Apache Tika, because that
            // is a connection to a third-party product rather than a ticket
            // setting. Install-wide, not per-analyst: two analysts cannot
            // usefully disagree about whether a queue is being worked.
            'id'           => 'indexing',
            'cap'          => Cap::TICKETS_INDEXING,
            'label_key'    => 'tickets.settings.tabs.indexing',
            'grant'        => 'Decide how the text inside attachments gets read for searching',
            'setting_keys' => ['attachment_extract_cron', 'attachment_extract_opportunistic'],
        ],
        [
            'id'        => 'rota',
            'cap'       => Cap::TICKETS_ROTA,
            'label_key' => 'tickets.settings.tabs.rota',
            'grant'     => 'Manage the on-call rota',
        ],
        [
            // Configurable ticket numbering (GH #71). Sensitive: renumbering
            // rewrites the reference on every existing ticket.
            'id'           => 'numbering',
            'cap'          => Cap::TICKETS_NUMBERING,
            'label_key'    => 'tickets.settings.tabs.numbering',
            'grant'        => 'Choose how ticket numbers are made, and renumber existing tickets',
            'sensitive'    => true,
            'setting_keys' => ['ticket_number_style', 'ticket_number_format', 'ticket_number_start', 'ticket_number_scope', 'ticket_number_reset', 'ticket_number_renumber'],
        ],
        [
            'id'           => 'general',
            'cap'          => Cap::TICKETS_GENERAL,
            'label_key'    => 'tickets.settings.tabs.general',
            'grant'        => 'Manage general ticket settings',
            'setting_keys' => ['system_name', 'reopen_on_customer_reply', 'snooze_wake_hour',
                                // How long messages are displayed (discussion #104).
                                'ticket_collapse_enabled', 'ticket_collapse_lines',
                                'ticket_collapse_expand_newest', 'ticket_collapse_quoted',
                                'ticket_collapse_remember',
                                // Long TICKETS, as distinct from long messages.
                                'ticket_group_older', 'ticket_group_show', 'ticket_flag_duplicates',
                                // The two AI reading aids. Both default to OFF —
                                // they are the only settings here that spend money.
                                'ticket_ai_summary_enabled', 'ticket_ai_summary_auto_after',
                                'ticket_ai_summary_max_messages', 'ticket_ai_summary_include_notes',
                                'ticket_ai_read_enabled'],
        ],
        [
            'id'           => 'privacy',
            'cap'          => Cap::TICKETS_PRIVACY,
            'label_key'    => 'tickets.settings.tabs.privacy',
            'grant'        => 'Control what requesters see of their own ticket in the self-service portal',
            'setting_keys' => ['portal_third_party_visibility'],
        ],
        [
            // The shared AI settings panel, namespace tickets_reply_cleanup.
            'id'           => 'reply-cleanup',
            'cap'          => Cap::TICKETS_REPLY_CLEANUP,
            'label_key'    => 'tickets.settings.tabs.reply_cleanup',
            'grant'        => 'Configure the reply-cleanup AI provider, including its API key',
            'sensitive'    => true,
            'setting_keys' => [
                'tickets_reply_cleanup_provider', 'tickets_reply_cleanup_model',
                'tickets_reply_cleanup_api_key', 'tickets_reply_cleanup_verify_ssl',
                'tickets_reply_cleanup_tone',
            ],
        ],
        [
            'id'        => 'csat',
            'cap'       => Cap::TICKETS_CSAT,
            'label_key' => 'tickets.settings.tabs.csat',
            'grant'     => 'Configure the customer satisfaction survey',
        ],
        [
            // What your own ticket rows show (discussion #61). A per-analyst view
            // preference, so there is nothing here to grant and nobody to gate —
            // the same reasoning as the left-panel tab in the other modules.
            //
            // The tab does contain one administrative control: "make this the
            // default for everyone". That button is gated inside the tab on
            // Cap::TICKETS_MANAGE rather than by hiding the whole tab, because
            // hiding it would take away everybody else's personal setting to
            // protect one button.
            'id'        => 'row-display',
            'cap'       => null,
            'label_key' => 'tickets.settings.tabs.row_display',
        ],
    ],
];
