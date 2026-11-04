<?php
/**
 * Per-company settings, falling back to the install-wide value.
 *
 * ⚠️ WHY THIS EXISTS. `system_settings` is a flat key/value table for the WHOLE
 * install, and `getTenantConfigRows()` handles per-company LOOKUP LISTS (statuses,
 * ticket types). Neither can express "this company bills for time and that one
 * does not" — so there was no way to answer a per-company yes/no question at all.
 *
 * The shape is deliberately the only sensible one:
 *
 *     tenant_settings   the answer for ONE company, when it has been given
 *          ↓ falls back to
 *     system_settings   the install-wide default
 *          ↓ falls back to
 *     the caller's default
 *
 * 🔑 A COMPANY WITH NO ROW IS NOT "OFF". It is "whatever the install says", which
 * is what makes the default meaningful and what keeps this invisible on a
 * single-company install: one toggle, no company column, nothing new to learn.
 *
 * ⚠️ Built for ONE setting and expected to serve many. "Per company, defaulting to
 * the install-wide value" is the shape most settings take once an install has more
 * than one company in it, so this is deliberately generic rather than a
 * time-tracking flag with a table around it.
 */

require_once __DIR__ . '/tenancy.php';

/**
 * The value of a setting for one company.
 *
 * @param ?int $tenantId  null = ask the install-wide value only
 * @param string $key  the setting key, the same name in tenant_settings and system_settings
 * @param ?string $default  returned when neither the company nor the install has a value
 */
function tenantSetting(PDO $conn, ?int $tenantId, string $key, ?string $default = null): ?string
{
    $cache = &tenantSettingCache();
    $ck = ($tenantId ?? 0) . '|' . $key;
    if (array_key_exists($ck, $cache)) return $cache[$ck];

    $value = null;

    // 1. The company's own answer, if it has one and companies exist at all.
    if ($tenantId !== null && $tenantId > 0) {
        try {
            if (isMultiTenant($conn) || tenancyTablesReady($conn)) {
                $st = $conn->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
                $st->execute([$tenantId, $key]);
                $row = $st->fetchColumn();
                if ($row !== false) $value = (string) $row;
            }
        } catch (Throwable) {
            // Table absent on a part-migrated install: fall through to the
            // install-wide value rather than failing the page.
        }
    }

    // 2. The install-wide default.
    if ($value === null) {
        try {
            $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $st->execute([$key]);
            $row = $st->fetchColumn();
            if ($row !== false) $value = (string) $row;
        } catch (Throwable) { /* fall through */ }
    }

    // 3. The caller's default.
    if ($value === null) $value = $default;

    $cache[$ck] = $value;
    return $value;
}

/**
 * The per-request cache behind tenantSetting(). A function rather than a
 * `static` inside tenantSetting() so it can be emptied from outside: a page
 * that saves a setting and then reads it back (PR #164's label settings) must
 * see the new value, and so must a test.
 */
function &tenantSettingCache(): array
{
    static $cache = [];
    return $cache;
}

/** Forget every cached setting - after a write, or between test cases. */
function tenantSettingForget(): void
{
    $cache = &tenantSettingCache();
    $cache = [];
}

/**
 * Convenience for a yes/no setting. Anything but '0' is on.
 *
 * @param string $key  the setting key, the same name in tenant_settings and system_settings
 * @param bool $default  the answer when neither the company nor the install has a value
 */
function tenantSettingOn(PDO $conn, ?int $tenantId, string $key, bool $default = true): bool
{
    $v = tenantSetting($conn, $tenantId, $key, $default ? '1' : '0');
    return $v !== '0';
}

/**
 * Set (or clear) one company's answer.
 *
 * ⚠️ A NULL value DELETES the row rather than storing an empty string, so the
 * company goes back to following the install-wide default. "Not set" and "set to
 * nothing" have to stay distinguishable, or a company can never be handed back to
 * the default once it has been given an answer.
 *
 * @param string $key  the setting key
 * @param ?string $value  the company's answer; null deletes it, so the company follows the install-wide value again
 */
