<?php
/**
 * Files — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. The tab bar, the tick-boxes on System → Roles and their descriptions
 * are all derived from this file. See includes/capabilities.php.
 *
 * ⚠️ NO 'setting_keys' ON ANY TAB, deliberately. Every setting here is saved through
 * api/files/settings.php, which validates the value (a storage root must be a
 * writable directory, a size a number) and checks the SAME capability as the tab.
 *
 * Personal preferences (desktop colour, the navbar) are not here: they live in the
 * desktop's own Personalise window and save to user_preferences.
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'files',
    'label'  => 'Files',

    'umbrella' => [
        'cap'       => Cap::FILES_MANAGE,
        'grant'     => 'Manage everything in Files settings',
        'sensitive' => true,   // implies take ownership
    ],

    'tabs' => [
        [
            'id'        => 'folders',
            'cap'       => Cap::FILES_FOLDERS,
            'label_key' => 'files.settings.tab_folders',
            'grant'     => 'Create top-level folders, see every folder that exists, and take ownership of any folder (this grants themselves access to its files)',
            'sensitive' => true,
        ],
        [
            'id'        => 'storage',
            'cap'       => Cap::FILES_STORAGE,
            'label_key' => 'files.settings.tab_storage',
            'grant'     => 'Choose where files are stored on the server and the largest file that can be uploaded',
            'sensitive' => true,   // moves every file's bytes
        ],
    ],
];
