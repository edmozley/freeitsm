<?php
/**
 * Time-based workflow triggers.
 *
 * Every other trigger in the engine hangs off a WRITE PATH: someone saved a
 * ticket, submitted a form, approved a change — so there is a moment to call
 * dispatch() from. These ones don't. "The SLA is about to breach" and "this
 * contract expires in 30 days" are not events at all: nothing happened, TIME
 * PASSED. There is nothing to hang a dispatch() off, so something has to go
 * LOOKING.
 *
 * That creates a problem the write-path triggers never have: the detector runs
 * every few minutes, and the condition it detects STAYS TRUE. A breached SLA is
 * still breached on the next run, and the one after that. Naively dispatching
 * on detection would re-fire the same escalation every few minutes, forever —
 * which would be worse than no feature at all.
 *
 * So the heart of this file is not the detectors. It's workflowEmitOnce():
 * an atomic, database-backed ledger that guarantees a given (event, thing,
 * state) fires exactly once, even if two cron runs overlap.
 */

require_once __DIR__ . '/../workflow/includes/engine.php';

/**
 * Dispatch a time-based event — but only the FIRST time this exact situation is
 * seen. Returns true if it fired, false if it had already fired.
 *
 * The three keys:
 *   $event       the trigger, e.g. 'sla.breached'
 *   $entityKey   WHAT it's about, e.g. 'ticket:183:response'
 *   $fingerprint the STATE it's about — a value that changes if the underlying
 *                deadline changes.
 *
 * The fingerprint is what makes this re-arm correctly. If a ticket's priority
 * changes, its SLA target changes, and the fingerprint changes with it — so the
 * new deadline can breach and fire again, rather than being suppressed forever
 * by an emission recorded against the OLD deadline. Same for a contract whose
 * end date is pushed back. Without it, "fire once" would quietly mean "fire once
 * ever, even if the thing you were watching changed underneath you".
 *
 * Atomicity: the ledger has a UNIQUE key over all three, and we INSERT IGNORE.
 * Only the insert that actually created a row dispatches. Two overlapping cron
 * runs therefore cannot double-fire — the database, not the application, is the
 * arbiter.
 */
function workflowEmitOnce(PDO $conn, string $event, string $entityKey, string $fingerprint, array $payload): bool
{
    // Nobody listening? Then record NOTHING.
    //
    // This is not an optimisation — it's a correctness fix. If we wrote a ledger
    // row when no workflow was active, the ledger would say "already fired" for
    // every contract currently inside its window. Switch on a renewal workflow
    // tomorrow and it would stay silent for all of them, because the emission was
    // burned on an audience of nobody. Enabling a workflow would appear to do
    // nothing at all, and the reason would be invisible.
    //
    // Skipping the ledger means the next run after you activate a workflow fires
    // for everything currently in-window — which is what anyone would expect.
    try {
        $listening = $conn->prepare("SELECT COUNT(*) FROM workflows WHERE trigger_event = ? AND is_active = 1");
        $listening->execute([$event]);
        if ((int)$listening->fetchColumn() === 0) {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO workflow_scheduled_emissions
             (trigger_event, entity_key, fingerprint, emitted_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$event, $entityKey, $fingerprint]);
        if ($stmt->rowCount() !== 1) {
            return false;   // already emitted for this exact state
        }
    } catch (Exception $e) {
        // Ledger unavailable (pre-Database-Verify). Emitting anyway would risk
        // re-firing an escalation every few minutes, which is far worse than not
        // firing — so we stay silent and say so in the log.
        error_log('[workflow_scheduled] emission ledger unavailable, NOT dispatching ' . $event . ': ' . $e->getMessage());
        return false;
    }

    try {
        WorkflowEngine::dispatch($event, $payload);
    } catch (Exception $e) {
        // The engine swallows its own errors; this is belt and braces.
        error_log('[workflow_scheduled] dispatch failed for ' . $event . ': ' . $e->getMessage());
    }
    return true;
}

/**
 * The windows we announce an expiry at. A contract expiring in 45 days crosses
 * the 90-day window today and the 30-day window in a fortnight — two separate
 * emissions, each carrying `window_days`, so a workflow can say "only tell me at
 * 30 days" with a plain condition rather than needing its own scheduling.
 */
function workflowExpiryWindows(): array
{
    return [90, 30, 7, 1];
}

