<?php
/**
 * War room — settings manifest. See includes/capabilities.php.
 *
 * USING the war room is deliberately plain module access — during an outage you
 * do not want a permission sitting between an analyst and the only chat that
 * still works, and that includes creating a channel or opening a DM. What IS
 * gated is the pair of decisions an administrator owns: how long messages are
 * kept, and which AI provider gets shown the transcript.
 */
require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module'   => 'war-room',
    'label'    => 'War room',
    'umbrella' => [
        'cap'   => capKey(Cap::WAR_ROOM_MANAGE),
        'grant' => 'Manage war room settings',
    ],
    'tabs' => [
        [
            'id'        => 'retention',
            'cap'       => capKey(Cap::WAR_ROOM_MANAGE),
            'label_key' => 'war-room.settings.heading',
            'grant'     => 'Set how long war room messages are kept',
            // Declaring the key here is what authorises it: settingKeyOwners()
            // derives ownership from the manifest, so the tab that shows the
            // setting and the capability that guards it cannot disagree — and
            // the generic settings writer refuses any key nobody owns.
            'setting_keys' => ['warroom_retention_days', 'warroom_seen_by'],   // 5.9.0: Seen by on/off
        ],
        [
            'id'        => 'ai',
            'cap'       => capKey(Cap::WAR_ROOM_MANAGE),
            // "AI", not "Situation report" (5.9.0): the tab now also holds translation, and
            // Ed could not find the switch under the old name. The section keeps ai_heading.
            'label_key' => 'war-room.settings.ai_tab',
            'grant'     => 'Configure the war room AI - situation report, Warbot and translation',
            'setting_keys' => [
                'warroom_ai_provider', 'warroom_ai_model',
                'warroom_ai_api_key', 'warroom_ai_verify_ssl',
                'warroom_translate',   // 5.9.0: translate messages into each reader's language (off by default)
            ],
        ],
    ],
];
