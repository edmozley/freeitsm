<?php
/**
 * Problem Management — English strings.
 *
 * This namespace did not exist: problem-management/index.php exported only
 * 'common', and everything the module says was written in English inside the
 * page and inside assets/js/problem-management.js. The module name in the nav
 * was the one translated word on the screen.
 *
 * Most of what a user reads here is drawn by the SCRIPT, not by PHP, so the
 * majority of these keys are looked up with tf() from JavaScript.
 */

return [
    'browser_title' => 'Service Desk - Problem Management',

    // ── The list view ───────────────────────────────────────────────────
    'list' => [
        'search'        => 'Search',
        'new'           => '+ New problem',
        'status'        => 'Status',
        'all'           => 'All',
        'detect'        => '🤖 Detect problems',
        'detect_title'  => 'Let AI scan recent open incidents for recurring patterns',
        'loading'       => 'Loading…',
        'empty'         => 'No problems. Click “New problem” to create one.',
        'known_error'   => 'Known error',
        // "1 incident" and "7 incidents" are different sentences in most
        // languages, so they are different keys rather than a bare "s".
        'incidents_one'  => '🎫 1 incident',
        'incidents_many' => '🎫 {n} incidents',
        'load_failed'    => 'Failed to load',
        'load_failed_list' => 'Failed to load problems',
    ],

    // ── The search modal ────────────────────────────────────────────────
    'search' => [
        'heading'      => 'Search problems',
        'number'       => 'Problem number',
        'number_ph'    => 'e.g. PRB-0001',
        'title'        => 'Title',
        'title_ph'     => 'Search by title…',
        'go'           => 'Search',
        'clear'        => 'Clear',
        'prompt'       => 'Enter a problem number or title above and press Search.',
        'need_terms'   => 'Enter a problem number or title to search',
        'failed'       => 'Search failed',
        'failed_retry' => 'Search failed. Please try again.',
        'no_matches'   => 'No matching problems.',
        'count_one'    => '1 result',
        'count_many'   => '{n} results',
    ],

    // ── The detail view ─────────────────────────────────────────────────
    'detail' => [
        'back'            => '← Back',
        'projects'        => 'Projects',
        'not_found'       => 'Not found',
        'open_failed'     => 'Failed to open problem',
        'edit'            => 'Edit',
        'link_incident'   => 'Link incident',
        'link_change'     => 'Link change',
        'draft_cause'     => '🤖 Draft root cause',
        'draft_title'     => 'Draft a root cause from the linked incidents',
        'delete'          => 'Delete',

        'details'         => 'Details',
        'priority'        => 'Priority',
        'assigned_to'     => 'Assigned to',
        'description'     => 'Description',
        'root_cause'      => 'Root cause',
        'workaround'      => 'Workaround',

        'incidents'       => 'Linked incidents ({n})',
        'fix'             => 'Fix (linked change)',
        'notes'           => 'Notes',
        'history'         => 'History',
        'documents'       => 'Documents',

        'col_reference'   => 'Reference',
        'col_subject'     => 'Subject',
        'col_status'      => 'Status',
        'col_title'       => 'Title',
        'col_when'        => 'When',
        'col_who'         => 'Who',
        'col_what'        => 'What',
        'change_ref'      => 'Change #{id}',
        'no_incidents'    => 'No incidents linked yet.',
        'no_change'       => 'No change linked yet.',
        'no_history'      => 'No history.',
        'no_notes'        => 'No notes yet.',
        'open_incident'   => 'Open incident',
        'unlink_incident' => 'Unlink incident',
        'open_change'     => 'Open change',
        'unlink_change'   => 'Unlink change',

        'note_ph'         => 'Add a note…',
        'note_add'        => 'Add',
        'note_empty'      => 'Enter a note first',
        'note_failed'     => 'Failed to add note',
        'note_added'      => 'Note added',

        // History rows. The verb and the field are one sentence, not a verb
        // glued to a noun.
        'audit_created'   => 'created the problem',
        'audit_changed'   => 'changed {field}',
        'audit_changed_to' => 'changed {field} to “{value}”',
        'someone'         => 'Someone',
    ],

    // ── The editor ──────────────────────────────────────────────────────
    'editor' => [
        'new'             => 'New problem',
        'edit'            => 'Edit {number}',
        'edit_generic'    => 'Edit problem',
        'title'           => 'Title *',
        'title_ph'        => 'Short summary of the underlying problem',
        'status'          => 'Status',
        'priority'        => 'Priority',
        'assignee'        => 'Assigned to',
        'known_error'     => 'Known error (workaround available)',
        'description'     => 'Description',
        'description_ph'  => 'What\'s the problem?',
        'root_cause'      => 'Root cause',
        'root_cause_ph'   => 'The underlying cause (fill in as the investigation progresses)',
        'workaround'      => 'Workaround',
        'workaround_ph'   => 'Temporary workaround for affected users',
        'title_required'  => 'Title is required',
        'save_failed'     => 'Save failed',
        'saved'           => 'Saved',
        'delete_title'    => 'Delete problem?',
        'delete_message'  => 'Linked incidents are not deleted; they just lose the link. This cannot be undone.',
        'delete_fallback' => 'Delete this problem? Linked incidents are not deleted; they just lose the link.',
        'delete_failed'   => 'Delete failed',
        'deleted'         => 'Problem deleted',
    ],

    // ── The two link pickers ────────────────────────────────────────────
    'link' => [
        'incidents_heading' => 'Link incidents to this problem',
        'incidents_ph'      => 'Search open incidents by number or subject…',
        'changes_heading'   => 'Link the change that fixes this problem',
        'changes_ph'        => 'Search changes by title or ID…',
        'select_all'        => 'Select all',
        'link_selected'     => 'Link selected',
        'linking'           => 'Linking…',
        'no_subject'        => '(no subject)',
        'no_matching_incidents' => 'No matching open incidents.',
        'none_linkable'     => 'No open incidents available to link.',
        'incidents_failed'  => 'Failed to load incidents',
        'no_matching_changes' => 'No matching changes.',
        'no_changes'        => 'No changes available to link.',
        'changes_failed'    => 'Failed to load changes',
        'need_incident'     => 'Select at least one incident',
        'need_change'       => 'Select at least one change',
        'failed'            => 'Link failed',
        'linked_incident_one'  => '1 incident linked',
        'linked_incident_many' => '{n} incidents linked',
        'linked_change_one'    => '1 change linked',
        'linked_change_many'   => '{n} changes linked',
        // Appended to the message above when some of the batch did not link.
        'and_failed'        => '{done}, {n} failed',

        'unlink_incident_title'   => 'Unlink incident?',
        'unlink_incident_message' => 'This removes the link to this problem. The incident itself is not deleted.',
        'unlink_change_title'     => 'Unlink change?',
        'unlink_change_message'   => 'This removes the link to this problem. The change itself is not deleted.',
        'unlink'                  => 'Unlink',
        'unlinked'                => 'Unlinked',
        'unlink_failed'           => 'Failed',
    ],

    // ── The AI helpers ──────────────────────────────────────────────────
    'ai' => [
        'analysing'      => 'Analysing the linked incidents…',
        'prefix'         => 'AI: {message}',
        'failed_word'    => 'failed',
        'draft_heading'  => 'Suggested root cause &amp; workaround (review before saving):',
        'open_in_editor' => 'Open in editor',
        'request_failed' => 'AI request failed',

        'suggest_heading'  => 'Suggested problems',
        'scanning'         => 'Scanning recent open incidents…',
        'none_found'       => 'No recurring patterns found across {n} open incidents.',
        'untitled'         => 'Untitled',
        'create_and_link'  => 'Create problem &amp; link these',
        'default_title'    => 'Recurring problem',
        'create_failed'    => 'Create failed',
        'created'          => 'Problem created from suggestion',
        'request_error'    => 'Request failed',
        'failed'           => 'Failed',
    ],
];