/**
 * Contracts approaching their end date → `contract.expiring`.
 */
function workflowEmitContractExpiries(PDO $conn): int
{
    $fired = 0;
    $windows = workflowExpiryWindows();
    $maxWindow = max($windows);

    $rows = $conn->prepare(
        // Suppliers have no single `name`: trading_name is what people call them,
        // legal_name is what's on the contract. Prefer the former, fall back.
        "SELECT c.id, c.contract_number, c.title, c.contract_end, c.supplier_id,
                COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier_name,
                DATEDIFF(c.contract_end, CURDATE()) AS days_remaining
           FROM contracts c
      LEFT JOIN suppliers s ON s.id = c.supplier_id
          WHERE c.is_active = 1
            AND c.contract_end IS NOT NULL
            AND c.contract_end >= CURDATE()
            AND c.contract_end <= DATE_ADD(CURDATE(), INTERVAL ? DAY)"
    );
    $rows->execute([$maxWindow]);

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $days = (int)$c['days_remaining'];
        foreach ($windows as $w) {
            if ($days > $w) continue;   // hasn't entered this window yet

            $fired += workflowEmitOnce(
                $conn,
                'contract.expiring',
                'contract:' . (int)$c['id'] . ':' . $w,
                // End date is the fingerprint: renew the contract and the new
                // date re-arms every window, so next year's reminders still fire.
                (string)$c['contract_end'],
                [
                    'contract' => [
                        'id'              => (int)$c['id'],
                        'number'          => $c['contract_number'],
                        'title'           => $c['title'],
                        'end_date'        => $c['contract_end'],
                        'days_remaining'  => $days,
                        'supplier_id'     => $c['supplier_id'] !== null ? (int)$c['supplier_id'] : null,
                        'supplier_name'   => $c['supplier_name'],
                    ],
                    'window_days' => $w,
                ]
            ) ? 1 : 0;
        }
    }
    return $fired;
}

/**
 * Asset warranties approaching expiry → `asset.warranty_expiring`.
 */
function workflowEmitWarrantyExpiries(PDO $conn): int
{
    $fired = 0;
    $windows = workflowExpiryWindows();
    $maxWindow = max($windows);

    $rows = $conn->prepare(
        "SELECT a.id, a.hostname, a.warranty_expiry,
                DATEDIFF(a.warranty_expiry, CURDATE()) AS days_remaining
           FROM assets a
          WHERE a.warranty_expiry IS NOT NULL
            AND a.warranty_expiry >= CURDATE()
            AND a.warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY)"
    );
    $rows->execute([$maxWindow]);

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $days = (int)$a['days_remaining'];
        foreach ($windows as $w) {
            if ($days > $w) continue;

            $fired += workflowEmitOnce(
                $conn,
                'asset.warranty_expiring',
                'asset:' . (int)$a['id'] . ':' . $w,
                (string)$a['warranty_expiry'],
                [
                    'asset' => [
                        'id'             => (int)$a['id'],
                        'hostname'       => $a['hostname'],
                        'warranty_end'   => $a['warranty_expiry'],
                        'days_remaining' => $days,
                    ],
                    'window_days' => $w,
                ]
            ) ? 1 : 0;
        }
    }
    return $fired;
}

/**
 * Everything the scheduled-trigger cron runs. SLA events are NOT here — they're
 * emitted from sla_run_breach_check(), which already walks every open ticket and
 * computes its SLA state, so emitting from there costs nothing and cannot drift
 * from what the SLA emails think is true.
 */
function workflowScheduledRun(PDO $conn): array
{
    return [
        'contract_expiring'        => workflowEmitContractExpiries($conn),
        'asset_warranty_expiring'  => workflowEmitWarrantyExpiries($conn),
    ];
}

/**
 * Drop ledger rows for events long past. Keeping them forever would grow without
 * bound; a year is far longer than any window we announce, so nothing can be
 * resurrected by a prune.
 */
function workflowPruneEmissions(PDO $conn, int $keepDays = 365): int
{
    try {
        $stmt = $conn->prepare(
            "DELETE FROM workflow_scheduled_emissions
              WHERE emitted_datetime < UTC_TIMESTAMP() - INTERVAL ? DAY"
        );
        $stmt->execute([$keepDays]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}
