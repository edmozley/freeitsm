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
            'id'        => 'rota',
            'cap'       => Cap::TICKETS_ROTA,
            'label_key' => 'tickets.settings.tabs.rota',
            'grant'     => 'Manage the on-call rota',
        ],
        [
            'id'           => 'general',
            'cap'          => Cap::TICKETS_GENERAL,
            'label_key'    => 'tickets.settings.tabs.general',
            'grant'        => 'Manage general ticket settings',
            'setting_keys' => ['system_name', 'reopen_on_customer_reply'],
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
    ],
];