function setTenantSetting(PDO $conn, int $tenantId, string $key, ?string $value): void
{
    tenantSettingForget();   // a read later in this request must see the new value
    if ($value === null) {
        $conn->prepare("DELETE FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?")
             ->execute([$tenantId, $key]);
        return;
    }
    $conn->prepare(
        "INSERT INTO tenant_settings (tenant_id, setting_key, setting_value)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()"
    )->execute([$tenantId, $key, $value]);
}

/**
 * Every company's answer for one key, for rendering a settings table.
 *
 * @param string $key  the setting key
 * @return array<int,string> tenant_id => value, containing ONLY companies that
 *                           have their own answer. Anything absent follows the
 *                           install default, which the caller shows separately.
 */
function tenantSettingsForKey(PDO $conn, string $key): array
{
    $out = [];
    try {
        $st = $conn->prepare("SELECT tenant_id, setting_value FROM tenant_settings WHERE setting_key = ?");
        $st->execute([$key]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['tenant_id']] = (string) $r['setting_value'];
        }
    } catch (Throwable) { /* table absent — everyone follows the default */ }
    return $out;
}

// ─── Time tracking (discussion #72) ─────────────────────────────────────────
// Two separate answers, deliberately. Hiding the panel is about interface
// clutter; silently emptying an API endpoint breaks integrations belonging to
// people who changed nothing. They are different decisions, so they are
// different switches.

const SETTING_TIME_TRACKING_UI  = 'time_tracking_enabled';
const SETTING_TIME_TRACKING_API = 'time_tracking_api_enabled';

/**
 * Should the time-recording UI appear for a ticket belonging to this company?
 *
 * Both answers are no while Time tracking is switched off for the whole install
 * (GH #217), whatever the company or the install-wide setting below says: that
 * switch sits above these two, and switching back on restores what they said.
 */
function timeTrackingUiOn(PDO $conn, ?int $tenantId): bool
{
    if (function_exists('moduleIsEnabled') && !moduleIsEnabled($conn, 'time-tracking')) return false;
    return tenantSettingOn($conn, $tenantId, SETTING_TIME_TRACKING_UI, true);
}

/** Should the REST API serve time entries for a ticket belonging to this company? */
function timeTrackingApiOn(PDO $conn, ?int $tenantId): bool
{
    if (function_exists('moduleIsEnabled') && !moduleIsEnabled($conn, 'time-tracking')) return false;   // GH #217
    return tenantSettingOn($conn, $tenantId, SETTING_TIME_TRACKING_API, true);
}

// ─── Ticket classification fields (#1540) ───────────────────────────────────
// Whether each of the three fields appears on a ticket at all. Same shape as
// time tracking above — per company, over an install-wide default.
//
// ⚠️ THESE DEFAULT TO OFF, where time tracking defaults to ON. An upgrade must
// not grow three empty dropdowns on everybody's ticket page before they have
// created a single category. You build the list, then you switch it on.
//
// 🔑 Deliberately INDEPENDENT of each other. "Category off, category-at-close on"
// is a real service desk: don't make the person raising it guess, let the analyst
// classify once they actually know. So nothing here implies anything about
// anything else.

const SETTING_TICKET_CATEGORY         = 'ticket_category_enabled';
const SETTING_TICKET_CLOSURE_CATEGORY = 'ticket_closure_category_enabled';
const SETTING_TICKET_RESOLUTION_CODE  = 'ticket_resolution_code_enabled';

