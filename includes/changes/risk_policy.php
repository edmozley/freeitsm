<?php
/**
 * Change risk policies (GH #246).
 *
 * IN PLAIN TERMS
 * --------------
 * A risk policy is the organisation's own scale: how many likelihood and impact levels
 * there are and what each means, the results (Low ... Critical, or gering ... sehr hoch)
 * with their colours, and a matrix saying which result every likelihood x impact cell is.
 * The built-in 5 x 5 is the default and lives in this file, not in the database.
 *
 * THE MODEL
 * ---------
 *  change_risk_policies          one row per install today (tenant_id NULL); a company may
 *                                have its own later. current_version_id NULL = built-in.
 *  change_risk_policy_versions   every save, as JSON. NEVER updated: a change points at the
 *                                version it was assessed under, for ever.
 *  changes.risk_policy_version_id          NULL = the built-in 5 x 5
 *  change_templates.risk_policy_version_id the version a template's ratings were given under
 *
 * A version's content:
 *   name        the policy's name
 *   likelihood  [{label, criteria}, ...] lowest first; a rating is the 1-based position
 *   impact      the same, for impact
 *   results     [{label, colour}, ...] lowest first; the label is what changes.risk_level stores
 *   matrix      matrix[l - 1][i - 1] = the index into results
 * The built-in content also carries 'builtin' => true and translation keys, so the screens
 * show it in the reader's language while it is still stored as the English words.
 *
 * TRAP: the score stays likelihood x impact - the API returns it and reports sort by it -
 * but it does NOT decide the result any more. The matrix does. Never band the score.
 */

require_once __DIR__ . '/../i18n.php';   // t() for the refusal messages
require_once __DIR__ . '/../service_context.php';   // ServiceError

final class ChangeRiskPolicy
{
    public const MIN_LEVELS  = 2;
    public const MAX_LEVELS  = 10;
    public const MIN_RESULTS = 2;
    public const MAX_RESULTS = 8;
    public const LABEL_MAX   = 50;     // results are stored in changes.risk_level VARCHAR(50)
    public const CRITERIA_MAX = 1000;
    public const NAME_MAX    = 100;

    /** @var array<int,array> version id => content, for this request */
    private static array $cache = [];

    /** @var ?bool whether Database Verification has added the columns, for this request */
    private static ?bool $ready = null;

    /**
     * Have the risk policy columns been created yet? Until they have, everything behaves
     * exactly as before #246 - the built-in 5 x 5, nothing new written - so an upgrade
     * that has not run Database Verification yet still saves changes.
     */
    public static function ready(PDO $conn): bool
    {
        if (self::$ready !== null) return self::$ready;
        try {
            $conn->query("SELECT risk_policy_version_id FROM changes LIMIT 0");
            $conn->query("SELECT risk_policy_version_id FROM change_templates LIMIT 0");
            $conn->query("SELECT id FROM change_risk_policy_versions LIMIT 0");
            return self::$ready = true;
        } catch (Throwable $e) {
            return self::$ready = false;
        }
    }

