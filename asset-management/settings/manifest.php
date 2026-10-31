<?php
/**
 * Asset Management — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. Everything else is derived from this file:
 *
 *   - the tab bar on the settings page (only the tabs you may see are rendered);
 *   - the capabilities offered on System → Roles, with their descriptions and badges;
 *   - who may write each of the system_settings keys listed below.
 *
 * See includes/capabilities.php for how the derivation works, and
 * includes/settings_manifest.php for the renderer.
 *
 * Per tab:
 *   'id'           the tab's DOM id (its panel is <id>-tab)
 *   'cap'          the Cap:: constant required to see and use it.
 *                  null = a personal preference, not administration: always visible,
 *                  never gated, contributes no capability.
 *   'label_key'    i18n key for the tab's NAME on the tab bar
 *   'grant'        plain-English description of the PERMISSION, shown in System → Roles
 *   'sensitive'    true if it reaches credentials, email, money or the audit trail —
 *                  badged in the Roles picker so granting it makes you think
 *   'setting_keys' the system_settings keys this tab writes through the shared
 *                  save_system_settings.php endpoint (enforced in settings_keys.php,
 *                  which derives from this list)
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'assets',
    'label'  => 'Asset management',

    // One tick that satisfies every capability below — the ordinary "Asset
    // Administrator" role. Sensitive, because it implies vCenter and Intune.
    'umbrella' => [
        'cap'       => capKey(Cap::ASSETS_MANAGE),
        'grant'     => 'Manage everything in Asset management settings',
        'sensitive' => true,
    ],

    'tabs' => [
        [
            'id'        => 'asset-types',
            'cap'       => capKey(Cap::ASSETS_TYPES),
            'label_key' => 'asset-management.settings.tab_asset_types',
            'grant'     => 'Manage asset types',
            // GH #200: how an asset says it has details its type's layout hides.
            'setting_keys' => ['asset_layout_hidden_notice'],
        ],
        [
            // Recording things that are not computers (printers, headsets, TVs).
            // Gates DESIGNING the fields; filling one in is plain module access,
            // exactly as designing the handover document is gated but printing
            // one is not.
            'id'        => 'custom-fields',
            'cap'       => capKey(Cap::ASSETS_FIELDS),
            'label_key' => 'asset-management.settings.tab_custom_fields',
            'grant'     => 'Design custom asset fields and field sets',
        ],
        [
            // Bulk import. Its own capability, and a sensitive one: an import
            // can create or overwrite thousands of records in one go, which is
            // a very different thing from editing one asset.
            'id'        => 'import',
            'cap'       => capKey(Cap::ASSETS_IMPORT),
            'label_key' => 'asset-management.settings.tab_import',
            'grant'     => 'Import assets in bulk from a file',
            'sensitive' => true,
        ],
        [
            'id'        => 'asset-statuses',
            'cap'       => capKey(Cap::ASSETS_STATUSES),
            'label_key' => 'asset-management.settings.tab_asset_statuses',
            'grant'     => 'Manage asset statuses',
        ],
        [
            'id'        => 'locations',
            'cap'       => capKey(Cap::ASSETS_LOCATIONS),
            'label_key' => 'asset-management.settings.tab_locations',
            'grant'     => 'Manage locations',
        ],
        [
            'id'        => 'suppliers',
            'cap'       => capKey(Cap::ASSETS_SUPPLIERS),
            'label_key' => 'asset-management.settings.tab_suppliers',
            'grant'     => 'Manage suppliers',
        ],
        [
            'id'           => 'warranty',
            'cap'          => capKey(Cap::ASSETS_WARRANTY),
            'label_key'    => 'asset-management.settings.tab_warranty',
            'grant'        => 'Configure warranty and lease expiry surfacing',
            // The id stays 'warranty' because it is the tab's stable address -
            // renaming it would silently revoke the capability from anybody it
            // is already granted to.
            'setting_keys' => ['asset_warranty_surface', 'asset_warranty_days',
                               'asset_lease_surface', 'asset_lease_days'],
        ],
        [
            // Assets -> Leases (3.5.0): what can become of a leased asset.
            'id'        => 'leasing',
            'cap'       => capKey(Cap::ASSETS_LEASING),
            'label_key' => 'asset-management.leases.settings_tab',
            'grant'     => 'Manage lease outcomes',
        ],
        [
            // Designing the handover document (discussion #56). Note this gates
            // DESIGNING it, not producing one — printing a handover for somebody
            // is plain module access, the same as viewing their equipment.
            'id'        => 'handover',
            'cap'       => capKey(Cap::ASSETS_HANDOVER),
            'label_key' => 'asset-management.settings.tab_handover',
            'grant'     => 'Design the equipment handover document',
        ],
        [
            'id'           => 'vcenter',
            'cap'          => capKey(Cap::ASSETS_VCENTER),
            'label_key'    => 'asset-management.settings.tab_vcenter',
            'grant'        => 'Configure the vCenter connection, including its credentials',
            'sensitive'    => true,
            'setting_keys' => ['vcenter_server', 'vcenter_user', 'vcenter_password'],
        ],
        [
            'id'           => 'asset-tags',
            'cap'          => capKey(Cap::ASSETS_TAGS),
            'label_key'    => 'asset-management.settings.tab_asset_tags',
            'grant'        => 'Configure asset tag auto-generation and numbering sequence',
            'setting_keys' => ['asset_tag_autogen_enabled', 'asset_tag_format', 'asset_tag_start', 'asset_tag_scope'],
        ],
        [
            'id'           => 'reconciliation',
            'cap'          => capKey(Cap::ASSETS_RECONCILIATION),
            'label_key'    => 'asset-management.settings.tab_reconciliation',
            'grant'        => 'Configure asset discovery, identity reconciliation and ignored serials',
            // GH #200 Part B: when an inventory list counts as stale on the asset.
            'setting_keys' => ['asset_reconciliation_ignored_serials', 'asset_inventory_stale_days', 'asset_protect_on_edit'],
        ],
        [
            // GH #200 part D: the cases matching refuses to guess, for a person to decide -
            // including MERGING two assets, which is why it shares the reconciliation
            // capability rather than plain module access.
            'id'           => 'review',
            'cap'          => capKey(Cap::ASSETS_RECONCILIATION),
            'label_key'    => 'asset-management.settings.tab_review',
            'grant'        => 'Configure asset discovery, identity reconciliation and ignored serials',
        ],
        [
            'id'           => 'asset-labels',
            'cap'          => capKey(Cap::ASSETS_LABELS),
            'label_key'    => 'asset-management.settings.tab_asset_labels',
            'grant'        => 'Configure physical asset labels, layout and QR code branding',
            'setting_keys' => ['asset_label_title', 'asset_label_fields', 'asset_label_footer',
                               'asset_label_logo_enabled', 'asset_label_logo_path',
                               'asset_label_show_field_labels'],
        ],
        [
            'id'           => 'intune',
            'cap'          => capKey(Cap::ASSETS_INTUNE),
            'label_key'    => 'asset-management.settings.tab_intune',
            'grant'        => 'Configure the Intune connection and run syncs, including its credentials',
            'sensitive'    => true,
            'setting_keys' => ['intune_tenant_id', 'intune_client_id', 'intune_client_secret', 'intune_verify_ssl', 'intune_app_batch_size', 'intune_company_id', 'intune_sync_hostnames', 'intune_assign_primary_user'],
        ],
        [
            // Several Proxmox VE servers. Their credentials live in proxmox_connections,
            // not system_settings, so there are no setting_keys for this tab.
            'id'           => 'proxmox',
            'cap'          => capKey(Cap::ASSETS_PROXMOX),
            'label_key'    => 'asset-management.settings.tab_proxmox',
            'grant'        => 'Configure Proxmox VE servers, including their credentials, and run syncs',
            'sensitive'    => true,
            'setting_keys' => [],
        ],
        [
            // GH #199: Hyper-V estates a collector PUSHES to. Each connection has its own API
            // key (minted here, shown once) - sensitive for that reason. The estate itself
            // lives in virt_* tables, so there are no setting_keys.
            'id'           => 'hyperv',
            'cap'          => capKey(Cap::ASSETS_HYPERV),
            'label_key'    => 'asset-management.settings.tab_hyperv',
            'grant'        => 'Set up Hyper-V connections, including their collector keys, and decide identity changes',
            'sensitive'    => true,
            'setting_keys' => [],
        ],
        [
            // Several VMware Cloud Director servers. Credentials live in vcloud_connections.
            'id'           => 'vcloud',
            'cap'          => capKey(Cap::ASSETS_VCLOUD),
            'label_key'    => 'asset-management.settings.tab_vcloud',
            'grant'        => 'Configure VMware Cloud Director servers, including their credentials, and run syncs',
            'sensitive'    => true,
            'setting_keys' => [],
        ],
        [
            // A per-analyst display preference — where your sidebar sits. Not
            // administration, so there is nothing here to grant and nobody to gate.
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],
];
