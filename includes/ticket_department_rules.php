<?php
/**
 * The rules that keep the Department settings honest (GH #218 part A).
 *
 * Two settings per company (tenant_settings.php): whether the field is SHOWN, and
 * the DEFAULT department for new tickets. Three things must never happen:
 *
 *   1. Department hidden while Mandatory fields requires it at closure. A hidden
 *      field nobody can fill would trap tickets, and a presentation setting must
 *      not quietly switch a separately configured rule off - so the combination is
 *      refused, from whichever screen tries to create it.
 *   2. A default that is not an active department.
 *   3. A department deactivated or deleted while it is somebody's default - which
 *      would silently leave new tickets with none. Refused until the default is
 *      replaced or explicitly cleared.
 *
 * Stored values: shown '1' / '0'; default a department id, '0' for an explicit
 * "no default" (distinct from no row, which follows the install-wide value).
 *
 * Departments decide folders, rules and routing - NOT who may open a ticket.
 *
 * @wiki Form-Fields-Design
 */

require_once __DIR__ . '/tenant_settings.php';
require_once __DIR__ . '/services/mandatory_fields.php';

/**
 * Every place a setting can be answered: the install (id null) and, on a
 * multi-company install, each company.
 *
 * @return array<int, array{id: ?int, name: string}>
 */
function ticketDepartmentPlaces(PDO $conn): array
{
    $places = [['id' => null, 'name' => '']];
    if (isMultiTenant($conn)) {
        foreach (getAllTenants($conn) as $t) $places[] = ['id' => (int) $t['id'], 'name' => (string) $t['name']];
    }
    return $places;
}

/**
 * Is Department required at closure for this company? (Mandatory fields is
 * install-wide today, read through tenantSetting() so this stays right if it
 * becomes per company.)
 */
function ticketDepartmentRequiredAtClose(PDO $conn, ?int $tenantId): bool
{
    return in_array('department', MandatoryFieldsService::settings($conn, $tenantId)['fields'], true);
}

/**
 * The companies (by name) where Department would be hidden AND required at closure,
 * given a proposed change. '' in the result stands for the install itself on a
 * single-company install.
 *
 * @param ?bool $default        proposed install-wide "shown", or null for unchanged
 * @param array $companyShown   proposed per-company "shown": tenant id => true|false|null (null = follow)
 * @param ?bool $requiredOverride  proposed "Department required at closure" for everyone, or null for as stored
 * @return string[]
 */
function ticketDepartmentConflicts(PDO $conn, ?bool $default, array $companyShown, ?bool $requiredOverride = null): array
{
    $installShown = $default ?? ticketDepartmentShown($conn, null);
    $stored = tenantSettingsForKey($conn, SETTING_TICKET_DEPARTMENT_SHOWN);
    $out = [];
    foreach (ticketDepartmentPlaces($conn) as $p) {
        if ($p['id'] === null) {
            // The install row only matters by itself on a single-company install;
            // with companies, each company is checked instead.
            if (isMultiTenant($conn)) continue;
            $shown = $installShown;
        } else {
            $tid = $p['id'];
            if (array_key_exists($tid, $companyShown)) $v = $companyShown[$tid];
            else $v = array_key_exists($tid, $stored) ? $stored[$tid] !== '0' : null;
            $shown = $v === null ? $installShown : (bool) $v;
        }
        $required = $requiredOverride ?? ticketDepartmentRequiredAtClose($conn, $p['id']);
        if (!$shown && $required) $out[] = $p['name'];
    }
    return $out;
}

/**
 * Where a department is the default for new tickets: '' for the install, or the
 * company names. Empty when it is nobody's default.
 *
 * @return string[]
 */
function ticketDepartmentDefaultUses(PDO $conn, int $departmentId): array
{
    $out = [];
    $install = (int) tenantSetting($conn, null, SETTING_TICKET_DEPARTMENT_DEFAULT, '');
    if ($install === $departmentId) $out[] = '';
    $names = [];
    foreach (ticketDepartmentPlaces($conn) as $p) if ($p['id'] !== null) $names[$p['id']] = $p['name'];
    foreach (tenantSettingsForKey($conn, SETTING_TICKET_DEPARTMENT_DEFAULT) as $tid => $v) {
        if ((int) $v === $departmentId) $out[] = $names[$tid] ?? ('#' . $tid);
    }
    return $out;
}

/** "Acme, Beta and the install-wide default" from ticketDepartmentDefaultUses()/Conflicts() output. */
function ticketDepartmentPlaceList(array $names): string
{
    $named = array_values(array_filter($names, fn($n) => $n !== ''));
    if (count($named) < count($names)) $named[] = 'the install-wide setting';
    return implode(', ', $named);
}
