<?php
/**
 * English (en) — Forms module strings.
 *
 * Source-of-truth locale. Every other lang/<code>/forms.php may omit keys;
 * missing keys fall back to the value here (see includes/i18n.php).
 *
 * Covers the chrome of the Forms module — the dashboard list, the form
 * builder/editor (toolbar, field-type palette, property panels), the
 * AI Assist modal, the fill page chrome, the submissions table, the
 * settings page and the help guide.
 *
 * IMPORTANT: user-authored form CONTENT (form titles, field labels,
 * options, descriptions) is DATA stored in the database and is NOT
 * translated here.
 */
return [
    'title' => 'Forms',

    'nav' => [
        'forms'     => 'Forms',
        'approvals' => 'Approvals',
        'settings'  => 'Settings',
        'help'      => 'Help',
    ],

    // ── Dashboard / list (forms/index.php) ──────────────────────────
    'list' => [
        'title'              => 'Forms',
        'page_title'         => 'Service Desk - Forms',
        'search_placeholder' => 'Search by title or description...',
        'new_form'           => 'New form',
        'col_title'          => 'Title',
        'col_version'        => 'Version',
        'col_status'         => 'Status',
        'col_fields'         => 'Fields',
        'col_submissions'    => 'Submissions',
        'col_modified'       => 'Last modified',
        'col_modified_by'    => 'Modified by',
        'loading'            => 'Loading forms…',
        'error_loading'      => 'Error loading forms',
        'status_active'      => 'Active',
        'portal_on'          => 'In catalogue',
        'portal_on_title'    => 'Customers can request this in the self-service portal',
        'portal_add_title'   => 'Offer this form to customers in the self-service portal',
        'portal_remove_title'=> 'Remove this form from the customer request catalogue',
        'portal_added'       => 'Form added to the customer request catalogue',
        'portal_removed'     => 'Form removed from the customer request catalogue',
        'portal_failed'      => 'Could not change where this form appears',
        'status_inactive'    => 'Inactive',
        'empty_none_title'   => 'No forms yet',
        'empty_match_title'  => 'No matching forms',
        'empty_none_body'    => 'Click <strong>New</strong> to create your first one.',
        'empty_match_body'   => 'Try a different search term, or clear the search box.',
        'unknown_user'       => '—',
        'fill_title'         => 'Fill in this form',
        'subs_title'         => 'View submissions',
        'approval_title'     => 'Approval settings',
        'approval_on'        => 'Needs approval',
        'approval_on_title'  => 'Catalogue requests wait for {name} to approve before a ticket is raised',
        'approval_team_title' => 'Catalogue requests wait for {n} approval(s) from the {team} team before a ticket is raised',
        // Restricted to a group of people (GH #145).
        'audience_title'     => 'Who can request this form',
        'audience_on'        => 'Restricted',
        'audience_on_title'  => 'Only certain groups can request this form',
        'delete_title'       => 'Delete form',
        'relative_just_now'  => 'Just now',
        'relative_min_ago'   => '{n} min ago',
        'relative_hr_ago'    => '{n} hr ago',
        'relative_days_ago'  => '{n} days ago',
    ],

    // ── Catalogue-request approval (#928): config modal + inbox ─────
    'approval' => [
        // Config modal (forms/index.php)
        'modal_title'      => 'Approval settings',
        'modal_intro'      => 'Require a member of staff to approve a catalogue request before a ticket is raised. Approving raises the ticket automatically; rejecting turns it down.',
        'requires_label'   => 'Require approval before a ticket is raised',
        'approver_label'   => 'Approver',
        'approver_hint'    => 'Only this person (or an administrator) can approve or reject these requests.',
        'approver_none'    => 'Choose an approver…',
        'not_portal_note'  => 'This form is not in the request catalogue yet, so approval will only take effect once customers can request it.',
        'save'             => 'Save',
        'need_approver'    => 'Choose an approver, or turn approval off.',
        'saved'            => 'Approval settings saved',
        'save_failed'      => 'Could not save the approval settings',
        // Who decides (GH #220): a named person, as before, or a team.
        'who_decides'      => 'Who decides',
        'mode_person'      => 'A person',
        'mode_team'        => 'A team',
        'exclude_requester_label' => 'The approver cannot approve a request they made themselves',
        'exclude_requester_hint'  => 'Matched on email address: a member of staff who requests through the portal. Their own requests then wait until an administrator moves them to someone else.',
        'team_label'       => 'Team',
        'team_none'        => 'Choose a team…',
        'team_members'     => '{n} member(s)',
        'approvals_needed' => 'Approvals needed',
        'rejections_needed'=> 'Rejections needed to decline',
        'team_hint'        => 'Each must come from a different member of the team. The person who made the request cannot vote on it. A rejection needs a reason.',
        'team_too_small'   => 'This team has {n} member(s) - fewer than the approvals needed, so its requests would get stuck.',
        'need_team'        => 'Choose a team, or turn approval off.',
        // Inbox (forms/approvals.php)
        'inbox_title'      => 'Request approvals',
        'inbox_heading'    => 'Requests awaiting approval',
        'filter'           => 'Show',
        'filter_mine'      => 'For me',
        'filter_all'       => 'All pending',
        'filter_decided'   => 'Decided by me',
        'loading'          => 'Loading…',
        'empty_heading'    => 'Nothing waiting',
        'empty_text'       => 'There are no catalogue requests awaiting your approval.',
        // When the list cannot be loaded at all. The detail is meant to be sent on
        // to whoever maintains the install, which is why it is copyable.
        'error_heading'    => 'The approvals list could not be loaded',
        'error_intro'      => 'Something went wrong on the server rather than in this browser. The details below say what, and can be copied and sent to whoever looks after this FreeITSM.',
        'error_note'       => 'This may contain technical detail such as file paths. Have a quick look before passing it on.',
        'requester'        => 'Requester',
        'submitted'        => 'Submitted',
        'approver'         => 'Approver',
        'unknown_requester'=> 'Unknown',
        'no_answers'       => 'No details were submitted.',
        'comment_placeholder' => 'Add a note (optional) — shared with no one for now',
        'approve'          => 'Approve',
        'reject'           => 'Reject',
        'status_approved'  => 'Approved',
        'status_rejected'  => 'Rejected',
        'approved_toast'   => 'Approved — raised ticket {ref}',
        'rejected_toast'   => 'Request rejected',
        'decide_failed'    => 'Could not record that decision',
        // Team approvals on the inbox cards (GH #220).
        'team'             => 'Team',
        'progress'         => '{a} of {n} approvals',
        'progress_rejections' => '{r} of {n} rejections',
        'voted_approved'   => 'approved',
        'voted_rejected'   => 'rejected',
        'vote_toast'       => 'Your approval is recorded - {a} of {n}',
        'reason_placeholder' => 'Reason (needed to reject)',
        'reason_required'  => 'Give a reason to reject this request',
        'stuck'            => 'Stuck - this request cannot be decided by its approvers. An administrator can move it or override.',
        'override'         => 'Override',
        'override_title'   => 'Decide this request yourself, without the votes',
        'override_prompt'  => 'Why are you deciding this without the team? This is recorded on the request.',
        'move'             => 'Move',
        'move_title'       => 'Send this request to another team or person',
        'move_heading'     => 'Move this request',
        'move_to_team'     => 'To a team',
        'move_to_person'   => 'To a person',
        'move_reason'      => 'Reason',
        'moved_toast'      => 'Request moved',
        'event_moved'      => 'Moved by {name}: {detail} - {reason}',
        'event_override'   => 'Overridden by {name} ({decision}): {reason}',
        'waiting_vote'     => 'Waiting for your vote',
        'override_hint'    => 'You are not voting on this one: as an administrator, Approve or Reject here overrides the team, and the note is the required reason.',
        'move_choose'      => 'Choose a team or a person…',
        'move_people'      => 'People',
        'move_teams'       => 'Teams',
        'move_need'        => 'Choose where to move it, and give a reason.',
    ],

    // ── Delete confirmation + toasts (forms/index.php) ──────────────
    'delete' => [
        'title'   => 'Delete form',
        'message' => "This will permanently delete this form, every version in its chain, and all submissions across all versions. This can't be undone.",
        'ok'      => 'Delete',
    ],

    'toast' => [
        'form_deleted' => 'Form deleted',
        'delete_failed' => 'Failed to delete',
        'form_saved'    => 'Form saved successfully',
        'form_not_found'=> 'Form not found',
        'load_failed'   => 'Failed to load form: {message}',
        'version_created' => 'Created v{n}',
        'version_failed'  => 'Failed to create version',
        'save_first'      => 'Save the form first before creating a new version.',
        'form_updated'    => 'Form updated',
        'form_built'      => 'Form built',
        'error_prefix'    => 'Error: {message}',
        'save_failed'     => 'Failed to save form',
        'settings_saved'  => 'Settings saved',
        'settings_save_failed' => 'Failed to save settings',
        'ai_settings_saved'    => 'AI settings saved',
    ],

    // ── Editor / builder (forms/edit/index.php) ─────────────────────
    'editor' => [
        'page_title'       => 'Edit form',
        'title_new'        => 'New form',
        'title_edit'       => 'Edit form',
        'unsaved'          => 'Unsaved changes',
        'open_properties'  => 'Open properties',
        'ai_assist'        => 'AI Assist',
        'ai_assist_title'  => 'Describe your form and let AI build it',
        'versions'         => 'Versions',
        'versions_title'   => 'Browse the version history of this form',
        'new_version'      => 'Save as new version',
        'new_version_title'=> 'Snapshot the current form as a new version. The current version becomes a frozen historical record.',
        'properties'       => 'Properties',
        'properties_title' => 'Show form properties + version history',
        'readonly_banner'  => '<strong>Read-only version.</strong> This is a historical snapshot. To make changes, open the <a href="#" onclick="jumpToCurrentVersion(event)">current version</a> or click Save as new version from there to fork forward.',
        'form_title_label' => 'Form title',
        'form_title_ph'    => 'Enter form title...',
        'description_label'=> 'Description',
        'description_ph'   => 'Optional description...',
        'tab_fields'       => 'Fields',
        'tab_preview'      => 'Preview',
        'fields_heading'   => 'Form fields',
        'add'              => 'Add',
        'no_fields'        => 'No fields added yet. Click "Add" to start building your form.',
        'preview_empty'    => 'Add fields to see a preview',
        'cancel'           => 'Cancel',
        'save'             => 'Save',
        'properties_heading' => 'Properties',
        'close'            => 'Close',
        'properties_unsaved' => "This form hasn't been saved yet — properties will appear here once you create it.",
        'meta_version'     => 'Version',
        'meta_author'      => 'Author',
        'meta_created'     => 'Created',
        'meta_modified'    => 'Last modified',
        'meta_modified_by' => 'Modified by',
        'readonly_save_title' => 'This is a historical version — open the current one to edit, or fork from there with "Save as new version"',
    ],

    // "What happens next" tab in the form editor (discussion #95): the actions a
    // form runs when it is submitted, approved or rejected.
    'actions' => [
        'tab'                  => 'What happens next',
        'intro'                => 'Choose what should happen when this form is used. Actions run in order, top to bottom, and a later one can use what an earlier one produced.',
        'when_submitted'       => 'When submitted',
        // Deliberately different wording when a gate exists: "when submitted" and
        // "when submitted, before anyone has approved it" are different promises.
        'when_submitted_gated' => 'When submitted (before approval)',
        'when_approved'        => 'When approved',
        'when_rejected'        => 'When rejected',
        'add'                  => 'Add',
        'none'                 => 'Nothing happens.',
        'remove'               => 'Remove',
        'move_up'              => 'Move up',
        'move_down'            => 'Move down',
        'arg_unset'            => 'Not set',
        'vars_hint'            => 'Answers can be used here, e.g. {{submission.fields.Device type}}',
        'gate_warning'         => 'This form needs approval, and these actions run before the approver has seen it — so raising a ticket here goes ahead without their sign-off.',
        'needs_gate'           => 'These only run if the form requires approval. Turn that on in Properties to use them; anything set here is kept either way.',
        'approved_default'     => 'Nothing is set, so approving raises a ticket in the usual way. Add an action here to decide for yourself instead.',
    ],

    // Field-type palette in the Add menu (forms/edit/index.php)
    'fieldtypes' => [
        'lookup' => 'Lookup',
        'text'       => 'Text input',
        'textarea'   => 'Text area',
        'email'      => 'Email',
        'number'     => 'Number',
        'dropdown'   => 'Dropdown (one of)',
        'radio'      => 'Radio buttons (one of)',
        'checkbox'   => 'Checkbox (yes/no)',
        'checkboxes' => 'Checkboxes (many of)',
        'datetime'   => 'Date / time',
        'section'    => 'Section heading',
        // Standing text. NOT a heading: unlike a section it does not own the fields
        // below it, it is simply there to be read before the next question.
        'note'       => 'Note or warning',
        // A picture on the form: a logo, a diagram, a floor plan.
        'image'      => 'Image',
        'grid'       => 'Table',
    ],

    // Short type-name badges shown on each field row + proposal diff
    'typename' => [
        'text'       => 'Text',
        'textarea'   => 'Textarea',
        'checkbox'   => 'Checkbox',
        'dropdown'   => 'Dropdown',
        'email'      => 'Email',
        'number'     => 'Number',
        'checkboxes' => 'Checkboxes',
        'radio'      => 'Radio',
        'datetime'   => 'Date / time',
        'section'    => 'Section',
        'note'       => 'Note',
        /* ⚠️ MISSING until #1823, so every lookup field's badge in the builder
           read the literal text "forms.typename.lookup". i18n surfaces the key
           on a miss by design, and 'lookup' was on the builder's known-types
           list — so it asked for a name that had never been written. Found by
           driving the type list rather than by looking at the screen. */
        'lookup'     => 'Lookup',
        'image'      => 'Image',
        'grid'       => 'Table',
        // The badge shows the MODE, so a glance down the field list tells you what
        // each date field actually asks for without opening it.
        'date_date'     => 'Date',
        'date_time'     => 'Time',
        'date_datetime' => 'Date + time',
    ],

    // Field row editing UI (forms/edit/index.php)
    'field' => [
        'lookup_portal' => 'Customers can use this on the portal',
        'lookup_src_user' => 'People',
        'lookup_src_cmdb' => 'CMDB objects',
        'lookup_src_asset' => 'Assets',
        'lookup_src_location' => 'Locations',   // GH #311
        'lookup_pick' => 'Choose…',
        'lookup_source' => 'Search which records',
        'options_dropdown'  => 'Dropdown options',
        'options_radio'     => 'Radio options',
        'options_checkbox'  => 'Checkbox options',
        'drag_reorder'      => 'Drag to reorder',
        'option_ph'         => 'Option {n}',
        'default_option'    => 'Option 1',
        // GH #190 phase 4: an answer that counts as an exception in a task's evidence.
        'exception'       => 'Exception',
        'exception_title' => 'Choosing this answer counts as an exception in a task\'s evidence report - Warning or Failure on a check, say',
        'add_option'        => '+ Add option',
        'label_ph'          => 'Field label...',
        'section_ph'        => 'Section heading...',
        'date_mode'          => 'Ask for',
        'date_mode_date'     => 'A date',
        'date_mode_time'     => 'A time',
        'date_mode_datetime' => 'Date and time',
        /* How wide a field is, in twelfths of the row. 🌍 These are the words
           on a picker, so they must read as fractions of a line, not as sizes:
           "Half" means half the row, not a medium-sized box. */
        'width'            => 'Width',
        'width_12'         => 'Full width',
        'width_9'          => 'Three-quarters',
        'width_8'          => 'Two-thirds',
        'width_6'          => 'Half',
        'width_4'          => 'Third',
        'width_3'          => 'Quarter',
        'width_hint'       => 'Fields narrower than the full row sit side by side, up to a full line. Everything is full width on a phone.',
        /* Where a question's label sits. "Beside" is what turns two half-width
           questions into a row reading | First name | [input] | Surname | [input] |
           which is the shape a paper form usually wants. */
        'label_pos'        => 'Label',
        'label_pos_above'  => 'Above',
        'label_pos_beside' => 'Beside',
        'label_pos_hint'   => 'Where the label sits. Beside puts it on the same line as the answer box, which is how a printed form usually reads. Labels go back above on a phone.',

        /* A note's appearance. 🔴 NAMES, never colours — each resolves to theme
           tokens defined for both light and dark mode, which is not something a
           form author can be asked to get right by picking a value. The names
           describe what the note IS ("a warning"), not what it looks like ("an
           amber box"), so a re-skin does not make every label a lie. */
        'note_style'         => 'Style',
        'note_style_plain'   => 'Plain',
        'note_style_info'    => 'Information',
        'note_style_warning' => 'Warning',
        'note_style_danger'  => 'Important',
        'note_style_success' => 'Success',
        'note_body_ph'       => 'Longer text, if the heading above is not enough (optional)',

        /* An image block. The size is a PERCENTAGE of the column, not pixels:
           everything else on a form is sized in twelfths and reflows, and a
           pixel width is right on one screen and wrong on the next. */
        'image_max'        => 'Size',
        'image_max_100'    => 'Full width',
        'image_max_75'     => 'Three-quarters',
        'image_max_50'     => 'Half',
        'image_max_25'     => 'Quarter',
        'required'          => 'Required',
        'remove_field'      => 'Remove field',
        'untitled_field'    => 'Untitled field',

        // Confirmation before a field leaves the form. Nothing is written to
        // the database until Save, and answers already given are kept — the
        // field is retired rather than deleted (see the "Retire whatever is no
        // longer on the form" pass in includes/services/forms.php). Both facts
        // are stated, because they are what makes this safe to say yes to.
        'remove_confirm_title'   => 'Remove field',
        'remove_confirm_message' => 'Remove "{label}" from this form? Answers already given to it are kept and stay visible in submissions. Nothing changes until you save.',
        'remove_confirm_refs'    => 'Remove "{label}" from this form? {n} rule that shows or hides another question depends on it and will be removed too. Answers already given are kept and stay visible in submissions. Nothing changes until you save.',
        'remove_confirm_refs_plural' => 'Remove "{label}" from this form? {n} rules that show or hide other questions depend on it and will be removed too. Answers already given are kept and stay visible in submissions. Nothing changes until you save.',
        'remove_confirm_ok'      => 'Remove',

        // Removing one choice from a dropdown / radio / checkbox list. Only
        // asked when the option has text in it — see removeOption().
        'remove_option_confirm_title'   => 'Remove option',
        'remove_option_confirm_message' => 'Remove "{label}" from this list? Nothing changes until you save.',
    ],

    // Conditional visibility editor (forms/edit/index.php)
    'cond' => [
        'show_when'            => 'Only show this when',
        'show_section_when'    => 'Only show this section when',
        'add'                  => '+ Add condition',
        'remove'               => 'Remove condition',
        'match_all'            => 'all match',
        'match_any'            => 'any match',
        'pick_value'           => 'Choose...',
        'value_ph'             => 'Value',
        'yes'                  => 'Ticked',
        'no'                   => 'Not ticked',
        'needs_earlier_field'  => 'Add a question above this one first — a condition can only depend on an earlier answer.',
        'dropped_forward'      => '{count} condition(s) removed: they depended on a question that now comes later.',
        'op' => [
            'equals'       => 'is',
            'not_equals'   => 'is not',
            'contains'     => 'contains',
            'is_empty'     => 'is empty',
            'is_not_empty' => 'is not empty',
            'greater_than' => 'is more than',
            'less_than'    => 'is less than',
            'is_after'     => 'is after',
            'is_before'    => 'is before',
        ],
    ],

    // Live preview (forms/edit/index.php)
    'preview' => [
        'untitled_form'     => 'Untitled form',
        'logo_alt'          => 'Company logo',
        'text_ph'           => 'Text input...',
        'textarea_ph'       => 'Text area...',
        'email_ph'          => 'name@example.com',
        'number_ph'         => '0',
        'select_ph'         => 'Select...',
        'no_options'        => 'No options yet',
        'conditional'       => 'Conditional',
        'conditional_hint'  => 'This only appears when its condition is met. The preview shows everything so you can see the whole form.',
    ],

    // Versions dropdown (forms/edit/index.php)
    'versions' => [
        'save_first_history' => 'Save the form first to start a version history.',
        'loading'        => 'Loading…',
        'failed'         => 'Failed',
        'load_failed'    => "Couldn't load versions: {message}",
        'none'           => 'No version history yet.',
        'current'        => 'current',
        'untitled'       => 'Untitled',
        'edited_by'      => 'Edited by {name} · {date}',
        'unknown'        => 'Unknown',
    ],

    // Save-as-new-version flow (forms/edit/index.php)
    'newversion' => [
        'unsaved_title'   => 'Unsaved changes',
        'unsaved_message' => 'You have unsaved changes. Save them as part of the new version? Cancel here and click Save first if you want to keep both.',
        'unsaved_ok'      => 'Continue',
        'confirm_title'   => 'Confirm',
        'confirm_message' => 'Create a new version? The current version becomes a frozen historical snapshot; the new one becomes the editable current version.',
        'confirm_ok'      => 'OK',
    ],

    // Cancel / discard (forms/edit/index.php)
    'cancel' => [
        'title'   => 'Discard changes',
        'message' => 'You have unsaved changes. Discard them?',
        'ok'      => 'Discard',
    ],

    // Save validation (forms/edit/index.php)
    'save' => [
        'need_title'  => 'Please enter a form title',
        'need_field'  => 'Please add at least one field with a label',
    ],

    // ── AI Assist modal (forms/edit/index.php) ──────────────────────
    'ai' => [
        'title_edit'   => 'AI Assist — what would you like to change?',
        'title_new'    => 'AI Assist — describe your form',
        'prompt_edit'  => 'What change do you want?',
        'prompt_new'   => "What's the form for?",
        'ta_ph_edit'   => 'e.g. Add a date-of-birth field. Make the email field required. Rewrite the description to mention the SLA.',
        'ta_ph_new'    => "e.g. A holiday request form for staff. Capture the requester's name, the start and end date, the type of leave (annual / sick / parental / unpaid), an optional note, and a confirmation checkbox that they've checked the team rota.",
        'hint_edit'    => "The AI will see the current form and modify it based on your request — it won't rebuild from scratch.",
        'hint_new'     => 'Tell it what the form does and what info it needs to capture. The more specific you are, the better the result.',
        'try'          => 'Try:',
        'status_designing' => 'Designing your form…',
        'status_applying'  => 'Applying your change…',
        'fields_detected'  => 'Fields detected:',
        'tokens_in'        => 'Tokens in:',
        'tokens_out'       => 'Tokens out:',
        'cached'           => 'Cached:',
        'cancel'           => 'Cancel',
        'generate'         => 'Generate',
        'apply'            => 'Apply',
        'need_change'      => 'Please describe what you want to change',
        'need_describe'    => 'Please describe the form you want to build',
        'too_long'         => 'Description is too long (max 2000 characters)',
        'streaming_unsupported' => 'Streaming not supported by your browser',
        'request_failed'   => 'AI request failed',
        'error_status'     => 'Error: {message}',
        'failed'           => 'AI Assist failed: {message}',
        // Proposal diff badges
        'badge_new'        => 'new',
        'badge_added'      => 'added',
        'badge_changed'    => 'changed',
        'badge_unchanged'  => 'unchanged',
        'badge_removed'    => 'removed',
        'prop_required'    => 'required',
        'prop_field'       => 'field',
        'prop_fields'      => 'fields',
        'prop_head_change' => 'this change',
        'prop_head_new'    => 'a new form',
        'prop_head'        => 'AI proposed {what} in {seconds}s —',
        'prop_count'       => '({count} {fields})',
        'prop_note'        => 'Click <strong>Apply</strong> to load this into the editor, or <strong>Cancel</strong> to keep your current form.',
        // Example prompts — NEW mode
        'ex_new1_label' => 'New starter onboarding form for IT',
        'ex_new1_text'  => "A new starter onboarding form for the IT team. Capture the new starter's name, job title, start date, line manager, software needed (Outlook, Teams, Adobe, Visual Studio), and a notes field for special equipment.",
        'ex_new2_label' => 'HR leaver form',
        'ex_new2_text'  => "A leaver form for HR. Capture the leaver's name, last working day, line manager, reason for leaving (resignation / retirement / redundancy / dismissal / end of contract), exit interview required (yes/no), and a notes field.",
        'ex_new3_label' => 'User incident reporting form',
        'ex_new3_text'  => "An incident reporting form for end users. Subject, description, severity (low / medium / high / critical), affected service, when it started (date as text), and a checkbox confirming they've already tried restarting.",
        // Example prompts — EDIT mode
        'ex_edit1_label' => 'Add a phone number field',
        'ex_edit1_text'  => 'Add a phone number field after the email address. Required.',
        'ex_edit2_label' => 'Make all fields required',
        'ex_edit2_text'  => 'Mark every field as required.',
        'ex_edit3_label' => 'Reorder so the name field comes first',
        'ex_edit3_text'  => 'Reorder the fields so the name field is at the top.',
        'ex_edit4_label' => 'Tighten the description to one short sentence',
        'ex_edit4_text'  => 'Rewrite the description to one short, neutral sentence (under 25 words).',
        'ex_edit5_label' => 'Remove the consent checkbox',
        'ex_edit5_text'  => 'Remove the consent checkbox at the bottom.',
    ],

    // ── Table questions: the column editor + the filler ─────────────
    // A "grid" in the database; a TABLE QUESTION to a person. One question whose
    // answer is many rows, with the rows added by whoever fills the form in.
    'grid' => [
        'columns'      => 'Columns',
        'add_column'   => 'Add column',
        'no_columns'   => 'No columns yet — add the first one.',
        'label_ph'     => 'Column heading',
        'options_ph'   => 'Choices, separated by commas',
        'required'     => 'Required',
        'move_up'      => 'Move up',
        'move_down'    => 'Move down',
        'count'        => '{n} columns',
        'too_many'     => 'A table can have at most 12 columns.',
        'need_one'     => 'A table needs at least one column.',
        // Retiring is a SOFT delete: the column keeps its id forever so answers
        // already given to it still say what they were answering.
        'retire'         => 'Retire',
        'restore'        => 'Restore',
        'retire_title'   => 'Retire this column?',
        'retire_message' => 'Nobody will be asked "{label}" again, but answers already given to it are kept and still shown on those submissions. You can restore it later.',
        // The restricted cell palette. A file upload or a signature in a narrow
        // column is unusable, so a table cell is deliberately one of these six.
        'celltype_text'     => 'Text',
        'celltype_number'   => 'Number',
        'celltype_dropdown' => 'Dropdown',
        'celltype_radio'    => 'Choose one',
        'celltype_checkbox' => 'Tick box',
        'celltype_datetime' => 'Date',
        // Filling one in
        'add_row'        => 'Add row',
        'remove_row'     => 'Remove this row',
        'too_many_rows'  => 'That is as many rows as this table can take.',
        // Shown where the table would be, if somebody published the form before
        // giving it any columns. Says what is wrong rather than drawing nothing.
        'not_configured' => 'This table has no columns yet.',
        /* Reading one back. A submissions list is one row per submission with a
           single value in every other column, so a table is summarised by its
           COUNT there and drawn properly in the detail panel. */
        'one_row'        => '1 row',
        'n_rows'         => '{n} rows',
    ],

    // ── Who may request a form from the catalogue (GH #145) ─────────
    // The groups are PEOPLE groups, managed in Tickets → Users → Groups.
    'audience' => [
        'modal_title'   => 'Who can request this form',
        'modal_intro'   => 'By default every customer who can reach the request catalogue can request this form. Restrict it and only people in the groups you choose will see it.',
        'restrict_label'=> 'Only let certain groups request this form',
        'groups_label'  => 'Groups',
        'groups_hint'   => 'Groups are managed in Tickets → Users → Groups. Somebody in any one of the groups you tick can request the form.',
        'no_groups'     => 'There are no groups yet. Create one in Tickets → Users → Groups.',
        'one_customer'  => '1 customer',
        'n_customers'   => '{n} customers',
        'need_group'    => 'Choose at least one group, or turn the restriction off.',
        'save'          => 'Save',
        'saved_restricted' => 'Only those groups can request this form now',
        'saved_everyone'   => 'Anyone can request this form now',
        'save_failed'   => 'Could not save who can request this form',
        // The restriction only bites on the catalogue, so say so rather than
        // letting somebody set it on a form customers cannot reach anyway.
        'not_portal_note' => 'This form is not in the request catalogue, so this will only take effect once customers can request it.',
    ],

    // ── Drafts: a form somebody started and has not finished ────────
    'draft' => [
        'save'     => 'Save draft',
        'saved'    => 'Draft saved. You can come back to this later.',
        'failed'   => 'That draft could not be saved',
        // {when} is when they last saved it, so "is this the right one?" is
        // answerable without opening anything.
        'restored' => 'Picked up your draft from {when}. Nothing has been submitted yet.',
        /* 🔴 The form has had a new version since the draft was saved, and a new
           version renumbers every question — so those answers would land in the
           wrong boxes. Says what happened rather than silently filling them in
           or silently discarding them. */
        'stale'    => 'You had a draft of this form, but the form has changed since you saved it — so it cannot be filled back in. Please start again.',
    ],

    // ── Image blocks (the builder + all three renderers) ────────────
    'image' => [
        // Shown where the picture would be, both in the builder before one is
        // chosen and on a form whose image has gone missing. Visible rather than
        // hidden, so an author can see where the block sits.
        'none'     => 'No image chosen',
        'chosen'   => 'Image uploaded',
        'uploaded' => 'Image uploaded',
        'failed'   => 'That image could not be uploaded',
    ],

    // ── Shared renderer (assets/js/form-render.js) ──────────────────
    // Shown in place of a question whose type this page cannot draw. Deliberately
    // NOT an input: the portal used to fall back to a text box, which looks like a
    // working question and stores whatever is typed as that field's answer.
    'render' => [
        'unsupported_type' => 'This question cannot be shown here.',
    ],

    // ── Fill page (forms/fill.php) ──────────────────────────────────
    'fill' => [
        'for_ticket' => 'For the ticket "{ticket}"',   // GH #272
        'page_title'       => 'Fill Form',
        'loading'          => 'Loading form...',
        'no_id'            => 'No form ID specified',
        'logo_alt'         => 'Company Logo',
        'select_ph'        => 'Select...',
        'email_ph'         => 'name@example.com',
        'lookup_placeholder' => 'Start typing to search…',
        'lookup_none'        => 'No matches',
        'err_required'     => 'This field is required',
        'err_email'        => 'Please enter a valid email address',
        'err_number'       => 'Please enter a number',
        'err_checkboxes'   => 'Please tick at least one option',
        // One per date mode — err_<mode>, chosen by the field's own setting.
        'err_date'         => 'Please choose a date',
        'err_time'         => 'Please choose a time',
        'err_datetime'     => 'Please choose a date and time',
        'submit'           => 'Submit',
        'cancel'           => 'Cancel',
        'fill_required'    => 'Please fill in all required fields',
        'success'          => 'Form submitted successfully!',
        'submit_another'   => 'Submit Another',
        'back_to_forms'    => 'Back to Forms',
        'error_prefix'     => 'Error: {message}',
        'submit_failed'    => 'Failed to submit form',
    ],

    // ── Submissions page (forms/submissions.php) ────────────────────
    /* Collections — a named exercise several forms' submissions belong to.
       🌍 "Closed" is ambiguous in a lot of languages (shut? concluded?), so the
       strings that carry weight say which, and the setting spells out the
       consequence rather than naming it. */
    'collections' => [
        'heading'          => 'Collections',
        'intro'            => 'A collection groups the submissions of several forms under one name, such as a survey or an audit round. It is optional — most forms, like a laptop request, do not belong to one.',
        'add'              => 'New',
        'name'             => 'Name',
        'name_ph'          => 'Staff Survey 2026',
        'description'      => 'Description',
        'description_ph'   => 'What this collection is for (optional)',
        'save'             => 'Save',
        'cancel'           => 'Cancel',
        'close'            => 'Close',
        'reopen'           => 'Reopen',
        'delete'           => 'Delete',
        'edit'             => 'Rename',
        /* Table headings. 🌍 One word each where English allows it, but the
           column they head is what gives them meaning - keep them literal. */
        'col_forms'        => 'Forms',
        'col_submissions'  => 'Submissions',
        'col_status'       => 'Status',
        'state_open'       => 'Open',
        'add_title'        => 'New collection',
        'edit_title'       => 'Rename collection',
        'no_forms_short'   => 'None',
        /* Managing membership from the collection's side, and reading what
           is in it. 🌍 `steal_warning` must survive translation intact - it
           is the sentence that stops somebody silently emptying another
           collection. */
        'manage_forms'     => 'Forms',
        'manage_title'     => 'Forms in this collection',
        'manage_intro'     => 'Tick the forms whose new submissions should be filed under this collection. A form can belong to only one collection at a time.',
        'manage_none'      => 'There are no forms yet.',
        'in_other'         => 'currently in {name}',
        'steal_warning'    => 'Ticking a form that is already in another collection moves it out of that one.',
        'moved_note'       => 'Moved out of another collection: {names}',
        'forms_saved'      => 'Forms updated',
        'view_submissions' => 'Submissions',
        'page_title'       => 'Collection submissions',
        'back'             => 'Back',
        'col_form'         => 'Form',
        'no_subs_title'    => 'Nothing filed here yet',
        'no_subs_body'     => 'Submissions appear here once a form in this collection is filled in. Submissions made before a form joined stay where they were.',
        'empty'            => 'No collections yet.',
        'empty_hint'       => 'Create one, then pair forms with it from the forms list.',
        'closed_badge'     => 'Closed',
        'closed_on'        => 'Closed {date} by {who}',
        'form_count'       => '{n} forms',
        'form_count_one'   => '1 form',
        'sub_count'        => '{n} submissions',
        'sub_count_one'    => '1 submission',
        'no_forms'         => 'No forms are paired with this yet.',
        'view_subs'        => 'Submissions',

        /* What closing DOES. An operator setting because organisations mean
           different things by it, and there is no right answer to hard-code. */
        'effect_heading'   => 'When a collection is closed',
        'effect_intro'     => 'Closing a collection never changes the forms themselves, so reopening one always restores exactly what was there before.',
        'effect_reporting' => 'Nothing stops. Closing is a label saying the exercise is over.',
        'effect_stop'      => 'The forms in it stop accepting new submissions.',
        'effect_hide'      => 'The forms in it stop accepting new submissions, and are hidden from the self-service portal.',

        'confirm_close'       => 'Close this collection?',
        'confirm_close_body'  => 'What this does depends on your setting above. It can be reopened at any time.',
        'confirm_delete'      => 'Delete this collection?',
        'confirm_delete_body' => 'The forms paired with it will simply have no collection. This cannot be undone.',
        'has_submissions'     => 'This collection holds submissions, so it can be closed but not deleted.',
        'saved'               => 'Collection saved',
        'save_failed'         => 'The collection could not be saved',
        'unavailable'         => 'Collections need a database update.',
        'unavailable_hint'    => 'Run DB Verification in System settings, then reload this page.',
    ],

    /* Pairing a form with a collection — on the FORM, because that is where
       every other per-form setting already lives. */
    'pairing' => [
        'title'        => 'Collection',
        'label'        => 'Part of',
        'none'         => 'No collection',
        'help'         => 'New submissions of this form will be filed under the collection you choose. Submissions already made keep the collection they were filed under, so changing this never rewrites history.',
        'closed_note'  => 'This collection is closed, so the form is not accepting new submissions.',
        'saved'        => 'Collection updated',
        'save_failed'  => 'The collection could not be updated',
        'none_yet'     => 'No collections exist yet. Create one in Forms settings.',
    ],
    'subs' => [
        'page_title'        => 'Form Submissions',
        'page_title_named'  => 'Service Desk - {title} Submissions',
        'back'              => 'Back',
        'heading'           => 'Submissions',
        'heading_named'     => '{title} — Submissions',
        'from'              => 'From',
        'to'                => 'To',
        'clear'             => 'Clear',
        'export_csv'        => 'Export CSV',
        'loading'           => 'Loading...',
        'no_id'             => 'No form ID specified',
        'load_failed'       => 'Failed to load submissions',
        'count'             => '{n} submission',
        'count_plural'      => '{n} submissions',
        'empty_title'       => 'No submissions yet',
        'empty_body'        => '<a href="{url}">Fill in this form</a> to create the first submission',
        'col_num'           => '#',
        'col_submitted_by'  => 'Submitted By',
        'col_date'          => 'Date',
        'unknown_user'      => 'Unknown',
        'retired'           => 'removed',
        'retired_hint'      => 'This question was removed from the form. The answers people already gave it are kept.',
        'delete'            => 'Delete',
        'detail_heading'    => 'Submission Detail',
        /* Ticking rows to export several at once. "Bundle" is one file holding
           them all; "Separate" is one file each. Both hints say which, because
           the two buttons are a single word apart. */
        'sel_all'           => 'Select all',
        'sel_row'           => 'Select this submission',
        /* Says what the bar is FOR, not just how many are ticked - "3 selected"
           next to buttons called Bundle and Separate never said PDF anywhere.
           🌍 Two forms, following `count`/`count_plural`: English reads the
           same either way, Polish does not. */
        'sel_count'         => '{n} selected for export to PDF',
        'sel_count_plural'  => '{n} selected for export to PDF',
        'sel_bundle'        => 'Bundle',
        'sel_bundle_hint'   => 'Download one PDF containing every selected submission',
        'sel_separate'      => 'Separate',
        'sel_separate_hint' => 'Download a separate PDF for each selected submission',
        'sel_clear'         => 'Clear',
        'bundle_name'       => '{title} - {n} submissions - {date}',
        'sel_many_confirm'  => 'This will download {n} separate files. Your browser may ask you to allow them. Continue?',
        /* PDF export of a single submission (Enrique: a record you can keep). */
        'export_pdf'        => 'Export as PDF',
        'pdf_footer'        => 'Page {n} of {total}',
        'pdf_error'         => 'The PDF could not be created.',
        /* The approval trail. `not_required` is not a blank - it means nobody
           had to approve this, which is worth saying on a record. */
        'detail_approval'   => 'Approval',
        'approval_awaiting' => 'Awaiting approval',
        'approval_none'     => 'Not required',
        'approval_comment'  => 'Comment',
        'detail_submitted_by' => 'Submitted by:',
        'detail_date'       => 'Date:',
        'yes'               => 'Yes',
        'no'                => 'No',
        'no_response'       => 'No response',
        'confirm_title'     => 'Delete Submission',
        'confirm_message'   => 'This will permanently delete this submission and its data. Are you sure?',
        'confirm_cancel'    => 'Cancel',
        'confirm_delete'    => 'Delete',
        // CSV header labels
        'csv_num'           => '#',
        'csv_submitted_by'  => 'Submitted By',
        'csv_date'          => 'Date',
        'csv_default_name'  => 'submissions',
        'csv_yes'           => 'Yes',
        'csv_no'            => 'No',
    ],

    // ── Settings page (forms/settings/index.php) ────────────────────
    'settings' => [
        'page_title'        => 'Service Desk - Forms Settings',
        'tab_layout'        => 'Layout',
        'tab_collections'   => 'Collections',
        'tab_ai'            => 'AI',
        'tab_access'        => 'Access',
        'access_heading'    => 'Who can design forms',
        'access_intro'      => 'Designing a form includes choosing who approves its requests and what happens after them. Restrict it to keep approvers and reviewers out of the designer.',
        'design_who'        => 'Who can design forms',
        'design_everyone'   => 'Everyone with Forms',
        'design_permission' => 'Only people with "Design forms"',
        'design_help'       => 'Build a role with "Design forms" in System → Roles and give it to the people who should keep designing, then choose the second option. Administrators always can.',
        'design_holders'    => 'Would keep designing ({count}):',
        'layout_heading'    => 'Layout Settings',
        'layout_intro'      => 'Configure how forms appear when users fill them in and in the form preview.',
        'logo_alignment'    => 'Logo Alignment',
        'logo_alignment_help' => 'Controls the position of the company logo on forms.',
        'align_left'        => 'Left',
        'align_center'      => 'Centre',
        'align_right'       => 'Right',
        'preview'           => 'Preview',
        'logo_alt'          => 'Company Logo',
        'save'              => 'Save',
        'ai_heading'        => 'AI',
        'ai_intro'          => "Configure the AI provider used by the form builder's AI Assist. These settings are billed against the key you supply here, so the Forms feature's usage stays separate from other modules' AI usage on your provider's dashboard.",
        'provider'          => 'Provider',
        'provider_anthropic'=> 'Anthropic (Claude)',
        'provider_openai'   => 'OpenAI (GPT)',
        'model'             => 'Model',
        'model_ph'          => 'e.g. claude-sonnet-4-6',
        'model_help'        => 'You can pick a model from the suggestions or paste any model ID supported by your provider.',
        'api_key'           => 'API key',
        'api_key_ph'        => 'Paste your API key',
        'api_key_help'      => 'Stored encrypted at rest. Anthropic: <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener" style="color:#00897b;">console.anthropic.com</a>. OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:#00897b;">platform.openai.com</a>.',
        'verify_ssl'        => 'Verify SSL certificate',
        'verify_ssl_help'   => "Turn off only if your network's proxy is doing TLS inspection with a self-signed CA.",
        'ssl_warning'       => "<strong>Warning:</strong> SSL verification is off — outbound traffic to the AI provider isn't being verified. Only use this on a controlled network with a known proxy.",
        'test_connection'   => 'Test connection',
        // JS-side AI status messages
        'load_failed'       => 'Load failed',
        'could_not_load'    => 'Could not load settings: {message}',
        'key_saved_ph'      => 'Key is saved — paste a new one to change it',
        'save_failed'       => 'Save failed',
        'pick_model'        => 'Pick a model first',
        'testing'           => 'Testing…',
        'test_failed'       => 'Failed',
        'test_failed_msg'   => 'Failed: {message}',
        'test_ok'           => 'OK — {provider} · {model} · {latency}ms{tokens}',
        'test_tokens'       => ' — {in} in / {out} out tokens',
        // Anthropic model option labels
        'model_opus'        => 'Opus 4.7 — most capable',
        'model_sonnet'      => 'Sonnet 4.6 — recommended (best balance)',
        'model_haiku'       => 'Haiku 4.5 — fastest and cheapest',
        // OpenAI model option labels
        'model_gpt41'       => 'GPT-4.1 — most capable',
        'model_gpt4o'       => 'GPT-4o — recommended default',
        'model_gpt4o_mini'  => 'GPT-4o mini — fastest and cheapest',
    ],

    // ── Help guide (forms/help.php) ─────────────────────────────────
    'help' => [
        'page_title'   => 'Service Desk - Forms Guide',
        'guide'        => 'Guide',
        // The card grid linking to the in-depth topic pages under forms/help/.
        'topics_title'   => 'In depth',
        'topics_body'    => 'Longer guides to the parts of a form that need more explaining than a paragraph.',
        'back_to_guide'  => 'Back to the Forms guide',
        'nav_overview'    => 'Overview',
        'nav_building'    => 'Building forms',
        'nav_filling'     => 'Filling in forms',
        'nav_submissions' => 'Submissions',
        'nav_export'      => 'Export',
        'nav_settings'    => 'Settings',
        'nav_tips'        => 'Quick tips',
        'nav_actions'     => 'What happens next',

        // Section 3 — the form's own actions (#95)
        'actions_title'  => 'What happens next',
        'actions_intro'  => 'A form can do something when it is used, rather than just storing an answer. Open a form, choose the What happens next tab, and say what should happen — raise a ticket, send an email, call a webhook, create a task, add a note. There are three separate moments, and you can put as many actions in each as you like, or none at all.',
        'actions_submitted_title' => 'When submitted',
        'actions_submitted_body'  => 'Runs the moment somebody sends the form in. This is the one most forms use.',
        'actions_approved_title'  => 'When approved',
        'actions_approved_body'   => 'Only applies to forms that need approval. Runs after your approver has said yes.',
        'actions_rejected_title'  => 'When rejected',
        'actions_rejected_body'   => 'Also approval only. Useful for telling the requester why — and it can raise a ticket too, if you want a record of the refusal.',
        'actions_step1'  => '<strong>Open the tab</strong> &mdash; edit a form and choose <strong>What happens next</strong>, next to Fields and Preview.',
        'actions_step2'  => '<strong>Add an action</strong> &mdash; press <strong>Add</strong> on the section you want, then pick what it should do. Each action asks for its own settings: raising a ticket lets you set the priority, the queue, the type and who it goes to.',
        'actions_step3'  => '<strong>Put them in order</strong> &mdash; actions run top to bottom. Use the arrows to move one up or down.',
        'actions_step4'  => '<strong>Save the form</strong> &mdash; nothing takes effect until you do.',
        'actions_answers_title' => 'Using the answers',
        'actions_answers_body'  => 'Anywhere you can type, you can drop in what somebody answered by writing the field label in double braces. If your form asks <em>Device type</em>, then <code>{{submission.fields.Device type}}</code> becomes their answer &mdash; so a ticket can be titled after what was actually asked for, rather than being called the same thing every time.',
        'actions_chain_title'   => 'One action using another',
        'actions_chain_body'    => 'A later action can use what an earlier one produced. Raise a ticket first, then send an email whose text includes <code>{{last.ticket_id}}</code>, and the person gets their ticket number in the confirmation. To reach a particular step rather than the one immediately before, use its number &mdash; <code>{{steps.1.ticket_number}}</code> is the first action on the list.',
        'actions_gate_title'    => 'Forms that need approval',
        'actions_gate_body'     => 'If a form requires approval, the first section renames itself <strong>When submitted (before approval)</strong>, because that is exactly when it runs. Putting <em>Raise a ticket</em> there means the ticket goes ahead without the sign-off you asked for, so the screen warns you &mdash; put it under <strong>When approved</strong> instead. And if approval is switched on but nothing is set up, approving still raises a ticket the usual way; the screen says so, rather than leaving an empty section to look like nothing happens.',
        'actions_tip'    => 'Every run is recorded. Open <strong>Workflows &rarr; Executions</strong> to see exactly what a form did, step by step, including anything that failed &mdash; a form&rsquo;s actions are logged there alongside everything else.',

        'hero_title' => 'Forms guide',
        'hero_sub'   => 'Build custom forms, collect structured data, and export submissions — all without writing a single line of code.',

        // Section 1: Overview
        'overview_title' => 'Overview',
        'overview_body'  => 'The Forms module lets you create custom forms with a visual drag-and-drop builder, share them with your team for completion, review every submission in one place, and export the data to CSV. Whether you need an onboarding checklist, a request form, or a feedback survey, Forms handles it all.',
        'flow_build'  => 'Build a form',
        'flow_fill'   => 'Fill it in',
        'flow_submit' => 'Submit',
        'flow_review' => 'Review & export',
        'card_builder_title' => 'Builder',
        'card_builder_body'  => 'Design forms visually with a drag-and-drop field editor. Eleven field types, from plain text and dropdowns to date pickers, section headings and lookups that pull a real record from elsewhere in FreeITSM. Rearrange them in seconds.',
        'card_fill_title' => 'Fill in',
        'card_fill_body'  => 'A clean, A4-style form interface with your company logo at the top. Required field validation ensures nothing important gets missed.',
        'card_subs_title' => 'Submissions',
        'card_subs_body'  => 'Browse every submission in a sortable table. Click any row to open the full detail view and see exactly what was entered.',
        'card_export_title' => 'Export',
        'card_export_body'  => 'Take the answers as a CSV for a spreadsheet, or keep a submission as a PDF — your logo, the answers and the approval on it — one at a time or several together.',

        // Section 2: Building forms
        'building_title' => 'Building forms',
        'building_intro' => 'The form builder is where you design your forms. You start with a title and optional description, then add fields one by one. The builder gives you a live preview so you can see exactly how the finished form will look before sharing it.',
        'building_step1' => '<strong>Create a new form</strong> &mdash; click <strong>New</strong> at the top right. Give your form a title and, optionally, a description that explains what the form is for.',
        'building_step2' => '<strong>Add fields</strong> &mdash; click the "Add" button to open the field type menu. There are eleven types: <strong>Text input</strong> for short answers, <strong>Text area</strong> for longer responses, <strong>Email</strong> and <strong>Number</strong> for values that need checking as they are typed, <strong>Dropdown</strong> and <strong>Radio buttons</strong> for one choice from a list, <strong>Checkbox</strong> for a single yes/no, <strong>Checkboxes</strong> for several answers at once, <strong>Date / time</strong>, <strong>Lookup</strong> to pick a real record from elsewhere in FreeITSM, and <strong>Section heading</strong> to break a long form into parts.',
        'building_step3' => '<strong>Configure each field</strong> &mdash; give every field a label and decide whether it should be required. For dropdowns, enter the list of options that users can choose from.',
        'building_step4' => '<strong>Reorder fields</strong> &mdash; drag and drop fields using the handle to arrange them in the order you want. The form will display fields in exactly this order when filled in.',
        'building_step5' => '<strong>Preview your form</strong> &mdash; switch to the Preview tab to see how your form will look to the person filling it in. This shows the A4-style layout with your company logo.',
        'building_step6' => '<strong>Save</strong> &mdash; click the Save button in the toolbar. The unsaved changes indicator disappears once the form is saved successfully.',
        'building_tip'   => 'The unsaved changes warning protects you from accidentally navigating away. If you have pending changes, you will be prompted before leaving the page.',

        // Lookup fields
        'lookup_title'       => 'Lookup fields',
        'lookup_body'        => 'Most fields are a box someone types into, so "which laptop is broken?" comes back as "the Dell one" and somebody has to work out which. A lookup field searches FreeITSM itself as the person types, and the answer is the actual record. Pick what it searches when you add the field:',
        'lookup_asset_title' => 'Equipment',
        'lookup_asset_body'  => 'Laptops, desktops, phones, monitors. Searchable by name, asset tag or service tag, so it works whether someone knows the machine name or is reading a sticker.',
        'lookup_cmdb_title'  => 'Infrastructure',
        'lookup_cmdb_body'   => 'Servers, services, applications and databases from your CMDB. Use it for "which system is affected?".',
        'lookup_user_title'  => 'People',
        'lookup_user_body'   => 'Your staff directory. Use it for "who is the new starter\'s manager?". Staff only, never available on the customer portal.',
        'lookup_location_title' => 'Locations',   // GH #311
        'lookup_location_body' => 'Your sites, buildings, floors and rooms from System → Locations, shown as a full path such as "UK > London > Floor 2". Use it for "which office are you in?".',
        'lookup_stored'      => 'Both the name and the link to the record are kept. Rename a machine later and an old submission still says what it said at the time, while the answer still points at the right record.',
        'lookup_portal_title' => 'Customers only see their own',
        'lookup_portal'      => 'A lookup is not offered on the self-service portal unless you tick it for that field, and a customer only ever sees records belonging to their own company. The staff directory is never offered to customers, whatever you tick.',

        // Section 3: Filling in forms
        'filling_title' => 'Filling in forms',
        'filling_body'  => 'When you open a form to fill in, it is presented in a clean A4-style layout designed to look professional and easy to read. Your company logo appears at the top of the form, followed by the title, description, and each field in order.',
        'filling_logo_title' => 'Company logo',
        'filling_logo_body'  => 'Displayed at the top of every form. Alignment (left, centre, or right) is controlled in Settings.',
        'filling_text_title' => 'Text inputs',
        'filling_text_body'  => 'Single-line fields for short answers like names, reference numbers, or email addresses.',
        'filling_textarea_title' => 'Text areas',
        'filling_textarea_body'  => 'Multi-line fields for longer responses such as descriptions, notes, or explanations.',
        'filling_checkbox_title' => 'Checkboxes',
        'filling_checkbox_body'  => 'Simple tick boxes for yes/no or agree/disagree selections.',
        'filling_dropdown_title' => 'Dropdowns',
        'filling_dropdown_body'  => 'Pick one option from a predefined list. Ideal for categories, departments, or priority levels.',
        'filling_required_title' => 'Required fields',
        'filling_required_body'  => 'Marked with a red asterisk. The form cannot be submitted until all required fields are completed.',
        'filling_validate' => 'Each field validates as you fill it in. Required fields that are left empty will be highlighted, and the form will not submit until every required field has a value. This prevents incomplete submissions from reaching the reviewer.',
        'filling_tip'   => 'The form interface is designed to feel like a printed document. The white card on a grey background mimics an A4 sheet, making it intuitive for users who are familiar with traditional paper forms.',

        // Section 4: Submissions
        'subs_title' => 'Submissions',
        'subs_body'  => 'Every completed form is stored as a submission. The Submissions page gives you a comprehensive view of all the data that has been collected, with tools to search, filter, and drill into individual responses.',
        'subs_step1' => '<strong>Table view</strong> &mdash; submissions are displayed in a sortable table showing the submitter, submission date, and a summary of the responses. The total count is shown in a badge next to the heading.',
        'subs_step2' => '<strong>Detail view</strong> &mdash; click any row in the table to open a modal showing the complete submission. Every field label and its corresponding answer are displayed in a clean, readable format.',
        'subs_step3' => '<strong>Date range filtering</strong> &mdash; use the date pickers in the toolbar to narrow submissions down to a specific time period. Set a start date, an end date, or both to focus on exactly the window you need.',
        'subs_step4' => '<strong>Collections</strong> &mdash; a collection groups the submissions of several forms under one name, such as <em>Staff Survey 2026</em> or an audit round. Create collections in Forms settings, then pair a form with one from the folder icon on the forms list. It is optional, and most forms &mdash; a laptop request, say &mdash; belong to none.',
        'subs_step5' => '<strong>A collection is recorded on the submission itself</strong>, at the moment it is submitted. Pairing a form with a different collection later, or with none at all, never changes what earlier submissions were filed under &mdash; so last year\'s survey still reads as last year\'s survey.',
        'subs_tip'   => 'Date range filtering is especially useful for recurring forms. For example, if you run a weekly checklist, filter by the current week to see only the latest responses.',

        // Section 5: Export
        'export_title' => 'Export',
        'export_intro' => 'There are two ways out. Export to CSV when you want the answers as data — in Excel, Google Sheets or anything that reads a spreadsheet. Export to PDF when you want the record itself: one submission laid out with your logo, the answers and who approved it, ready to file or send to an auditor. Both respect any active date range filter, so you can take just the submissions you need.',
        'export_f1' => '<strong>UTF-8 BOM encoding</strong> &mdash; the CSV file includes a byte order mark (BOM) so that Excel correctly displays special characters, accented letters, and currency symbols without manual encoding setup.',
        'export_f2' => '<strong>All fields included</strong> &mdash; every field from the form is represented as a column in the CSV. The submitter name and submission date are always included as the first columns.',
        'export_f3' => '<strong>Filtered export</strong> &mdash; if you have set a date range filter on the Submissions page, only submissions within that range are included in the export. Clear the filters to export everything.',
        'export_f4' => '<strong>Instant download</strong> &mdash; the CSV is generated on the server and downloaded directly to your browser. No email or background processing required.',
        'export_f5' => '<strong>A submission as a PDF</strong> &mdash; open a submission and choose <em>Export as PDF</em>, or use the download icon in its row. The document carries your own logo, the submission number, who submitted it and when, the approval decision and comment, and every answer &mdash; including answers to questions you have since removed from the form. Files are named <em>Form title - Person - date</em>, using your own date format.',
        'export_f6' => '<strong>Several at once</strong> &mdash; tick the box beside any rows you want, then choose <em>Bundle</em> for a single PDF with one page per submission, or <em>Separate</em> for one file each. Tick the box in the table heading to take everything currently shown. Changing the date filter clears the ticks, so you never export something you can no longer see.',
        'export_tip' => 'If you open the CSV in Excel and see garbled characters, make sure you are double-clicking the file to open it rather than using File &gt; Import. The BOM ensures automatic detection when opening directly.',

        // Section 6: Settings
        'settings_title' => 'Settings',
        'settings_body'  => 'The Settings page lets you configure how forms appear when they are filled in. These settings apply globally to all forms in the module.',
        'settings_step1' => '<strong>Logo alignment</strong> &mdash; choose whether your company logo appears on the left, in the centre, or on the right of the form header. The alignment is shown visually with preview tiles so you can see the result before saving.',
        'settings_step2' => '<strong>Company logo</strong> &mdash; the logo used on forms is the same one configured in your global system settings. To change the logo itself, update it in the main application settings.',
        'settings_step3' => '<strong>Collections</strong> &mdash; a collection groups the submissions of several forms under one name, such as <em>Staff Survey 2026</em> or an audit round. Create them here, tick which forms belong to each, and open a collection to read every submission in it across all of its forms. Most forms belong to no collection, and that is normal.',
        'settings_step4' => '<strong>Closing a collection</strong> &mdash; when an exercise is over you can close it, and you choose what closing means for your organisation: nothing stops and it is simply labelled as finished; or its forms stop accepting new submissions; or they also disappear from the self-service portal. Closing never changes the forms themselves, so reopening a collection always restores exactly what was there before, including a form you had deliberately kept off the portal.',
        'settings_options' => 'The alignment setting is saved per module, so changing it here does not affect logos in other parts of the application. The three options are:',
        'settings_left_title' => 'Left',
        'settings_left_body'  => 'Logo aligned to the left edge of the form. Works well for formal or corporate documents.',
        'settings_center_title' => 'Centre',
        'settings_center_body'  => 'Logo centred above the form title. The default option, giving a balanced and symmetrical appearance.',
        'settings_right_title' => 'Right',
        'settings_right_body'  => 'Logo aligned to the right edge. Useful when form fields start on the left and you want the logo out of the way.',
        'access_body'    => 'Who can do what in Forms:',
        'access_design_title' => 'Who can design forms',
        'access_design_body'  => 'Settings → Access can limit designing - including choosing approvers and what happens next - to people with "Design forms" in System → Roles. Administrators always can. The tab lists who would keep designing before you switch.',
        'access_approvers_title' => 'Approvers need no Forms access',
        'access_approvers_body'  => 'Somebody named as a form\'s approver opens Form approvals from the launcher and decides their own requests, without seeing the designer or anybody else\'s submissions.',
        'access_readonly_title' => 'Read only',
        'access_readonly_body'  => 'Give a reviewer Read only access to Forms in System → Modules: they can read forms and submissions and export them, and change nothing.',
        'settings_tip'   =>'Navigate to Settings from the header navigation bar. Changes take effect immediately on any form that is opened after saving.',

        // Section 7: Quick tips
        'tips_title' => 'Quick tips',
        'tip1_title' => 'Keep forms focused',
        'tip1_body'  => 'Shorter forms get higher completion rates. If a form grows beyond ten fields, consider splitting it into two separate forms with distinct purposes.',
        'tip2_title' => 'Use required wisely',
        'tip2_body'  => 'Only mark fields as required when the data is genuinely essential. Over-using required fields can frustrate users and lead to placeholder answers.',
        'tip3_title' => 'Preview before sharing',
        'tip3_body'  => 'Always switch to the Preview tab before saving a form. This is the exact view your users will see, so check that field order and labels make sense.',
        'tip4_title' => 'Export regularly',
        'tip4_body'  => 'Download submissions periodically for backup or analysis. The CSV format is compatible with pivot tables, mail merge, and most reporting tools.',
    ],
];
