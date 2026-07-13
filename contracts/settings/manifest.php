<?php
/**
 * Contracts — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. The tab bar, the tick-boxes on System → Roles and their descriptions are
 * all derived from this file. See includes/capabilities.php.
 *
 * Note what the split buys here. Five of these tabs are plain lookup lists — the kind of
 * thing a contracts administrator maintains without a second thought. The **RFP AI** tab
 * holds an AI provider's API key, which costs money and can be pointed at an attacker's
 * proxy. Under a single 'manage the Contracts settings' permission those would be the
 * same grant. They are not the same thing.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'contracts',
    'label'  => 'Contracts',

    'umbrella' => [
        'cap'       => Cap::CONTRACTS_MANAGE,
        'grant'     => 'Manage everything in Contracts settings',
        'sensitive' => true,   // implies the RFP AI key
    ],

    'tabs' => [
        [
            'id'        => 'supplier-types',
            'cap'       => Cap::CONTRACTS_SUPPLIER_TYPES,
            'label_key' => 'contracts.settings.tab_supplier_types',
            'grant'     => 'Manage supplier types',
        ],
        [
            'id'        => 'supplier-statuses',
            'cap'       => Cap::CONTRACTS_SUPPLIER_STATUSES,
            'label_key' => 'contracts.settings.tab_supplier_statuses',
            'grant'     => 'Manage supplier statuses',
        ],
        [
            'id'        => 'contract-statuses',
            'cap'       => Cap::CONTRACTS_CONTRACT_STATUSES,
            'label_key' => 'contracts.settings.tab_contract_statuses',
            'grant'     => 'Manage contract statuses',
        ],
        [
            'id'        => 'payment-schedules',
            'cap'       => Cap::CONTRACTS_PAYMENT_SCHEDULES,
            'label_key' => 'contracts.settings.tab_payment_schedules',
            'grant'     => 'Manage payment schedules',
        ],
        [
            // The TABS a contract's terms are organised into — the structure, not the
            // terms themselves. Editing a contract's actual terms is everyday work and
            // stays on plain module access (api/contracts/save_contract_terms.php).
            'id'        => 'contract-term-tabs',
            'cap'       => Cap::CONTRACTS_CONTRACT_TERMS,
            'label_key' => 'contracts.settings.tab_contract_terms',
            'grant'     => 'Manage the tabs that contract terms are organised into',
        ],
        [
            'id'        => 'rfp-departments',
            'cap'       => Cap::CONTRACTS_RFP_DEPARTMENTS,
            'label_key' => 'contracts.settings.tab_rfp_departments',
            'grant'     => 'Manage the departments an RFP can be scored against',
        ],
        [
            // Reaches an AI provider's API key: it spends money, and a key pointed at an
            // attacker's proxy would leak every RFP that gets drafted through it.
            'id'        => 'rfp-ai',
            'cap'       => Cap::CONTRACTS_RFP_AI,
            'label_key' => 'contracts.settings.tab_rfp_ai',
            'grant'     => 'Configure the RFP Builder\'s AI provider, including its API key',
            'sensitive' => true,
        ],
        [
            // A per-analyst display preference. Not administration; nothing to grant.
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'contracts.settings.tab_left_panel',
        ],
    ],
];