// ---------------------------------------------------------------------------
// SOP checklists: what happens when a ticket with outstanding mandatory steps
// is closed (PR #141).
//
// 🔑 A setting rather than a decision taken for everybody. An MSP running ISO
// procedures for one client and best-effort support for another needs both
// answers on one install, which is why it resolves per company over an
// install-wide default like the classification switches above.
//
// WARN is the default, matching what tasks already do on the same screen:
// "closing a ticket that still has unfinished tasks WARNS, it never blocks …
// a warning that cannot be shown must not become a block that cannot be
// cleared." Either way the skipped steps are recorded on the ticket - the
// setting decides whether the analyst may proceed, never whether it is logged.
const SETTING_TICKET_CHECKLIST_CLOSURE = 'ticket_checklist_closure_mode';
const SETTING_TICKET_CHECKLIST_EMPTY_CLOSURE = 'ticket_checklist_empty_closure_mode';

/** 'warn' (default) or 'block' — how closure with outstanding mandatory steps behaves. */
function ticketChecklistClosureMode(PDO $conn, ?int $tenantId): string
{
    $v = tenantSetting($conn, $tenantId, SETTING_TICKET_CHECKLIST_CLOSURE, 'per_template');
    if ($v === 'block' || $v === 'block_all') {
        return 'block_all';
    }
    return 'per_template';
}

/** 'off' (default), 'warn', or 'block' — when closing a ticket with no SOP attached. */
function ticketChecklistEmptyClosureMode(PDO $conn, ?int $tenantId): string
{
    $v = tenantSetting($conn, $tenantId, SETTING_TICKET_CHECKLIST_EMPTY_CLOSURE, 'off');
    if ($v === 'block') {
        return 'block';
    }
    if ($v === 'warn') {
        return 'warn';
    }
    return 'off';
}

// ---------------------------------------------------------------------------
// Mandatory fields at closure. Which ticket properties must hold a value before
// a ticket may close, and what happens when they do not. The rule itself lives
// in includes/services/mandatory_fields.php; these are only the stored answers.
//
// Same shape as the checklist closure mode above: install-wide today, read
// through tenantSetting() so a per-company column can be added to the tab later
// without touching the reading side.
const SETTING_TICKET_MANDATORY_FIELDS = 'ticket_mandatory_fields';   // comma-separated field keys
const SETTING_TICKET_MANDATORY_MODE   = 'ticket_mandatory_mode';     // warn | notify | block
const SETTING_TICKET_MANDATORY_NOTIFY = 'ticket_mandatory_notify';   // comma-separated addresses
const SETTING_TICKET_MANDATORY_RECORD = 'ticket_mandatory_record';   // '1' | '0'

/** Should the "reported as" category field show on a ticket for this company? */
function ticketCategoryOn(PDO $conn, ?int $tenantId): bool
{
    return tenantSettingOn($conn, $tenantId, SETTING_TICKET_CATEGORY, false);
}

/** Should the "turned out to be" category field show when closing a ticket? */
function ticketClosureCategoryOn(PDO $conn, ?int $tenantId): bool
{
    return tenantSettingOn($conn, $tenantId, SETTING_TICKET_CLOSURE_CATEGORY, false);
}

/** Should the resolution code field show when closing a ticket? */
function ticketResolutionCodeOn(PDO $conn, ?int $tenantId): bool
{
    return tenantSettingOn($conn, $tenantId, SETTING_TICKET_RESOLUTION_CODE, false);
}

/**
 * All three answers at once, for a page that is about to render a ticket.
 *
 * One call rather than three so a caller cannot accidentally resolve two of them
 * against one company and the third against another.
 *
 * @return array{category:bool, closure_category:bool, resolution_code:bool}
 */
function ticketClassificationSettings(PDO $conn, ?int $tenantId): array
{
    return [
        'category'         => ticketCategoryOn($conn, $tenantId),
        'closure_category' => ticketClosureCategoryOn($conn, $tenantId),
        'resolution_code'  => ticketResolutionCodeOn($conn, $tenantId),
        // GH #218: travels with the other field switches, so a board of several
        // companies can show Department for one ticket and hide it for the next.
        'department'       => ticketDepartmentShown($conn, $tenantId),
    ];
}

