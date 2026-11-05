<?php
/**
 * Change Management — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. See includes/capabilities.php.
 *
 * Nothing here reaches credentials, money or email, so no tab is marked sensitive — but
 * the split still earns its keep: the **Fields** tab decides which fields appear on every
 * change record and whether they are required, which is a far bigger lever over how the
 * team works than adding a value to the impacts list.
 *
 * Note what is NOT here. **CAB membership** is managed from the change record itself, not
 * from settings, and is reachable through the REST API — so it stays on plain module
 * access. Approving a change is the job, not the configuration.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'changes',
    'label'  => 'Change management',

    'umbrella' => [
        'cap'   => capKey(Cap::CHANGES_MANAGE),
        'grant' => 'Manage everything in Change management settings',
    ],

    'tabs' => [
        [
            // Which fields appear on a change, in what order, and which are required.
            'id'        => 'fields',
            'cap'       => capKey(Cap::CHANGES_FIELDS),
            'label_key' => 'change-management.settings.tab_fields',
            'grant'     => 'Manage the fields on a change, and how they are laid out',
        ],
        [
            'id'        => 'statuses',
            'cap'       => capKey(Cap::CHANGES_STATUSES),
            'label_key' => 'change-management.settings.tab_statuses',
            'grant'     => 'Manage change statuses',
        ],
        [
            'id'        => 'priorities',
            'cap'       => capKey(Cap::CHANGES_PRIORITIES),
            'label_key' => 'change-management.settings.tab_priorities',
            'grant'     => 'Manage change priorities',
        ],
        [
            'id'        => 'types',
            'cap'       => capKey(Cap::CHANGES_TYPES),
            'label_key' => 'change-management.settings.tab_types',
            'grant'     => 'Manage change types',
        ],
        [
            'id'        => 'impacts',
            'cap'       => capKey(Cap::CHANGES_IMPACTS),
            'label_key' => 'change-management.settings.tab_impacts',
            'grant'     => 'Manage change impacts',
        ],
        [
            // The approval rules (#180): Record or Enforce, independence, what counts as a
            // material edit, out-of-date votes, deleting submitted changes. Sensitive: these
            // decide what the approval record proves, so they are the audit trail's settings.
            'id'        => 'approvals',
            'cap'       => capKey(Cap::CHANGES_APPROVALS),
            'label_key' => 'change-management.settings.tab_approvals',
            'grant'     => 'Manage the approval rules for changes',
            'sensitive' => true,
        ],
        [
            // The risk policy (#246): the likelihood and impact levels, the results and the
            // matrix. Sensitive: it decides how risky every new change reads, and so how much
            // review it gets.
            'id'        => 'risk-policy',
            'cap'       => capKey(Cap::CHANGES_RISK_POLICY),
            'label_key' => 'change-management.settings.tab_risk_policy',
            'grant'     => 'Manage the change risk policy',
            'sensitive' => true,
        ],
        [
            // Standard-change templates (#180 §8): writing the recipes. Approving one is a
            // separate, everyday permission (below), so the author cannot approve their own.
            'id'        => 'templates',
            'cap'       => capKey(Cap::CHANGES_TEMPLATES),
            'label_key' => 'change-management.settings.tab_templates',
            'grant'     => 'Write standard-change templates',
            // An approver must be able to read the versions they approve.
            'also_visible_to' => [capKey(Cap::CHANGES_TEMPLATES_APPROVE)],
        ],
        [
            // A per-analyst display preference. Not administration; nothing to grant.
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'common.left_panel.tab',
        ],
    ],

    // Everyday work (#180 Part 3) - not a tab. Bites only once Settings → Approvals →
    // "Who can choose a change's approvers" is set to the permission.
    'actions' => [
        [
            'cap'      => capKey(Cap::CHANGES_CHOOSE_APPROVERS),
            'grant'    => 'Choose who approves a change: the approver, the CAB and whether it is required',
            'restrict' => ['setting' => 'change_approval_choose_approvers', 'value' => 'permission'],
        ],
        [
            // Always applies: approving a recipe that pre-approves changes is never everybody's job.
            'cap'       => capKey(Cap::CHANGES_TEMPLATES_APPROVE),
            'grant'     => 'Approve standard-change templates (which then approve the changes raised from them)',
            'sensitive' => true,
            'restrict'  => null,
        ],
    ],
];
