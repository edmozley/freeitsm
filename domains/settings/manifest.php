<?php
/**
 * Domains — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. The tab bar, the tick-boxes on System → Roles and their descriptions
 * are all derived from this file. See includes/capabilities.php.
 *
 * ⚠️ NO 'setting_keys' ON ANY TAB, deliberately. Every setting here is saved through
 * api/domains/settings.php, which validates the value and checks the SAME capability
 * as the tab — the LMS reminders pattern. Declaring the keys here as well would let
 * the generic api/settings/save_system_settings.php write them with no validation
 * (a days list of "banana", a recipient mode that does not exist).
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'domains',
    'label'  => 'Domains',

    'umbrella' => [
        'cap'       => capKey(Cap::DOMAINS_MANAGE),
        'grant'     => 'Manage everything in Domains settings',
        'sensitive' => true,   // implies the auth codes
    ],

    'tabs' => [
        [
            'id'        => 'statuses',
            'cap'       => capKey(Cap::DOMAINS_STATUSES),
            'label_key' => 'domains.settings.tab_statuses',
            'grant'     => 'Maintain the list of domain statuses',
        ],
        [
            'id'        => 'cert-types',
            'cap'       => capKey(Cap::DOMAINS_CERT_TYPES),
            'label_key' => 'domains.settings.tab_cert_types',
            'grant'     => 'Maintain the list of certificate types a recorded certificate can be',
        ],
        [
            'id'        => 'alerts',
            'cap'       => capKey(Cap::DOMAINS_ALERTS),
            'label_key' => 'domains.settings.tab_alerts',
            'grant'     => 'Configure expiry, certificate and change alerts, and where renewals are shown',
            'sensitive' => true,   // decides who is emailed, and can raise tickets and tasks
        ],
        [
            'id'        => 'monitoring',
            'cap'       => capKey(Cap::DOMAINS_MONITORING),
            'label_key' => 'domains.settings.tab_monitoring',
            'grant'     => 'Configure registry lookups, DNS and certificate checks, and look-alike scanning',
        ],
        [
            // GH #203 slice 2: the first time FreeITSM connects to addresses an
            // administrator types in, so who may set them is its own permission.
            'id'        => 'internal',
            'cap'       => capKey(Cap::DOMAINS_INTERNAL),
            'label_key' => 'domains.settings.tab_internal',
            'grant'     => 'Choose which internal websites have their certificates checked, the networks and ports FreeITSM may connect to, and the certificate authorities it trusts for them',
            'sensitive' => true,   // decides which internal addresses the server connects to
        ],
        [
            'id'        => 'service-status',
            'cap'       => capKey(Cap::DOMAINS_SERVICE_STATUS),
            'label_key' => 'domains.settings.tab_service_status',
            'grant'     => 'Decide whether a domain\'s problems are suggested or raised as incidents on the Service Status page',
            'sensitive' => true,   // can publish to a page customers read
        ],
        [
            'id'        => 'auth-codes',
            'cap'       => capKey(Cap::DOMAINS_AUTH_CODES),
            'label_key' => 'domains.settings.tab_auth_codes',
            'grant'     => 'See and change registrar auth codes (the secret that lets a domain be transferred away)',
            'sensitive' => true,
        ],
    ],
];
