<?php
/**
 * Preference templates (discussion #124, Kraleemil): named sets of preferences an
 * administrator can give to people - "Service desk", "Engineers" - so a team can
 * start from the same setup instead of each person finding every setting alone.
 *
 * How it works, and why:
 *  - A template is CAPTURED from somebody's real preferences (usually the admin's
 *    own, set up on the Preferences page first), not typed in. Every control on
 *    the Preferences page is the editor, so nothing here needs a second copy of
 *    the timezone list, the module order or the sidebar picker.
 *  - Only the SECTIONS ticked go in. A section is a heading on the Preferences
 *    page; each holds one or more user_preferences keys (PREF_TEMPLATE_SECTIONS).
 *  - APPLYING copies those keys into each chosen analyst's user_preferences. It
 *    is a one-off copy: afterwards everyone can still change their own. Nothing
 *    that READS a preference changed - the 33 places that read them still read
 *    user_preferences, which is why this needed no change to any of them.
 *  - One template can be marked "for new analysts": prefTemplatesApplyForNew()
 *    gives it to an analyst the moment they are created (every create path calls
 *    it - the analyst screen, the REST API, LDAP and SSO).
 *
 * A captured key the source had never set is stored as null and means "back to
 * the built-in default": applying it DELETES the target's own row, so applying a
 * template always leaves people exactly as the source was.
 *
 * Personal sections are deliberately not offered: My details, Signature and
 * Working hours (each analyst's own calendar feed).
 *
 * @wiki Preference-Templates
 */

/**
 * The Preferences page sections a template can hold: section id (the anchor on
 * the Preferences page) => [heading translation key, [user_preferences keys]].
 * Listed in the order the Preferences page shows them.
 *
 * TRAP: a new setting on the Preferences page is NOT in templates until its key
 * is added here. The test (tests/preference-templates.php) fails when a key the
 * Preferences page saves is in neither this list nor PREF_TEMPLATE_PERSONAL.
 */
const PREF_TEMPLATE_SECTIONS = [
    'language'       => ['system.preferences.language_heading',        ['interface_language']],
    'timezone'       => ['system.preferences.timezone_heading',        ['timezone']],
    'date-format'    => ['system.preferences.dateformat_heading',      ['date_format']],
    'time-format'    => ['system.preferences.timeformat_heading',      ['time_format']],
    'money'          => ['system.preferences.money_heading',           ['currency_format', 'currency_formats']],
    'task-view'      => ['system.preferences.task_view_heading',       ['tasks_detail_view']],
    'ticket-list'    => ['system.preferences.ticket_list_heading',     ['tickets_list_layout']],
    'ticket-layout'  => ['system.preferences.ticket_layout_heading',   ['tickets_detail_layout']],
    'search-panel'   => ['system.preferences.search_dismiss_heading',  ['search_panel_close_outside']],
    'cal-subtasks'   => ['system.preferences.cal_subtasks_heading',    ['tasks_calendar_subtasks']],
    'board-subtasks' => ['system.preferences.board_subtasks_heading',  ['tasks_board_subtasks', 'tasks_hide_done_subtasks']],
    'start-page'     => ['system.preferences.landing_heading',         ['default_landing_page']],
    'watchtower'     => ['watchtower.scope.impersonal_heading',        ['watchtower_impersonal']],
    'notifications'  => ['system.preferences.notif_heading',           ['notification_types']],
    'sound'          => ['system.preferences.sound_heading',           ['notification_sound']],
    'desktop'        => ['system.preferences.desktop_heading',         ['desktop_notifications']],
    'task-email'     => ['system.preferences.task_email_heading',      ['task_assigned_email']],
    'position'       => ['system.preferences.position_heading',        ['toast_position']],
    'animation'      => ['system.preferences.animation_heading',       ['toast_animation']],
    'module-order'   => ['system.preferences.modorder_heading',        ['module_order']],
    'app-sidebar'    => ['system.preferences.sidebar_heading',         ['app_sidebar', 'app_sidebar_modules', 'app_sidebar_names']],
    'left-panels'    => ['system.preferences.panels_heading',          ['knowledge_sidebar_mode', 'process_mapper_sidebar_mode', 'contracts_sidebar_mode',
                                                                         'calendar_sidebar_mode', 'tasks_sidebar_mode', 'cmdb_sidebar_mode',
                                                                         'change_management_sidebar_mode', 'asset_management_sidebar_mode',
                                                                         'system_wiki_sidebar_mode']],
    'multi-select'   => ['system.preferences.multiselect_heading',     ['tickets_multiselect_pane']],
    'morning-checks' => ['system.preferences.mc_heading',              ['mc_chart_fill_style']],
];

