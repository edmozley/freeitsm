<?php
/**
 * AnalystsService — creating analysts and setting what they may do (3.5.0).
 *
 * Built for the REST API's analyst provisioning (PR #174, from @Kraleemil's
 * design): an external identity source - an HR feed, an OIDC provider that owns
 * staff membership - keeps analysts and their access in step with it.
 *
 * ACCESS has four parts, each with an "all" switch and a specific list:
 *   - admin         analysts.is_admin (System module, everything administrative)
 *   - modules       analysts.can_access_all_modules + analyst_modules
 *   - companies     analysts.can_access_all_tenants + analyst_tenant_access
 *   - capabilities  RBAC settings capabilities, held on ONE role per analyst that
 *                   this service owns (rbac_roles.managed_for_analyst_id). Roles
 *                   assigned by hand in System -> Roles and team grants are never
 *                   touched; System -> Roles shows the managed one read-only.
 *
 * 🔴 WHO MAY CALL THIS is the caller's decision, not this file's. In the app only
 * administrators manage analysts (admin_api_guard.php), and the REST adapter
 * (api/v1/resources/analysts.php) holds keys to the same rule. The rules below
 * are the ones that hold whoever calls:
 *   - never remove or deactivate the LAST active administrator (the same guard
 *     System -> Analysts has), which would lock everyone out of System;
 *   - an actor never deactivates or demotes themselves;
 *   - module, company and capability keys are validated - a typo is refused,
 *     never silently dropped;
 *   - a new analyst starts with NOTHING: no modules, no companies, no admin, and a
 *     local password nobody knows (they sign in through their provider).
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../rbac.php';

final class AnalystsService
{
    /**
     * The analyst row, or not_found.
     *
     * @param int $id  the analyst to load
     */
    public static function load(PDO $conn, int $id): array
    {
        $s = $conn->prepare("SELECT * FROM analysts WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Analyst not found.');
        }
        return $row;
    }

    /**
     * Create an analyst. $in: email (required), full_name, username (made unique),
     * auth_provider_id. Returns the new id.
     *
     * @param array $in  the request fields: email (required), full_name, username, auth_provider_id
     */
    public static function create(PDO $conn, ActorContext $ctx, array $in): int
    {
        $email = self::cleanEmail($in['email'] ?? null);
        if ($email === null) {
            throw new ServiceError('validation', 'missing_field', "'email' is required.");
        }
        self::assertEmailFree($conn, $email, 0);

        $fullName = trim((string)($in['full_name'] ?? ''));
        $fullName = $fullName !== '' ? mb_substr($fullName, 0, 100) : ucfirst(explode('@', $email)[0]);
        $providerId = self::providerId($conn, $in['auth_provider_id'] ?? null);
        $username = self::uniqueUsername($conn, (string)($in['username'] ?? ''), $email);

        // Nothing until it is granted: no modules, no companies on a multi-company
        // install (the column defaults to every company), no admin.
        $allTenants = isMultiTenant($conn) ? 0 : 1;
        $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $conn->prepare(
            "INSERT INTO analysts (username, password_hash, full_name, email, is_active, auth_provider_id,
                                   is_admin, can_access_all_modules, can_access_all_tenants, created_datetime, last_modified_datetime)
             VALUES (?, ?, ?, ?, 1, ?, 0, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$username, $unusable, $fullName, $email, $providerId, $allTenants]);
        $newId = (int)$conn->lastInsertId();
        // The "for new analysts" preference template (discussion #124).
        require_once __DIR__ . '/../preference_templates.php';
        prefTemplatesApplyForNew($conn, $newId);
        return $newId;
    }

    /**
     * Change an analyst. $in: any of full_name, email, is_active, auth_provider_id.
     * Setting auth_provider_id switches off "Follow team" (GH #41), which would
     * otherwise replace it on the next team change.
     *
     * @param int $id  the analyst to change
     * @param array $in  the fields to change, any of full_name, email, is_active, auth_provider_id; absent keys are left alone
     */
    public static function update(PDO $conn, ActorContext $ctx, int $id, array $in): void
    {
        $a = self::load($conn, $id);
        $sets = [];
        $args = [];

        if (array_key_exists('full_name', $in)) {
            $name = trim((string)$in['full_name']);
            if ($name === '') {
                throw new ServiceError('validation', 'invalid_field', "'full_name' cannot be empty.");
            }
            $sets[] = 'full_name = ?';
            $args[] = mb_substr($name, 0, 100);
        }
        if (array_key_exists('email', $in)) {
            $email = self::cleanEmail($in['email']);
            if ($email === null) {
                throw new ServiceError('validation', 'invalid_field', "'email' cannot be empty.");
            }
            self::assertEmailFree($conn, $email, $id);
            $sets[] = 'email = ?';
            $args[] = $email;
        }
        if (array_key_exists('is_active', $in)) {
            $active = self::flag($in['is_active'], 'is_active');
            if (!$active && (int)$a['is_active'] === 1) {
                if ($id === $ctx->actorId) {
                    throw new ServiceError('validation', 'invalid_field', 'You cannot deactivate yourself.');
                }
                if ((int)$a['is_admin'] === 1) {
                    self::assertNotLastAdmin($conn, $id);
                }
            }
            $sets[] = 'is_active = ?';
            $args[] = $active ? 1 : 0;
        }
        if (array_key_exists('auth_provider_id', $in)) {
            $sets[] = 'auth_provider_id = ?';
            $args[] = self::providerId($conn, $in['auth_provider_id']);
            if (array_key_exists('auth_follow_team', $a)) {
                $sets[] = 'auth_follow_team = 0';
            }
        }
        if (!$sets) {
            throw new ServiceError('validation', 'missing_field', "Send at least one of 'full_name', 'email', 'is_active', 'auth_provider_id'.");
        }
        $args[] = $id;
        $conn->prepare("UPDATE analysts SET " . implode(', ', $sets) . ", last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute($args);
        if (array_key_exists('is_active', $in) && !self::flag($in['is_active'], 'is_active') && (int)$a['is_active'] === 1) {
            require_once __DIR__ . '/ticket_watchers.php';
            TicketWatchers::analystDeactivated($conn, $id);   // the 'remove' setting (Tickets -> Settings -> General)
        }
    }

    /**
     * What the analyst has been given directly, plus (when $effective) what they
     * end up with once team grants and hand-assigned roles are counted:
     *   effective.modules   null = every module
     *   effective.companies every company id they can reach
     *   effective.capabilities
     * ⚠️ The effective helpers cache per request, so only ask after any write.
     *
     * @param int $id  the analyst whose access to read
     * @param bool $effective  true to add the 'effective' block (team grants and hand-assigned roles included)
     */
    public static function access(PDO $conn, int $id, bool $effective = true): array
    {
        $a = self::load($conn, $id);
        $m = $conn->prepare("SELECT module_key FROM analyst_modules WHERE analyst_id = ? ORDER BY module_key");
        $m->execute([$id]);
        $c = $conn->prepare("SELECT tenant_id FROM analyst_tenant_access WHERE analyst_id = ? ORDER BY tenant_id");
        $c->execute([$id]);
        $caps = [];
        if (($roleId = self::managedRoleId($conn, $id)) !== null) {
            $r = $conn->prepare("SELECT capability_key FROM rbac_role_capabilities WHERE role_id = ? ORDER BY capability_key");
            $r->execute([$roleId]);
            $caps = $r->fetchAll(PDO::FETCH_COLUMN);
        }
        $out = [
            'is_admin'      => (int)$a['is_admin'] === 1,
            'all_modules'   => (int)$a['can_access_all_modules'] === 1,
            'modules'       => $m->fetchAll(PDO::FETCH_COLUMN),
            'all_companies' => (int)$a['can_access_all_tenants'] === 1,
            'companies'     => array_map('intval', $c->fetchAll(PDO::FETCH_COLUMN)),
            'capabilities'  => $caps,
        ];
        if ($effective) {
            $out['effective'] = [
                // Effective: what they can open, so less any module switched off (GH #217).
                'modules'      => modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, $id)),
                'companies'    => getAccessibleTenantIds($conn, $id),
                'capabilities' => getAnalystCapabilities($conn, $id),
            ];
        }
        return $out;
    }

    /**
     * Set access. $in: any of is_admin, all_modules, modules[], all_companies,
     * companies[], capabilities[]. Each list REPLACES what the analyst has in that
     * part; anything not sent stays as it is.
     *
     * @param int $id  the analyst whose access to set
     * @param array $in  any of is_admin, all_modules, modules[], all_companies, companies[], capabilities[]; absent parts stay
     */
    public static function setAccess(PDO $conn, ActorContext $ctx, int $id, array $in): void
    {
        $parts = ['is_admin', 'all_modules', 'modules', 'all_companies', 'companies', 'capabilities'];
        if (!array_intersect($parts, array_keys($in))) {
            throw new ServiceError('validation', 'missing_field', "Send at least one of '" . implode("', '", $parts) . "'.");
        }
        $a = self::load($conn, $id);
        $cur = self::access($conn, $id, false);

        $isAdmin = array_key_exists('is_admin', $in) ? self::flag($in['is_admin'], 'is_admin') : $cur['is_admin'];
        if ($cur['is_admin'] && !$isAdmin) {
            if ($id === $ctx->actorId) {
                throw new ServiceError('validation', 'invalid_field', 'You cannot remove your own administrator access.');
            }
            self::assertNotLastAdmin($conn, $id);
        }
        $allModules   = array_key_exists('all_modules', $in) ? self::flag($in['all_modules'], 'all_modules') : $cur['all_modules'];
        $allCompanies = array_key_exists('all_companies', $in) ? self::flag($in['all_companies'], 'all_companies') : $cur['all_companies'];
        $modules   = array_key_exists('modules', $in) ? self::moduleKeys($in['modules']) : $cur['modules'];
        $companies = array_key_exists('companies', $in) ? self::companyIds($conn, $in['companies']) : $cur['companies'];
        $caps      = array_key_exists('capabilities', $in) ? self::capabilityKeys($in['capabilities']) : $cur['capabilities'];

        // All or nothing - inside the caller's transaction when there is one.
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE analysts SET is_admin = ?, can_access_all_modules = ?, can_access_all_tenants = ?,
                                   last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$isAdmin ? 1 : 0, $allModules ? 1 : 0, $allCompanies ? 1 : 0, $id]);

            if (array_key_exists('modules', $in)) {
                // Each grant keeps its level (#180): re-saving the list must not turn
                // a Read only grant into Full.
                $levels = moduleGrantLevels($conn, 'analyst', $id);
                $conn->prepare("DELETE FROM analyst_modules WHERE analyst_id = ?")->execute([$id]);
                $ins = $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key, access_level) VALUES (?, ?, ?)");
                foreach ($modules as $k) $ins->execute([$id, $k, ($levels[$k] ?? 'full') === 'read' ? 'read' : 'full']);
            }
            if (array_key_exists('companies', $in)) {
                $conn->prepare("DELETE FROM analyst_tenant_access WHERE analyst_id = ?")->execute([$id]);
                $ins = $conn->prepare("INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)");
                foreach ($companies as $t) $ins->execute([$id, $t]);
            }
            if (array_key_exists('capabilities', $in)) {
                self::writeManagedRole($conn, $ctx, $a, $caps);
            }
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** The REST-API-managed role's id for an analyst, or null. */
    public static function managedRoleId(PDO $conn, int $analystId): ?int
    {
        try {
            $s = $conn->prepare("SELECT id FROM rbac_roles WHERE managed_for_analyst_id = ?");
            $s->execute([$analystId]);
            $id = $s->fetchColumn();
            return $id === false ? null : (int)$id;
        } catch (Throwable) {
            return null;   // before Database Verification adds the column
        }
    }

    /**
     * Refuse when this analyst is the last ACTIVE administrator - removing them
     * would leave nobody able to open System. Same rule as System -> Analysts.
     *
     * @param int $id  the administrator about to be demoted or deactivated
     */
    public static function assertNotLastAdmin(PDO $conn, int $id): void
    {
        $s = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE is_admin = 1 AND is_active = 1 AND id <> ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() === 0) {
            throw new ServiceError('conflict', 'conflict',
                'This is the last active administrator. Make another analyst an administrator first.');
        }
    }

    // ------------------------------------------------------------------------

    /**
     * Sets the capabilities of the analyst's API-managed role, creating the role the first
     * time and assigning it to them.
     *
     * @param array $a  the analyst row, as loaded by load()
     * @param array $caps  the validated capability keys the role should hold, replacing any it has; [] empties it
     * @writes rbac_roles, rbac_role_capabilities, rbac_analyst_roles - the role the
     *     REST API keeps for this analyst
     */
    private static function writeManagedRole(PDO $conn, ActorContext $ctx, array $a, array $caps): void
    {
        $id = (int)$a['id'];
        $roleId = self::managedRoleId($conn, $id);
        if ($roleId === null) {
            if (!$caps) return;   // nothing to hold, so no role yet
            $conn->prepare("INSERT INTO rbac_roles (name, description, is_active, created_by_id, managed_for_analyst_id, created_datetime, updated_datetime)
                            VALUES (?, ?, 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute([
                     mb_substr('API: ' . $a['full_name'], 0, 100),
                     'Set through the REST API (PATCH /analysts/' . $id . '/access). Read-only here: the next sync would overwrite any change.',
                     $ctx->actorId ?: null,
                     $id,
                 ]);
            $roleId = (int)$conn->lastInsertId();
        }
        $conn->prepare("DELETE FROM rbac_role_capabilities WHERE role_id = ?")->execute([$roleId]);
        $ins = $conn->prepare("INSERT INTO rbac_role_capabilities (role_id, capability_key) VALUES (?, ?)");
        foreach ($caps as $c) $ins->execute([$roleId, $c]);
        $conn->prepare("INSERT IGNORE INTO rbac_analyst_roles (analyst_id, role_id, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())")
             ->execute([$id, $roleId]);
        $conn->prepare("UPDATE rbac_roles SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$roleId]);
    }

    /**
     * Validates a list of module keys against the module registry.
     *
     * @param mixed $v  the 'modules' value from the request - must be an array of module keys
     */
    private static function moduleKeys($v): array
    {
        $keys = self::stringList($v, 'modules');
        $bad = array_values(array_diff($keys, array_keys(getModuleRegistry())));
        if ($bad) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown module key(s): ' . implode(', ', $bad)
                . '. (System is not a module grant - it comes with is_admin.)');
        }
        return $keys;
    }

    /**
     * Validates a list of company ids against the known companies.
     *
     * @param mixed $v  the 'companies' value from the request - must be an array of tenant ids
     */
    private static function companyIds(PDO $conn, $v): array
    {
        if (!is_array($v)) {
            throw new ServiceError('validation', 'invalid_field', "'companies' must be an array of company ids.");
        }
        $ids = array_values(array_unique(array_map('intval', $v)));
        $known = array_map(fn($t) => (int)$t['id'], getAllTenants($conn));
        $bad = array_values(array_diff($ids, $known));
        if ($bad) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown company id(s): ' . implode(', ', $bad));
        }
        return $ids;
    }

    /**
     * Validates a list of capability keys, mapping retired keys to their current ones and
     * removing duplicates.
     *
     * @param mixed $v  the 'capabilities' value from the request - must be an array of capability keys
     */
    private static function capabilityKeys($v): array
    {
        $out = [];
        foreach (self::stringList($v, 'capabilities') as $k) {
            $resolved = capFromKey($k);   // also maps a retired key to its current one
            if ($resolved === null) {
                throw new ServiceError('validation', 'invalid_field', 'Unknown capability key: ' . $k);
            }
            $out[] = $resolved;
        }
        // De-duplicate AFTER resolving: two spellings of one key would hit uq_rrc_role_capability.
        return array_values(array_unique($out));
    }

    /**
     * A list of unique trimmed strings; throws when the value is not an array.
     *
     * @param mixed $v  the request value that should be an array
     * @param string $field  the request field name, used in the error message
     */
    private static function stringList($v, string $field): array
    {
        if (!is_array($v)) {
            throw new ServiceError('validation', 'invalid_field', "'$field' must be an array.");
        }
        return array_values(array_unique(array_map(fn($x) => trim((string)$x), $v)));
    }

    /**
     * A true/false input as a bool; accepts true, false, 0, 1, '0' or '1' and throws on anything else.
     *
     * @param mixed $v  the request value to read as true or false
     * @param string $field  the request field name, used in the error message
     */
    private static function flag($v, string $field): bool
    {
        if (is_bool($v) || $v === 0 || $v === 1 || $v === '0' || $v === '1') return (bool)(int)$v;
        throw new ServiceError('validation', 'invalid_field', "'$field' must be true or false.");
    }

    /**
     * A lower-case, validated email address, or null when blank.
     *
     * @param mixed $v  the email address from the request; null or blank gives null
     */
    private static function cleanEmail($v): ?string
    {
        $e = strtolower(trim((string)($v ?? '')));
        if ($e === '') return null;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            throw new ServiceError('validation', 'invalid_field', "'email' must be a valid email address.");
        }
        return $e;
    }

    /**
     * Throws a conflict when another analyst already uses the email.
     *
     * @param string $email  the lower-cased email address to check
     * @param int $exceptId  the analyst allowed to hold it already (the one being changed); 0 on create
     */
    private static function assertEmailFree(PDO $conn, string $email, int $exceptId): void
    {
        $s = $conn->prepare("SELECT id FROM analysts WHERE LOWER(email) = ? AND id <> ?");
        $s->execute([$email, $exceptId]);
        if ($s->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'Another analyst already uses this email.');
        }
    }

    /**
     * A validated sign-in provider id, or null for none.
     *
     * @param mixed $v  the auth_provider_id from the request; null, '', 0 or '0' means none
     */
    private static function providerId(PDO $conn, $v): ?int
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
        $id = (int)$v;
        $s = $conn->prepare("SELECT id FROM auth_providers WHERE id = ?");
        $s->execute([$id]);
        if (!$s->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "'auth_provider_id' is not a known sign-in provider (System -> SSO).");
        }
        return $id;
    }

    /**
     * The asked-for username, else the email's local part, made unique - the SSO sign-up rule.
     *
     * @param string $want  the username asked for; '' falls back to the email's local part
     * @param string $email  the analyst's email address
     */
    private static function uniqueUsername(PDO $conn, string $want, string $email): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $want !== '' ? $want : explode('@', $email)[0]));
        $base = mb_substr($base !== '' ? $base : 'analyst', 0, 90);
        $s = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = ?");
        for ($i = 0, $u = $base; ; $u = $base . (++$i)) {
            $s->execute([$u]);
            if ((int)$s->fetchColumn() === 0) return $u;
        }
    }
}
