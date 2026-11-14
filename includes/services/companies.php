<?php
/**
 * Companies (tenants) - the one place their rules live (GH #279).
 *
 * System -> Companies (api/system/save_tenant.php) and the REST API
 * (POST /companies, PATCH /companies/{id}, POST /companies/upsert) both save
 * through here, so a rule cannot hold in one and not the other:
 *
 *  - a name is required;
 *  - a ticket code, an outside reference and a tax ID are each unique when set
 *    (ignoring case and the spaces around it);
 *  - the Default company cannot be made inactive;
 *  - only what is sent changes (a PATCH of the tax ID leaves the name alone).
 *
 * external_ref is the company's key in an ERP or CRM - the system that is the
 * source of truth for a support business's customers (pappas81's ~3,000 small
 * businesses, #262). upsert() creates or updates by it, so the same nightly
 * sync can run again and again without making duplicates. tax_id is the tax
 * number (VAT, AFM ...) - in some countries how a business is actually known.
 *
 * Who may call it is the caller's job: the System page is administrators only,
 * and the REST API asks for an administrator's key (see api/v1/resources/companies.php).
 *
 * @wiki Companies-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../ticket_numbering.php';

class CompaniesService
{
    /** Has Database Verification added tenants.external_ref and tax_id yet? */
    public static function refsReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = (int)$conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME IN ('external_ref', 'tax_id')")->fetchColumn() === 2;
            } catch (Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** One company row, or null. */
    public static function get(PDO $conn, int $id): ?array
    {
        $cols = 'id, name, ticket_code, is_default, is_active, created_datetime'
              . (self::refsReady($conn) ? ', external_ref, tax_id' : ', NULL AS external_ref, NULL AS tax_id');
        $st = $conn->prepare("SELECT $cols FROM tenants WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** The company whose outside reference is $ref (ignoring case and spaces), or null. */
    public static function findByExternalRef(PDO $conn, string $ref): ?array
    {
        $ref = self::clean($ref, 100);
        if ($ref === null || !self::refsReady($conn)) return null;
        $st = $conn->prepare("SELECT id FROM tenants WHERE LOWER(external_ref) = LOWER(?) LIMIT 1");
        $st->execute([$ref]);
        $id = $st->fetchColumn();
        return $id === false ? null : self::get($conn, (int)$id);
    }

    /**
     * Create ($id null) or update a company. Only the keys present in $in change on
     * an update: name, is_active, ticket_code, external_ref, tax_id. An empty
     * string clears ticket_code, external_ref and tax_id.
     *
     * @return array{id:int, created:bool}
     * @throws ServiceError validation / not_found / conflict
     */
    public static function save(PDO $conn, array $in, ?int $id = null): array
    {
        $existing = null;
        if ($id !== null) {
            $existing = self::get($conn, $id);
            if (!$existing) throw new ServiceError('not_found', 'not_found', 'Company not found.');
        }
        $isCreate = $existing === null;

        // name
        if ($isCreate || array_key_exists('name', $in)) {
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') throw new ServiceError('validation', 'missing_field', 'Name is required.');
            if (mb_strlen($name) > 150) throw new ServiceError('validation', 'invalid_field', 'Name is too long (150 characters at most).');
        } else {
            $name = $existing['name'];
        }
        // active
        $isActive = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : ($isCreate ? 1 : (int)$existing['is_active']);
        if (!$isCreate && (int)$existing['is_default'] === 1 && !$isActive) {
            throw new ServiceError('validation', 'invalid_field', 'The default company cannot be set inactive.');
        }
        // ticket code
        if (array_key_exists('ticket_code', $in)) {
            $code = TicketNumbering::cleanCode((string)($in['ticket_code'] ?? ''));
            $code = $code === '' ? null : $code;
        } else {
            $code = $isCreate ? null : $existing['ticket_code'];
        }
        if ($code !== null) {
            $st = $conn->prepare("SELECT name FROM tenants WHERE UPPER(ticket_code) = UPPER(?) AND id <> ? LIMIT 1");
            $st->execute([$code, (int)$id]);
            if (($clash = $st->fetchColumn()) !== false) {
                throw new ServiceError('conflict', 'duplicate', "The ticket code $code is already used by $clash.");
            }
        }
        // outside reference and tax ID
        $ready = self::refsReady($conn);
        $refs = [];
        foreach (['external_ref' => [100, 'outside reference'], 'tax_id' => [50, 'tax ID']] as $col => [$max, $what]) {
            if (!array_key_exists($col, $in)) {
                $refs[$col] = $isCreate ? null : $existing[$col];
                continue;
            }
            if (!$ready) throw new ServiceError('validation', 'invalid_field', "The $what needs a database update: run System -> Database Verification.");
            $raw = $in[$col];
            if ($raw !== null && !is_scalar($raw)) throw new ServiceError('validation', 'invalid_field', "The $what must be text.");
            $v = self::clean((string)($raw ?? ''), $max);
            if ($v === false) throw new ServiceError('validation', 'invalid_field', "The $what is too long ($max characters at most).");
            if ($v !== null) {
                $st = $conn->prepare("SELECT name FROM tenants WHERE LOWER($col) = LOWER(?) AND id <> ? LIMIT 1");
                $st->execute([$v, (int)$id]);
                if (($clash = $st->fetchColumn()) !== false) {
                    throw new ServiceError('conflict', 'duplicate', "That $what is already used by $clash.");
                }
            }
            $refs[$col] = $v;
        }

        $refSql = $ready ? ', external_ref = ?, tax_id = ?' : '';
        $refArgs = $ready ? [$refs['external_ref'], $refs['tax_id']] : [];
        if ($isCreate) {
            $conn->prepare("INSERT INTO tenants SET name = ?, is_default = 0, is_active = ?, ticket_code = ?$refSql, created_datetime = UTC_TIMESTAMP()")
                 ->execute(array_merge([$name, $isActive, $code], $refArgs));
            return ['id' => (int)$conn->lastInsertId(), 'created' => true];
        }
        $conn->prepare("UPDATE tenants SET name = ?, is_active = ?, ticket_code = ?$refSql WHERE id = ?")
             ->execute(array_merge([$name, $isActive, $code], $refArgs, [(int)$id]));
        return ['id' => (int)$id, 'created' => false];
    }

    /**
     * Create or update by outside reference - the call an ERP / CRM sync makes for
     * each customer, every night, without duplicates. external_ref is required.
     *
     * @return array{id:int, created:bool}
     */
    public static function upsert(PDO $conn, array $in): array
    {
        $ref = self::clean((string)($in['external_ref'] ?? ''), 100);
        if ($ref === null || $ref === false) {
            throw new ServiceError('validation', 'missing_field', 'external_ref is required (100 characters at most).');
        }
        $hit = self::findByExternalRef($conn, $ref);
        return self::save($conn, $in, $hit ? (int)$hit['id'] : null);
    }

    /** A company as the API and the System page show it. */
    public static function serialize(array $r): array
    {
        return [
            'id'           => (int)$r['id'],
            'name'         => $r['name'],
            'is_default'   => (int)$r['is_default'] === 1,
            'is_active'    => (int)$r['is_active'] === 1,
            'ticket_code'  => $r['ticket_code'] ?? null,
            'external_ref' => $r['external_ref'] ?? null,
            'tax_id'       => $r['tax_id'] ?? null,
        ];
    }

    /**
     * Trimmed text, null when empty, false when longer than $max.
     *
     * @return string|null|false
     */
    private static function clean(string $v, int $max)
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if ($v === '') return null;
        return mb_strlen($v) > $max ? false : $v;
    }
}