/** Preferences page keys that are each person's own and never go in a template. */
const PREF_TEMPLATE_PERSONAL = ['tickets_schedule_feed_detail'];

/** Every user_preferences key a template may hold. */
function prefTemplateKeys(): array
{
    $keys = [];
    foreach (PREF_TEMPLATE_SECTIONS as [, $k]) $keys = array_merge($keys, $k);
    return $keys;
}

/**
 * Read a template row into its usable shape.
 *
 * @param array $row  a preference_templates row
 * @return array  id, name, description, for_new_analysts (bool), settings (key => ?string),
 *                sections (the section ids it holds, in page order), updated_datetime
 */
function prefTemplateShape(array $row): array
{
    $settings = json_decode((string)($row['settings'] ?? ''), true);
    $settings = is_array($settings) ? $settings : [];
    // Keep only keys still in the map: a key retired from the Preferences page
    // must not be written back to anyone.
    $settings = array_intersect_key($settings, array_flip(prefTemplateKeys()));
    $sections = [];
    foreach (PREF_TEMPLATE_SECTIONS as $id => [, $keys]) {
        if (array_intersect($keys, array_keys($settings))) $sections[] = $id;
    }
    return [
        'id'               => (int)$row['id'],
        'name'             => (string)$row['name'],
        'description'      => (string)($row['description'] ?? ''),
        'for_new_analysts' => (int)($row['for_new_analysts'] ?? 0) === 1,
        'settings'         => $settings,
        'sections'         => $sections,
        'updated_datetime' => $row['updated_datetime'] ?? $row['created_datetime'] ?? null,
    ];
}

/**
 * Capture the given sections from one analyst's preferences.
 *
 * @param PDO $conn  the database
 * @param int $analystId  whose preferences to copy
 * @param array $sectionIds  section ids from PREF_TEMPLATE_SECTIONS; unknown ones are ignored
 * @return array  key => value, with null for a key the analyst has never set
 */
function prefTemplateCapture(PDO $conn, int $analystId, array $sectionIds): array
{
    $keys = [];
    foreach ($sectionIds as $id) {
        if (isset(PREF_TEMPLATE_SECTIONS[$id])) $keys = array_merge($keys, PREF_TEMPLATE_SECTIONS[$id][1]);
    }
    if (!$keys) return [];
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $conn->prepare("SELECT preference_key, preference_value FROM user_preferences
                           WHERE analyst_id = ? AND preference_key IN ($in)");
    $st->execute(array_merge([$analystId], $keys));
    $have = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($keys as $k) $out[$k] = array_key_exists($k, $have) ? $have[$k] : null;
    return $out;
}

/**
 * Give a template's settings to analysts: each key is written to their
 * user_preferences, or removed where the template holds null (the built-in
 * default). One transaction, so a failure leaves nobody half-applied.
 *
 * @param PDO $conn  the database
 * @param array $template  a prefTemplateShape() result
 * @param array $analystIds  who gets it
 * @return int  how many analysts were updated
 */
function prefTemplateApply(PDO $conn, array $template, array $analystIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $analystIds), fn($i) => $i > 0)));
    if (!$ids || !$template['settings']) return 0;
    $set = $conn->prepare(
        "INSERT INTO user_preferences (analyst_id, preference_key, preference_value, updated_datetime)
         VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_datetime = UTC_TIMESTAMP()"
    );
    $del = $conn->prepare("DELETE FROM user_preferences WHERE analyst_id = ? AND preference_key = ?");
    // An analyst-create path may already be inside a transaction; join it rather
    // than start a second one, which PDO refuses.
    $own = !$conn->inTransaction();
    if ($own) $conn->beginTransaction();
    try {
        foreach ($ids as $aid) {
            foreach ($template['settings'] as $key => $value) {
                if ($value === null) $del->execute([$aid, $key]);
                else $set->execute([$aid, $key, (string)$value]);
            }
        }
        if ($own) $conn->commit();
    } catch (Throwable $e) {
        if ($own) $conn->rollBack();
        throw $e;
    }
    return count($ids);
}

/**
 * Give a newly created analyst the template marked "for new analysts", if there
 * is one. Never throws: a missing table or a bad template must not stop an
 * analyst being created.
 *
 * @param PDO $conn  the database
 * @param int $analystId  the analyst just created
 */
function prefTemplatesApplyForNew(PDO $conn, int $analystId): void
{
    if ($analystId <= 0) return;
    try {
        $row = $conn->query("SELECT * FROM preference_templates WHERE for_new_analysts = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) prefTemplateApply($conn, prefTemplateShape($row), [$analystId]);
    } catch (Throwable $e) {
        error_log('[preference_templates] new analyst ' . $analystId . ': ' . $e->getMessage());
    }
}