    /**
     * The built-in 5 x 5: today's bands (<=4 Low, <=9 Medium, <=15 High, <=20 Very High,
     * else Critical) written out as a matrix. Labels are the English the database stores;
     * 'key' lets the screens translate them.
     */
    public static function builtin(): array
    {
        $band = static fn(int $s): int => $s <= 4 ? 0 : ($s <= 9 ? 1 : ($s <= 15 ? 2 : ($s <= 20 ? 3 : 4)));
        $matrix = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($i = 1; $i <= 5; $i++) $matrix[$l - 1][$i - 1] = $band($l * $i);
        }
        $levels = static function (string $axis): array {
            $words = ['Very low', 'Low', 'Medium', 'High', 'Very high'];
            $out = [];
            foreach ($words as $n => $w) {
                $out[] = ['label' => ($n + 1) . ' - ' . $w, 'criteria' => '', 'key' => 'rl_' . ($n + 1),
                          'criteria_key' => 'risk_help_' . ($axis === 'likelihood' ? 'l' : 'i') . ($n + 1)];
            }
            return $out;
        };
        return [
            'builtin'    => true,
            'name'       => 'Built-in 5 x 5',
            'likelihood' => $levels('likelihood'),
            'impact'     => $levels('impact'),
            'results'    => [
                ['label' => 'Low',       'colour' => '#16a34a', 'key' => 'low'],
                ['label' => 'Medium',    'colour' => '#ca8a04', 'key' => 'medium'],
                ['label' => 'High',      'colour' => '#ea580c', 'key' => 'high'],
                ['label' => 'Very High', 'colour' => '#dc2626', 'key' => 'very_high'],
                ['label' => 'Critical',  'colour' => '#991b1b', 'key' => 'critical'],
            ],
            'matrix'     => $matrix,
        ];
    }

    /**
     * A version's content. Null - and a version that cannot be found - is the built-in.
     *
     * @param ?int $versionId  change_risk_policy_versions.id; null for the built-in
     */
    public static function content(PDO $conn, ?int $versionId): array
    {
        if (!$versionId) return self::builtin();
        if (isset(self::$cache[$versionId])) return self::$cache[$versionId];
        try {
            $s = $conn->prepare("SELECT content FROM change_risk_policy_versions WHERE id = ?");
            $s->execute([$versionId]);
            $raw = $s->fetchColumn();
            $c = $raw !== false ? json_decode((string)$raw, true) : null;
        } catch (Throwable $e) {
            $c = null;
        }
        return self::$cache[$versionId] = is_array($c) ? $c : self::builtin();
    }

    /**
     * The policy new assessments use for a company: its own if it has one, else the
     * install's, else the built-in. Every caller passes the change's company, so a
     * per-company policy later is a settings screen, not a rework.
     *
     * @param ?int $tenantId  the change's company; null for install-wide things (templates)
     * @return array{version_id: ?int, version_no: ?int, content: array}
     */
    public static function current(PDO $conn, ?int $tenantId): array
    {
        if (!self::ready($conn)) return ['version_id' => null, 'version_no' => null, 'content' => self::builtin()];
        try {
            $s = $conn->prepare(
                "SELECT p.current_version_id, v.version_no
                   FROM change_risk_policies p
              LEFT JOIN change_risk_policy_versions v ON v.id = p.current_version_id
                  WHERE p.tenant_id <=> ? OR p.tenant_id IS NULL
               ORDER BY p.tenant_id IS NULL, p.id
                  LIMIT 1"
            );
            $s->execute([$tenantId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $r = false;   // before Database Verification: the built-in
        }
        $vid = $r && $r['current_version_id'] !== null ? (int)$r['current_version_id'] : null;
        return ['version_id' => $vid, 'version_no' => $vid ? (int)$r['version_no'] : null,
                'content' => self::content($conn, $vid)];
    }

    /** How many levels an axis has. */
    public static function levels(array $content, string $axis): int
    {
        return count($content[$axis] ?? []);
    }

    /**
     * Validate one rating against a policy. Null or '' clears.
     *
     * @param mixed $value  the rating from the request
     * @param string $field  the request field, for the message
     * @param string $axis  'likelihood' or 'impact'
     */
    public static function rating($value, string $field, array $content, string $axis): ?int
    {
        if ($value === null || $value === '') return null;
        $n = self::levels($content, $axis);
        if (!is_numeric($value) || (int)$value != $value || (int)$value < 1 || (int)$value > $n) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be between 1 and {$n}.");
        }
        return (int)$value;
    }

    /**
     * The score and result for a pair of ratings. Either missing: both null.
     *
     * @return array{0: ?int, 1: ?string}  [score, result label]
     */
    public static function assess(array $content, ?int $likelihood, ?int $impact): array
    {
        if ($likelihood === null || $impact === null) return [null, null];
        $idx = $content['matrix'][$likelihood - 1][$impact - 1] ?? null;
        $label = $idx !== null ? ($content['results'][$idx]['label'] ?? null) : null;
        return [$likelihood * $impact, $label];
    }

    /**
     * A short name for a version, for the audit trail: "Built-in 5 x 5" or "Name (version 2)".
     *
     * @param ?int $versionNo  the version number; null for the built-in
     */
    public static function describe(array $content, ?int $versionNo): string
    {
        if (!empty($content['builtin'])) return 'Built-in 5 x 5';
        return (string)($content['name'] ?? 'Risk policy') . ($versionNo ? ' (version ' . $versionNo . ')' : '');
    }

    /** describe() from a version id. */
    public static function describeVersion(PDO $conn, ?int $versionId): string
    {
        if (!$versionId) return self::describe(self::builtin(), null);
        $no = null;
        try {
            $s = $conn->prepare("SELECT version_no FROM change_risk_policy_versions WHERE id = ?");
            $s->execute([$versionId]);
            $no = $s->fetchColumn() ?: null;
        } catch (Throwable $e) {
        }
        return self::describe(self::content($conn, $versionId), $no !== null ? (int)$no : null);
    }

    /**
     * Check and tidy a policy sent by the settings page. Returns the content to store.
     * Refuses rather than repairs: a policy with a gap in it is not a policy.
     *
     * @param array $in  name, likelihood, impact, results, matrix as described above
     */
    public static function normalise(array $in): array
    {
        $fail = static function (string $msg) { throw new ServiceError('validation', 'invalid_field', $msg); };
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') $fail(t('change-management.risk_policy.err_name'));
        if (mb_strlen($name) > self::NAME_MAX) $fail(t('change-management.risk_policy.err_name_long', ['n' => self::NAME_MAX]));

        $axis = static function (string $key, string $what) use ($in, $fail): array {
            $rows = is_array($in[$key] ?? null) ? array_values($in[$key]) : [];
            if (count($rows) < self::MIN_LEVELS || count($rows) > self::MAX_LEVELS) {
                $fail(t('change-management.risk_policy.err_levels', ['what' => $what, 'min' => self::MIN_LEVELS, 'max' => self::MAX_LEVELS]));
            }
            $out = []; $seen = [];
            foreach ($rows as $n => $r) {
                $label = trim((string)(is_array($r) ? ($r['label'] ?? '') : ''));
                $criteria = trim((string)(is_array($r) ? ($r['criteria'] ?? '') : ''));
                if ($label === '') $fail(t('change-management.risk_policy.err_label', ['what' => $what, 'n' => $n + 1]));
                if (mb_strlen($label) > self::LABEL_MAX) $fail(t('change-management.risk_policy.err_label_long', ['what' => $what, 'n' => $n + 1, 'max' => self::LABEL_MAX]));
                if (mb_strlen($criteria) > self::CRITERIA_MAX) $fail(t('change-management.risk_policy.err_criteria_long', ['what' => $what, 'n' => $n + 1, 'max' => self::CRITERIA_MAX]));
                $k = mb_strtolower($label);
                if (isset($seen[$k])) $fail(t('change-management.risk_policy.err_duplicate', ['what' => $what, 'label' => $label]));
                $seen[$k] = true;
                $out[] = ['label' => $label, 'criteria' => $criteria];
            }
            return $out;
        };
        $likelihood = $axis('likelihood', t('change-management.risk.likelihood'));
        $impact     = $axis('impact', t('change-management.risk.impact'));

        $rows = is_array($in['results'] ?? null) ? array_values($in['results']) : [];
        if (count($rows) < self::MIN_RESULTS || count($rows) > self::MAX_RESULTS) {
            $fail(t('change-management.risk_policy.err_results', ['min' => self::MIN_RESULTS, 'max' => self::MAX_RESULTS]));
        }
        $results = []; $seen = [];
        foreach ($rows as $n => $r) {
            $label = trim((string)(is_array($r) ? ($r['label'] ?? '') : ''));
            $colour = strtolower(trim((string)(is_array($r) ? ($r['colour'] ?? '') : '')));
            if ($label === '') $fail(t('change-management.risk_policy.err_result_label', ['n' => $n + 1]));
            if (mb_strlen($label) > self::LABEL_MAX) $fail(t('change-management.risk_policy.err_result_long', ['n' => $n + 1, 'max' => self::LABEL_MAX]));
            if (!preg_match('/^#[0-9a-f]{6}$/', $colour)) $fail(t('change-management.risk_policy.err_colour', ['label' => $label]));
            $k = mb_strtolower($label);
            if (isset($seen[$k])) $fail(t('change-management.risk_policy.err_result_duplicate', ['label' => $label]));
            $seen[$k] = true;
            $results[] = ['label' => $label, 'colour' => $colour];
        }

        $grid = is_array($in['matrix'] ?? null) ? array_values($in['matrix']) : [];
        $matrix = [];
        for ($l = 0; $l < count($likelihood); $l++) {
            $row = isset($grid[$l]) && is_array($grid[$l]) ? array_values($grid[$l]) : [];
            for ($i = 0; $i < count($impact); $i++) {
                $v = $row[$i] ?? null;
                if ($v === null || $v === '' || !is_numeric($v) || (int)$v != $v || (int)$v < 0 || (int)$v >= count($results)) {
                    $fail(t('change-management.risk_policy.err_cell', ['l' => $likelihood[$l]['label'], 'i' => $impact[$i]['label']]));
                }
                $matrix[$l][$i] = (int)$v;
            }
        }
        return ['name' => $name, 'likelihood' => $likelihood, 'impact' => $impact, 'results' => $results, 'matrix' => $matrix];
    }

    /**
     * The policy row for a company (null = the install's), created when missing.
     * Called inside save()'s lock, so two first saves cannot make two rows.
     */
    private static function policyRow(PDO $conn, ?int $tenantId): int
    {
        $s = $conn->prepare("SELECT id FROM change_risk_policies WHERE tenant_id <=> ? ORDER BY id LIMIT 1");
        $s->execute([$tenantId]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;
        $conn->prepare("INSERT INTO change_risk_policies (tenant_id) VALUES (?)")->execute([$tenantId]);
        return (int)$conn->lastInsertId();
    }

    /** The lock name: per database, because GET_LOCK names are per MySQL server. */
    private static function lockName(PDO $conn): string
    {
        return 'freeitsm_risk_policy_' . (string)$conn->query('SELECT DATABASE()')->fetchColumn();
    }

    /**
     * Save a policy as its next version and make it the one new assessments use.
     * Returns the new version id. Nothing already assessed changes.
     *
     * @param array $in  the policy, as normalise() takes it
     * @param int $actorId  who saves it
     * @param ?int $tenantId  the company it is for; null = the install (the only choice today)
     */
    public static function save(PDO $conn, array $in, int $actorId, ?int $tenantId = null): int
    {
        $content = self::normalise($in);
        $json = json_encode($content, JSON_UNESCAPED_UNICODE);
        $lock = self::lockName($conn);
        $conn->prepare("SELECT GET_LOCK(?, 10)")->execute([$lock]);
        try {
            $conn->beginTransaction();
            $policyId = self::policyRow($conn, $tenantId);
            $n = $conn->prepare("SELECT COALESCE(MAX(version_no), 0) + 1 FROM change_risk_policy_versions WHERE policy_id = ?");
            $n->execute([$policyId]);
            $conn->prepare(
                "INSERT INTO change_risk_policy_versions (policy_id, version_no, content, content_hash, created_by_id, created_datetime)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$policyId, (int)$n->fetchColumn(), $json, hash('sha256', $json), $actorId ?: null]);
            $vid = (int)$conn->lastInsertId();
            $conn->prepare("UPDATE change_risk_policies SET current_version_id = ?, updated_by_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$vid, $actorId ?: null, $policyId]);
            $conn->commit();
            return $vid;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        } finally {
            $conn->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
        }
    }

    /**
     * Go back to the built-in 5 x 5 for new assessments. The saved versions stay, and so
     * does every change assessed under them.
     */
    public static function useBuiltin(PDO $conn, int $actorId, ?int $tenantId = null): void
    {
        $s = $conn->prepare("UPDATE change_risk_policies SET current_version_id = NULL, updated_by_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE tenant_id <=> ?");
        $s->execute([$actorId ?: null, $tenantId]);
    }

    /**
     * Every saved version for a company (null = the install), newest first, with how many
     * changes were assessed under each - and the built-in's count.
     *
     * @return array{versions: array, builtin_count: int}
     */
    public static function history(PDO $conn, ?int $tenantId = null): array
    {
        $out = [];
        try {
            $s = $conn->prepare(
                "SELECT v.id, v.version_no, v.content, v.created_datetime, a.full_name AS created_by,
                        (SELECT COUNT(*) FROM changes c WHERE c.risk_policy_version_id = v.id AND c.risk_likelihood IS NOT NULL) AS changes
                   FROM change_risk_policy_versions v
                   JOIN change_risk_policies p ON p.id = v.policy_id
              LEFT JOIN analysts a ON a.id = v.created_by_id
                  WHERE p.tenant_id <=> ?
               ORDER BY v.version_no DESC"
            );
            $s->execute([$tenantId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = json_decode((string)$r['content'], true) ?: [];
                $out[] = ['id' => (int)$r['id'], 'version_no' => (int)$r['version_no'], 'name' => $c['name'] ?? '',
                          'size' => count($c['likelihood'] ?? []) . ' x ' . count($c['impact'] ?? []),
                          'created_datetime' => $r['created_datetime'], 'created_by' => $r['created_by'], 'changes' => (int)$r['changes']];
            }
            $b = (int)$conn->query("SELECT COUNT(*) FROM changes WHERE risk_policy_version_id IS NULL AND risk_likelihood IS NOT NULL")->fetchColumn();
        } catch (Throwable $e) {
            $b = 0;
        }
        return ['versions' => $out, 'builtin_count' => $b];
    }

    /**
     * Every version a page may need to draw - the built-in (key 0) and every saved one -
     * so a change is always drawn on the scale it was rated on. Versions are few and small.
     *
     * @return array<int,array>  version id (0 = built-in) => content
     */
    public static function all(PDO $conn): array
    {
        $out = [0 => self::builtin()];
        try {
            foreach ($conn->query("SELECT id, content FROM change_risk_policy_versions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = json_decode((string)$r['content'], true);
                if (is_array($c)) $out[(int)$r['id']] = $c;
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * The <script> that gives a page every version and the one in use, for
     * assets/js/change-risk.js. The words are the administrator's, so JSON_HEX_TAG keeps
     * a "</script>" in a label from ending the block.
     */
    public static function jsBootstrap(PDO $conn): string
    {
        $cur = self::current($conn, null);
        $data = ['policies' => (object)self::all($conn), 'current' => $cur['version_id'] ?? 0,
                 'current_no' => $cur['version_no']];
        return '<script>window.CM_RISK = ' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . ';</script>';
    }

    /**
     * The colour of a stored result: from the version the change was assessed under,
     * falling back to any version that has a result of that name, then grey.
     *
     * @param ?string $label  changes.risk_level
     * @param ?int $versionId  changes.risk_policy_version_id
     */
    public static function colourFor(PDO $conn, ?string $label, ?int $versionId = null): string
    {
        if ($label === null || $label === '') return '#94a3b8';
        foreach (self::content($conn, $versionId)['results'] as $r) {
            if (strcasecmp($r['label'], $label) === 0) return $r['colour'];
        }
        foreach (self::all($conn) as $c) {
            foreach ($c['results'] ?? [] as $r) {
                if (strcasecmp($r['label'], $label) === 0) return $r['colour'];
            }
        }
        return '#94a3b8';
    }
}
