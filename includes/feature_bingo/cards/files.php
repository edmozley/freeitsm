<?php
/**
 * Feature Bingo cards - Files. See includes/feature_bingo.php for the format.
 *
 * Files has no demo data (nothing in it carries is_demo), so every row counts.
 * Deleted folders and files (deleted_datetime set) never do.
 */

return [
    [
        'id'       => 'files.first_folder',
        'module'   => 'files',
        'tier'     => 'essential',
        'category' => 'getting-started',
        'title'    => 'A shared folder in Files',
        'what'     => 'A top-level folder on the Files desktop - somewhere to put installers, policies or anything else that needs to reach particular people.',
        'why'      => 'Big files and confidential documents otherwise travel as email attachments and links to personal cloud drives, with no record of who opened them.',
        'done'     => 'At least one top-level folder exists.',
        'link'     => 'files/',
        'check'    => ['rows', 'files_folders', 'parent_id IS NULL AND deleted_datetime IS NULL'],
    ],
    [
        'id'       => 'files.first_upload',
        'module'   => 'files',
        'tier'     => 'essential',
        'category' => 'getting-started',
        'title'    => 'A file uploaded to Files',
        'what'     => 'Any file dragged onto a folder window or uploaded from its toolbar. Files of several gigabytes go up in pieces, so size is not a problem.',
        'why'      => 'Once it is in Files, every download is recorded - who, when, and from which address - and the copy can be proved against its fingerprint.',
        'done'     => 'At least one file has been uploaded.',
        'link'     => 'files/',
        'check'    => ['rows', 'files_items', 'deleted_datetime IS NULL'],
    ],
    [
        'id'       => 'files.team_access',
        'module'   => 'files',
        'tier'     => 'recommended',
        'category' => 'security',
        'title'    => 'A folder shared with a team',
        'what'     => 'Right-click a folder, choose Permissions, and give a team access at the level it needs - View, Download, Upload, Modify or Full control.',
        'why'      => 'Nothing in Files is visible until it is shared. Sharing with a team rather than with people one by one means a new starter gets access by joining the team.',
        'done'     => 'At least one folder grants access to a team.',
        'link'     => 'files/',
        'check'    => ['rows', 'files_permissions', "principal_type = 'team'"],
    ],
    [
        'id'       => 'files.private_subfolder',
        'module'   => 'files',
        'tier'     => 'extra',
        'category' => 'security',
        'title'    => 'A subfolder with its own permissions',
        'what'     => 'A folder that has stopped inheriting from the one above it, so it can be locked down further than its parent.',
        'why'      => 'One shared folder for a department, with a confidential corner inside it only some of them can open - without a second folder tree.',
        'done'     => 'At least one subfolder does not inherit its parent\'s permissions.',
        'link'     => 'files/',
        'check'    => ['rows', 'files_folders', 'parent_id IS NOT NULL AND inherit_permissions = 0 AND deleted_datetime IS NULL'],
    ],
    [
        'id'       => 'files.storage_root',
        'module'   => 'files',
        'tier'     => 'extra',
        'category' => 'organisation',
        'title'    => 'Files stored where you chose',
        'what'     => 'Files -> Settings -> Storage pointed at a folder of your choosing - a bigger disk, or somewhere outside the web root.',
        'why'      => 'Shared files can grow large quickly. Putting them on the right disk from the start is easier than moving gigabytes later.',
        'done'     => 'A storage folder has been set.',
        'link'     => 'files/settings/?tab=storage',
        'check'    => ['sql', "SELECT COUNT(*) FROM system_settings WHERE setting_key = 'files_storage_root' AND setting_value IS NOT NULL AND setting_value <> ''"],
    ],
    [
        'id'       => 'files.company_logo',
        'module'   => 'files',
        'tier'     => 'extra',
        'category' => 'look',
        'title'    => 'A company logo on the desktop',
        'what'     => 'A logo uploaded for a company in System -> Companies, shown in the corner of the Files desktop for the people who work in it.',
        'why'      => 'On an install that serves several companies, everyone sees their own brand rather than the organisation\'s.',
        'done'     => 'At least one company has a logo.',
        'link'     => 'system/companies/',
        'check'    => ['rows', 'tenants', 'logo_path IS NOT NULL'],
    ],
];
