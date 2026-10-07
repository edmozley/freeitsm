<?php
/**
 * Projects -> Settings, behind the SAME capability as each tab (3.2.0).
 *
 * GET                                  every setting with its default, the roles
 *                                      list, and which tabs this analyst may write
 * POST {action:'save', tab, settings:{key: value}}
 *                                      validated per key; every key must belong
 *                                      to `tab`, and `tab`'s capability is checked
 * POST {action:'role_save', id?, name, description, is_active}     Roles tab
 * POST {action:'role_delete', id}                                  Roles tab
 * POST {action:'role_reorder', ids:[...]}                          Roles tab
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/projects/settings.php';
require_once __DIR__ . '/../../includes/i18n.php';
I18n::initFromSession();

$tabCaps = [
    'general' => Cap::PROJECTS_GENERAL,
    'health'  => Cap::PROJECTS_HEALTH,
    'roles'   => Cap::PROJECTS_ROLES,
    'raid'    => Cap::PROJECTS_RAID,
];

/** The roles list with how many members hold each - for the Roles tab. */
function projectRolesForSettings(PDO $conn): array
{
    try {
        return $conn->query("SELECT r.id, r.name, r.description, r.display_order, r.is_active,
                                    (SELECT COUNT(*) FROM project_members m WHERE m.role_id = r.id) AS in_use
                               FROM project_roles r ORDER BY r.display_order, r.name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Settings as the screen shows them: each risk scale as its five words. */
function projectSettingsForScreen(PDO $conn): array
{
    $out = projectSettings($conn, true);
    foreach (['probability', 'impact'] as $scale) $out['project_' . $scale . '_labels'] = projectScaleLabels($conn, $scale);
    return $out;
}

projectApiRun(function () use ($conn, $analystId, $tabCaps) {
    $canWrite = [];
    foreach ($tabCaps as $tab => $cap) {
        try { $canWrite[$tab] = analystHasCapability($conn, $analystId, $cap); } catch (Throwable $e) { $canWrite[$tab] = false; }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $defs = [];
        foreach (projectSettingDefinitions() as $k => $d) $defs[$k] = ['default' => $d[0], 'tab' => $d[2]];
        foreach (['probability', 'impact'] as $scale) $defs['project_' . $scale . '_labels']['default'] = projectScaleDefaults($scale);
        projectApiOk([
            'settings'    => projectSettingsForScreen($conn),
            'definitions' => $defs,
            'can_write'   => $canWrite,
            'roles'       => projectRolesForSettings($conn),
        ]);
    }

    $in = projectApiBody();
    $action = (string)($in['action'] ?? 'save');

    if (strpos($action, 'role_') === 0) {
        if (empty($canWrite['roles'])) projectApiFail('You do not have permission to change project roles.', 403);
        if ($action === 'role_save') {
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) projectApiFail('Give the role a name of up to 100 characters.');
            $desc = mb_substr(trim((string)($in['description'] ?? '')), 0, 255) ?: null;
            $active = !empty($in['is_active']) ? 1 : 0;
            $id = (int)($in['id'] ?? 0);
            $dup = $conn->prepare("SELECT id FROM project_roles WHERE name = ? AND id <> ?");
            $dup->execute([$name, $id]);
            if ($dup->fetchColumn()) projectApiFail('There is already a role with that name.');
            if ($id > 0) {
                $conn->prepare("UPDATE project_roles SET name = ?, description = ?, is_active = ? WHERE id = ?")->execute([$name, $desc, $active, $id]);
            } else {
                $pos = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM project_roles")->fetchColumn();
                $conn->prepare("INSERT INTO project_roles (name, description, display_order, is_active) VALUES (?, ?, ?, ?)")->execute([$name, $desc, $pos, $active]);
            }
            projectApiOk(['roles' => projectRolesForSettings($conn)]);
        }
        if ($action === 'role_delete') {
            // Members who held it keep their place on the project with no role
            // (the FK is SET NULL) - deleting a word never removes a person.
            $conn->prepare("DELETE FROM project_roles WHERE id = ?")->execute([(int)($in['id'] ?? 0)]);
            projectApiOk(['roles' => projectRolesForSettings($conn)]);
        }
        if ($action === 'role_reorder') {
            $st = $conn->prepare("UPDATE project_roles SET display_order = ? WHERE id = ?");
            $pos = 1;
            foreach ((array)($in['ids'] ?? []) as $rid) $st->execute([$pos++, (int)$rid]);
            projectApiOk(['roles' => projectRolesForSettings($conn)]);
        }
        projectApiFail('Unknown action.');
    }

    if ($action !== 'save') projectApiFail('Unknown action.');
    $tab = (string)($in['tab'] ?? '');
    if (!isset($tabCaps[$tab])) projectApiFail('Unknown settings tab.');
    if (empty($canWrite[$tab])) projectApiFail('You do not have permission to change these settings.', 403);

    $defs = projectSettingDefinitions();
    $clean = [];
    foreach ((array)($in['settings'] ?? []) as $key => $value) {
        if (!isset($defs[$key]) || $defs[$key][2] !== $tab) projectApiFail("That setting does not belong on this tab: $key");
        try { $clean[$key] = projectSettingValidate($key, $value); }
        catch (InvalidArgumentException $e) { projectApiFail($e->getMessage()); }
    }
    foreach ($clean as $k => $v) projectSettingWrite($conn, $k, $v);
    projectApiOk(['settings' => projectSettingsForScreen($conn)]);
});
