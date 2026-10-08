<?php
/**
 * English (en) — Service Status module strings.
 *
 * Source-of-truth locale. Every other lang/<code>/service-status.php may omit
 * keys; missing keys fall back to the value here (see includes/i18n.php).
 *
 * Covers the status dashboard, the incident modal, the settings page (services,
 * statuses and impact-level tabs plus their shared lookup modal), the module
 * header navigation and the help guide.
 *
 * NOTE: Service names, incident titles/comments and the configurable
 * status/impact-level NAMES stored in the database are user data and are NOT
 * translated here — only app-defined chrome lives in this file.
 */
return [
    'title' => 'Service Status',

    'nav' => [
        'status'   => 'Status',
        'settings' => 'Settings',
        'help'     => 'Help',
    ],

    'board' => [
        'services'        => 'Services',
        'service_count'   => '{count} services',
        'loading'         => 'Loading...',
        'no_services'     => 'No services configured. Go to Settings to add services.',
        // Service history + uptime (discussion #59), derived from incidents.
        'history_show'        => 'History and uptime',
        'history_hide'        => 'Hide history',
        'history_loading'     => 'Working it out…',
        'history_uptime'      => 'uptime',
        'history_today'       => 'today',
        // 🔴 TRANSLATORS: keep {n}, and put it where YOUR language wants it.
        // This used to be the bare suffix ' days ago', glued onto the number in
        // JS. That forced every language into English word order - Spanish
        // wants "hace 30 días" and could only produce "30 días atrás".
        'history_days_ago'    => '{n} days ago',
        'history_none'        => 'No incidents in this period.',
        'history_ongoing'     => 'Ongoing',
        'history_excluded'     => '(not counted as downtime)',
        'history_no_issues'   => 'no issues',
        'updates_show'      => 'Updates',
        'updates_hide'      => 'Hide updates',
        'col_actions'       => 'Actions',
        'updates_failed'    => 'Could not load the updates for this incident.',
        'updates_none'      => 'No updates were recorded for this incident. Incidents raised before update logging was added do not have one.',
        'updates_all_clear' => 'No services impacted at this point.',
        'incidents'       => 'Incidents',
        'new'             => 'New',
        'col_title'       => 'Title',
        'col_status'      => 'Status',
        'col_affected'    => 'Affected Services',
        'col_updated'     => 'Updated',
        'no_incidents'    => 'No incidents to show.',
        'none'            => 'None',
    ],

    'modal' => [
        'new_incident'        => 'New Incident',
        'edit_incident'       => 'Edit Incident',
        'title'               => 'Title',
        'title_placeholder'   => 'Brief description of the incident',
        'status'              => 'Status',
        'comment'             => 'Comment',
        'comment_placeholder' => 'Details about the incident...',
        'vis_internal'             => 'Internal note',
        'vis_external'             => 'External update',
        'vis_internal_hint'        => 'Only your team sees this. Use it for what you are doing about the problem.',
        'vis_external_hint'        => 'End users will see this in the self-service portal. Write it for somebody who does not work here.',
        'vis_external_hint_off'    => 'Marked for the portal, but the portal is not currently showing incidents. Nobody outside your team will see it until an administrator turns that on under System.',
        'affected_services'   => 'Affected Services',
        'add_service'         => '+ Add Service',
        'delete'              => 'Delete',
        'cancel'              => 'Cancel',
        'save'                => 'Save',
    ],

    // Planned maintenance (3.2.0) - includes/service_status_planned.php.
    'planned' => [
        'title'          => 'Planned maintenance',
        'new'            => 'Plan',
        'new_title'      => 'Plan maintenance',
        'edit_title'     => 'Change planned maintenance',
        'intro'          => 'Tell people about work before it happens. Until it starts it shows here as upcoming and changes nothing; at the start it becomes an incident on the services you choose, and at the end it is resolved.',
        'title_label'    => 'Title',
        'title_ph'       => 'e.g. Phone system move',
        'start'          => 'Starts',
        'end'            => 'Ends (optional)',
        'comment'        => 'What people should know',
        'comment_ph'     => 'What will be unavailable, and what to do instead',
        'none'           => 'No planned maintenance.',
        'when_range'     => '{start} to {end}',
        'when_open'      => 'From {start}, until resolved',
        'from_project'   => 'From the project {name}',
        'open'           => 'Open',
        'edit'           => 'Edit',
        'cancel_plan'    => 'Withdraw',
        'cancel_title'   => 'Withdraw this maintenance?',
        'cancel_message' => '"{name}" will not start, and it disappears from the board and the portal.',
        'state_scheduled' => 'Upcoming',
        'state_started'   => 'In progress',
        'state_finished'  => 'Finished',
        'state_cancelled' => 'Withdrawn',
        'portal_heading'  => 'Planned maintenance',
    ],

    'toast' => [
        'incident_saved'   => 'Incident saved',
        'incident_deleted' => 'Incident deleted',
        'incident_resolved'    => 'Incident resolved',
        'no_resolved_status'   => 'No incident status is marked as resolved, so there is nothing to set. Add one under Settings.',
        'save_failed'      => 'Failed to save',
        'delete_failed'    => 'Failed to delete',
        'save_incident_failed'   => 'Failed to save incident',
        'delete_incident_failed' => 'Failed to delete incident',
        'saved'            => 'Saved',
        'deleted'          => 'Deleted',
        'save_service_failed'    => 'Failed to save service',
        'delete_service_failed'  => 'Failed to delete service',
    ],

    // Row actions and the right-click menu (#100)
    'actions' => [
        'edit'       => 'Edit incident',
        'resolve'    => 'Resolve incident',
        'show_updates' => 'Show updates',
        'delete'     => 'Delete incident',
    ],

    // Correcting a posted update (Ed, on top of #99)
    'updates' => [
        'edit'             => 'Correct this update',
        'edit_prompt'      => 'Correct the wording. This changes the update that is already there rather than posting another one.',
        'edit_note'        => 'This changes the update that is already there rather than posting another one, so the timeline does not end up with the same message twice.',
        'who_can_see'      => 'Who can see it',
        'edited'           => 'Update corrected',
        'delete'           => 'Remove this update',
        'delete_message'   => 'This removes one entry from the incident timeline. The incident itself and every other update stay as they are.',
        'deleted'          => 'Update removed',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Delete incident',
        'delete_incident_message' => 'Delete this incident?',
        'resolve_title'        => 'Resolve this incident?',
        'resolve_message'      => 'This sets "{title}" to {status}. The affected services return to normal and the change is recorded in the incident\'s updates.',
        'delete_title'            => 'Delete',
        'delete_message'          => 'Delete "{name}"?',
        'delete_label'            => 'Delete',
    ],

    'settings' => [
        'tab_services'     => 'Services',
        'tab_statuses'     => 'Statuses',
        'tab_impacts'      => 'Impact levels',
        'tab_uptime'      => 'Uptime',
        'uptime_heading'  => 'Uptime reporting',
        'uptime_intro_html' => 'Service history and uptime are worked out from your incidents, so they cover outages that have <strong>already happened</strong> rather than starting from today. Which impact levels count as downtime is set on each level, over on <strong>Impact levels</strong>.',
        'uptime_window'   => 'Default period',
        'uptime_window_days' => 'Last {days} days',
        'uptime_window_help' => 'What each service shows before anyone changes it. Individual services can still be viewed over a different period.',
        'uptime_portal'   => 'Show uptime to customers in the portal',
        'uptime_portal_help' => 'Off by default. A percentage is a stronger statement than a status dot: once customers can see it, it reads as a published figure.',
        'uptime_saved'    => 'Uptime settings saved.',

        'services_heading' => 'Services',
        'statuses_heading' => 'Incident statuses',
        'impacts_heading'  => 'Impact levels',
        'add'              => 'Add',
        'loading'          => 'Loading...',
        'no_services'      => 'No services yet. Click Add to create one.',
        'no_items'         => 'No items found',
        'load_failed'      => 'Failed to load data',
        'error_prefix'     => 'Error: {message}',

        'statuses_intro_html' => 'Workflow states for service incidents. Statuses flagged as <em>resolved</em> close the incident — auto-stamping <code>resolved_datetime</code> and removing the incident from the active dashboard. Exactly one status is the default for new incidents.',
        'impacts_intro_html'  => 'Severity bands shown as the badge on each service card. <strong>Severity order</strong> drives the "worst current impact" ordering on the dashboard — lower = worse (1 = major outage, 5 = operational). Two rows can share an order.',

        'col_name'        => 'Name',
        'col_description' => 'Description',
        'col_order'       => 'Order',
        'col_status'      => 'Status',
        'col_actions'     => 'Actions',
        'col_colour'      => 'Colour',
        'col_resolved'    => 'Resolved',
        'col_default'     => 'Default',
        'col_severity'    => 'Severity',
        'col_downtime'    => 'Counts as downtime',

        'active'          => 'Active',
        'inactive'        => 'Inactive',
        'yes'             => 'Yes',
        'no'              => 'No',
        'edit'            => 'Edit',
        'delete'          => 'Delete',

        'kind_status'     => 'status',
        'kind_impact'     => 'impact level',

        // Service modal
        'add_service'     => 'Add service',
        'edit_service'    => 'Edit service',
        'field_name'      => 'Name',
        'field_description' => 'Description',
        'field_order'     => 'Display order',
        'field_active'    => 'Active',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Add item',
        'add_kind'        => 'Add {kind}',
        'edit_kind'       => 'Edit {kind}',
        'field_colour'    => 'Colour',
        'field_resolved'  => 'Counts as resolved',
        'resolved_help_html' => 'Incidents in this status auto-stamp <code>resolved_datetime</code> and drop off the active dashboard.',
        'field_severity'  => 'Severity order',
        'severity_help'   => '1 = worst (Major Outage). Higher = less severe.',
 // Uptime (discussion #59). Lives on the impact level because that is what the
 // question is about; see the Uptime tab for the window and portal visibility.
        'field_downtime'  => 'Time at this level counts as downtime',
        'downtime_help'   => 'Turn this off for planned maintenance. Counting maintenance makes a well-run service look worse than a neglected one, so it is excluded by default.',
        'field_default'   => 'Default',

        'cancel'          => 'Cancel',
        'save'            => 'Save',
    ],

    'help' => [
        'page_title' => 'Service Status Guide',
        'guide'      => 'Guide',

        'nav_overview'  => 'Overview',
        'nav_dashboard' => 'The status dashboard',
        'nav_services'  => 'Managing services',
        'nav_history'   => 'Incident history',
        'nav_uptime' => 'Uptime & history',
        'nav_settings'  => 'Settings',
        // Uptime + per-service history (discussion #59). Written as "how do I
        // record this" rather than "what the feature is", because the accuracy
        // of the figures depends entirely on how incidents are updated.
        'uptime_heading'      => 'Uptime and service history',
        'uptime_p1_html'      => 'Every service has a <strong>History and uptime</strong> link on its card. It shows a day-by-day strip for the last 7, 30, 90 or 365 days, the percentage of that period the service was available, and each period it spent at a given impact level.',
        'uptime_p2_html'      => 'None of this is a separate log you have to maintain: it is worked out from your incidents. That means it already covers outages that happened before the feature existed &mdash; but it also means <strong>the figures are only as good as the way incidents are recorded</strong>, which is what the rest of this section is about.',
        'uptime_record_heading' => 'Recording an outage so the history is right',
        'uptime_record_p_html'  => 'A service is counted as down from the moment an incident puts it at a level that counts as downtime, until the moment something says otherwise. Each time you save an incident, FreeITSM records a snapshot of which services were at which level &mdash; so the way to get an accurate history is simply to <strong>update the incident as things change</strong>.',
        'uptime_step1_html'   => '<strong>Raise the incident</strong> with every affected service and its impact level. That is the start time.',
        'uptime_step2_html'   => '<strong>Save it again each time something changes</strong> &mdash; a service gets worse, gets better, or comes back. Change that service&rsquo;s impact level, or remove it from the incident if it is fully restored. Each save is timestamped, so a service that was on Major Outage for a day and Degraded for eight hours is recorded as exactly that.',
        'uptime_step3_html'   => '<strong>Resolve the incident</strong> when everything is back. That is the end time for anything still listed.',
        'uptime_resolve_note_html' => 'You do not have to remove services one at a time &mdash; but if you do, each one stops counting at that moment rather than when the whole incident is resolved. That is the difference between &ldquo;the file server was down for two days&rdquo; and &ldquo;everything was down for three&rdquo;.',
        'uptime_counts_heading' => 'What counts as downtime',
        'uptime_counts_p_html'  => 'Each impact level decides for itself, on <strong>Settings &rarr; Impact levels</strong>. Major Outage, Partial Outage and Degraded count by default; Maintenance, Operational and No Disruption do not. Planned maintenance is excluded deliberately &mdash; counting it makes a well-run service look worse than a neglected one. Any level you add yourself counts until you say otherwise.',
        'uptime_reading_heading' => 'Reading the strip',
        'uptime_read_green_html' => '<strong>Green</strong> &mdash; no incident touched the service that day.',
        'uptime_read_red_html'   => '<strong>Red</strong> &mdash; an incident counted against uptime. The colour is the impact level; hover to see which.',
        'uptime_read_grey_html'  => '<strong>Grey</strong> &mdash; an incident touched the service but at a level that does not count, such as planned maintenance. Hover to see which level it actually was.',
        'uptime_tip_html'      => 'A service showing far worse uptime than you expect is usually an incident nobody resolved &mdash; an open incident counts until it is closed. Check the incident list before assuming the figure is wrong.',
        'nav_tips'      => 'Quick tips',

        'hero_title' => 'Service status guide',
        'hero_sub'   => 'Monitor your IT services, communicate incidents, and keep stakeholders informed in real time.',

        // Section 1: Overview
        'overview_heading' => 'Overview',
        'overview_intro'   => 'The Service Status module gives you a centralised view of the health of every IT service your organisation relies on. When something goes wrong, you can record incidents, update affected services, and keep users informed throughout the resolution process.',
        'feature_dashboard_title' => 'Status dashboard',
        'feature_dashboard_desc'  => 'See the current health of every service at a glance. Colour-coded badges show whether each service is operational, degraded, under maintenance, or experiencing an outage.',
        'feature_incident_title'  => 'Incident tracking',
        'feature_incident_desc'   => 'Record incidents with titles, status updates, and comments. Link affected services to each incident so everyone knows exactly what is impacted and why.',
        'feature_management_title' => 'Service management',
        'feature_management_desc'  => 'Configure your service catalogue in settings. Add services with names, descriptions, and display order. Activate or deactivate services as your infrastructure evolves.',
        'feature_comms_title' => 'Communication',
        'feature_comms_desc'  => 'Keep stakeholders informed with real-time status updates. Each incident carries a status and comment trail so users can follow the resolution progress without chasing the service desk.',

        // Section 2: Dashboard
        'dashboard_heading' => 'The status dashboard',
        'dashboard_p1'      => 'The dashboard is the first thing you see when you open the Service Status module. It displays a grid of service cards, each showing the service name, a short description, and a colour-coded impact badge reflecting its current worst status. Below the grid sits the incidents table listing all recent and active incidents.',
        'dashboard_p2_html' => 'Each service card automatically reflects the most severe impact level assigned to it from any active (unresolved) incident. When all incidents affecting a service are resolved, it returns to <strong>Operational</strong>.',
        'status_levels'     => 'Status levels',
        'level_operational_name' => 'Operational',
        'level_operational_desc' => 'The service is running normally with no known issues. This is the default state for all healthy services.',
        'level_degraded_name'    => 'Degraded Performance',
        'level_degraded_desc'    => 'The service is available but running slower than expected or with reduced functionality. Users may notice delays.',
        'level_maintenance_name' => 'Under Maintenance',
        'level_maintenance_desc' => 'Planned downtime or maintenance window. The service may be temporarily unavailable while work is carried out.',
        'level_outage_name'      => 'Major Outage',
        'level_outage_desc'      => 'The service is completely unavailable. This is the most severe status and should trigger immediate investigation.',
        'dashboard_tip'     => 'Impact levels are hierarchical. If a service is linked to multiple active incidents, the dashboard shows the worst impact. For example, one incident marking a service as Degraded and another marking it as Major Outage will result in Major Outage being displayed.',

        // Section 3: Managing services
        'services_heading_html' => 'Managing services &amp; recording incidents',
        'services_intro'        => 'Services are the building blocks of your status page. Each one represents an IT service, system, or infrastructure component that your users depend on. When something goes wrong, you create an incident and link it to the affected services.',
        'add_incident_heading'  => 'Adding a new incident',
        'add_incident_step1_html' => '<strong>Click "New"</strong> on the dashboard to open the incident form.',
        'add_incident_step2_html' => '<strong>Enter a title</strong> &mdash; a brief, clear description of the issue. For example: "Email delivery delays" or "VPN gateway unreachable".',
        'add_incident_step3_html' => '<strong>Set the status</strong> &mdash; choose Investigating, Identified, 3rd Party, Monitoring, or Resolved. Start with Investigating and update as you learn more.',
        'add_incident_step4_html' => '<strong>Add a comment</strong> &mdash; describe what is known so far, what actions are being taken, and any workarounds available to users.',
        'add_incident_step5_html' => '<strong>Link affected services</strong> &mdash; add one or more services and choose the impact level for each (Major Outage, Partial Outage, Degraded, Maintenance, Operational, or No Disruption).',
        'add_incident_step6_html' => '<strong>Save</strong> &mdash; the incident appears in the table and affected service cards update immediately on the dashboard.',
        'workflow_heading'  => 'Incident status workflow',
        'workflow_investigating' => 'Investigating',
        'workflow_identified'    => 'Identified',
        'workflow_monitoring'    => 'Monitoring',
        'workflow_resolved'      => 'Resolved',
        'workflow_note_html'     => 'Use <strong>3rd Party</strong> when the root cause lies with an external vendor or provider.',
        'services_tip'      => 'You can edit any incident by clicking its title in the table. Update the status, add new comments, or change affected services as the situation evolves. Keeping incidents updated is key to transparent communication.',

        // Section 4: Incident history
        'history_heading' => 'Incident history',
        'history_p1'      => 'The incidents table on the dashboard shows both active and resolved incidents, giving you a complete timeline of service health. Each row displays the incident title, current status, affected services with their impact levels, and the last updated timestamp.',
        'history_field_title_html'    => '<strong>Title</strong> &mdash; a clickable link that opens the incident for editing. Use clear, descriptive titles so the history is easy to scan.',
        'history_field_status_html'   => '<strong>Status</strong> &mdash; colour-coded badge showing the current investigation phase (Investigating, Identified, 3rd Party, Monitoring, or Resolved).',
        'history_field_affected_html' => '<strong>Affected services</strong> &mdash; tagged badges showing each linked service with its impact level colour. At a glance you can see what is impacted and how severely.',
        'history_field_updated_html'  => '<strong>Updated</strong> &mdash; the timestamp of the most recent change. Resolved incidents are styled with muted text so active incidents stand out visually.',
        'history_field_actions_html'   => '<strong>Actions</strong> &mdash; edit, resolve or delete the incident without opening it.',
        'actions_heading'              => 'Acting on an incident',
        'actions_p1'                   => 'Every incident carries three buttons in the <strong>Actions</strong> column, and the same actions are on the <strong>right-click</strong> menu, where they are named rather than drawn. The incident title is still a link to the full editor, and now looks like one.',
        'actions_edit'                 => '<strong>Edit</strong> &mdash; opens the incident for changes: its title, status, comment and which services it affects.',
        'actions_resolve'              => '<strong>Resolve</strong> &mdash; sets the incident to your first status marked as <em>resolved</em>, in one click. The affected services return to normal, the resolution time is stamped, and a line is added to the incident&rsquo;s updates so the history still reads properly. Nothing else about the incident is changed. It is not offered on an incident that is already resolved.',
        'actions_delete'               => '<strong>Delete</strong> &mdash; removes the incident and its updates. There is no undo, so it asks first.',
        'actions_updates'              => 'The right-click menu also offers <strong>Show updates</strong>, the same thread the link beside the title opens.',
        'actions_tip'                  => 'If <strong>Resolve</strong> reports that no status is marked as resolved, add one under Settings &rarr; Incident statuses and tick <em>Resolved</em>. Until something is marked that way, FreeITSM has no way to know which of your statuses means the incident is over.',
        'history_p2'      => 'Resolved incidents remain visible in the table as a historical record. This makes it easy to spot recurring issues, review how past incidents were handled, and identify patterns that might point to underlying problems.',
        'history_tip'     => 'Regularly reviewing your incident history helps you identify services that are frequently disrupted. If the same service appears in multiple incidents, it may be time to investigate the root cause more deeply or plan an infrastructure upgrade.',
        'vis_heading'      => 'Internal notes and external updates',
        'vis_p1'           => 'Every time you save an incident with something written in the comment box, that becomes an entry on the incident&rsquo;s timeline &mdash; and you choose who it is for. An <strong>internal note</strong> is seen only by your team: what you are doing about the problem, what you have ruled out, which supplier you are chasing. An <strong>external update</strong> is written for the people affected by the outage.',
        'vis_p2'           => '<strong>Internal is the default, and it resets to internal every time you open the dialog.</strong> Publishing should be the deliberate act, and the choice is not carried over from the last incident you touched &mdash; otherwise you could publish because of a decision you made an hour ago on something else.',
        'vis_p3'           => 'Each entry in the timeline is badged, so you can tell afterwards which is which without opening anything.',
        'vis_note'         => 'Nothing is published to end users until an administrator switches it on under <strong>System &rarr; Service status on the portal</strong>. Until then, choosing <em>external</em> marks the update and the hint under the choice tells you plainly that nobody outside your team will see it yet. Everything written before this feature existed is internal, and stays internal.',
        'corr_heading'     => 'Fixing a typo, and what counts as an update',
        'corr_p1'          => 'Each entry on the timeline has a <strong>correct</strong> and a <strong>remove</strong> action. Correcting changes the words that are already there, so a typo does not become a second, nearly identical message a few minutes after the first &mdash; which on a page customers read looks like carelessness.',
        'corr_p2'          => 'You can also change <strong>who can see</strong> an entry after the fact: an internal note that should have gone out can be published without retyping it, and one published by mistake can be pulled back without losing the record that it happened. What the entry says about the <em>status</em> and the <em>service impacts</em> cannot be changed &mdash; those are what was true at that moment.',
        'corr_p3'          => 'Saving an incident only adds to the timeline when there is something new to say: the status changed, the wording changed, or the affected services changed. Renaming an incident is not a new message, and opening one and pressing Save does not post anything at all.',
        'corr_note'        => 'A status change with no new wording is recorded on its own rather than repeating the previous sentence, so a reader does not see &ldquo;Investigating &mdash; we are aware of delays&rdquo; followed by &ldquo;Resolved &mdash; we are aware of delays&rdquo;.',

        // Section 5: Settings
        'settings_heading' => 'Settings',
        'settings_p1'      => 'The Settings page is where you build and maintain your service catalogue. Every service that appears on the status dashboard must first be configured here.',
        'settings_step1_html' => '<strong>Add a service</strong> &mdash; click "Add" and provide a name (e.g. "Email", "VPN", "ERP System") and an optional description explaining what the service does.',
        'settings_step2_html' => '<strong>Set the display order</strong> &mdash; the order number controls where the service appears on the dashboard grid. Lower numbers appear first, so put your most critical services at the top.',
        'settings_step3_html' => '<strong>Toggle active/inactive</strong> &mdash; deactivating a service removes it from the dashboard without deleting it. This is useful for decommissioned services or seasonal systems.',
        'settings_step4_html' => '<strong>Edit or delete</strong> &mdash; use the action buttons on each row to update service details or remove a service entirely. Editing is always preferred over deleting so that historical incident links remain intact.',
        'settings_tip'     => 'Think of your service catalogue as the foundation of your status page. Spend time getting the names and descriptions right &mdash; these are what your users and stakeholders will see when they check the health of your IT environment.',

        // Section 6: Quick tips
        'tips_heading' => 'Quick tips',
        'tip_communicate_title' => 'Communicate early',
        'tip_communicate_desc'  => "Post an incident as soon as you know something is wrong, even if you don't have all the details yet. Acknowledging an issue quickly builds trust with your users.",
        'tip_update_title' => 'Update frequently',
        'tip_update_desc'  => 'Regular status updates &mdash; even if nothing has changed &mdash; show users that the issue is being actively worked on. Silence breeds frustration and support tickets.',
        'tip_review_title' => 'Review patterns',
        'tip_review_desc'  => 'Check your incident history regularly. If the same service keeps appearing, it might point to a deeper infrastructure issue worth addressing proactively.',
        'planned_heading' => 'Planned maintenance',
        'planned_p1'      => 'Press Plan under Planned maintenance to tell people about work before it happens: a title, when it starts and (optionally) ends, what people should know, and the services it affects with an impact - Maintenance unless you choose another. It shows straight away as Upcoming, here and on the portal if incidents are shown there, and changes nothing on the services.',
        'planned_p2'      => 'At its start it becomes an ordinary incident on those services, dated from the planned start, with your words as its first external update - so its workflows run and its history reads as if you had raised it then. At its end it is resolved, dated at the planned end. Leave the end empty to resolve it by hand. Resolving it early yourself is fine; your time stands.',
        'planned_p3'      => 'Until it starts you can change it or withdraw it with Edit. Once it has started, it is an incident: change it there, with Open.',
        'planned_note'    => 'Starts and ends are noticed when somebody opens this page, the portal or Watchtower, and by the scheduled workflow job (cron/workflow_scheduled.php) - so schedule that job if the start must happen on time while nobody is looking. The Maintenance level does not count against uptime. A project can announce planned maintenance from its Connections tab.',
        'tip_maintenance_title' => 'Plan maintenance',
        'tip_maintenance_desc'  => 'Use Planned maintenance for work you know about in advance. It is announced straight away, and only becomes an incident - at the Maintenance level, which does not count against uptime - when the work starts.',
    ],
];
