<?php
/**
 * Software — settings manifest. See includes/capabilities.php.
 *
 * One tab, and it MINTS CREDENTIALS: the API keys that software-inventory agents present
 * when they push data in. Whoever can generate one can feed the inventory; whoever can
 * revoke one can stop it dead.
 *
 * That is nothing like being able to browse the software list, which is what module access
 * gives you — which is exactly why it is split out, even though it leaves the module with a
 * single capability.
 */
require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'software',
    'label'  => 'Software',
    'umbrella' => [
        'cap'       => Cap::SOFTWARE_MANAGE,
        'grant'     => 'Manage everything in Software settings',
        'sensitive' => true,
    ],
    'tabs' => [
        [
            'id'        => 'api-keys',
            'cap'       => Cap::SOFTWARE_API_KEYS,
            'label_key' => 'software.settings.tab_api_keys',
            'grant'     => 'Create and revoke the API keys inventory agents use',
            'sensitive' => true,
        ],
        [
            // Where licence renewals show up (#1551). Deliberately NOT sensitive,
            // and nothing like the tab above it: this decides where a date is
            // DISPLAYED, not who may read it or feed the inventory. Mirrors the
            // Assets "warranty" tab, which answers the same question about
            // warranty expiries and holds the same shape of setting.
            'id'           => 'renewals',
            'cap'          => Cap::SOFTWARE_RENEWALS,
            'label_key'    => 'software.settings.tab_renewals',
            'grant'        => 'Configure where licence renewals are surfaced',
            // Only the surface key — see the note in watchtower_queries.php for why
            // there is no 'days ahead' twin of asset_warranty_days.
            'setting_keys' => ['software_renewal_surface'],
        ],
    ],
];
