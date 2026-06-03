<?php
/**
 * English (en) — Calendar module strings.
 *
 * Source-of-truth locale. Every other lang/<code>/calendar.php may omit keys;
 * missing keys fall back to the value here (see includes/i18n.php).
 *
 * Covers the month/week/day calendar, the sidebar category filter, the event
 * modal + quick-view popup, the categories settings page, the table view, the
 * toasts, and the full help guide.
 *
 * NOTE: month names, weekday names and the prev/next/today/view-month/week/day
 * navigation primitives are SHARED — pull them from common.calendar.* (see
 * lang/en/common.php), not from here.
 */
return [
    'title' => 'Calendar',

    'nav' => [
        'calendar' => 'Calendar',
        'table'    => 'Table',
        'settings' => 'Settings',
        'help'     => 'Help',
    ],

    'sidebar' => [
        'new_event'   => 'New Event',
        'categories'  => 'Categories',
        'none'        => 'No categories found',
    ],

    'subscribe' => [
        'heading'       => 'Add to your phone',
        'intro'         => 'Add the team calendar to your phone — it updates automatically.',
        'button'        => 'Subscribe',
        'modal_title'   => 'Add to your phone',
        'modal_intro'   => 'Scan the QR code with your phone\'s camera, then choose Subscribe. The calendar will keep itself up to date.',
        'address_label' => 'Server address',
        'address_hint'  => 'Your phone can\'t reach "localhost" — set this to your computer\'s network IP address (e.g. 192.168.1.50) so the phone can connect. The QR code and link update as you type.',
        'url_label'     => 'Subscription link',
        'copy'          => 'Copy',
        'copied'        => 'Copied',
        'ios_label'     => 'iPhone',
        'ios_hint'      => 'Scan the QR code (or tap the copied link), then choose Subscribe.',
        'android_label' => 'Android',
        'android_hint'  => 'Open Google Calendar on the web → Other calendars → From URL, and paste the link.',
        'reset'         => 'Reset link',
        'reset_confirm' => 'Reset your calendar link? The current link will stop working on any device already subscribed to it.',
        'close'         => 'Close',
    ],

    'event' => [
        'modal_new'      => 'New Event',
        'modal_edit'     => 'Edit Event',
        'title'          => 'Title',
        'title_ph'       => 'Event title...',
        'category'       => 'Category',
        'category_none'  => '-- Select category --',
        'start_date'     => 'Start Date',
        'start_time'     => 'Start Time',
        'end_date'       => 'End Date',
        'end_time'       => 'End Time',
        'all_day'        => 'All day event',
        'location'       => 'Location',
        'location_ph'    => 'Location (optional)',
        'description'    => 'Description',
        'description_ph' => 'Description (optional)',
        'delete'         => 'Delete',
        'cancel'         => 'Cancel',
        'save'           => 'Save',
        'edit'           => 'Edit',
        'delete_confirm' => 'Are you sure you want to delete this event?',
        'title_required' => 'Please enter an event title',
        'start_required' => 'Please select a start date',
    ],

    'table' => [
        'start_required' => 'Start date/time is required',
        'save_failed'    => 'Save failed',
        'col_title'       => 'Title',
        'col_category'    => 'Category',
        'col_start'       => 'Start',
        'col_end'         => 'End',
        'col_all_day'     => 'All day',
        'col_location'    => 'Location',
        'col_description' => 'Description',
        'col_created_by'  => 'Created by',
        'col_created'     => 'Created',
    ],

    'settings' => [
        'title'           => 'Calendar settings',
        'tab_categories'  => 'Categories',
        'heading'         => 'Event categories',
        'add'             => 'Add',
        'intro'           => 'Manage categories used to organise calendar events. Each category can have a custom colour for easy identification.',
        'col_name'        => 'Name',
        'col_description' => 'Description',
        'col_status'      => 'Status',
        'active'          => 'Active',
        'inactive'        => 'Inactive',
        'edit'            => 'Edit',
        'delete'          => 'Delete',
        'empty'           => 'No categories yet. Click <strong>Add</strong> to create one.',
        'load_error'      => 'Error loading categories',

        'modal_add'       => 'Add category',
        'modal_edit'      => 'Edit category',
        'modal_name'      => 'Name',
        'modal_name_ph'   => 'e.g. Certificate expiry',
        'modal_description'    => 'Description',
        'modal_description_ph' => 'Optional description...',
        'modal_colour'    => 'Colour',
        'modal_active'    => 'Active',
        'cancel'          => 'Cancel',
        'save'            => 'Save',
        'name_required'   => 'Please enter a category name',

        'delete_title'    => 'Delete category',
        'delete_confirm'  => 'Are you sure you want to delete "{name}"? This cannot be undone.',
        'delete_this'     => 'this category',

        // Left panel (per-analyst sidebar visibility)
        'tab_left_panel'          => 'Left panel',
        'left_panel_intro'        => 'Choose how the left panel behaves on the calendar. This preference is saved to your account.',
        'left_panel_visibility'   => 'Left panel visibility',
        'left_panel_always'       => 'Always visible',
        'left_panel_always_desc'  => 'Keep the left panel pinned open at all times.',
        'left_panel_hover'        => 'Show on hover',
        'left_panel_hover_desc'   => 'Collapse the left panel to a thin strip that expands when you hover over it, giving the calendar more room.',
    ],

    'toast' => [
        'saved'         => 'Saved',
        'deleted'       => 'Deleted',
        'save_failed'   => 'Failed to save',
        'delete_failed' => 'Failed to delete',
    ],

    'help' => [
        'page_title'  => 'Calendar Guide',
        'guide'       => 'Guide',
        'hero_title'  => 'Calendar guide',
        'hero_sub'    => 'Track certificates, contracts, maintenance windows, and recurring events &mdash; all in one place.',

        'nav_overview'  => 'Overview',
        'nav_views'     => 'Calendar views',
        'nav_creating'  => 'Creating events',
        'nav_categories'=> 'Event categories',
        'nav_settings'  => 'Settings',
        'nav_tips'      => 'Quick tips',

        // Section 1 — Overview
        'overview_heading' => 'Overview',
        'overview_intro'   => 'The Calendar module gives your IT team a shared timeline for everything that matters. Instead of relying on spreadsheets or personal reminders, you can track certificate expiry dates, contract renewals, scheduled maintenance windows, and team events in a single, colour-coded calendar that everyone on the service desk can see.',
        'feature_tracking_title' => 'Event tracking',
        'feature_tracking_desc'  => 'Create events with titles, dates, times, locations, and descriptions. Every event is visible to the team so nothing falls through the cracks.',
        'feature_views_title'    => 'Multiple views',
        'feature_views_desc'     => 'Switch between month, week, and day views to get the level of detail you need. The month view shows an overview; week and day views show precise time slots.',
        'feature_categories_title' => 'Categories',
        'feature_categories_desc'  => 'Organise events into colour-coded categories like certificates, contracts, maintenance, and meetings. Filter the calendar to show only what you care about.',
        'feature_scheduling_title' => 'Scheduling',
        'feature_scheduling_desc'  => 'Plan maintenance windows, set all-day events for deadlines, and schedule recurring work. The calendar helps your team coordinate and avoid conflicts.',

        // Section 2 — Views
        'views_heading' => 'Calendar views',
        'views_intro'   => 'The calendar offers three views so you can zoom in or out depending on what you need. Switch between them using the toggle buttons in the top-right corner of the calendar header.',
        'views_month_title' => 'Month view',
        'views_month_desc'  => 'The default view. Shows a full month grid with events displayed as coloured bars on each day. Ideal for getting an overview of what is coming up across the team.',
        'views_week_title'  => 'Week view',
        'views_week_desc'   => 'Displays seven days with hourly time slots. Events are positioned according to their start and end times, making it easy to spot scheduling conflicts.',
        'views_day_title'   => 'Day view',
        'views_day_desc'    => 'Focuses on a single day with detailed hourly breakdowns. Use this when you need to see exactly what is happening hour by hour during a busy day.',
        'views_nav'         => 'Use the navigation arrows next to the month/week/day title to move forwards and backwards in time. The <strong>Today</strong> button brings you straight back to the current date, no matter how far you have navigated.',
        'views_flow_today'  => 'Today button',
        'views_flow_nav'    => 'Navigate prev/next',
        'views_flow_choose' => 'Choose view',
        'views_flow_click'  => 'Click event',
        'views_tip'         => 'Click any event on the calendar to open a quick-view popup showing the title, time, location, and description. From there you can open the full edit form.',

        // Section 3 — Creating events
        'creating_heading' => 'Creating events',
        'creating_intro'   => 'Adding events to the calendar is straightforward. Click the <strong>+ New Event</strong> button in the sidebar to open the event form. Fill in the details and save &mdash; the event appears on the calendar immediately.',
        'creating_step1'   => '<strong>Click + New Event</strong> &mdash; the button is in the calendar sidebar on the left. This opens the event creation modal.',
        'creating_step2'   => '<strong>Enter a title</strong> &mdash; give the event a clear, descriptive name. For example: "SSL certificate renewal &mdash; webserver01" or "Monthly patching window".',
        'creating_step3'   => '<strong>Choose a category</strong> &mdash; select from the dropdown to colour-code the event. Categories are configured in Settings and help you filter the calendar later.',
        'creating_step4'   => '<strong>Set the dates and times</strong> &mdash; pick a start date and optionally an end date. Add start and end times for timed events, or tick "All day event" for deadlines and full-day entries.',
        'creating_step5'   => '<strong>Add location and description</strong> &mdash; optionally specify where the event takes place and add notes. These details are shown in the quick-view popup when someone clicks the event.',
        'creating_step6'   => '<strong>Save</strong> &mdash; click Save and the event is created. It appears on the calendar straight away, colour-coded by its category.',
        'creating_tip'     => 'To edit an existing event, click it on the calendar to open the popup, then click <strong>Edit</strong>. The same form opens pre-filled with the event\'s current details. You can also delete events from the edit form.',

        // Section 4 — Categories
        'categories_heading' => 'Event categories',
        'categories_intro'   => 'Categories are the backbone of calendar organisation. Each category has a name and a colour, so events are instantly recognisable at a glance. The sidebar shows all available categories with checkboxes &mdash; untick a category to hide those events from the calendar.',
        'categories_certificates' => '<strong>Certificates</strong> &mdash; track SSL/TLS certificate expiry dates, code signing certificates, and other credentials that need periodic renewal',
        'categories_contracts'    => '<strong>Contracts</strong> &mdash; log vendor contract renewal dates, licence expiry, and SLA review milestones so nothing lapses unexpectedly',
        'categories_maintenance'  => '<strong>Maintenance</strong> &mdash; schedule planned maintenance windows for servers, network equipment, and infrastructure. Your team and stakeholders can see exactly when downtime is expected',
        'categories_meetings'     => '<strong>Meetings</strong> &mdash; record team stand-ups, CAB meetings, vendor calls, and other recurring appointments relevant to IT operations',
        'categories_custom'       => '<strong>Custom categories</strong> &mdash; add your own categories in Settings to suit your team\'s workflow. Common additions include "Deployments", "Audits", and "Training"',
        'categories_filtering'    => 'Filtering is applied in real time. When you untick a category in the sidebar, events in that category are hidden immediately without reloading the page. Tick it again to bring them back.',
        'categories_tip'          => 'Colour-coding works across all three views. In month view, events show as coloured bars. In week and day views, events are displayed as coloured blocks positioned at the correct time.',

        // Section 5 — Settings
        'settings_heading' => 'Settings',
        'settings_intro'   => 'The Settings page lets you configure how the calendar works for your team. Access it by clicking <strong>Settings</strong> in the navigation bar at the top of the calendar module.',
        'settings_step1'   => '<strong>Manage categories</strong> &mdash; add, edit, or remove event categories. Each category has a name and a colour. Changes take effect immediately across the calendar for all users.',
        'settings_step2'   => '<strong>Set colours</strong> &mdash; choose a colour for each category using the colour picker. Pick distinct colours so events are easy to tell apart on a busy calendar.',
        'settings_step3'   => '<strong>Rename categories</strong> &mdash; click on a category name to edit it. Existing events assigned to that category are updated automatically.',
        'settings_step4'   => '<strong>Delete categories</strong> &mdash; remove categories you no longer need. Events in a deleted category are not removed &mdash; they remain on the calendar without a category assignment.',
        'settings_tip'     => 'Keep your category list focused. Having too many categories can make the sidebar cluttered and the colour coding harder to read. Aim for 5&ndash;10 well-defined categories that cover your team\'s needs.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Quick tips',
        'tips_maintenance_title' => 'Maintenance windows',
        'tips_maintenance_desc'  => 'Create all-day events or timed blocks for planned maintenance. Include the affected systems in the description so analysts can quickly check if an outage is expected.',
        'tips_certificates_title' => 'Certificate renewals',
        'tips_certificates_desc'  => 'Add events 30 days before each certificate expires. This gives your team enough lead time to renew without risking an outage from an expired cert.',
        'tips_contracts_title'   => 'Contract tracking',
        'tips_contracts_desc'    => 'Log contract renewal dates as all-day events. Add the vendor name and contract value in the description so the information is at hand when it is time to negotiate.',
        'tips_filters_title'     => 'Use category filters',
        'tips_filters_desc'      => 'When the calendar gets busy, untick categories you do not need. For example, hide meetings when you are only interested in upcoming maintenance windows.',
    ],
];
