<?php
/**
 * Knowledge — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. See includes/capabilities.php.
 *
 * Three of these four reach something that costs or leaks:
 *   - Email      — SMTP credentials for sharing articles out
 *   - AI         — the AI provider's API key
 *   - Embeddings — re-embedding the whole knowledge base spends real money
 * so all three are badged sensitive. The recycle bin (how long deleted articles are kept)
 * is the only ordinary one.
 *
 * Two endpoints here serve MORE THAN ONE tab, so neither can be authorised with a single
 * guard — both authorise per setting key, from the keys declared below:
 *   - api/knowledge/save_email_settings.php  → Email + Recycle bin
 *   - api/system/ai/save_settings.php        → shared by SEVEN modules' AI tabs
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'knowledge',
    'label'  => 'Knowledge',

    'umbrella' => [
        'cap'       => capKey(Cap::KNOWLEDGE_MANAGE),
        'grant'     => 'Manage everything in Knowledge settings',
        'sensitive' => true,   // implies the SMTP and AI credentials
    ],

    'tabs' => [
        [
            'id'           => 'email',
            'cap'          => capKey(Cap::KNOWLEDGE_EMAIL),
            'label_key'    => 'knowledge.settings.tab_email',
            'grant'        => 'Configure how articles are emailed out, including the SMTP credentials',
            'sensitive'    => true,
            'setting_keys' => [
                'knowledge_email_method', 'knowledge_email_mailbox_id',
                'knowledge_email_smtp_host', 'knowledge_email_smtp_port',
                'knowledge_email_smtp_encryption', 'knowledge_email_smtp_auth',
                'knowledge_email_smtp_username', 'knowledge_email_smtp_password',
                'knowledge_email_smtp_from_name', 'knowledge_email_smtp_from_email',
            ],
        ],
        [
            // The shared AI panel writes these; api/system/ai/* authorises against them.
            'id'           => 'ai',
            'cap'          => capKey(Cap::KNOWLEDGE_AI),
            'label_key'    => 'knowledge.settings.tab_ai',
            'grant'        => 'Configure the Knowledge AI provider, including its API key',
            'sensitive'    => true,
            'setting_keys' => [
                'knowledge_ai_provider', 'knowledge_ai_model',
                'knowledge_ai_api_key', 'knowledge_ai_verify_ssl',
            ],
        ],
        [
            // Re-embedding the knowledge base calls the provider once per article.
            'id'           => 'embeddings',
            'cap'          => capKey(Cap::KNOWLEDGE_EMBEDDINGS),
            'label_key'    => 'knowledge.settings.tab_embeddings',
            'grant'        => 'Generate article embeddings, and hold the OpenAI key they use (this costs money)',
            'sensitive'    => true,
            'setting_keys' => ['knowledge_openai_api_key'],
        ],
        [
            'id'           => 'recycle-bin',
            'cap'          => capKey(Cap::KNOWLEDGE_RECYCLE_BIN),
            'label_key'    => 'knowledge.settings.tab_recycle',
            'grant'        => 'Set how long deleted articles are kept',
            'setting_keys' => ['knowledge_recycle_bin_days'],
        ],
        [
            // The only setting in the module that changes who can READ things, so
            // it sits under the umbrella capability rather than getting a
            // narrower one of its own: whoever may change it may already change
            // every access list.
            'id'           => 'permissions',
            'cap'          => capKey(Cap::KNOWLEDGE_MANAGE),
            'label_key'    => 'knowledge.settings.tab_permissions',
            'grant'        => 'Choose whether folder permissions apply to everything inside them',
            'sensitive'    => true,
            'setting_keys' => ['knowledge_folder_permission_model'],
        ],
        [
            // GH #417: the largest image an integration may upload through the REST API.
            // Not the editor's pictures - it scales its own (knowledge.js KB_IMAGE_MAX_BYTES).
            'id'           => 'images',
            'cap'          => capKey(Cap::KNOWLEDGE_MANAGE),
            'label_key'    => 'knowledge.settings.tab_images',
            'grant'        => 'Set the largest image an integration may upload into an article',
            'setting_keys' => ['knowledge_image_max_mb'],
        ],
        [
            'id'        => 'left-panel',
            'cap'       => null,
            'label_key' => 'knowledge.settings.tab_left_panel',
        ],
    ],
];
