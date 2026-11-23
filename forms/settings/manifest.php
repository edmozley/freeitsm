<?php
/**
 * Forms — settings manifest. See includes/capabilities.php.
 *
 * Reading submissions is the everyday job and stays on plain module access. Designing
 * forms is everyday work too, but it decides who approves a request, so since #180 it is
 * its own permission ('actions' below) that an install can switch on in the Access tab.
 * The AI tab uses the shared AI settings panel (namespace forms_ai).
 */
require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'forms',
    'label'  => 'Forms',
    'umbrella' => [
        'cap'       => capKey(Cap::FORMS_MANAGE),
        'grant'     => 'Manage everything in Forms settings',
        'sensitive' => true,   // implies the AI credentials
    ],
    'tabs' => [
        [
            'id'           => 'layout',
            'cap'          => capKey(Cap::FORMS_LAYOUT),
            'label_key'    => 'forms.settings.tab_layout',
            'grant'        => 'Configure the form layout',
            'setting_keys' => ['forms_logo_alignment'],
        ],
        [
            // Its own capability rather than sharing the layout one: closing a
            // collection can stop several forms accepting submissions, which is
            // a different order of thing from choosing where the logo sits.
            'id'           => 'collections',
            'cap'          => capKey(Cap::FORMS_COLLECTIONS),
            'label_key'    => 'forms.settings.tab_collections',
            'grant'        => 'Create, close and delete form collections',
            'setting_keys' => ['forms_collection_close_effect'],
        ],
        [
            // GH #160 Part C1: named approval chains, and who decides when a step
            // finds nobody. Its own capability: a chain decides who approves
            // requests on every form that uses it.
            'id'           => 'approvals',
            'cap'          => capKey(Cap::FORMS_APPROVALS),
            'label_key'    => 'forms.settings.tab_approvals',
            'grant'        => 'Manage approval chains and the default approver',
            'setting_keys' => ['forms_approval_fallback'],
        ],
        [
            'id'           => 'ai',
            'cap'          => capKey(Cap::FORMS_AI),
            'label_key'    => 'forms.settings.tab_ai',
            'grant'        => 'Configure the Forms AI provider, including its API key',
            'sensitive'    => true,
            'setting_keys' => ['forms_ai_provider', 'forms_ai_model', 'forms_ai_api_key', 'forms_ai_verify_ssl'],
        ],
        [
            // Who may design forms (#180). The switch that makes "Design forms" bite.
            'id'           => 'access',
            'cap'          => capKey(Cap::FORMS_ACCESS),
            'label_key'    => 'forms.settings.tab_access',
            'grant'        => 'Choose who can design forms',
            'setting_keys' => ['forms_design_restricted'],
        ],
    ],

    // Everyday work (#180 Part 3) - not a tab. Designing a form includes choosing who
    // approves it and what happens next, so an approver role must be able to leave it out.
    // Bites only once Forms → Settings → Access says so.
    'actions' => [
        [
            'cap'      => capKey(Cap::FORMS_DESIGN),
            'grant'    => 'Create, edit, publish and delete forms, including who approves them and what happens next',
            'restrict' => ['setting' => 'forms_design_restricted', 'value' => '1'],
        ],
    ],
];
