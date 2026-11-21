<?php
/**
 * ConsumablesService - Assets -> Consumables (5.8.0).
 *
 * The things a service desk COUNTS rather than tracks one by one - toner, paper,
 * mice, RAM - with stock levels, issuing against tickets, stock takes and a full
 * audit trail. Design and decisions: wiki Consumables-Design (Ed, 2026-11-21).
 *
 * THE RULES THIS FILE KEEPS
 *  - The ONLY writer of the consumable tables. Screens, the ticket and the API all
 *    come through here, so every rule below holds whichever door was used.
 *  - Stock is a LEDGER. consumable_movements is append-only: nothing here updates or
 *    deletes a movement. A mistake is undone by reverse(), which adds the opposite
 *    movement pointing at the one it cancels (once - a unique key).
 *  - On hand (consumable_stock) changes only inside the transaction that writes the
 *    movement explaining it, with the stock row locked - verify() checks they agree.
 *  - Never below zero (Ed): an issue bigger than what is there is refused.
 *  - Value is fixed when it happens: every movement stores its unit cost.
 *  - A store on every row: one per company today (storeFor() makes it), so more
 *    stores later is screens and a transfer, not a migration.
 *  - Who: anyone with write access to Assets receives, issues and returns (the API
 *    checks that); adjusting stock and finishing a stock take need
 *    Cap::ASSETS_CONSUMABLES_ADJUST - administrators, or a role given it in System -> Roles.
 *
 * @wiki Consumables-Developer-Guide
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/record_fields.php';
require_once __DIR__ . '/notifications.php';

final class ConsumablesService
{
    /** Movement kinds, and the sign each puts on the stock. 0 = either way (adjust, count). */
    public const KINDS = ['receive' => 1, 'issue' => -1, 'return' => 1, 'adjust' => 0, 'count' => 0, 'reverse' => 0];

    /** The most rows a list or a history returns. */
    public const LIST_LIMIT = 2000;
    public const HISTORY_LIMIT = 300;

    /** Has Database Verification made the tables? Every screen says so rather than failing. */
    public static function ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $conn->query("SELECT 1 FROM consumable_movements LIMIT 0");
                $conn->query("SELECT 1 FROM consumable_count_lines LIMIT 0");
                $ready = true;
            } catch (Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    // ====================================================================
    //  Stores and scope
    // ====================================================================

    /** The company the analyst is working in - the switcher's choice, as for assets. */
    public static function tenantFor(PDO $conn, ActorContext $ctx): int
    {
        return getActiveTenantId($conn, $ctx->actorId > 0 ? $ctx->actorId : null);
    }

    /**
     * The company's store, made the first time it is needed. One per company for now;
     * the oldest active one is "the" store, so a second store added later changes nothing
     * for the code that asks this.
     */
    public static function storeFor(PDO $conn, int $tenantId): int
    {
        $st = $conn->prepare("SELECT id FROM consumable_stores WHERE tenant_id = ? AND is_active = 1 ORDER BY id LIMIT 1");
        $st->execute([$tenantId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;
        $conn->prepare("INSERT INTO consumable_stores (tenant_id, name) VALUES (?, 'Store')")->execute([$tenantId]);
        return (int)$conn->lastInsertId();
    }

    /** An item the analyst may see (its company is one they can access), or a not_found error. */
    private static function item(PDO $conn, ActorContext $ctx, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM consumable_items WHERE id = ?");
        $st->execute([$id]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        $tenant = $it ? ((int)$it['tenant_id'] ?: getDefaultTenantId($conn)) : 0;
        if (!$it || ($ctx->actorId > 0 && !analystCanAccessTenant($conn, $ctx->actorId, $tenant))) {
            throw new ServiceError('not_found', 'not_found', 'That item does not exist, or is in a company you cannot see.');
        }
        return $it;
    }

    private static function itemStore(PDO $conn, array $item): int
    {
        return self::storeFor($conn, (int)$item['tenant_id'] ?: getDefaultTenantId($conn));
    }

    private static function audit(PDO $conn, ActorContext $ctx, array $at, string $field, $old, $new): void
    {
        $old = $old === null || $old === '' ? null : mb_substr((string)$old, 0, 1000);
        $new = $new === null || $new === '' ? null : mb_substr((string)$new, 0, 1000);
        if ($old === $new) return;
        $conn->prepare(
            "INSERT INTO consumable_audit (item_id, type_id, count_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$at['item'] ?? null, $at['type'] ?? null, $at['count'] ?? null,
                    $ctx->actorId > 0 ? $ctx->actorId : null, $field, $old, $new, self::source($ctx)]);
    }

    private static function source(ActorContext $ctx): string
    {
        return in_array($ctx->source, ['api', 'ticket'], true) ? $ctx->source : 'app';
    }

    private static function mayAdjust(PDO $conn, ActorContext $ctx): bool
    {
        return $ctx->actorId > 0 && analystHasCapability($conn, $ctx->actorId, Cap::ASSETS_CONSUMABLES_ADJUST);
    }

    // ====================================================================
    //  Types
    // ====================================================================

    /** @return array<int,array> id, name, description, is_active, items */
    public static function types(PDO $conn, bool $activeOnly = false): array
    {
        $rows = $conn->query(
            "SELECT t.id, t.name, t.description, t.is_active,
                    (SELECT COUNT(*) FROM consumable_items i WHERE i.type_id = t.id) AS items
               FROM consumable_types t" . ($activeOnly ? " WHERE t.is_active = 1" : "") . "
              ORDER BY t.display_order, t.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'],
                                    'is_active' => (int)$r['is_active'] === 1, 'items' => (int)$r['items']], $rows);
    }

    /** Create or change a type; retired with is_active = 0, never deleted (items keep it). */
    public static function saveType(PDO $conn, ActorContext $ctx, array $in): int
    {
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the type a name.');
        $name = mb_substr($name, 0, 100);
        $desc = trim((string)($in['description'] ?? ''));
        $desc = $desc === '' ? null : mb_substr($desc, 0, 255);
        $active = array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1;
        if ($id > 0) {
            $st = $conn->prepare("SELECT * FROM consumable_types WHERE id = ?");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new ServiceError('not_found', 'not_found', 'That type no longer exists.');
            $conn->prepare("UPDATE consumable_types SET name = ?, description = ?, is_active = ? WHERE id = ?")->execute([$name, $desc, $active, $id]);
            self::audit($conn, $ctx, ['type' => $id], 'type.name', $cur['name'], $name);
            self::audit($conn, $ctx, ['type' => $id], 'type.description', $cur['description'], $desc);
            self::audit($conn, $ctx, ['type' => $id], 'type.active', (int)$cur['is_active'] ? 'Yes' : 'No', $active ? 'Yes' : 'No');
            return $id;
        }
        $order = (int)$conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM consumable_types")->fetchColumn();
        $conn->prepare("INSERT INTO consumable_types (name, description, is_active, display_order) VALUES (?, ?, ?, ?)")->execute([$name, $desc, $active, $order]);
        $id = (int)$conn->lastInsertId();
        self::audit($conn, $ctx, ['type' => $id], 'type.created', null, $name);
        return $id;
    }

    // ====================================================================
    //  Items
    // ====================================================================

    /** Low, out or fine, from on hand and the reorder point. */
    public static function status(int $onHand, ?int $reorderPoint): string
    {
        if ($onHand <= 0) return 'out';
        if ($reorderPoint !== null && $onHand <= $reorderPoint) return 'low';
        return 'ok';
    }

    /**
     * The items in the analyst's active company, with what is on hand.
     *
     * @param array $f  q (name, part number, type), type (id), status ('low' = low or out | 'out'), inactive (bool)
     */
    public static function listItems(PDO $conn, ActorContext $ctx, array $f = []): array
    {
        $tenant = self::tenantFor($conn, $ctx);
        $store = self::storeFor($conn, $tenant);
        [$scope, $args] = activeTenantFilter($conn, $ctx->actorId, 'i');
        $where = "1=1" . $scope;
        if (empty($f['inactive'])) $where .= " AND i.is_active = 1";
        if (!empty($f['type'])) { $where .= " AND i.type_id = ?"; $args[] = (int)$f['type']; }
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $like = likeContainsPattern($q);
            $where .= " AND (i.name LIKE ? OR i.part_number LIKE ? OR t.name LIKE ?)";
            array_push($args, $like, $like, $like);
        }
        $st = $conn->prepare(
            "SELECT i.id, i.name, i.part_number, i.unit, i.unit_cost, i.is_active, i.type_id, t.name AS type_name,
                    i.supplier_id, COALESCE(NULLIF(sp.trading_name, ''), sp.legal_name) AS supplier_name,
                    COALESCE(s.on_hand, 0) AS on_hand, s.min_level, s.reorder_point
               FROM consumable_items i
               LEFT JOIN consumable_types t ON t.id = i.type_id
               LEFT JOIN suppliers sp ON sp.id = i.supplier_id
               LEFT JOIN consumable_stock s ON s.item_id = i.id AND s.store_id = ?
              WHERE $where
              ORDER BY t.name IS NULL, t.name, i.name
              LIMIT " . self::LIST_LIMIT
        );
        $st->execute(array_merge([$store], $args));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = self::itemRow($r);
            if (($f['status'] ?? '') === 'low' && $row['status'] === 'ok') continue;
            if (($f['status'] ?? '') === 'out' && $row['status'] !== 'out') continue;
            $out[] = $row;
        }
        return $out;
    }

    private static function itemRow(array $r): array
    {
        $onHand = (int)$r['on_hand'];
        $reorder = $r['reorder_point'] !== null ? (int)$r['reorder_point'] : null;
        $cost = $r['unit_cost'] !== null ? (float)$r['unit_cost'] : null;
        return [
            'id' => (int)$r['id'], 'name' => $r['name'], 'part_number' => $r['part_number'], 'unit' => $r['unit'],
            'type_id' => $r['type_id'] !== null ? (int)$r['type_id'] : null, 'type_name' => $r['type_name'],
            'supplier_id' => $r['supplier_id'] !== null ? (int)$r['supplier_id'] : null, 'supplier_name' => $r['supplier_name'] ?? null,
            'unit_cost' => $cost, 'on_hand' => $onHand,
            'min_level' => $r['min_level'] !== null ? (int)$r['min_level'] : null, 'reorder_point' => $reorder,
            'value' => $cost !== null ? round($onHand * $cost, 2) : null,
            'status' => self::status($onHand, $reorder), 'is_active' => (int)$r['is_active'] === 1,
        ];
    }

    /** How many items are low or out, in the analyst's active company - Watchtower and the header. */
    public static function lowCount(PDO $conn, ActorContext $ctx): int
    {
        if (!self::ready($conn)) return 0;
        return count(self::listItems($conn, $ctx, ['status' => 'low']));
    }

    /** One item: the row, its custom fields, its movements and its history. */
    public static function getItem(PDO $conn, ActorContext $ctx, int $id): array
    {
        $it = self::item($conn, $ctx, $id);
        $store = self::itemStore($conn, $it);
        $st = $conn->prepare(
            "SELECT i.*, t.name AS type_name, COALESCE(NULLIF(sp.trading_name, ''), sp.legal_name) AS supplier_name,
                    COALESCE(s.on_hand, 0) AS on_hand, s.min_level, s.reorder_point
               FROM consumable_items i
               LEFT JOIN consumable_types t ON t.id = i.type_id
               LEFT JOIN suppliers sp ON sp.id = i.supplier_id
               LEFT JOIN consumable_stock s ON s.item_id = i.id AND s.store_id = ?
              WHERE i.id = ?"
        );
        $st->execute([$store, $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $row = self::itemRow($r) + ['notes' => $r['notes']];
        $row['fields'] = RecordFieldsService::schemaReady($conn, 'consumable')
            ? RecordFieldsService::forRecord($conn, 'consumable', $id, $row['type_id']) : [];
        $row['movements'] = self::movements($conn, ['item' => $id], self::HISTORY_LIMIT);
        $a = $conn->prepare(
            "SELECT a.field_name, a.old_value, a.new_value, a.source, a.created_datetime, an.full_name AS analyst_name
               FROM consumable_audit a LEFT JOIN analysts an ON an.id = a.analyst_id
              WHERE a.item_id = ? ORDER BY a.id DESC LIMIT " . self::HISTORY_LIMIT
        );
        $a->execute([$id]);
        $row['audit'] = $a->fetchAll(PDO::FETCH_ASSOC);
        $row['may_adjust'] = self::mayAdjust($conn, $ctx);
        return $row;
    }

    /**
     * Create or change an item. Every changed field is recorded, levels included.
     *
     * @param array $in  id (0 = new), name, part_number, unit, type_id, supplier_id, unit_cost, notes,
     *                   is_active, min_level, reorder_point, fields {key: value}
     */
    public static function saveItem(PDO $conn, ActorContext $ctx, array $in): int
    {
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the item a name.');
        $clean = [
            'name'        => mb_substr($name, 0, 200),
            'part_number' => self::text($in['part_number'] ?? null, 100),
            'unit'        => self::text($in['unit'] ?? null, 30),
            'type_id'     => self::ref($conn, $in['type_id'] ?? null, 'consumable_types', 'That type'),
            'supplier_id' => self::ref($conn, $in['supplier_id'] ?? null, 'suppliers', 'That supplier'),
            'unit_cost'   => self::money($in['unit_cost'] ?? null, 'The unit cost'),
            'notes'       => self::text($in['notes'] ?? null, 5000),
            'is_active'   => array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1,
        ];
        $levels = [
            'min_level'     => self::level($in['min_level'] ?? null, 'The minimum level'),
            'reorder_point' => self::level($in['reorder_point'] ?? null, 'The reorder point'),
        ];
        $fieldsIn = is_array($in['fields'] ?? null) ? $in['fields'] : [];
        $defs = RecordFieldsService::schemaReady($conn, 'consumable')
            ? RecordFieldsService::fieldsFor($conn, 'consumable', $clean['type_id']) : [];
        $fieldsIn = array_intersect_key($fieldsIn, $defs);   // a field of another type is not this item's
        $fieldsIn = RecordFieldsService::validate($conn, 'consumable', $defs, $fieldsIn, $id === 0);

        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            if ($id > 0) {
                $cur = self::item($conn, $ctx, $id);
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($clean)));
                $conn->prepare("UPDATE consumable_items SET $sets, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute(array_merge(array_values($clean), [$id]));
                foreach ($clean as $k => $v) {
                    self::audit($conn, $ctx, ['item' => $id], $k, self::shown($conn, $k, $cur[$k]), self::shown($conn, $k, $v));
                }
                $item = array_merge($cur, $clean);
            } else {
                $tenant = self::tenantFor($conn, $ctx);
                $cols = array_keys($clean);
                $conn->prepare("INSERT INTO consumable_items (tenant_id, " . implode(', ', $cols) . ", created_by_analyst_id, created_datetime)
                                VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, UTC_TIMESTAMP())")
                     ->execute(array_merge([$tenant], array_values($clean), [$ctx->actorId > 0 ? $ctx->actorId : null]));
                $id = (int)$conn->lastInsertId();
                self::audit($conn, $ctx, ['item' => $id], 'created', null, $clean['name']);
                $item = ['id' => $id, 'tenant_id' => $tenant] + $clean;
            }
            // The levels, on the store's stock row.
            $store = self::itemStore($conn, $item);
            $s = self::lockStock($conn, $id, $store);
            $conn->prepare("UPDATE consumable_stock SET min_level = ?, reorder_point = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$levels['min_level'], $levels['reorder_point'], $s['id']]);
            self::audit($conn, $ctx, ['item' => $id], 'min_level', $s['min_level'], $levels['min_level']);
            self::audit($conn, $ctx, ['item' => $id], 'reorder_point', $s['reorder_point'], $levels['reorder_point']);
            if ($fieldsIn) RecordFieldsService::write($conn, $ctx, 'consumable', $id, $defs, $fieldsIn, self::source($ctx));
            if ($owns) $conn->commit();
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::checkLow($conn, $ctx, $id);
        return $id;
    }

    /** A reference written as its name in the history, not its id. */
    private static function shown(PDO $conn, string $k, $v)
    {
        if ($v === null || $v === '') return null;
        if ($k === 'type_id') {
            $st = $conn->prepare("SELECT name FROM consumable_types WHERE id = ?");
            $st->execute([(int)$v]);
            return $st->fetchColumn() ?: '#' . $v;
        }
        if ($k === 'supplier_id') {
            $st = $conn->prepare("SELECT COALESCE(NULLIF(trading_name, ''), legal_name) FROM suppliers WHERE id = ?");
            $st->execute([(int)$v]);
            return $st->fetchColumn() ?: '#' . $v;
        }
        if ($k === 'unit_cost') return number_format((float)$v, 2, '.', '');
        if ($k === 'is_active') return (int)$v ? 'Yes' : 'No';
        return (string)$v;
    }

    private static function text($v, int $max): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function ref(PDO $conn, $v, string $table, string $what): ?int
    {
        if ($v === null || $v === '' || (int)$v <= 0) return null;
        $st = $conn->prepare("SELECT 1 FROM `$table` WHERE id = ?");
        $st->execute([(int)$v]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', "$what no longer exists.");
        return (int)$v;
    }

    private static function money($v, string $what): ?float
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 9999999999) throw new ServiceError('validation', 'invalid_field', "$what must be a number of 0 or more.");
        return round((float)$v, 2);
    }

    private static function level($v, string $what): ?int
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v) || (int)$v != $v || (int)$v < 0) throw new ServiceError('validation', 'invalid_field', "$what must be a whole number of 0 or more.");
        return (int)$v;
    }

    // ====================================================================
    //  The ledger
    // ====================================================================

    /** The stock row, made if it is not there yet, LOCKED for the rest of the transaction. */
    private static function lockStock(PDO $conn, int $itemId, int $storeId): array
    {
        $conn->prepare("INSERT IGNORE INTO consumable_stock (item_id, store_id, on_hand) VALUES (?, ?, 0)")->execute([$itemId, $storeId]);
        $st = $conn->prepare("SELECT * FROM consumable_stock WHERE item_id = ? AND store_id = ? FOR UPDATE");
        $st->execute([$itemId, $storeId]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Receive, issue, return or adjust stock.
     *
     * @param string $kind  'receive' | 'issue' | 'return' | 'adjust'
     * @param array $in  quantity (a whole number; adjust: signed, never 0), unit_cost, supplier_id,
     *                   invoice_ref, invoice_date (Y-m-d), ticket_id, recipient_user_id, reason
     * @return array {movement_id, on_hand, status}
     */
    public static function move(PDO $conn, ActorContext $ctx, string $kind, int $itemId, array $in): array
    {
        if (!in_array($kind, ['receive', 'issue', 'return', 'adjust'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown kind of movement.');
        }
        if ($kind === 'adjust' && !self::mayAdjust($conn, $ctx)) {
            throw new ServiceError('forbidden', 'forbidden', 'Adjusting stock needs the "Adjust consumable stock" permission (System -> Roles).');
        }
        $qty = $in['quantity'] ?? null;
        if (!is_numeric($qty) || (int)$qty != $qty || (int)$qty === 0) {
            throw new ServiceError('validation', 'invalid_field', 'Enter a whole number for the quantity.');
        }
        $qty = (int)$qty;
        if ($kind !== 'adjust' && $qty < 0) throw new ServiceError('validation', 'invalid_field', 'The quantity must be more than 0.');
        $signed = $kind === 'adjust' ? $qty : self::KINDS[$kind] * $qty;
        $reason = self::text($in['reason'] ?? null, 500);
        if ($kind === 'adjust' && $reason === null) throw new ServiceError('validation', 'missing_field', 'Say why the stock is being adjusted.');

        $item = self::item($conn, $ctx, $itemId);
        if (!(int)$item['is_active'] && $kind !== 'adjust') {
            throw new ServiceError('conflict', 'inactive', 'That item is no longer in use. Turn it back on to move stock.');
        }
        $ticketId = null;
        if (!empty($in['ticket_id'])) {
            $ticketId = (int)$in['ticket_id'];
            if ($ctx->actorId > 0 && !analystCanAccessTicket($conn, $ctx->actorId, $ticketId)) {
                throw new ServiceError('not_found', 'not_found', 'That ticket does not exist, or you cannot see it.');
            }
        }
        $userId = null;
        if (!empty($in['recipient_user_id'])) {
            $userId = (int)$in['recipient_user_id'];
            if ($ctx->actorId > 0 && !analystCanAccessUser($conn, $ctx->actorId, $userId)) {
                throw new ServiceError('not_found', 'not_found', 'That person does not exist, or you cannot see them.');
            }
        }
        $supplierId = $kind === 'receive' ? (self::ref($conn, $in['supplier_id'] ?? null, 'suppliers', 'That supplier') ?? ($item['supplier_id'] !== null ? (int)$item['supplier_id'] : null)) : null;
        $cost = $kind === 'receive' ? self::money($in['unit_cost'] ?? null, 'The unit cost') : null;
        $cost ??= $item['unit_cost'] !== null ? (float)$item['unit_cost'] : null;
        $invoiceDate = null;
        if ($kind === 'receive' && !empty($in['invoice_date'])) {
            $d = DateTime::createFromFormat('!Y-m-d', (string)$in['invoice_date']);
            if (!$d) throw new ServiceError('validation', 'invalid_field', 'The invoice date is not a date.');
            $invoiceDate = $d->format('Y-m-d');
        }

        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            $store = self::itemStore($conn, $item);
            $s = self::lockStock($conn, $itemId, $store);
            $after = (int)$s['on_hand'] + $signed;
            if ($after < 0) {
                throw new ServiceError('conflict', 'not_enough', 'Only ' . (int)$s['on_hand'] . ' in stock.');
            }
            $mid = self::insertMovement($conn, $ctx, [
                'item_id' => $itemId, 'store_id' => $store, 'kind' => $kind, 'quantity' => $signed, 'balance_after' => $after,
                'unit_cost' => $cost, 'ticket_id' => $ticketId, 'recipient_user_id' => $userId, 'supplier_id' => $supplierId,
                'invoice_ref' => $kind === 'receive' ? self::text($in['invoice_ref'] ?? null, 100) : null,
                'invoice_date' => $invoiceDate, 'reason' => $reason,
            ]);
            $conn->prepare("UPDATE consumable_stock SET on_hand = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$after, $s['id']]);
            // A delivery at a new price: that is the price now (Ed: value at the latest receipt).
            if ($kind === 'receive' && isset($in['unit_cost']) && $in['unit_cost'] !== '' && $cost !== null
                && ($item['unit_cost'] === null || round((float)$item['unit_cost'], 2) !== round($cost, 2))) {
                $conn->prepare("UPDATE consumable_items SET unit_cost = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$cost, $itemId]);
                self::audit($conn, $ctx, ['item' => $itemId], 'unit_cost', self::shown($conn, 'unit_cost', $item['unit_cost']), self::shown($conn, 'unit_cost', $cost));
            }
            if ($ticketId) self::ticketNote($conn, $ctx, $ticketId, $kind, $item, abs($signed), $userId);
            if ($owns) $conn->commit();
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::checkLow($conn, $ctx, $itemId);
        return ['movement_id' => $mid, 'on_hand' => $after, 'status' => self::status($after, $s['reorder_point'] !== null ? (int)$s['reorder_point'] : null)];
    }

    private static function insertMovement(PDO $conn, ActorContext $ctx, array $m): int
    {
        $conn->prepare(
            "INSERT INTO consumable_movements (item_id, store_id, kind, quantity, balance_after, unit_cost, ticket_id, recipient_user_id,
                                               supplier_id, invoice_ref, invoice_date, reason, reverses_id, count_id, analyst_id, source, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $m['item_id'], $m['store_id'], $m['kind'], $m['quantity'], $m['balance_after'], $m['unit_cost'] ?? null,
            $m['ticket_id'] ?? null, $m['recipient_user_id'] ?? null, $m['supplier_id'] ?? null, $m['invoice_ref'] ?? null,
            $m['invoice_date'] ?? null, $m['reason'] ?? null, $m['reverses_id'] ?? null, $m['count_id'] ?? null,
            $ctx->actorId > 0 ? $ctx->actorId : null, self::source($ctx),
        ]);
        return (int)$conn->lastInsertId();
    }

    /** An issue or return against a ticket says so in the ticket's history. */
    private static function ticketNote(PDO $conn, ActorContext $ctx, int $ticketId, string $kind, array $item, int $qty, ?int $userId): void
    {
        $what = $qty . ' x ' . $item['name'] . ($item['part_number'] ? ' (' . $item['part_number'] . ')' : '');
        if ($userId) {
            $st = $conn->prepare("SELECT display_name, preferred_name, email, username, id FROM users WHERE id = ?");
            $st->execute([$userId]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                require_once __DIR__ . '/../people.php';
                $what .= ($kind === 'return' ? ' from ' : ' to ') . peopleName($u);
            }
        }
        $field = ['issue' => 'Consumable issued', 'return' => 'Consumable returned', 'reverse' => 'Consumable movement undone'][$kind] ?? 'Consumable';
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, NULL, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, $ctx->actorId > 0 ? $ctx->actorId : null, $field, mb_substr($what, 0, 500)]);
    }

    /**
     * Undo a movement: the opposite quantity, pointing at the original, with a reason.
     * Undoing an adjustment needs the adjust permission, as making one did. A stock
     * take's posting, and an undo, cannot be undone - a new stock take or adjustment is.
     */
    public static function reverse(PDO $conn, ActorContext $ctx, int $movementId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') throw new ServiceError('validation', 'missing_field', 'Say why this is being undone.');
        $st = $conn->prepare("SELECT * FROM consumable_movements WHERE id = ?");
        $st->execute([$movementId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new ServiceError('not_found', 'not_found', 'That movement does not exist.');
        $item = self::item($conn, $ctx, (int)$m['item_id']);
        if (in_array($m['kind'], ['reverse', 'count'], true)) {
            throw new ServiceError('conflict', 'not_reversible', $m['kind'] === 'count'
                ? 'A stock take cannot be undone. Count again, or adjust the stock.'
                : 'An undo cannot itself be undone. Record the movement again instead.');
        }
        if ($m['kind'] === 'adjust' && !self::mayAdjust($conn, $ctx)) {
            throw new ServiceError('forbidden', 'forbidden', 'Undoing an adjustment needs the "Adjust consumable stock" permission (System -> Roles).');
        }
        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            $s = self::lockStock($conn, (int)$m['item_id'], (int)$m['store_id']);
            $done = $conn->prepare("SELECT 1 FROM consumable_movements WHERE reverses_id = ?");
            $done->execute([$movementId]);
            if ($done->fetchColumn()) throw new ServiceError('conflict', 'already_reversed', 'That movement has already been undone.');
            $signed = -(int)$m['quantity'];
            $after = (int)$s['on_hand'] + $signed;
            if ($after < 0) throw new ServiceError('conflict', 'not_enough', 'Only ' . (int)$s['on_hand'] . ' in stock, so this cannot be undone.');
            $mid = self::insertMovement($conn, $ctx, [
                'item_id' => (int)$m['item_id'], 'store_id' => (int)$m['store_id'], 'kind' => 'reverse', 'quantity' => $signed,
                'balance_after' => $after, 'unit_cost' => $m['unit_cost'], 'ticket_id' => $m['ticket_id'],
                'recipient_user_id' => $m['recipient_user_id'], 'reason' => mb_substr($reason, 0, 500), 'reverses_id' => $movementId,
            ]);
            $conn->prepare("UPDATE consumable_stock SET on_hand = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$after, $s['id']]);
            if ($m['ticket_id']) self::ticketNote($conn, $ctx, (int)$m['ticket_id'], 'reverse', $item, abs($signed), null);
            if ($owns) $conn->commit();
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::checkLow($conn, $ctx, (int)$m['item_id']);
        return ['movement_id' => $mid, 'on_hand' => $after];
    }

    /**
     * Movements, newest first, with names: for an item's history, a ticket or a person.
     *
     * @param array $f  item, ticket, recipient (ids)
     */
    public static function movements(PDO $conn, array $f, int $limit = self::HISTORY_LIMIT): array
    {
        $where = []; $args = [];
        foreach (['item' => 'm.item_id', 'ticket' => 'm.ticket_id', 'recipient' => 'm.recipient_user_id'] as $k => $col) {
            if (!empty($f[$k])) { $where[] = "$col = ?"; $args[] = (int)$f[$k]; }
        }
        if (!$where) return [];
        $st = $conn->prepare(
            "SELECT m.*, i.name AS item_name, i.unit, an.full_name AS analyst_name,
                    COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email, u.username) AS recipient_name,
                    COALESCE(NULLIF(sp.trading_name, ''), sp.legal_name) AS supplier_name,
                    t.ticket_number, (SELECT r.id FROM consumable_movements r WHERE r.reverses_id = m.id) AS reversed_by
               FROM consumable_movements m
               JOIN consumable_items i ON i.id = m.item_id
               LEFT JOIN analysts an ON an.id = m.analyst_id
               LEFT JOIN users u ON u.id = m.recipient_user_id
               LEFT JOIN suppliers sp ON sp.id = m.supplier_id
               LEFT JOIN tickets t ON t.id = m.ticket_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY m.id DESC LIMIT " . max(1, $limit)
        );
        $st->execute($args);
        return array_map(function ($r) {
            foreach (['id', 'item_id', 'store_id', 'quantity', 'balance_after', 'ticket_id', 'recipient_user_id', 'supplier_id', 'reverses_id', 'count_id', 'reversed_by'] as $k) {
                $r[$k] = $r[$k] !== null ? (int)$r[$k] : null;
            }
            $r['unit_cost'] = $r['unit_cost'] !== null ? (float)$r['unit_cost'] : null;
            $r['value'] = $r['unit_cost'] !== null ? round($r['quantity'] * $r['unit_cost'], 2) : null;
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ====================================================================
    //  Running low
    // ====================================================================

    /**
     * After any change: at or below the reorder point, tell the people chosen in
     * Assets -> Settings -> Consumables - once per dip (low_notified_datetime), and
     * again only after the stock has recovered and fallen again.
     */
    public static function checkLow(PDO $conn, ActorContext $ctx, int $itemId): void
    {
        try {
            $st = $conn->prepare(
                "SELECT s.id, s.on_hand, s.reorder_point, s.low_notified_datetime, i.name, i.is_active
                   FROM consumable_stock s JOIN consumable_items i ON i.id = s.item_id
                  WHERE s.item_id = ?"
            );
            $st->execute([$itemId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $low = $s['reorder_point'] !== null && (int)$s['on_hand'] <= (int)$s['reorder_point'];
                if (!$low) {
                    if ($s['low_notified_datetime'] !== null) {
                        $conn->prepare("UPDATE consumable_stock SET low_notified_datetime = NULL WHERE id = ?")->execute([$s['id']]);
                    }
                    continue;
                }
                if ($s['low_notified_datetime'] !== null || !(int)$s['is_active']) continue;
                $conn->prepare("UPDATE consumable_stock SET low_notified_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$s['id']]);
                foreach (self::notifyList($conn) as $aid) {
                    NotificationsService::notify($conn, [
                        'analyst_id' => $aid, 'event_type' => 'consumable.low_stock', 'entity_type' => 'consumable',
                        'entity_id' => $itemId, 'entity_ref' => $s['name'],
                        'title' => $s['name'], 'body' => (int)$s['on_hand'] . ' left (reorder at ' . (int)$s['reorder_point'] . ')',
                        'actor_id' => $ctx->actorId > 0 ? $ctx->actorId : null, 'actor_name' => $ctx->actorName,
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('consumables low-stock: ' . $e->getMessage());   // never stops the movement that caused it
        }
    }

    /** The analysts told about low stock: Assets -> Settings -> Consumables. */
    public static function notifyList(PDO $conn): array
    {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'consumables_low_notify'");
        $st->execute();
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$st->fetchColumn()))));
        if (!$ids) return [];
        $chk = $conn->prepare("SELECT id FROM analysts WHERE is_active = 1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $chk->execute($ids);
        return array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
    }

    // ====================================================================
    //  Stock takes
    // ====================================================================

    /** The store's open stock take, or null. */
    public static function openCount(PDO $conn, ActorContext $ctx): ?array
    {
        $store = self::storeFor($conn, self::tenantFor($conn, $ctx));
        $st = $conn->prepare(
            "SELECT c.*, an.full_name AS started_by FROM consumable_counts c LEFT JOIN analysts an ON an.id = c.started_by_analyst_id
              WHERE c.store_id = ? AND c.status = 'open' ORDER BY c.id DESC LIMIT 1"
        );
        $st->execute([$store]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Start a stock take for the active company's store. One at a time. */
    public static function startCount(PDO $conn, ActorContext $ctx, string $note = ''): int
    {
        if (self::openCount($conn, $ctx)) throw new ServiceError('conflict', 'count_open', 'A stock take is already under way. Finish or cancel it first.');
        $store = self::storeFor($conn, self::tenantFor($conn, $ctx));
        $conn->prepare("INSERT INTO consumable_counts (store_id, status, note, started_by_analyst_id, started_datetime) VALUES (?, 'open', ?, ?, UTC_TIMESTAMP())")
             ->execute([$store, self::text($note, 500), $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        self::audit($conn, $ctx, ['count' => $id], 'count.started', null, 'Stock take #' . $id);
        return $id;
    }

    private static function loadCount(PDO $conn, ActorContext $ctx, int $countId): array
    {
        $st = $conn->prepare("SELECT c.*, s.tenant_id, a1.full_name AS started_by, a2.full_name AS finished_by FROM consumable_counts c
                                JOIN consumable_stores s ON s.id = c.store_id
                                LEFT JOIN analysts a1 ON a1.id = c.started_by_analyst_id LEFT JOIN analysts a2 ON a2.id = c.finished_by_analyst_id
                               WHERE c.id = ?");
        $st->execute([$countId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || ($ctx->actorId > 0 && !analystCanAccessTenant($conn, $ctx->actorId, (int)$c['tenant_id'] ?: getDefaultTenantId($conn)))) {
            throw new ServiceError('not_found', 'not_found', 'That stock take does not exist.');
        }
        return $c;
    }

    /** A stock take: every active item in its store, what is expected, and what has been counted. */
    public static function getCount(PDO $conn, ActorContext $ctx, int $countId): array
    {
        $c = self::loadCount($conn, $ctx, $countId);
        $st = $conn->prepare(
            "SELECT i.id AS item_id, i.name, i.part_number, i.unit, t.name AS type_name, COALESCE(s.on_hand, 0) AS on_hand,
                    i.unit_cost, l.expected, l.counted, l.difference, l.value_difference, l.counted_datetime, an.full_name AS counted_by
               FROM consumable_items i
               LEFT JOIN consumable_types t ON t.id = i.type_id
               LEFT JOIN consumable_stock s ON s.item_id = i.id AND s.store_id = ?
               LEFT JOIN consumable_count_lines l ON l.item_id = i.id AND l.count_id = ?
               LEFT JOIN analysts an ON an.id = l.counted_by_analyst_id
              WHERE COALESCE(i.tenant_id, ?) = ? AND (i.is_active = 1 OR l.id IS NOT NULL)
              ORDER BY t.name IS NULL, t.name, i.name"
        );
        $tenant = (int)$c['tenant_id'] ?: getDefaultTenantId($conn);
        $st->execute([(int)$c['store_id'], $countId, getDefaultTenantId($conn), $tenant]);
        $lines = array_map(function ($r) {
            foreach (['item_id', 'on_hand', 'expected', 'counted', 'difference'] as $k) $r[$k] = $r[$k] !== null ? (int)$r[$k] : null;
            foreach (['unit_cost', 'value_difference'] as $k) $r[$k] = $r[$k] !== null ? (float)$r[$k] : null;
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $c['lines'] = $lines;
        $c['may_finish'] = self::mayAdjust($conn, $ctx);
        return $c;
    }

    /**
     * Record what is on the shelf for one item. The expected figure is what the ledger
     * says AT THIS MOMENT, so the difference is fixed now - stock issued between counting
     * and finishing still comes out right, because finishing posts the difference, not
     * the counted figure. Counting again replaces the line (both are in the history).
     */
    public static function countLine(PDO $conn, ActorContext $ctx, int $countId, int $itemId, $counted): array
    {
        $c = self::loadCount($conn, $ctx, $countId);
        if ($c['status'] !== 'open') throw new ServiceError('conflict', 'count_closed', 'That stock take is closed.');
        if (!is_numeric($counted) || (int)$counted != $counted || (int)$counted < 0) {
            throw new ServiceError('validation', 'invalid_field', 'Enter how many are there - a whole number, 0 or more.');
        }
        $counted = (int)$counted;
        $item = self::item($conn, $ctx, $itemId);
        $st = $conn->prepare("SELECT on_hand FROM consumable_stock WHERE item_id = ? AND store_id = ?");
        $st->execute([$itemId, (int)$c['store_id']]);
        $expected = (int)$st->fetchColumn();
        $diff = $counted - $expected;
        $cost = $item['unit_cost'] !== null ? (float)$item['unit_cost'] : null;
        $value = $cost !== null ? round($diff * $cost, 2) : null;
        $old = $conn->prepare("SELECT counted FROM consumable_count_lines WHERE count_id = ? AND item_id = ?");
        $old->execute([$countId, $itemId]);
        $was = $old->fetchColumn();
        $conn->prepare(
            "INSERT INTO consumable_count_lines (count_id, item_id, expected, counted, difference, unit_cost, value_difference, counted_by_analyst_id, counted_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE expected = VALUES(expected), counted = VALUES(counted), difference = VALUES(difference),
                                     unit_cost = VALUES(unit_cost), value_difference = VALUES(value_difference),
                                     counted_by_analyst_id = VALUES(counted_by_analyst_id), counted_datetime = VALUES(counted_datetime)"
        )->execute([$countId, $itemId, $expected, $counted, $diff, $cost, $value, $ctx->actorId > 0 ? $ctx->actorId : null]);
        self::audit($conn, $ctx, ['item' => $itemId, 'count' => $countId], 'count.counted',
            $was === false ? null : (string)$was, $counted . ' (expected ' . $expected . ')');
        return ['expected' => $expected, 'counted' => $counted, 'difference' => $diff, 'value_difference' => $value];
    }

    /**
     * Finish a stock take: each difference is posted as a Counted movement, and stays on
     * the line for ever. Needs the adjust permission - a count that changes stock IS an
     * adjustment (Ed: "configurable in System -> Roles").
     */
    public static function finishCount(PDO $conn, ActorContext $ctx, int $countId): array
    {
        if (!self::mayAdjust($conn, $ctx)) {
            throw new ServiceError('forbidden', 'forbidden', 'Finishing a stock take needs the "Adjust consumable stock" permission (System -> Roles).');
        }
        $c = self::loadCount($conn, $ctx, $countId);
        if ($c['status'] !== 'open') throw new ServiceError('conflict', 'count_closed', 'That stock take is already closed.');
        $posted = 0; $units = 0; $value = 0.0;
        $owns = !$conn->inTransaction();
        if ($owns) $conn->beginTransaction();
        try {
            $st = $conn->prepare("SELECT * FROM consumable_count_lines WHERE count_id = ? AND difference <> 0 FOR UPDATE");
            $st->execute([$countId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $s = self::lockStock($conn, (int)$l['item_id'], (int)$c['store_id']);
                $after = max(0, (int)$s['on_hand'] + (int)$l['difference']);   // stock issued since counting cannot take it below zero
                $mid = self::insertMovement($conn, $ctx, [
                    'item_id' => (int)$l['item_id'], 'store_id' => (int)$c['store_id'], 'kind' => 'count',
                    'quantity' => $after - (int)$s['on_hand'], 'balance_after' => $after, 'unit_cost' => $l['unit_cost'],
                    'reason' => 'Stock take #' . $countId . ': expected ' . (int)$l['expected'] . ', counted ' . (int)$l['counted'],
                    'count_id' => $countId,
                ]);
                $conn->prepare("UPDATE consumable_stock SET on_hand = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$after, $s['id']]);
                $conn->prepare("UPDATE consumable_count_lines SET movement_id = ? WHERE id = ?")->execute([$mid, $l['id']]);
                $posted++; $units += (int)$l['difference']; $value += (float)($l['value_difference'] ?? 0);
            }
            $conn->prepare("UPDATE consumable_counts SET status = 'finished', finished_by_analyst_id = ?, finished_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, $countId]);
            self::audit($conn, $ctx, ['count' => $countId], 'count.finished', 'open', $posted . ' differences, ' . $units . ' units');
            if ($owns) $conn->commit();
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        $items = $conn->prepare("SELECT DISTINCT item_id FROM consumable_count_lines WHERE count_id = ? AND difference <> 0");
        $items->execute([$countId]);
        foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $iid) self::checkLow($conn, $ctx, (int)$iid);
        return ['differences' => $posted, 'units' => $units, 'value' => round($value, 2)];
    }

    /** Cancel an open stock take: nothing is posted; what was counted stays on record. */
    public static function cancelCount(PDO $conn, ActorContext $ctx, int $countId): void
    {
        $c = self::loadCount($conn, $ctx, $countId);
        if ($c['status'] !== 'open') throw new ServiceError('conflict', 'count_closed', 'That stock take is already closed.');
        $conn->prepare("UPDATE consumable_counts SET status = 'cancelled', finished_by_analyst_id = ?, finished_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, $countId]);
        self::audit($conn, $ctx, ['count' => $countId], 'count.cancelled', 'open', 'cancelled');
    }

    /** Every stock take in the active company's store, newest first, with its totals. */
    public static function counts(PDO $conn, ActorContext $ctx): array
    {
        $store = self::storeFor($conn, self::tenantFor($conn, $ctx));
        $st = $conn->prepare(
            "SELECT c.id, c.status, c.note, c.started_datetime, c.finished_datetime, a1.full_name AS started_by, a2.full_name AS finished_by,
                    COUNT(l.id) AS line_count,
                    COALESCE(SUM(CASE WHEN l.difference < 0 THEN l.difference END), 0) AS units_lost,
                    COALESCE(SUM(CASE WHEN l.difference > 0 THEN l.difference END), 0) AS units_found,
                    COALESCE(SUM(CASE WHEN l.value_difference < 0 THEN l.value_difference END), 0) AS value_lost,
                    COALESCE(SUM(CASE WHEN l.value_difference > 0 THEN l.value_difference END), 0) AS value_found
               FROM consumable_counts c
               LEFT JOIN consumable_count_lines l ON l.count_id = c.id
               LEFT JOIN analysts a1 ON a1.id = c.started_by_analyst_id
               LEFT JOIN analysts a2 ON a2.id = c.finished_by_analyst_id
              WHERE c.store_id = ?
              GROUP BY c.id ORDER BY c.id DESC LIMIT 200"
        );
        $st->execute([$store]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The differences report: what finished stock takes found, by month and by item.
     * Losses and gains apart - a gain of 3 must not hide a loss of 3.
     *
     * @param int $months  how far back
     */
    public static function differences(PDO $conn, ActorContext $ctx, int $months = 12): array
    {
        $store = self::storeFor($conn, self::tenantFor($conn, $ctx));
        $months = max(1, min(60, $months));
        // Month arithmetic in UTC throughout - strtotime() works in the server's zone, and an
        // hour of summer time either side of midnight gave one month twice and lost another.
        $first = new DateTimeImmutable('first day of this month 00:00', new DateTimeZone('UTC'));
        $since = $first->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
        $base = "FROM consumable_count_lines l
                 JOIN consumable_counts c ON c.id = l.count_id AND c.status = 'finished' AND c.store_id = ?
                 JOIN consumable_items i ON i.id = l.item_id
                WHERE l.difference <> 0 AND c.finished_datetime >= ?";
        $sum = "COALESCE(SUM(CASE WHEN l.difference < 0 THEN l.difference END), 0) AS units_lost,
                COALESCE(SUM(CASE WHEN l.difference > 0 THEN l.difference END), 0) AS units_found,
                COALESCE(SUM(CASE WHEN l.value_difference < 0 THEN l.value_difference END), 0) AS value_lost,
                COALESCE(SUM(CASE WHEN l.value_difference > 0 THEN l.value_difference END), 0) AS value_found";
        $m = $conn->prepare("SELECT DATE_FORMAT(c.finished_datetime, '%Y-%m') AS month, $sum $base GROUP BY month ORDER BY month");
        $m->execute([$store, $since]);
        $byMonth = [];
        foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $byMonth[$r['month']] = $r;
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $k = $first->modify("-$i months")->format('Y-m');
            $r = $byMonth[$k] ?? ['units_lost' => 0, 'units_found' => 0, 'value_lost' => 0, 'value_found' => 0];
            $series[] = ['month' => $k, 'units_lost' => (int)$r['units_lost'], 'units_found' => (int)$r['units_found'],
                         'value_lost' => (float)$r['value_lost'], 'value_found' => (float)$r['value_found']];
        }
        $it = $conn->prepare("SELECT i.id, i.name, i.unit, COUNT(*) AS times, $sum $base GROUP BY i.id ORDER BY value_lost, units_lost LIMIT 50");
        $it->execute([$store, $since]);
        $items = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'times' => (int)$r['times'],
            'units_lost' => (int)$r['units_lost'], 'units_found' => (int)$r['units_found'],
            'value_lost' => (float)$r['value_lost'], 'value_found' => (float)$r['value_found']], $it->fetchAll(PDO::FETCH_ASSOC));
        return ['months' => $months, 'series' => $series, 'items' => $items, 'counts' => self::counts($conn, $ctx)];
    }

    // ====================================================================
    //  Checking the books
    // ====================================================================

    /**
     * Items whose on-hand figure does not equal the sum of their ledger - which should
     * never happen. Returned for the screen and the tests; never "fixed" silently.
     *
     * @return array<int,array> item_id, store_id, on_hand, ledger
     */
    public static function verify(PDO $conn): array
    {
        return $conn->query(
            "SELECT s.item_id, s.store_id, s.on_hand, COALESCE(SUM(m.quantity), 0) AS ledger
               FROM consumable_stock s
               LEFT JOIN consumable_movements m ON m.item_id = s.item_id AND m.store_id = s.store_id
              GROUP BY s.id HAVING s.on_hand <> ledger"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
