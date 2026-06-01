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
        'affected_services'   => 'Affected Services',
        'add_service'         => '+ Add Service',
        'delete'              => 'Delete',
        'cancel'              => 'Cancel',
        'save'                => 'Save',
    ],

    'toast' => [
        'incident_saved'   => 'Incident saved',
        'incident_deleted' => 'Incident deleted',
        'save_failed'      => 'Failed to save',
        'delete_failed'    => 'Failed to delete',
        'save_incident_failed'   => 'Failed to save incident',
        'delete_incident_failed' => 'Failed to delete incident',
        'saved'            => 'Saved',
        'deleted'          => 'Deleted',
        'save_service_failed'    => 'Failed to save service',
        'delete_service_failed'  => 'Failed to delete service',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Delete incident',
        'delete_incident_message' => 'Delete this incident?',
        'delete_title'            => 'Delete',
        'delete_message'          => 'Delete "{name}"?',
        'delete_label'            => 'Delete',
    ],

    'settings' => [
        'tab_services'     => 'Services',
        'tab_statuses'     => 'Statuses',
        'tab_impacts'      => 'Impact levels',

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
        'nav_settings'  => 'Settings',
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
        'history_p2'      => 'Resolved incidents remain visible in the table as a historical record. This makes it easy to spot recurring issues, review how past incidents were handled, and identify patterns that might point to underlying problems.',
        'history_tip'     => 'Regularly reviewing your incident history helps you identify services that are frequently disrupted. If the same service appears in multiple incidents, it may be time to investigate the root cause more deeply or plan an infrastructure upgrade.',

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
        'tip_maintenance_title' => 'Plan maintenance',
        'tip_maintenance_desc'  => 'Use the Maintenance impact level for planned work. Creating an incident in advance lets users know about scheduled downtime before it happens.',
    ],
];