// ---------------------------------------------------------------------------
// Contractor fields on tasks (GH #218 part A). Whether the task screen shows the
// Contractor and Contact there pair. PRESENTATION ONLY: Projects and the REST API
// still assign contractors, and a task that already has one keeps it and shows it
// read-only. Switching contractors off for real is the Contracts module's switch
// (#217). Default on - nothing changes until someone hides them.
const SETTING_TASK_CONTRACTOR_FIELDS = 'tasks_contractor_fields';

/** Should the task screen offer the Contractor and Contact there fields for this company? */
function taskContractorFieldsOn(PDO $conn, ?int $tenantId): bool
{
    return tenantSettingOn($conn, $tenantId, SETTING_TASK_CONTRACTOR_FIELDS, true);
}

// ---------------------------------------------------------------------------
// Department on tickets (GH #218 part A). Two answers per company:
//   shown    - whether the Department field is on the ticket screens (default yes);
//   default  - the department a NEW ticket starts in when nothing else gave it one.
// Hiding is presentation only: a ticket keeps its department, and folders, routing,
// SLA rules and workflows all still read it (departments never decide who may open a ticket). Guards that stop a
// hidden Department conflicting with Mandatory fields, or a default pointing at a
// retired department, live in includes/ticket_department_rules.php.
const SETTING_TICKET_DEPARTMENT_SHOWN   = 'ticket_department_shown';
const SETTING_TICKET_DEPARTMENT_DEFAULT = 'ticket_department_default';

/** Is the Department field shown on tickets of this company? */
function ticketDepartmentShown(PDO $conn, ?int $tenantId): bool
{
    return tenantSettingOn($conn, $tenantId, SETTING_TICKET_DEPARTMENT_SHOWN, true);
}

/** The company's default department id as stored, or null. Not checked - see ticketDepartmentForNewTicket(). */
function ticketDefaultDepartmentId(PDO $conn, ?int $tenantId): ?int
{
    $v = (int) tenantSetting($conn, $tenantId, SETTING_TICKET_DEPARTMENT_DEFAULT, '');
    return $v > 0 ? $v : null;
}

/**
 * The department a NEW ticket starts in, for every channel that creates one.
 *
 * A department the channel itself set always wins (an invalid one is the caller's
 * to refuse - never quietly swapped for the default). Otherwise the company's
 * default, checked AGAIN here: one no longer active is not applied, and the note
 * returned goes on the ticket's history, so a stale setting never silently leaves
 * tickets with no department (and so out of every department folder and rule).
 *
 * @param mixed $explicit  the department the channel set: an id, or null / '' for none
 * @return array{0: ?int, 1: ?string}  [the department to store, a history note or null]
 */
function ticketDepartmentForNewTicket(PDO $conn, ?int $tenantId, $explicit): array
{
    if ($explicit !== null && $explicit !== '' && (int) $explicit > 0) return [(int) $explicit, null];
    $id = ticketDefaultDepartmentId($conn, $tenantId);
    if ($id === null) return [null, null];
    try {
        $st = $conn->prepare("SELECT name, is_active FROM departments WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [null, null];
    }
    if ($row && (int) $row['is_active'] === 1) return [$id, null];
    $name = $row ? (string) $row['name'] : ('#' . $id);
    return [null, "Default department '{$name}' is no longer active, so it was not applied."];
}

/** Writes ticketDepartmentForNewTicket()'s note on a new ticket's history. Never throws. */
function ticketDepartmentNote(PDO $conn, int $ticketId, ?string $note): void
{
    if ($note === null) return;
    try {
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                        VALUES (?, NULL, 'Department', NULL, ?, UTC_TIMESTAMP())")->execute([$ticketId, $note]);
    } catch (Throwable $e) {
        error_log('[department-default] could not note ticket ' . $ticketId . ': ' . $e->getMessage());
    }
}

/** The company a ticket belongs to, or null. Used to resolve both of the above. */
function ticketTenantId(PDO $conn, int $ticketId): ?int
{
    try {
        $st = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
        $st->execute([$ticketId]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    } catch (Throwable) {
        return null;
    }
}
