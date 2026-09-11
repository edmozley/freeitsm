<?php
/**
 * API Endpoint: Database Verification
 * Checks all tables and columns exist, creates any that are missing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/setup_state.php';
require_once '../../includes/db_errors.php';    // dbErrorIsUnknownColumn(), used below
require_once '../../includes/encryption.php';   // seeds + migrates secret settings
require_once '../../includes/uploads.php';      // ATTACHMENT_QUARANTINE_EXT

header('Content-Type: application/json');

// ⚠️ Database Verification CREATES, ALTERS **AND DROPS**. TAKE A BACKUP FIRST.
//
// This comment used to say it "never drops anything". That was false, and had been
// for a long time: this file contains DROP COLUMN for tickets.status,
// tickets.priority, tickets.requester_email, tickets.requester_name,
// ticket_rota_entries.location, warroom_messages.team_id and
// warroom_presence.team_id, plus DROP INDEX and DROP FOREIGN KEY. Each drop is
// guarded and each is the tail end of a migration that has already copied the data
// somewhere better — but "the data was moved first" is a very different promise from
// "nothing is ever dropped", and an administrator who read the old sentence would
// reasonably have skipped their backup. Reported by Erlend Volden (F10).
//
// It IS idempotent: running it twice changes nothing the second time.
//
// To see what a run would do to a particular database before running it, call
// api/system/db_verify_preview.php — read-only, executes no DDL at all.
//
// It is unmistakably an administrator's action, and it once checked only that you were
// logged in: any analyst at all could run schema changes against the live database.
// (Found closing out the RBAC roll-out. D005 had missed it too, for two separate
// reasons — see that tool's write-detection and signature patterns.)
//
// There IS a real bootstrap problem underneath: on a fresh install no analyst exists yet,
// and this is the endpoint that builds the table they will log in against. That used to be
// solved with a $_SESSION['setup_access'] flag — but setup/index.php granted that flag to
// every anonymous visitor, so `GET /setup/` then `POST` here ran migrations with no
// credentials at any point. We now ask the database the question the flag was standing in
// for, so there is nothing left to forge: the unauthenticated path exists only while it is
// genuinely the only path. See includes/setup_state.php.
try {
    $conn = connectToDatabase();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!installIsUnprovisioned($conn)) {
    if (!isset($_SESSION['analyst_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    require_once '../../includes/admin_api_guard.php';   // administrators only
}

/**
 * Complete database schema definition (MySQL).
 * Each table maps to an array of columns.
 * Column format: 'column_name' => 'TYPE [NOT] NULL [DEFAULT ...]'
 * The first column with 'AUTO_INCREMENT' is the primary key.
 */
// The expected columns of every table, lifted into its own requirable file so
// the drift guard (and any future tooling) can read it — see the header there.
$schema = require __DIR__ . '/../../includes/db_verify_schema.php';

// Primary key definitions: table => pk_column (defaults to 'id')
$primaryKeys = [
    // ⚠️ No auto-increment id on purpose: counter_key being the PK is what lets
    // the read-and-increment happen in one statement (#1147).
    'ticket_number_counters'    => 'counter_key',
    'attachment_text'           => 'attachment_id',
    'document_text'             => 'document_id',
    'system_settings'           => 'setting_key',
    'morningChecks_Checks'      => 'CheckID',
    'morningChecks_Results'     => 'ResultID',
    'morningChecks_Statuses'    => 'StatusID',
    // ⚠️ A new table whose PK is not literally `id` MUST be listed here. The
    // CREATE builder below falls back to PRIMARY KEY (`id`), so the table fails
    // to create with "Key column 'id' doesn't exist" — which is how these two
    // announced themselves. The $schema array carries columns and nothing else.
    'morningChecks_Groups'      => 'GroupID',
    'morningChecks_ResultLinks' => 'LinkID',
    // Composite keys are an ARRAY. They used to be `null` here and then named
    // again in an if/elseif chain inside the CREATE builder, which meant adding
    // one was a code change in two places and forgetting the second half failed
    // silently — the table simply fell through to PRIMARY KEY (`id`).
    'knowledge_article_tags'    => ['article_id', 'tag_id'],
    'task_tag_map'              => ['task_id', 'tag_id'],
    // 🔴 GH #123. Both of these were missed, and the warning above is the one
    // they were missed in spite of. A table is only safe to leave out of this
    // map if its PK is literally `id`; neither of these has an `id` column at
    // all, so verification could not create them on a fresh install and
    // reported "Key column 'id' doesn't exist in table" twice, every run.
    'knowledge_gap_tickets'         => 'ticket_id',
    'knowledge_gap_cluster_tickets' => ['cluster_id', 'ticket_id'],
];

try {
    // $conn was opened above, before the authorisation gate — that gate has to ask
    // the database whether any analyst exists, so the connection has to come first.
    $results = [];
    $dbName = DB_NAME;

    // Multi-tenancy: was target_mailboxes.tenant_id absent *before* this run added
    // it? If so the post-schema section backfills existing mailboxes to the Default
    // company (pinning them) — but ONLY this once. Once multi-tenancy is live a NULL
    // tenant_id legitimately means "shared intake" (route by sender), so we must
    // never re-backfill on later verifies or we'd clobber that deliberate choice.
    $mailboxTenantColWasMissing = false;
    try {
        $mbProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'target_mailboxes' AND column_name = 'tenant_id'");
        $mbProbe->execute([$dbName]);
        $mailboxTenantColWasMissing = ((int)$mbProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Same one-time logic for tickets.tenant_id: backfill existing tickets to the
    // Default company only when the column is first added. After that a NULL
    // tenant_id is meaningful — it marks an inbound email that matched no company
    // and is waiting in the TRIAGE queue — so a repeated sweep would wrongly file
    // every triaged ticket under Default and empty the queue.
    $ticketsTenantColWasMissing = false;
    try {
        $tkProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'tickets' AND column_name = 'tenant_id'");
        $tkProbe->execute([$dbName]);
        $ticketsTenantColWasMissing = ((int)$tkProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Was analysts.is_admin absent *before* this run added it? If so, every existing
    // analyst predates the admin/non-admin split and must be grandfathered to admin
    // (below) so an upgrade never locks anyone out of System. Once the column exists
    // the flag is managed deliberately, so this backfill must run only this once.
    $analystIsAdminColWasMissing = false;
    try {
        $iaProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'analysts' AND column_name = 'is_admin'");
        $iaProbe->execute([$dbName]);
        $analystIsAdminColWasMissing = ((int)$iaProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Module access (issue #30): was analysts.can_access_all_modules absent before
    // this run? The column defaults to 1 (all modules), which is right for analysts
    // who were unrestricted — but analysts who already had analyst_modules rows were
    // RESTRICTED, so once the column is added we must flip them to 0 (see back-fill
    // below), or the upgrade would silently give restricted analysts every module.
    $analystAllModulesColWasMissing = false;
    try {
        $amProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'analysts' AND column_name = 'can_access_all_modules'");
        $amProbe->execute([$dbName]);
        $analystAllModulesColWasMissing = ((int)$amProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // Was users.tenant_id absent before this run? Requesters predate the column,
    // so on the run that adds it we pre-fill it from each address's domain (see
    // the back-fill below) rather than leaving every existing person company-less
    // and sending all their future portal tickets to triage.
    $userTenantColWasMissing = false;
    try {
        $utProbe = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name = 'tenant_id'");
        $utProbe->execute([$dbName]);
        $userTenantColWasMissing = ((int)$utProbe->fetchColumn() === 0);
    } catch (Exception $e) {}

    // `users.email` was NOT NULL. A directory user may genuinely have no mailbox
    // (GitHub #47 — warehouse and shop-floor staff are never given one), so it
    // has to become nullable. Relaxing NOT NULL is safe in a way that tightening
    // never is: every existing row already satisfies the looser rule.
    //
    // Only ADDing columns is the convention for the $schema array, not a wall —
    // there are five MODIFY precedents in this file. Probed first, so it fires
    // once and is a no-op on every later run.
    try {
        $emailCol = $conn->prepare(
            "SELECT IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'users' AND column_name = 'email'"
        );
        $emailCol->execute([$dbName]);
        $emailRow = $emailCol->fetch(PDO::FETCH_ASSOC);
        if ($emailRow && strtoupper($emailRow['IS_NULLABLE']) === 'NO') {
            $conn->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(255) NULL");

            // Any address already stored as '' must become NULL, or it occupies
            // the unique index and the NEXT mailbox-less person cannot be
            // created. '' and NULL look equally empty on screen, so this would
            // surface as an inexplicable "email already in use".
            $blanked = $conn->exec("UPDATE `users` SET `email` = NULL WHERE `email` = ''");

            $detail = "email: NOT NULL → NULL (directory users may have no mailbox)";
            if ($blanked > 0) {
                $detail .= "; converted $blanked empty address(es) to NULL so they stop occupying the unique index";
            }
            $results[] = ['table' => 'users', 'status' => 'updated', 'details' => [$detail]];
        }
    } catch (Exception $e) {
        // Non-fatal: the portal keeps working for everyone who has an address.
    }

    // `ticket_audit.analyst_id` was NOT NULL, and the workflow engine's
    // `add_ticket_note` action writes NULL there deliberately — its comment says
    // so: NULL marks the entry as written by automation rather than by a person.
    // The column never allowed it, so the action failed with
    //   SQLSTATE[23000] ... Column 'analyst_id' cannot be null
    // every single time it ran, on every installation, since it was written
    // (GitHub #120).
    //
    // Same probe-then-MODIFY shape as the two either side. Safe for the same
    // reason: every existing row already has an analyst, so relaxing the rule
    // cannot invalidate one. The `fk_ticket_audit_analyst` foreign key is
    // unaffected — a NULL never violates a foreign key, it simply has nothing
    // to check.
    try {
        $auditCol = $conn->prepare(
            "SELECT IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'ticket_audit' AND column_name = 'analyst_id'"
        );
        $auditCol->execute([$dbName]);
        $auditRow = $auditCol->fetch(PDO::FETCH_ASSOC);
        if ($auditRow && strtoupper($auditRow['IS_NULLABLE']) === 'NO') {
            $conn->exec("ALTER TABLE `ticket_audit` MODIFY `analyst_id` INT NULL");
            $results[] = [
                'table'   => 'ticket_audit',
                'status'  => 'updated',
                'details' => ["analyst_id: NOT NULL → NULL (a workflow writes history entries that no analyst made)"],
            ];
        }
    } catch (Exception $e) {
        // Non-fatal: ticket history keeps working; only the workflow action stays broken.
    }

    // `emails.from_address` was NOT NULL. A portal requester who signs in
    // through a directory may have no mailbox at all (GitHub #47), and their
    // ticket has no sender address to record — the INSERT failed outright, so
    // the person could sign in and then not raise a ticket. Same probe-then-
    // MODIFY shape as users.email above; relaxing NOT NULL can't invalidate an
    // existing row.
    try {
        $fromCol = $conn->prepare(
            "SELECT IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'emails' AND column_name = 'from_address'"
        );
        $fromCol->execute([$dbName]);
        $fromRow = $fromCol->fetch(PDO::FETCH_ASSOC);
        if ($fromRow && strtoupper($fromRow['IS_NULLABLE']) === 'NO') {
            $conn->exec("ALTER TABLE `emails` MODIFY `from_address` VARCHAR(255) NULL");
            $results[] = [
                'table'   => 'emails',
                'status'  => 'updated',
                'details' => ['from_address: NOT NULL → NULL (a portal requester may have no mailbox)'],
            ];
        }
    } catch (Exception $e) {
        // Non-fatal — everyone with an address is unaffected either way.
    }

    // `calendar_sync_events.ticket_id` was NOT NULL back when only a ticket
    // could reach a calendar. A task now can (#75), and a row belongs to one or
    // the other, never both — so a task's row has no ticket to name. Same
    // probe-then-MODIFY shape as the two above; relaxing NOT NULL cannot
    // invalidate a row that already exists.
    //
    // ⚠️ The UNIQUE index on (ticket_id, analyst_id) is deliberately left
    // alone. MySQL permits any number of NULLs in a unique index, so task rows
    // pass straight through it and get their own unique on
    // (task_id, analyst_id, kind) instead.
    try {
        $csCol = $conn->prepare(
            "SELECT IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'calendar_sync_events' AND column_name = 'ticket_id'"
        );
        $csCol->execute([$dbName]);
        $csRow = $csCol->fetch(PDO::FETCH_ASSOC);
        if ($csRow && strtoupper($csRow['IS_NULLABLE']) === 'NO') {
            $conn->exec("ALTER TABLE `calendar_sync_events` MODIFY `ticket_id` INT NULL");
            $results[] = [
                'table'   => 'calendar_sync_events',
                'status'  => 'updated',
                'details' => ['ticket_id: NOT NULL → NULL (a row can now belong to a task instead)'],
            ];
        }
    } catch (Exception $e) {
        // Non-fatal — the table may not exist yet on an install that has never
        // configured calendar sync, and $schema creates it correctly.
    }

    foreach ($schema as $tableName => $columns) {
        $tableResult = ['table' => $tableName, 'status' => 'ok', 'details' => []];

        // Check if table exists
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
        $check->execute([$dbName, $tableName]);
        $exists = (int)$check->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

        if (!$exists) {
            // Build CREATE TABLE statement
            $colDefs = [];
            foreach ($columns as $colName => $colDef) {
                $colDefs[] = "`$colName` $colDef";
            }

            /* Determine the primary key. One rule now, reading $primaryKeys:
               an array is a composite, a string is one column, and absent means
               `id`. The two composites used to be named here as well as in the
               map, so a third one had to be added in both places to work. */
            $pk = $primaryKeys[$tableName] ?? 'id';
            $pkCols = is_array($pk) ? $pk : [$pk];
            $colDefs[] = 'PRIMARY KEY (`' . implode('`, `', $pkCols) . '`)';

            $sql = "CREATE TABLE `$tableName` (\n    " . implode(",\n    ", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            try {
                $conn->exec($sql);
                $tableResult['status'] = 'created';
                $tableResult['details'][] = 'Table created with ' . count($columns) . ' columns';
            } catch (Exception $e) {
                $tableResult['status'] = 'error';
                $tableResult['details'][] = 'Failed to create table: ' . $e->getMessage();
            }
        } else {
            // Table exists - check each column
            $addedColumns = [];
            foreach ($columns as $colName => $colDef) {
                $colCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
                $colCheck->execute([$dbName, $tableName, $colName]);
                $colExists = (int)$colCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

                if (!$colExists) {
                    // Strip AUTO_INCREMENT from ALTER TABLE ADD (can't add auto_increment column to existing table)
                    $alterDef = str_ireplace('AUTO_INCREMENT', '', $colDef);
                    $alterDef = trim(preg_replace('/\s+/', ' ', $alterDef));

                    // For NOT NULL columns without defaults, add a sensible default to avoid errors on existing rows
                    if (stripos($alterDef, 'NOT NULL') !== false && stripos($alterDef, 'DEFAULT') === false) {
                        if (stripos($alterDef, 'INT') === 0 || stripos($alterDef, 'DECIMAL') === 0 || stripos($alterDef, 'BIGINT') === 0) {
                            $alterDef .= ' DEFAULT 0';
                        } elseif (stripos($alterDef, 'TINYINT') === 0) {
                            $alterDef .= ' DEFAULT 0';
                        } elseif (stripos($alterDef, 'DATETIME') === 0 || stripos($alterDef, 'DATE') === 0) {
                            $alterDef .= ' DEFAULT CURRENT_TIMESTAMP';
                        } else {
                            $alterDef .= " DEFAULT ''";
                        }
                    }

                    try {
                        $conn->exec("ALTER TABLE `$tableName` ADD `$colName` $alterDef");
                        $addedColumns[] = $colName;
                    } catch (Exception $e) {
                        $tableResult['status'] = 'error';
                        $tableResult['details'][] = "Failed to add column $colName: " . $e->getMessage();
                    }
                }
            }

            if (count($addedColumns) > 0) {
                $tableResult['status'] = 'updated';
                $tableResult['details'][] = 'Added columns: ' . implode(', ', $addedColumns);
            }
        }
        $results[] = $tableResult;
    }

    // Existing installs may have encrypted mailbox values in VARCHAR columns
    // that are too small. AES-GCM ciphertext can far exceed the original
    // plaintext length (e.g. a ~72-char Google OAuth client ID encrypts to
    // ~140 chars and was silently truncated by the old VARCHAR(100), corrupting
    // the stored value so it could no longer be decrypted). The schema loop only
    // ADDs missing columns; it never widens existing ones — so widen them here.
    $mailboxEncryptedColumns = [
        'azure_tenant_id',
        'azure_client_id',
        'azure_client_secret',
        'oauth_redirect_uri',
        'imap_server',
        'target_mailbox',
    ];
    $modifiedMailboxColumns = [];
    foreach ($mailboxEncryptedColumns as $columnName) {
        $typeStmt = $conn->prepare(
            "SELECT DATA_TYPE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'target_mailboxes' AND column_name = ?"
        );
        $typeStmt->execute([$dbName, $columnName]);
        $dataType = strtolower((string)$typeStmt->fetchColumn());
        if ($dataType !== '' && $dataType !== 'text') {
            try {
                $conn->exec("ALTER TABLE `target_mailboxes` MODIFY `$columnName` TEXT NOT NULL");
                $modifiedMailboxColumns[] = $columnName;
            } catch (Exception $e) {
                $results[] = [
                    'table' => 'target_mailboxes',
                    'status' => 'error',
                    'details' => ["Failed to widen $columnName: " . $e->getMessage()]
                ];
            }
        }
    }
    if (count($modifiedMailboxColumns) > 0) {
        $results[] = [
            'table' => 'target_mailboxes',
            'status' => 'updated',
            'details' => ['Widened encrypted mailbox columns: ' . implode(', ', $modifiedMailboxColumns)]
        ];
    }

    // One-time grandfather: if is_admin was just added, promote every existing
    // analyst to admin so the upgrade preserves today's behaviour (all analysts
    // could reach System) rather than locking everyone out. Admins then demote
    // people deliberately. Runs only on the run that first adds the column.
    if ($analystIsAdminColWasMissing) {
        $graduated = $conn->exec("UPDATE analysts SET is_admin = 1");
        $results[] = [
            'table' => 'analysts',
            'status' => 'updated',
            'details' => ['Granted admin to ' . (int)$graduated . ' existing analyst(s) (one-time upgrade — demote non-admins in System → Analysts)']
        ];
    }

    // One-time module-access grandfather (issue #30): analysts who already had
    // analyst_modules rows were restricted, so flip their new all-modules flag to 0
    // (the default 1 correctly leaves previously-unrestricted analysts untouched).
    // Runs only on the run that first adds the column, so a later deliberate
    // "all modules" choice is never clobbered.
    if ($analystAllModulesColWasMissing) {
        $restricted = $conn->exec("UPDATE analysts SET can_access_all_modules = 0
            WHERE id IN (SELECT analyst_id FROM (SELECT DISTINCT analyst_id FROM analyst_modules) t)");
        $results[] = [
            'table' => 'analysts',
            'status' => 'updated',
            'details' => ['Preserved module restrictions for ' . (int)$restricted . ' analyst(s) on upgrade (issue #30)']
        ];
    }

    // One-time company pre-fill for requesters (portal tickets were landing in
    // triage because nobody had a company). Runs ONLY on the run that first adds
    // the column, so an admin's later deliberate choice — including deliberately
    // clearing someone — is never overwritten on a subsequent verify.
    //
    // Domain match only. Freemail is skipped on purpose: two customers share
    // gmail.com, so a domain match there would be a guess, and a wrong company is
    // worse than a blank one. Those get set by hand in Tickets → Users.
    if ($userTenantColWasMissing) {
        require_once '../../includes/tenancy.php';
        $filled = 0;
        try {
            if (!isMultiTenant($conn)) {
                // Single-company install: everyone belongs to the silent Default.
                // Doing this now means that if a second company is added later,
                // these people stay on Default instead of falling into triage.
                $stmt = $conn->prepare("UPDATE users SET tenant_id = ? WHERE tenant_id IS NULL");
                $stmt->execute([getDefaultTenantId($conn)]);
                $filled = $stmt->rowCount();
            } else {
                $upd = $conn->prepare(
                    "UPDATE users SET tenant_id = ?
                     WHERE tenant_id IS NULL AND SUBSTRING_INDEX(email, '@', -1) = ?"
                );
                foreach ($conn->query("SELECT domain, tenant_id FROM tenant_domains") as $row) {
                    $domain = strtolower(trim($row['domain']));
                    if ($domain === '' || $row['tenant_id'] === null) continue;
                    if (isFreemailDomain($conn, $domain)) continue;
                    $upd->execute([(int)$row['tenant_id'], $domain]);
                    $filled += $upd->rowCount();
                }
            }
        } catch (Exception $e) {
            // tenancy tables missing / part-migrated install — leave them blank,
            // which is the safe state (triage), not a broken one.
        }

        $blank = 0;
        try {
            $blank = (int)$conn->query("SELECT COUNT(*) FROM users WHERE tenant_id IS NULL")->fetchColumn();
        } catch (Exception $e) {}

        $detail = 'Set the company for ' . $filled . ' requester(s) from their email domain (one-time upgrade)';
        if ($blank > 0) {
            $detail .= '; ' . $blank . ' left blank (freemail or unregistered domain) — set these in Tickets → Users or their tickets go to triage';
        }
        $results[] = [
            'table' => 'users',
            'status' => 'updated',
            'details' => [$detail]
        ];
    }

    // Seed default admin account if no analysts exist
    $countStmt = $conn->query("SELECT COUNT(*) FROM analysts");
    $analystCount = (int) $countStmt->fetchColumn();
    if ($analystCount === 0) {
        // must_change_password = 1: admin/freeitsm used to be permanent. There was no
        // column like this and nothing anywhere forced, warned about or nagged on the
        // change, so the published default credentials stayed valid on installs that
        // never got round to it. Now the first sign-in cannot go anywhere else.
        $defaultHash = password_hash('freeitsm', PASSWORD_DEFAULT);
        $seedStmt = $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, must_change_password, created_datetime) VALUES (?, ?, ?, ?, 1, 1, 1, UTC_TIMESTAMP())");
        $seedStmt->execute(['admin', $defaultHash, 'Administrator', 'admin@localhost']);
        $results[] = [
            'table' => 'analysts',
            'status' => 'seeded',
            'details' => ['Created default admin account (username: admin, password: freeitsm) — it must be changed at first sign-in']
        ];
    }

    // ⚠️ …and the same guarantee for installs that did NOT get their admin row from the
    // block above. database/freeitsm.sql is mounted as a docker-entrypoint-initdb.d
    // script, so on the Docker quickstart the row already exists by the time this runs
    // and `COUNT(*) === 0` is false — which left admin/freeitsm permanently valid there.
    // The SQL seed now sets the flag itself, but that only helps a fresh container; this
    // catches the installs already out there.
    //
    // Deliberately narrow: the flag is only forced when the published default password
    // STILL WORKS. Testing the password rather than the username means an admin who
    // long ago changed it is never nagged, and an account merely named "admin" with a
    // real password is left alone. password_verify() against the stored hash is the
    // only honest test of that — the hash is salted, so it cannot be compared literally.
    try {
        $defStmt = $conn->query("SELECT id, password_hash, must_change_password FROM analysts WHERE username = 'admin'");
        $defAdmin = $defStmt ? $defStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($defAdmin
            && (int)$defAdmin['must_change_password'] === 0
            && password_verify('freeitsm', (string)$defAdmin['password_hash'])) {
            $conn->prepare("UPDATE analysts SET must_change_password = 1 WHERE id = ?")
                 ->execute([$defAdmin['id']]);
            $results[] = [
                'table'   => 'analysts',
                'status'  => 'updated',
                'details' => ['The default admin account is still using the published password — it must now be changed at the next sign-in']
            ];
        }
    } catch (PDOException $e) {
        // must_change_password may not exist yet on a part-migrated install; the column
        // pass above creates it, and the next run of Database Verify will do this.
        if (!dbErrorIsUnknownColumn($e)) throw $e;
    }

    // Seed the silent Default tenant (multi-tenancy foundation) if none exists.
    // Single-company installs run entirely inside this one tenant; it stays
    // invisible until a second tenant is created.
    $tenantTableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'tenants'");
    $tenantTableCheck->execute([DB_NAME]);
    if ((int)$tenantTableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $tenantCount = (int) $conn->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
        if ($tenantCount === 0) {
            $conn->exec("INSERT INTO tenants (name, is_default, is_active, created_datetime) VALUES ('Default', 1, 1, UTC_TIMESTAMP())");
            $results[] = [
                'table' => 'tenants',
                'status' => 'seeded',
                'details' => ['Created the default tenant (multi-tenancy foundation; invisible until a second tenant is added)']
            ];
        }
    }

    // Seed default dashboard widgets if table is empty
    $widgetCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'asset_dashboard_widgets'");
    $widgetCheck->execute([DB_NAME]);
    if ((int)$widgetCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $widgetCount = (int) $conn->query("SELECT COUNT(*) FROM asset_dashboard_widgets")->fetchColumn();
        if ($widgetCount === 0) {
            $conn->exec("INSERT INTO asset_dashboard_widgets (id, title, description, chart_type, aggregate_property, is_status_filterable, default_status_id, display_order) VALUES
                (1,  'OS Distribution',   'Distribution of operating systems across assets',       'doughnut', 'operating_system',  1, NULL, 1),
                (2,  'Manufacturer',      'Asset count by manufacturer',                           'bar',      'manufacturer',      1, NULL, 2),
                (3,  'Model',             'Asset count by model',                                  'bar',      'model',             1, NULL, 3),
                (4,  'Asset Type',        'Breakdown by asset type',                               'doughnut', 'asset_type_id',     1, NULL, 4),
                (5,  'Asset Status',      'Current status of all assets',                          'doughnut', 'asset_status_id',   0, NULL, 5),
                (6,  'Feature Release',   'Windows feature release versions',                      'bar',      'feature_release',   1, NULL, 6),
                (7,  'Domain',            'Assets grouped by domain',                              'doughnut', 'domain',            1, NULL, 7),
                (8,  'CPU',               'Processor models across the estate',                    'bar',      'cpu_name',          1, NULL, 8),
                (9,  'Memory',            'RAM distribution across assets',                        'bar',      'memory',            1, NULL, 9),
                (10, 'GPU',               'Graphics adapters across the estate',                   'bar',      'gpu_name',          1, NULL, 10),
                (11, 'TPM Version',       'TPM module versions',                                   'doughnut', 'tpm_version',       1, NULL, 11),
                (12, 'BitLocker Status',  'BitLocker encryption status',                           'doughnut', 'bitlocker_status',  1, NULL, 12),
                (13, 'BIOS Version',      'BIOS versions across the estate',                       'bar',      'bios_version',      1, NULL, 13)
            ");
            $results[] = [
                'table' => 'asset_dashboard_widgets',
                'status' => 'seeded',
                'details' => ['Inserted 13 default dashboard widgets']
            ];
        }
    }

    // Migration: convert old aggregate_property values to new format with time_grouping
    $tktWidgetCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'ticket_dashboard_widgets'");
    $tktWidgetCheck->execute([DB_NAME]);
    if ((int)$tktWidgetCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $migCheck = $conn->query("SELECT COUNT(*) FROM ticket_dashboard_widgets WHERE aggregate_property IN ('created_daily','created_monthly','closed_daily','closed_monthly','created_vs_closed_daily','created_vs_closed_monthly')");
        if ((int)$migCheck->fetchColumn() > 0) {
            $migrations = [
                ['created_daily',             'created',          'day',   'this_month'],
                ['created_monthly',           'created',          'month', '12m'],
                ['closed_daily',              'closed',           'day',   'this_month'],
                ['closed_monthly',            'closed',           'month', '12m'],
                ['created_vs_closed_daily',   'created_vs_closed','day',   'this_month'],
                ['created_vs_closed_monthly', 'created_vs_closed','month', '12m'],
            ];
            foreach ($migrations as [$old, $new, $grouping, $dateRange]) {
                $conn->prepare("UPDATE ticket_dashboard_widgets SET aggregate_property = ?, time_grouping = ?, date_range = COALESCE(date_range, ?) WHERE aggregate_property = ?")
                     ->execute([$new, $grouping, $dateRange, $old]);
            }
            $results[] = ['table' => 'ticket_dashboard_widgets', 'status' => 'migrated', 'details' => ['Converted legacy aggregate properties to new format with time_grouping']];
        }
    }

    // Seed default software dashboard widgets if table is empty
    $swWidgetCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'software_dashboard_widgets'");
    $swWidgetCheck->execute([DB_NAME]);
    if ((int)$swWidgetCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) {
        $swWidgetCount = (int) $conn->query("SELECT COUNT(*) FROM software_dashboard_widgets")->fetchColumn();
        if ($swWidgetCount === 0) {
            $conn->exec("INSERT INTO software_dashboard_widgets (id, title, description, chart_type, aggregate_property, app_id, exclude_system_components, display_order) VALUES
                (1, 'Top Installed Applications', 'Most installed applications across all machines', 'bar', 'top_installed', NULL, 1, 1),
                (2, 'Publisher Distribution', 'Software distribution by publisher', 'doughnut', 'publisher_distribution', NULL, 1, 2)
            ");
            $results[] = [
                'table' => 'software_dashboard_widgets',
                'status' => 'seeded',
                'details' => ['Inserted 2 default software dashboard widgets']
            ];
        }
    }

    // ----------------------------------------------------------------------
    // Tickets normalisation: lookup tables, backfill, drop legacy columns
    // ----------------------------------------------------------------------
    $colExists = function($table, $col) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
        $s->execute([$dbName, $table, $col]);
        return (int)$s->fetchColumn() > 0;
    };
    $tableExists = function($table) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
        $s->execute([$dbName, $table]);
        return (int)$s->fetchColumn() > 0;
    };
    $fkExists = function($table, $fk) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
        $s->execute([$dbName, $table, $fk]);
        return (int)$s->fetchColumn() > 0;
    };
    $idxExists = function($table, $idx) use ($conn, $dbName) {
        $s = $conn->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?");
        $s->execute([$dbName, $table, $idx]);
        return (int)$s->fetchColumn() > 0;
    };

    // Seed default ticket statuses if table is empty
    if ($tableExists('ticket_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('Open',              0, '#2563eb', 1, 10),
                ('In Progress',       0, '#9333ea', 0, 20),
                ('On Hold',           0, '#f59e0b', 0, 30),
                ('Awaiting Response', 0, '#0891b2', 0, 40),
                ('Closed',            1, '#6b7280', 0, 50)");
            $results[] = ['table' => 'ticket_statuses', 'status' => 'seeded', 'details' => ['Inserted 5 default ticket statuses']];
        }
    }

    // Seed default ticket priorities if table is empty
    if ($tableExists('ticket_priorities')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_priorities")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_priorities (name, colour, is_default, display_order) VALUES
                ('Low',      '#16a34a', 0, 10),
                ('Normal',   '#2563eb', 1, 20),
                ('High',     '#f59e0b', 0, 30),
                ('Critical', '#dc2626', 0, 40),
                ('Urgent',   '#b91c1c', 0, 50)");
            $results[] = ['table' => 'ticket_priorities', 'status' => 'seeded', 'details' => ['Inserted 5 default ticket priorities']];
        }
    }

    // Seed default ticket types if table is empty. Parity with statuses/priorities
    // above (GitHub #42): a fresh install landed with an empty Type dropdown because
    // types were only ever created by the demo data. Global defaults (tenant_id NULL);
    // only ever seeded into an empty table, so a deliberately-cleared list is respected.
    if ($tableExists('ticket_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_types (name, description, is_active, display_order, tenant_id) VALUES
                ('Incident',        'Something is broken or not working as expected',   1, 10, NULL),
                ('Service Request', 'A request for something new or a standard change',  1, 20, NULL),
                ('Question',        'A general query or how-to',                         1, 30, NULL)");
            $results[] = ['table' => 'ticket_types', 'status' => 'seeded', 'details' => ['Inserted 3 default ticket types']];
        }
    }

    // Seed default ticket origins if table is empty (GitHub #42). WhatsApp is
    // deliberately excluded here — the WhatsApp origin is owned by its own seeder
    // further down (which adds it whether or not this base set exists).
    if ($tableExists('ticket_origins')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_origins")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES
                ('Email',   'Received by email',                     10, 1, NULL),
                ('Phone',   'Logged from a phone call',              20, 1, NULL),
                ('Portal',  'Raised via the self-service portal',    30, 1, NULL),
                ('Walk-up', 'Reported in person',                    40, 1, NULL)");
            $results[] = ['table' => 'ticket_origins', 'status' => 'seeded', 'details' => ['Inserted 4 default ticket origins']];
        }
    }

    // ----------------------------------------------------------
    // Multi-tenancy foundation — unique keys + FKs for the tenant tables
    // (the $schema loop builds columns + PK only). Added idempotently.
    // ----------------------------------------------------------
    // Native LMS content. The cascades are the point: deleting a course takes its
    // lessons, their questions and those questions' answers with it, so a deleted
    // course can't leave an orphaned answer key behind.
    if ($tableExists('lms_lessons') && $tableExists('lms_courses')) {
        if (!$fkExists('lms_lessons', 'fk_lms_lessons_course')) {
            try { $conn->exec("ALTER TABLE lms_lessons ADD CONSTRAINT fk_lms_lessons_course FOREIGN KEY (course_id) REFERENCES lms_courses (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('lms_questions') && $tableExists('lms_lessons')) {
        if (!$fkExists('lms_questions', 'fk_lms_questions_lesson')) {
            try { $conn->exec("ALTER TABLE lms_questions ADD CONSTRAINT fk_lms_questions_lesson FOREIGN KEY (lesson_id) REFERENCES lms_lessons (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('lms_answers') && $tableExists('lms_questions')) {
        if (!$fkExists('lms_answers', 'fk_lms_answers_question')) {
            try { $conn->exec("ALTER TABLE lms_answers ADD CONSTRAINT fk_lms_answers_question FOREIGN KEY (question_id) REFERENCES lms_questions (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }

    // Documents. Deleting the document takes its links with it. Note the cascade
    // runs ONE way only, deliberately: removing a link must never remove the
    // document, because something else may still be using the same file.
    if ($tableExists('document_links') && $tableExists('documents')) {
        if (!$fkExists('document_links', 'fk_document_links_document')) {
            try { $conn->exec("ALTER TABLE document_links ADD CONSTRAINT fk_document_links_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // Per-company settings. Deleting a company takes its overrides with it —
    // they would otherwise outlive it and be inherited by a reused id.
    if ($tableExists('tenant_settings') && $tableExists('tenants')) {
        if (!$fkExists('tenant_settings', 'fk_tenant_settings_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_settings ADD CONSTRAINT fk_tenant_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('document_text') && $tableExists('documents')) {
        if (!$fkExists('document_text', 'fk_document_text_document')) {
            try { $conn->exec("ALTER TABLE document_text ADD CONSTRAINT fk_document_text_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('document_access_log') && $tableExists('documents')) {
        if (!$fkExists('document_access_log', 'fk_document_access_document')) {
            try { $conn->exec("ALTER TABLE document_access_log ADD CONSTRAINT fk_document_access_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }

    // Ticket numbering (#1147). ticket_number_history is defined BEFORE the
    // tickets table in freeitsm.sql, so its FK cannot be inline there — same
    // reason as fk_assets_supplier.
    if ($tableExists('ticket_number_history') && $tableExists('tickets')
        && !$fkExists('ticket_number_history', 'fk_tnh_ticket')) {
        try {
            $conn->exec("ALTER TABLE ticket_number_history ADD CONSTRAINT fk_tnh_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE");
        } catch (Exception $e) {}
    }

    // Ticket classification (#1540): categories + resolution codes.
    //
    // ⚠️ The three `tickets` constraints carry NO ON DELETE action, so MySQL
    // RESTRICTs. That is deliberate and load-bearing: a category or resolution
    // code that any ticket still references cannot be deleted at all, so a
    // closed ticket keeps the label it was closed with. Lists are RETIRED
    // (is_active = 0), never removed. The settings screen checks first and
    // explains why; this is the backstop for anything that doesn't.
    //
    // The category's own type link is SET NULL instead — deleting a ticket type
    // must not silently destroy the categories underneath it. They fall back to
    // "offered for every type", which is visible and fixable.
    $ticketClassificationFks = [
        ['ticket_categories',       'fk_ticket_categories_parent',         'ticket_categories',       'parent_id',           'id', null],
        ['ticket_categories',       'fk_ticket_categories_type',           'ticket_types',            'ticket_type_id',      'id', 'SET NULL'],
        ['ticket_categories',       'fk_ticket_categories_tenant',         'tenants',                 'tenant_id',           'id', 'CASCADE'],
        ['ticket_resolution_codes', 'fk_ticket_resolution_codes_tenant',   'tenants',                 'tenant_id',           'id', 'CASCADE'],
        ['tickets',                 'fk_tickets_category',                 'ticket_categories',       'category_id',         'id', null],
        ['tickets',                 'fk_tickets_closure_category',         'ticket_categories',       'closure_category_id', 'id', null],
        ['tickets',                 'fk_tickets_resolution_code',          'ticket_resolution_codes', 'resolution_code_id',  'id', null],
    ];
    foreach ($ticketClassificationFks as [$table, $name, $refTable, $col, $refCol, $onDelete]) {
        if ($tableExists($table) && $tableExists($refTable) && !$fkExists($table, $name)) {
            $action = $onDelete ? " ON DELETE {$onDelete}" : '';
            try {
                $conn->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol}){$action}");
            } catch (Exception $e) {}
        }
    }

    // Ticket team assignment (#1566). SET NULL, never CASCADE: deleting a team
    // must not take its tickets with it. They return to "no team", which is
    // visible on screen and fixable; vanishing is neither.
    if ($tableExists('tickets') && $tableExists('teams')
        && !$fkExists('tickets', 'fk_tickets_team')) {
        try {
            $conn->exec("ALTER TABLE tickets ADD CONSTRAINT fk_tickets_team FOREIGN KEY (assigned_team_id) REFERENCES teams (id) ON DELETE SET NULL");
        } catch (Exception $e) {}
    }

    // Manually added applications (#1549). SET NULL: deleting the analyst who
    // typed an application in must not take the application with them.
    if ($tableExists('software_inventory_apps') && $tableExists('analysts')
        && !$fkExists('software_inventory_apps', 'fk_software_apps_created_by')) {
        try {
            $conn->exec("ALTER TABLE software_inventory_apps ADD CONSTRAINT fk_software_apps_created_by FOREIGN KEY (created_by) REFERENCES analysts (id) ON DELETE SET NULL");
        } catch (Exception $e) {}
    }

    // An asset type's icon (#1146). SET NULL, never CASCADE: retiring a glyph
    // from the library must not delete the asset type that was using it.
    if ($tableExists('asset_types') && $tableExists('cmdb_icons') && !$fkExists('asset_types', 'fk_asset_types_icon')) {
        try {
            $conn->exec("ALTER TABLE asset_types ADD CONSTRAINT fk_asset_types_icon FOREIGN KEY (icon_id) REFERENCES cmdb_icons (id) ON DELETE SET NULL");
        } catch (Exception $e) {}
    }

    // Physical disks (discussion #97). Matched to its siblings asset_disks and
    // asset_devices — a plain RESTRICT, no cascade — because that is what every
    // other agent-reported child table on this install already has, and a table
    // that deletes differently from the two beside it is a surprise waiting to
    // happen. The agent clears and reinserts its own rows on every report.
    if ($tableExists('asset_physical_disks') && $tableExists('assets')
        && !$fkExists('asset_physical_disks', 'fk_asset_physical_disks_asset')) {
        try {
            $conn->exec("ALTER TABLE asset_physical_disks ADD CONSTRAINT fk_asset_physical_disks_asset FOREIGN KEY (asset_id) REFERENCES assets (id)");
        } catch (Exception $e) {}
    }

    // ── A hand-added television is not a machine that stopped reporting ──────
    //
    // Until #1583, creating an asset by hand — the Add button, a CSV import, or
    // POST /assets — stamped BOTH first_seen and last_seen with the moment it
    // was typed in, as though something had reported it. That was invisible
    // while nothing displayed last_seen, and wrong the moment #1578 put it on
    // screen: a television reads "21 days ago" in amber as if its agent had
    // gone quiet, and it has been inflating the Watchtower "not seen" count all
    // along. Every install in the wild has these, not just the one you are
    // reading this on, and nobody out there knows to go looking.
    //
    // 🔑 THIS DESTROYS NO INFORMATION. The precondition is first_seen = last_seen,
    // so the timestamp survives in first_seen — all that is removed is a
    // duplicate of it that was masquerading as an agent report.
    //
    // 🔑 EVIDENCE, NOT A HEURISTIC. Two facts together, both recorded rather
    // than inferred:
    //   1. an `asset_created` row in asset_history — written by
    //      AssetsService::createAsset() and by nothing else, so a person or an
    //      import made this record;
    //   2. first_seen = last_seen — no agent has reported it since.
    // Guessing from "it has no CPU or BIOS recorded" would have been close, and
    // close is not the same: on the install this was developed against that
    // guess caught two extra rows whose dates were seeded deliberately.
    //
    // ⚠️ Naturally idempotent, so it needs no run-once flag: after #1583 a
    // manual asset has last_seen NULL, which fails `first_seen <=> last_seen`
    // and is excluded. A second verification finds nothing.
    //
    // And if it ever did fire on a machine that genuinely reports, the next
    // agent run puts last_seen straight back.
    if ($tableExists('assets') && $tableExists('asset_history')) {
        try {
            $cleared = $conn->exec(
                "UPDATE assets a
                    SET a.last_seen = NULL
                  WHERE a.last_seen IS NOT NULL
                    AND a.first_seen <=> a.last_seen
                    AND EXISTS (SELECT 1 FROM asset_history h
                                 WHERE h.asset_id = a.id AND h.field_name = 'asset_created')"
            );
            if ($cleared > 0) {
                $results[] = ['table' => 'assets', 'status' => 'updated', 'details' => [
                    "Cleared a last_seen on $cleared hand-added asset(s) that no inventory agent had ever reported — "
                    . "they were showing as machines that had stopped reporting. The date itself is unchanged in first_seen."
                ]];
            }
        } catch (Exception $e) {
            // A repair that cannot run must never fail a verification: the
            // schema work around it is what an upgrade actually needs.
        }
    }

    // Disk hide rules (#97). CASCADE, unlike its neighbours above: an
    // asset-scoped rule is ABOUT that asset and means nothing once it is gone,
    // where a disk row is a record of what was in the machine. A rule with a
    // NULL asset_id is estate-wide and no asset's deletion touches it.
    if ($tableExists('asset_disk_hide_rules') && $tableExists('assets')
        && !$fkExists('asset_disk_hide_rules', 'fk_adhr_asset')) {
        try {
            $conn->exec("ALTER TABLE asset_disk_hide_rules ADD CONSTRAINT fk_adhr_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE");
        } catch (Exception $e) {}
    }

    // Custom asset fields. Cascades everywhere EXCEPT asset_field_values.field_id,
    // which is deliberately RESTRICT: a field is retired by setting is_deleted,
    // never dropped, because dropping it would silently destroy every answer ever
    // recorded against it. The FK is what makes that a hard guarantee rather than
    // a convention. See docs/design/flexible-asset-fields.md §3.1.
    $assetFieldFks = [
        ['asset_fields',           'fk_asset_fields_tenant',       'tenants',           'tenant_id',             'id', 'CASCADE'],
        ['asset_field_options',    'fk_asset_field_options_field', 'asset_fields',      'field_id',              'id', 'CASCADE'],
        ['asset_field_sets',       'fk_asset_field_sets_tenant',   'tenants',           'tenant_id',             'id', 'CASCADE'],
        ['asset_field_set_fields', 'fk_afsf_set',                  'asset_field_sets',  'set_id',                'id', 'CASCADE'],
        ['asset_field_set_fields', 'fk_afsf_field',                'asset_fields',      'field_id',              'id', 'CASCADE'],
        ['asset_type_field_sets',  'fk_atfs_type',                 'asset_types',       'asset_type_id',         'id', 'CASCADE'],
        ['asset_type_field_sets',  'fk_atfs_set',                  'asset_field_sets',  'set_id',                'id', 'CASCADE'],
        ['asset_field_set_assets', 'fk_afsa_asset',                'assets',            'asset_id',              'id', 'CASCADE'],
        ['asset_field_set_assets', 'fk_afsa_set',                  'asset_field_sets',  'set_id',                'id', 'CASCADE'],
        ['asset_field_set_assets', 'fk_afsa_analyst',              'analysts',          'created_by_analyst_id', 'id', 'SET NULL'],
        ['asset_field_values',     'fk_afv_asset',                 'assets',            'asset_id',              'id', 'CASCADE'],
        ['asset_field_values',     'fk_afv_field',                 'asset_fields',      'field_id',              'id', null],
    ];
    foreach ($assetFieldFks as [$table, $name, $refTable, $col, $refCol, $onDelete]) {
        if ($tableExists($table) && $tableExists($refTable) && !$fkExists($table, $name)) {
            $action = $onDelete ? " ON DELETE {$onDelete}" : '';
            try {
                $conn->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol}){$action}");
            } catch (Exception $e) {}
        }
    }

    // Asset import. A run OUTLIVES its profile (SET NULL): deleting the saved
    // mapping must not erase the history of what it did, or the holding area of
    // rows it could not import.
    $assetImportFks = [
        ['asset_import_profiles',    'fk_aip_tenant',      'tenants',               'tenant_id',             'id', 'CASCADE'],
        ['asset_import_profiles',    'fk_aip_type',        'asset_types',           'default_asset_type_id', 'id', 'SET NULL'],
        ['asset_import_profiles',    'fk_aip_status',      'asset_status_types',    'default_status_id',     'id', 'SET NULL'],
        ['asset_import_profiles',    'fk_aip_set',         'asset_field_sets',      'apply_field_set_id',    'id', 'SET NULL'],
        ['asset_import_mappings',    'fk_aim_profile',     'asset_import_profiles', 'profile_id',            'id', 'CASCADE'],
        ['asset_import_runs',        'fk_air_profile',     'asset_import_profiles', 'profile_id',            'id', 'SET NULL'],
        ['asset_import_run_entries', 'fk_aire_run',        'asset_import_runs',     'run_id',                'id', 'CASCADE'],
    ];
    foreach ($assetImportFks as [$table, $name, $refTable, $col, $refCol, $onDelete]) {
        if ($tableExists($table) && $tableExists($refTable) && !$fkExists($table, $name)) {
            $action = $onDelete ? " ON DELETE {$onDelete}" : '';
            try {
                $conn->exec("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol}){$action}");
            } catch (Exception $e) {}
        }
    }

    // RBAC Layer 2. Cascades keep the join tables clean: deleting a role, an
    // analyst or a team removes the assignments that pointed at it.
    if ($tableExists('rbac_role_capabilities') && $tableExists('rbac_roles')) {
        if (!$fkExists('rbac_role_capabilities', 'fk_rrc_role')) {
            try { $conn->exec("ALTER TABLE rbac_role_capabilities ADD CONSTRAINT fk_rrc_role FOREIGN KEY (role_id) REFERENCES rbac_roles (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('rbac_analyst_roles') && $tableExists('rbac_roles') && $tableExists('analysts')) {
        if (!$fkExists('rbac_analyst_roles', 'fk_rar_analyst')) {
            try { $conn->exec("ALTER TABLE rbac_analyst_roles ADD CONSTRAINT fk_rar_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('rbac_analyst_roles', 'fk_rar_role')) {
            try { $conn->exec("ALTER TABLE rbac_analyst_roles ADD CONSTRAINT fk_rar_role FOREIGN KEY (role_id) REFERENCES rbac_roles (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // Search corpus. The cascade is the point, not tidiness: a corpus row holds a
    // COPY of ticket content that is searchable outside the ticket's own access
    // check, so a deleted ticket whose text is still findable would be a
    // data-protection problem rather than an untidy table.
    if ($tableExists('search_documents') && $tableExists('tickets')) {
        if (!$fkExists('search_documents', 'fk_search_docs_ticket')) {
            try { $conn->exec("ALTER TABLE search_documents ADD CONSTRAINT fk_search_docs_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('rbac_team_roles') && $tableExists('rbac_roles') && $tableExists('teams')) {
        if (!$fkExists('rbac_team_roles', 'fk_rtr_team')) {
            try { $conn->exec("ALTER TABLE rbac_team_roles ADD CONSTRAINT fk_rtr_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('rbac_team_roles', 'fk_rtr_role')) {
            try { $conn->exec("ALTER TABLE rbac_team_roles ADD CONSTRAINT fk_rtr_role FOREIGN KEY (role_id) REFERENCES rbac_roles (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }

    if ($tableExists('tenant_domains') && $tableExists('tenants')) {
        if (!$idxExists('tenant_domains', 'uq_tenant_domains_domain')) {
            try { $conn->exec("ALTER TABLE tenant_domains ADD UNIQUE KEY uq_tenant_domains_domain (domain)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_domains', 'fk_tenant_domains_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_domains ADD CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('tenant_sender_addresses') && $tableExists('tenants')) {
        if (!$idxExists('tenant_sender_addresses', 'uq_tenant_sender_email')) {
            try { $conn->exec("ALTER TABLE tenant_sender_addresses ADD UNIQUE KEY uq_tenant_sender_email (email)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_sender_addresses', 'fk_tenant_sender_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_sender_addresses ADD CONSTRAINT fk_tenant_sender_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // Messaging channels (WhatsApp etc.): tenant index + FK (pinned company), and the
    // sender-phone → company map FK. Mirrors target_mailboxes / tenant_sender_addresses.
    if ($tableExists('messaging_channels') && $tableExists('tenants') && $colExists('messaging_channels', 'tenant_id')) {
        if (!$idxExists('messaging_channels', 'ix_messaging_channels_tenant_id')) {
            try { $conn->exec("ALTER TABLE messaging_channels ADD KEY ix_messaging_channels_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('messaging_channels', 'fk_messaging_channels_tenant')) {
            try { $conn->exec("ALTER TABLE messaging_channels ADD CONSTRAINT fk_messaging_channels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('tenant_channel_senders') && $tableExists('tenants')) {
        if (!$idxExists('tenant_channel_senders', 'uq_tenant_channel_sender_identifier')) {
            try { $conn->exec("ALTER TABLE tenant_channel_senders ADD UNIQUE KEY uq_tenant_channel_sender_identifier (identifier)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_channel_senders', 'fk_tenant_channel_sender_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_channel_senders ADD CONSTRAINT fk_tenant_channel_sender_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('messaging_templates') && $tableExists('tenants') && $colExists('messaging_templates', 'tenant_id')) {
        if (!$idxExists('messaging_templates', 'ix_messaging_templates_tenant_id')) {
            try { $conn->exec("ALTER TABLE messaging_templates ADD KEY ix_messaging_templates_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('messaging_templates', 'fk_messaging_templates_tenant')) {
            try { $conn->exec("ALTER TABLE messaging_templates ADD CONSTRAINT fk_messaging_templates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // Web chat widget → its messaging channel (1:1). CASCADE so deleting the channel
    // removes its widget config. The two UNIQUE keys are built from $uniqueIndexes below.
    if ($tableExists('webchat_widgets') && $tableExists('messaging_channels') && $colExists('webchat_widgets', 'channel_id')) {
        if (!$fkExists('webchat_widgets', 'fk_webchat_widget_channel')) {
            try { $conn->exec("ALTER TABLE webchat_widgets ADD CONSTRAINT fk_webchat_widget_channel FOREIGN KEY (channel_id) REFERENCES messaging_channels (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // Web chat conversation → its channel. CASCADE so removing the widget/channel clears
    // its conversations. The token UNIQUE key is built from $uniqueIndexes below.
    if ($tableExists('webchat_conversations') && $tableExists('messaging_channels') && $colExists('webchat_conversations', 'channel_id')) {
        if (!$idxExists('webchat_conversations', 'ix_webchat_conversation_channel')) {
            try { $conn->exec("ALTER TABLE webchat_conversations ADD KEY ix_webchat_conversation_channel (channel_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('webchat_conversations', 'ix_webchat_conversation_ticket')) {
            try { $conn->exec("ALTER TABLE webchat_conversations ADD KEY ix_webchat_conversation_ticket (ticket_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('webchat_conversations', 'fk_webchat_conversation_channel')) {
            try { $conn->exec("ALTER TABLE webchat_conversations ADD CONSTRAINT fk_webchat_conversation_channel FOREIGN KEY (channel_id) REFERENCES messaging_channels (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // Web chat pre-ticket transcript → its conversation. CASCADE with the conversation.
    if ($tableExists('webchat_messages') && $tableExists('webchat_conversations') && $colExists('webchat_messages', 'conversation_id')) {
        if (!$idxExists('webchat_messages', 'ix_webchat_messages_conversation')) {
            try { $conn->exec("ALTER TABLE webchat_messages ADD KEY ix_webchat_messages_conversation (conversation_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('webchat_messages', 'fk_webchat_messages_conversation')) {
            try { $conn->exec("ALTER TABLE webchat_messages ADD CONSTRAINT fk_webchat_messages_conversation FOREIGN KEY (conversation_id) REFERENCES webchat_conversations (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // ── War room ──────────────────────────────────────────────────────────────
    //
    // Channels. team_id and dm_key are each UNIQUE over a NULLABLE column, which
    // MySQL lets repeat NULLs — so "one channel per team" and "one DM per pair"
    // are enforced without constraining the other kinds. The DM one matters more
    // than it looks: without it, two people opening a DM with each other at the
    // same moment end up with one conversation each.
    if ($tableExists('warroom_channels')) {
        if ($tableExists('teams') && !$idxExists('warroom_channels', 'uq_warroom_channels_team')) {
            try { $conn->exec("ALTER TABLE warroom_channels ADD UNIQUE KEY uq_warroom_channels_team (team_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_channels', 'uq_warroom_channels_dm')) {
            try { $conn->exec("ALTER TABLE warroom_channels ADD UNIQUE KEY uq_warroom_channels_dm (dm_key)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_channels', 'ix_warroom_channels_kind')) {
            try { $conn->exec("ALTER TABLE warroom_channels ADD KEY ix_warroom_channels_kind (kind, archived_datetime)"); } catch (Exception $e) {}
        }
        // CASCADE: a team channel goes with its team, which is what keeps a team
        // channel from being orphaned or renamed into a lie.
        if ($tableExists('teams') && !$fkExists('warroom_channels', 'fk_warroom_channels_team')) {
            try { $conn->exec("ALTER TABLE warroom_channels ADD CONSTRAINT fk_warroom_channels_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        // SET NULL: a channel must outlive whoever opened it.
        if ($tableExists('analysts') && !$fkExists('warroom_channels', 'fk_warroom_channels_creator')) {
            try { $conn->exec("ALTER TABLE warroom_channels ADD CONSTRAINT fk_warroom_channels_creator FOREIGN KEY (created_by) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
        // The all-hands room. Seeded here as well as in freeitsm.sql so an
        // installation grown by Database Verification gets one too.
        try {
            $conn->exec(
                "INSERT INTO warroom_channels (kind, created_datetime)
                 SELECT 'all', UTC_TIMESTAMP() FROM DUAL
                  WHERE NOT EXISTS (SELECT 1 FROM warroom_channels WHERE kind = 'all')"
            );
        } catch (Exception $e) {}
    }
    if ($tableExists('warroom_channel_members') && $tableExists('warroom_channels')) {
        if (!$idxExists('warroom_channel_members', 'uq_warroom_member')) {
            try { $conn->exec("ALTER TABLE warroom_channel_members ADD UNIQUE KEY uq_warroom_member (channel_id, analyst_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_channel_members', 'ix_warroom_member_analyst')) {
            try { $conn->exec("ALTER TABLE warroom_channel_members ADD KEY ix_warroom_member_analyst (analyst_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('warroom_channel_members', 'fk_warroom_member_channel')) {
            try { $conn->exec("ALTER TABLE warroom_channel_members ADD CONSTRAINT fk_warroom_member_channel FOREIGN KEY (channel_id) REFERENCES warroom_channels (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('warroom_channel_members', 'fk_warroom_member_analyst')) {
            try { $conn->exec("ALTER TABLE warroom_channel_members ADD CONSTRAINT fk_warroom_member_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // War room messages → their channel and their author.
    //
    // The two rules are deliberately different, and the difference is the point:
    //   channel_id CASCADE  — delete a channel and its conversation goes with it.
    //   analyst_id SET NULL — delete an analyst and the conversation SURVIVES.
    //                        These messages are the record of what was said during
    //                        an incident; losing half of it because somebody left
    //                        the company would be the wrong trade. Orphaned rows
    //                        render as "Former analyst".
    if ($tableExists('warroom_messages')) {
        // ONE-TIME MIGRATION. The first cut of the war room hung messages directly
        // off team_id, because a channel was a team and nothing else. Channels are
        // rows now, so every existing message needs pointing at one — and it must
        // happen BEFORE the FK below, or the constraint fails on rows with a NULL
        // channel_id and the whole table silently keeps the old shape.
        if ($colExists('warroom_messages', 'team_id') && $colExists('warroom_messages', 'channel_id')
            && $tableExists('warroom_channels')) {
            try {
                // Make sure a channel exists for every team that has messages,
                // then point the messages at it. All-hands (team_id IS NULL) goes
                // to the seeded 'all' row.
                $conn->exec(
                    "INSERT INTO warroom_channels (kind, team_id, created_datetime)
                     SELECT DISTINCT 'team', m.team_id, UTC_TIMESTAMP()
                       FROM warroom_messages m
                      WHERE m.team_id IS NOT NULL
                        AND NOT EXISTS (SELECT 1 FROM warroom_channels c
                                         WHERE c.kind = 'team' AND c.team_id = m.team_id)"
                );
                $moved = (int) $conn->exec(
                    "UPDATE warroom_messages m
                       JOIN warroom_channels c
                         ON (m.team_id IS NULL AND c.kind = 'all')
                         OR (m.team_id IS NOT NULL AND c.kind = 'team' AND c.team_id = m.team_id)
                        SET m.channel_id = c.id
                      WHERE m.channel_id IS NULL"
                );
                if ($moved > 0) {
                    $results[] = ['table' => 'warroom_messages', 'status' => 'migrated', 'details' => ["Moved $moved war room message(s) onto the new channel records"]];
                }
                // Only once every row has a home. A leftover NULL means a team was
                // deleted between the two statements; leaving the column in place
                // is safer than dropping it with data still hanging off it.
                $orphans = (int) $conn->query("SELECT COUNT(*) FROM warroom_messages WHERE channel_id IS NULL")->fetchColumn();
                if ($orphans === 0) {
                    try { $conn->exec("ALTER TABLE warroom_messages DROP FOREIGN KEY fk_warroom_messages_team"); } catch (Exception $e) {}
                    try { $conn->exec("ALTER TABLE warroom_messages DROP INDEX ix_warroom_messages_team"); } catch (Exception $e) {}
                    try { $conn->exec("ALTER TABLE warroom_messages DROP COLUMN team_id"); } catch (Exception $e) {}
                }
            } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_messages', 'ix_warroom_messages_channel')) {
            // (channel_id, id) — every read is "this channel, newer than id N".
            try { $conn->exec("ALTER TABLE warroom_messages ADD KEY ix_warroom_messages_channel (channel_id, id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_messages', 'ix_warroom_messages_created')) {
            // Retention deletes by age across every channel at once.
            try { $conn->exec("ALTER TABLE warroom_messages ADD KEY ix_warroom_messages_created (created_datetime)"); } catch (Exception $e) {}
        }
        if ($colExists('warroom_messages', 'reply_to_id') && !$idxExists('warroom_messages', 'ix_warroom_messages_reply')) {
            // Warbot's reply points at the message it answers; the lookup is also
            // what stops a retried trigger posting the same answer twice.
            try { $conn->exec("ALTER TABLE warroom_messages ADD KEY ix_warroom_messages_reply (reply_to_id)"); } catch (Exception $e) {}
        }
        if ($tableExists('warroom_channels') && !$fkExists('warroom_messages', 'fk_warroom_messages_channel')) {
            try { $conn->exec("ALTER TABLE warroom_messages ADD CONSTRAINT fk_warroom_messages_channel FOREIGN KEY (channel_id) REFERENCES warroom_channels (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('warroom_messages', 'fk_warroom_messages_analyst')) {
            try { $conn->exec("ALTER TABLE warroom_messages ADD CONSTRAINT fk_warroom_messages_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
        // Who deleted a message. SET NULL, like the author: the tombstone must
        // outlive the person, or deleting a leaver would turn "deleted by Sarah"
        // back into an unexplained gap.
        if ($tableExists('analysts') && $colExists('warroom_messages', 'deleted_by')
            && !$fkExists('warroom_messages', 'fk_warroom_messages_deleter')) {
            try { $conn->exec("ALTER TABLE warroom_messages ADD CONSTRAINT fk_warroom_messages_deleter FOREIGN KEY (deleted_by) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('warroom_mentions') && $tableExists('warroom_messages')) {
        if (!$idxExists('warroom_mentions', 'uq_warroom_mention')) {
            // One row per (message, person), however many times their name appears.
            try { $conn->exec("ALTER TABLE warroom_mentions ADD UNIQUE KEY uq_warroom_mention (message_id, analyst_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_mentions', 'ix_warroom_mentions_analyst')) {
            // The notifications panel asks "mine, newest first".
            try { $conn->exec("ALTER TABLE warroom_mentions ADD KEY ix_warroom_mentions_analyst (analyst_id, id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('warroom_mentions', 'fk_warroom_mentions_message')) {
            try { $conn->exec("ALTER TABLE warroom_mentions ADD CONSTRAINT fk_warroom_mentions_message FOREIGN KEY (message_id) REFERENCES warroom_messages (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('warroom_mentions', 'fk_warroom_mentions_analyst')) {
            try { $conn->exec("ALTER TABLE warroom_mentions ADD CONSTRAINT fk_warroom_mentions_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('warroom_attachments') && $tableExists('warroom_messages')) {
        if (!$idxExists('warroom_attachments', 'ix_warroom_attachments_message')) {
            try { $conn->exec("ALTER TABLE warroom_attachments ADD KEY ix_warroom_attachments_message (message_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_attachments', 'ix_warroom_attachments_stored')) {
            // Retention looks a file up by its stored name before unlinking it.
            try { $conn->exec("ALTER TABLE warroom_attachments ADD KEY ix_warroom_attachments_stored (stored_name)"); } catch (Exception $e) {}
        }
        if (!$fkExists('warroom_attachments', 'fk_warroom_attachments_message')) {
            try { $conn->exec("ALTER TABLE warroom_attachments ADD CONSTRAINT fk_warroom_attachments_message FOREIGN KEY (message_id) REFERENCES warroom_messages (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('warroom_reads') && $tableExists('warroom_channels')) {
        if (!$idxExists('warroom_reads', 'uq_warroom_read')) {
            // UNIQUE is what makes marking-as-read an upsert rather than a row per poll.
            try { $conn->exec("ALTER TABLE warroom_reads ADD UNIQUE KEY uq_warroom_read (analyst_id, channel_id)"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('warroom_reads', 'fk_warroom_reads_analyst')) {
            try { $conn->exec("ALTER TABLE warroom_reads ADD CONSTRAINT fk_warroom_reads_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('warroom_reads', 'fk_warroom_reads_channel')) {
            try { $conn->exec("ALTER TABLE warroom_reads ADD CONSTRAINT fk_warroom_reads_channel FOREIGN KEY (channel_id) REFERENCES warroom_channels (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // War room presence. UNIQUE on analyst_id is what makes the heartbeat an
    // upsert — without it a long-running poll would add a row per request.
    // Presence is ephemeral, so both parents CASCADE/SET NULL freely.
    if ($tableExists('warroom_presence')) {
        // Same one-time move as messages: presence used to record which TEAM you
        // were in, and now records which CHANNEL. Presence is ephemeral, so there
        // is nothing worth migrating — the column is replaced, not backfilled.
        if ($colExists('warroom_presence', 'team_id') && $colExists('warroom_presence', 'channel_id')) {
            try { $conn->exec("ALTER TABLE warroom_presence DROP FOREIGN KEY fk_warroom_presence_team"); } catch (Exception $e) {}
            try { $conn->exec("ALTER TABLE warroom_presence DROP COLUMN team_id"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_presence', 'uq_warroom_presence')) {
            try { $conn->exec("ALTER TABLE warroom_presence ADD UNIQUE KEY uq_warroom_presence (analyst_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('warroom_presence', 'ix_warroom_presence_last_seen')) {
            try { $conn->exec("ALTER TABLE warroom_presence ADD KEY ix_warroom_presence_last_seen (last_seen)"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('warroom_presence', 'fk_warroom_presence_analyst')) {
            try { $conn->exec("ALTER TABLE warroom_presence ADD CONSTRAINT fk_warroom_presence_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('warroom_channels') && !$fkExists('warroom_presence', 'fk_warroom_presence_channel')) {
            try { $conn->exec("ALTER TABLE warroom_presence ADD CONSTRAINT fk_warroom_presence_channel FOREIGN KEY (channel_id) REFERENCES warroom_channels (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // Web chat widget → its optional business-hours calendar (SET NULL if the calendar goes).
    if ($tableExists('webchat_widgets') && $tableExists('sla_calendars') && $colExists('webchat_widgets', 'business_calendar_id')) {
        if (!$fkExists('webchat_widgets', 'fk_webchat_widget_calendar')) {
            try { $conn->exec("ALTER TABLE webchat_widgets ADD CONSTRAINT fk_webchat_widget_calendar FOREIGN KEY (business_calendar_id) REFERENCES sla_calendars (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // Seed the WhatsApp ticket origin (global default) if absent, so channel tickets
    // can be tagged by origin out of the box. Single-company installs see it like any
    // other origin; it can be hidden per-company via the add+hide model.
    if ($tableExists('ticket_origins')) {
        try {
            $chk = $conn->prepare("SELECT COUNT(*) FROM ticket_origins WHERE name = 'WhatsApp' AND tenant_id IS NULL");
            $chk->execute();
            if ((int) $chk->fetchColumn() === 0) {
                $conn->exec("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES ('WhatsApp', 'Messages received via WhatsApp', 50, 1, NULL)");
                $results[] = ['table' => 'ticket_origins', 'status' => 'updated', 'details' => ['Seeded the WhatsApp ticket origin']];
            }
        } catch (Exception $e) {}
        // Web chat origin — same treatment, for tickets raised from a website chat widget.
        try {
            $chk = $conn->prepare("SELECT COUNT(*) FROM ticket_origins WHERE name = 'Web chat' AND tenant_id IS NULL");
            $chk->execute();
            if ((int) $chk->fetchColumn() === 0) {
                $conn->exec("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES ('Web chat', 'Messages received via the website chat widget', 51, 1, NULL)");
                $results[] = ['table' => 'ticket_origins', 'status' => 'updated', 'details' => ['Seeded the Web chat ticket origin']];
            }
        } catch (Exception $e) {}
        // Slack origin — for tickets raised from a message in a Slack channel.
        // Without it getChannelOriginId() returns null and Slack tickets have no
        // origin at all, so they vanish from any report grouped by origin.
        try {
            $chk = $conn->prepare("SELECT COUNT(*) FROM ticket_origins WHERE name = 'Slack' AND tenant_id IS NULL");
            $chk->execute();
            if ((int) $chk->fetchColumn() === 0) {
                $conn->exec("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES ('Slack', 'Messages received via Slack', 52, 1, NULL)");
                $results[] = ['table' => 'ticket_origins', 'status' => 'updated', 'details' => ['Seeded the Slack ticket origin']];
            }
        } catch (Exception $e) {}
    }
    // Per-company config: the generic "hide" layer + per-entity tenant_id columns.
    if ($tableExists('tenant_config_hidden') && $tableExists('tenants')) {
        if (!$idxExists('tenant_config_hidden', 'uq_tenant_config_hidden')) {
            try { $conn->exec("ALTER TABLE tenant_config_hidden ADD UNIQUE KEY uq_tenant_config_hidden (tenant_id, entity_type, entity_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tenant_config_hidden', 'fk_tenant_config_hidden_tenant')) {
            try { $conn->exec("ALTER TABLE tenant_config_hidden ADD CONSTRAINT fk_tenant_config_hidden_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('ticket_types') && $tableExists('tenants') && $colExists('ticket_types', 'tenant_id')) {
        if (!$fkExists('ticket_types', 'fk_ticket_types_tenant')) {
            try { $conn->exec("ALTER TABLE ticket_types ADD CONSTRAINT fk_ticket_types_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        // Widen name-uniqueness from global to per-scope so a company can hold a
        // type whose name matches a global default. (Global-name dedup is enforced
        // in the API — NULL tenant_id rows aren't de-duped by a unique key.)
        if ($idxExists('ticket_types', 'uq_ticket_types_name')) {
            try { $conn->exec("ALTER TABLE ticket_types DROP INDEX uq_ticket_types_name"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_types', 'uq_ticket_types_tenant_name')) {
            try { $conn->exec("ALTER TABLE ticket_types ADD UNIQUE KEY uq_ticket_types_tenant_name (tenant_id, name)"); } catch (Exception $e) {}
        }
    }

    // --- Asset Management multi-tenancy (mirrors ticket_types above) ----------
    // asset_types / asset_status_types are CONFIG LISTS: NULL tenant_id = global
    // default (shared by every company); set = a company's own. Widen their
    // name-uniqueness from global to per-scope so a company may hold an option
    // whose name matches a shared default (global-name dedup is enforced in the
    // API — NULL tenant_id rows aren't de-duped by a unique key).
    // asset_locations is NOT a config list — it is SCOPED DATA (a company's sites
    // are its own, never shared), so NULL means the Default company's rather than
    // "global" and it takes no name unique. Same CASCADE either way.
    foreach ([
        ['asset_types',        'fk_asset_types_tenant',        'uq_asset_types_name',        'uq_asset_types_tenant_name'],
        ['asset_status_types', 'fk_asset_status_types_tenant', 'uq_asset_status_types_name', 'uq_asset_status_types_tenant_name'],
        ['asset_locations',    'fk_asset_locations_tenant',    null,                         null],
    ] as [$tbl, $fk, $oldIdx, $newIdx]) {
        if ($tableExists($tbl) && $tableExists('tenants') && $colExists($tbl, 'tenant_id')) {
            if (!$fkExists($tbl, $fk)) {
                try { $conn->exec("ALTER TABLE $tbl ADD CONSTRAINT $fk FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
            }
            if ($oldIdx && $idxExists($tbl, $oldIdx)) {
                try { $conn->exec("ALTER TABLE $tbl DROP INDEX $oldIdx"); } catch (Exception $e) {}
            }
            if ($newIdx && !$idxExists($tbl, $newIdx)) {
                try { $conn->exec("ALTER TABLE $tbl ADD UNIQUE KEY $newIdx (tenant_id, name)"); } catch (Exception $e) {}
            }
        }
    }
    // assets.tenant_id (SCOPED DATA, not config): the company an asset belongs to.
    // FK reverts to Default (SET NULL) if a company is ever deleted — never
    // cascade-delete assets. Index backs the list scope filter.
    if ($tableExists('assets') && $tableExists('tenants') && $colExists('assets', 'tenant_id')) {
        if (!$fkExists('assets', 'fk_assets_tenant')) {
            try { $conn->exec("ALTER TABLE assets ADD CONSTRAINT fk_assets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
        if (!$idxExists('assets', 'idx_assets_tenant')) {
            try { $conn->exec("ALTER TABLE assets ADD INDEX idx_assets_tenant (tenant_id)"); } catch (Exception $e) {}
        }
    }
    // tasks.tenant_id (SCOPED DATA, not config): the company a task belongs to.
    // NULL = the Default company's, matching tickets and assets — a task is a work
    // item, so it takes the data convention, not the shared-intake one. SET NULL so
    // deleting a company reverts its tasks to Default rather than destroying work.
    if ($tableExists('tasks') && $tableExists('tenants') && $colExists('tasks', 'tenant_id')) {
        if (!$fkExists('tasks', 'fk_tasks_tenant')) {
            try { $conn->exec("ALTER TABLE tasks ADD CONSTRAINT fk_tasks_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
        if (!$idxExists('tasks', 'idx_tasks_tenant')) {
            try { $conn->exec("ALTER TABLE tasks ADD INDEX idx_tasks_tenant (tenant_id)"); } catch (Exception $e) {}
        }
    }
    // Recurring tasks (#94). Both SET NULL rather than CASCADE, deliberately:
    // deleting the rule must stop the series repeating, and deleting the first
    // task must not take the work already done from later occurrences with it.
    // A task that has happened is a record of something, whatever produced it.
    if ($tableExists('tasks') && $tableExists('task_recurrences') && $colExists('tasks', 'recurrence_id')) {
        if (!$fkExists('tasks', 'fk_tasks_recurrence')) {
            try { $conn->exec("ALTER TABLE tasks ADD CONSTRAINT fk_tasks_recurrence FOREIGN KEY (recurrence_id) REFERENCES task_recurrences (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('tasks') && $colExists('tasks', 'recurrence_master_id')) {
        if (!$fkExists('tasks', 'fk_tasks_recurrence_master')) {
            try { $conn->exec("ALTER TABLE tasks ADD CONSTRAINT fk_tasks_recurrence_master FOREIGN KEY (recurrence_master_id) REFERENCES tasks (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // apikeys.tenant_id: the company an ingest key pins its assets to (NULL =
    // Default). SET NULL so deleting a company reverts its keys to Default rather
    // than orphaning the agent.
    if ($tableExists('apikeys') && $tableExists('tenants') && $colExists('apikeys', 'tenant_id')) {
        if (!$fkExists('apikeys', 'fk_apikeys_tenant')) {
            try { $conn->exec("ALTER TABLE apikeys ADD CONSTRAINT fk_apikeys_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // cmdb_objects.tenant_id (SCOPED DATA): the company a configuration item
    // belongs to. SET NULL like assets — deleting a company reverts its CIs to
    // Default rather than destroying the estate record. Only cmdb_objects is
    // scoped; classes/properties/relationship types stay install-wide config, and
    // the child tables inherit through their object.
    // (The backing index ix_cmdb_objects_tenant_id comes from the generated index
    // list, which is parsed out of freeitsm.sql — no manual add needed here.)
    if ($tableExists('cmdb_objects') && $tableExists('tenants') && $colExists('cmdb_objects', 'tenant_id')) {
        if (!$fkExists('cmdb_objects', 'fk_cmdb_objects_tenant')) {
            try { $conn->exec("ALTER TABLE cmdb_objects ADD CONSTRAINT fk_cmdb_objects_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // ticket_origins had no name unique key historically; we don't add one (would
    // fail on pre-existing duplicate names) — dedup is enforced in the API.
    if ($tableExists('ticket_origins') && $tableExists('tenants') && $colExists('ticket_origins', 'tenant_id')) {
        if (!$fkExists('ticket_origins', 'fk_ticket_origins_tenant')) {
            try { $conn->exec("ALTER TABLE ticket_origins ADD CONSTRAINT fk_ticket_origins_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('analyst_tenant_access') && $tableExists('analysts') && $tableExists('tenants')) {
        if (!$idxExists('analyst_tenant_access', 'uq_analyst_tenant')) {
            try { $conn->exec("ALTER TABLE analyst_tenant_access ADD UNIQUE KEY uq_analyst_tenant (analyst_id, tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('analyst_tenant_access', 'fk_ata_analyst')) {
            try { $conn->exec("ALTER TABLE analyst_tenant_access ADD CONSTRAINT fk_ata_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('analyst_tenant_access', 'fk_ata_tenant')) {
            try { $conn->exec("ALTER TABLE analyst_tenant_access ADD CONSTRAINT fk_ata_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('team_tenant_access') && $tableExists('teams') && $tableExists('tenants')) {
        if (!$idxExists('team_tenant_access', 'uq_team_tenant')) {
            try { $conn->exec("ALTER TABLE team_tenant_access ADD UNIQUE KEY uq_team_tenant (team_id, tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('team_tenant_access', 'fk_tta_team')) {
            try { $conn->exec("ALTER TABLE team_tenant_access ADD CONSTRAINT fk_tta_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if (!$fkExists('team_tenant_access', 'fk_tta_tenant')) {
            try { $conn->exec("ALTER TABLE team_tenant_access ADD CONSTRAINT fk_tta_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    // tickets.tenant_id — index + FK + a ONE-TIME backfill of existing tickets to
    // the Default company. Like target_mailboxes (and unlike a naive sweep), we
    // backfill ONLY when the column was just added ($ticketsTenantColWasMissing):
    // afterwards a NULL tenant_id marks an un-routed inbound email sitting in the
    // TRIAGE queue, so re-sweeping it to Default would empty that queue.
    if ($tableExists('tickets') && $tableExists('tenants') && $colExists('tickets', 'tenant_id')) {
        if (!$idxExists('tickets', 'ix_tickets_tenant_id')) {
            try { $conn->exec("ALTER TABLE tickets ADD KEY ix_tickets_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('tickets', 'fk_tickets_tenant')) {
            try { $conn->exec("ALTER TABLE tickets ADD CONSTRAINT fk_tickets_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)"); } catch (Exception $e) {}
        }
        if ($ticketsTenantColWasMissing) {
            $defaultTenantId = (int) ($conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            if ($defaultTenantId > 0) {
                $backfilled = $conn->exec("UPDATE tickets SET tenant_id = $defaultTenantId WHERE tenant_id IS NULL");
                if ($backfilled > 0) {
                    $results[] = ['table' => 'tickets', 'status' => 'updated', 'details' => ["Backfilled tenant_id on $backfilled ticket(s) to the Default company (multi-tenancy migration)"]];
                }
            }
        }
    }
    // target_mailboxes.tenant_id — index + FK + a ONE-TIME backfill pinning every
    // existing mailbox to the Default company. This keeps existing inbound mail
    // flowing to Default exactly as before once a second company is added (a
    // pinned mailbox decides the tenant; the sender is ignored). NULL means
    // "shared intake" (route by sender domain) going forward, so — unlike tickets
    // — we backfill ONLY when the column was just added ($mailboxTenantColWasMissing),
    // never on later verifies, so an admin's deliberate shared-intake choice sticks.
    if ($tableExists('target_mailboxes') && $tableExists('tenants') && $colExists('target_mailboxes', 'tenant_id')) {
        if (!$idxExists('target_mailboxes', 'ix_target_mailboxes_tenant_id')) {
            try { $conn->exec("ALTER TABLE target_mailboxes ADD KEY ix_target_mailboxes_tenant_id (tenant_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('target_mailboxes', 'fk_target_mailboxes_tenant')) {
            try { $conn->exec("ALTER TABLE target_mailboxes ADD CONSTRAINT fk_target_mailboxes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
        if ($mailboxTenantColWasMissing) {
            $defaultTenantId = (int) ($conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            if ($defaultTenantId > 0) {
                $pinned = $conn->exec("UPDATE target_mailboxes SET tenant_id = $defaultTenantId WHERE tenant_id IS NULL");
                if ($pinned > 0) {
                    $results[] = ['table' => 'target_mailboxes', 'status' => 'updated', 'details' => ["Pinned $pinned existing mailbox(es) to the Default company (multi-tenancy migration)"]];
                }
            }
        }
    }

    // ----------------------------------------------------------
    // SLA setup — see docs/sla.md
    // ----------------------------------------------------------

    // Unique key + FK constraints for the SLA hours/holidays tables (columns
    // alone aren't enough). Add idempotently.
    if ($tableExists('sla_calendar_hours') && $tableExists('sla_calendars')) {
        if (!$idxExists('sla_calendar_hours', 'uq_sla_calendar_hours')) {
            try { $conn->exec("ALTER TABLE sla_calendar_hours ADD UNIQUE KEY uq_sla_calendar_hours (calendar_id, weekday)"); } catch (Exception $e) {}
        }
        if (!$fkExists('sla_calendar_hours', 'fk_sla_hours_calendar')) {
            try { $conn->exec("ALTER TABLE sla_calendar_hours ADD CONSTRAINT fk_sla_hours_calendar FOREIGN KEY (calendar_id) REFERENCES sla_calendars (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('sla_calendar_holidays') && $tableExists('sla_calendars')) {
        if (!$idxExists('sla_calendar_holidays', 'uq_sla_holidays')) {
            try { $conn->exec("ALTER TABLE sla_calendar_holidays ADD UNIQUE KEY uq_sla_holidays (calendar_id, holiday_date)"); } catch (Exception $e) {}
        }
        if (!$fkExists('sla_calendar_holidays', 'fk_sla_holidays_calendar')) {
            try { $conn->exec("ALTER TABLE sla_calendar_holidays ADD CONSTRAINT fk_sla_holidays_calendar FOREIGN KEY (calendar_id) REFERENCES sla_calendars (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('ticket_priorities') && $tableExists('sla_calendars') && $colExists('ticket_priorities', 'sla_calendar_id')) {
        if (!$fkExists('ticket_priorities', 'fk_ticket_priorities_sla_calendar')) {
            try { $conn->exec("ALTER TABLE ticket_priorities ADD CONSTRAINT fk_ticket_priorities_sla_calendar FOREIGN KEY (sla_calendar_id) REFERENCES sla_calendars (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    // FK + UNIQUE constraints for SLA breach notification rules + dedup log
    if ($tableExists('sla_notification_rules')) {
        if ($tableExists('departments') && !$fkExists('sla_notification_rules', 'fk_sla_notif_rule_dept')) {
            try { $conn->exec("ALTER TABLE sla_notification_rules ADD CONSTRAINT fk_sla_notif_rule_dept FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('sla_notification_rules', 'fk_sla_notif_rule_analyst')) {
            try { $conn->exec("ALTER TABLE sla_notification_rules ADD CONSTRAINT fk_sla_notif_rule_analyst FOREIGN KEY (notify_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('sla_notifications_sent')) {
        if (!$idxExists('sla_notifications_sent', 'uq_sla_notif_sent')) {
            try { $conn->exec("ALTER TABLE sla_notifications_sent ADD UNIQUE KEY uq_sla_notif_sent (ticket_id, target_type, trigger_type)"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('sla_notifications_sent', 'fk_sla_notif_sent_ticket')) {
            try { $conn->exec("ALTER TABLE sla_notifications_sent ADD CONSTRAINT fk_sla_notif_sent_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
    }
    if ($tableExists('sla_cron_runs')) {
        if (!$idxExists('sla_cron_runs', 'idx_sla_cron_started')) {
            try { $conn->exec("ALTER TABLE sla_cron_runs ADD INDEX idx_sla_cron_started (started_at)"); } catch (Exception $e) {}
        }
        if (!$idxExists('sla_cron_runs', 'idx_sla_cron_ip_started')) {
            try { $conn->exec("ALTER TABLE sla_cron_runs ADD INDEX idx_sla_cron_ip_started (client_ip, started_at)"); } catch (Exception $e) {}
        }
    }

    // ticket_recordings: ticket_id is nullable (pending uploads before ticket creation), CASCADE on ticket delete
    if ($tableExists('ticket_recordings')) {
        if (!$idxExists('ticket_recordings', 'ix_ticket_recordings_ticket_id')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD INDEX ix_ticket_recordings_ticket_id (ticket_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_recordings', 'ix_ticket_recordings_pending')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD INDEX ix_ticket_recordings_pending (ticket_id, created_at)"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('ticket_recordings', 'fk_ticket_recordings_ticket')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD CONSTRAINT fk_ticket_recordings_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('users') && !$fkExists('ticket_recordings', 'fk_ticket_recordings_user')) {
            try { $conn->exec("ALTER TABLE ticket_recordings ADD CONSTRAINT fk_ticket_recordings_user FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // ticket_csat_responses: token must be unique (it's the survey URL key), CASCADE on ticket delete
    if ($tableExists('ticket_csat_responses')) {
        if (!$idxExists('ticket_csat_responses', 'uq_ticket_csat_token')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD UNIQUE KEY uq_ticket_csat_token (token)"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_csat_responses', 'ix_ticket_csat_ticket_id')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD INDEX ix_ticket_csat_ticket_id (ticket_id)"); } catch (Exception $e) {}
        }
        if (!$idxExists('ticket_csat_responses', 'ix_ticket_csat_responded')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD INDEX ix_ticket_csat_responded (responded_datetime)"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('ticket_csat_responses', 'fk_ticket_csat_ticket')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD CONSTRAINT fk_ticket_csat_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('ticket_csat_responses', 'fk_ticket_csat_analyst')) {
            try { $conn->exec("ALTER TABLE ticket_csat_responses ADD CONSTRAINT fk_ticket_csat_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // ticket_merges: the merge log. CASCADE on either ticket being hard-deleted (the
    // row describes a relationship between two tickets and means nothing without
    // them), SET NULL on the analyst so a merge record survives a leaver.
    if ($tableExists('ticket_merges')) {
        if ($tableExists('tickets') && !$fkExists('ticket_merges', 'fk_ticket_merges_source')) {
            try { $conn->exec("ALTER TABLE ticket_merges ADD CONSTRAINT fk_ticket_merges_source FOREIGN KEY (source_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('ticket_merges', 'fk_ticket_merges_target')) {
            try { $conn->exec("ALTER TABLE ticket_merges ADD CONSTRAINT fk_ticket_merges_target FOREIGN KEY (target_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('ticket_merges', 'fk_ticket_merges_analyst')) {
            try { $conn->exec("ALTER TABLE ticket_merges ADD CONSTRAINT fk_ticket_merges_analyst FOREIGN KEY (merged_by_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // ticket_splits: the mirror of ticket_merges, same FK reasoning.
    if ($tableExists('ticket_splits')) {
        if ($tableExists('tickets') && !$fkExists('ticket_splits', 'fk_ticket_splits_source')) {
            try { $conn->exec("ALTER TABLE ticket_splits ADD CONSTRAINT fk_ticket_splits_source FOREIGN KEY (source_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('tickets') && !$fkExists('ticket_splits', 'fk_ticket_splits_new')) {
            try { $conn->exec("ALTER TABLE ticket_splits ADD CONSTRAINT fk_ticket_splits_new FOREIGN KEY (new_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"); } catch (Exception $e) {}
        }
        if ($tableExists('analysts') && !$fkExists('ticket_splits', 'fk_ticket_splits_analyst')) {
            try { $conn->exec("ALTER TABLE ticket_splits ADD CONSTRAINT fk_ticket_splits_analyst FOREIGN KEY (split_by_id) REFERENCES analysts (id) ON DELETE SET NULL"); } catch (Exception $e) {}
        }
    }

    // tickets.merged_into_id is a SELF-reference, and deliberately SET NULL rather
    // than CASCADE: hard-deleting a surviving ticket must never drag the tickets that
    // were merged into it out of the database along with it.
    if ($tableExists('tickets') && !$fkExists('tickets', 'fk_tickets_merged_into')) {
        try { $conn->exec("ALTER TABLE tickets ADD CONSTRAINT fk_tickets_merged_into FOREIGN KEY (merged_into_id) REFERENCES tickets (id) ON DELETE SET NULL"); } catch (Exception $e) {}
    }

    // Seed a default Mon-Fri 09:00-17:00 calendar in Europe/London if no
    // calendars exist yet. Detected installs that pre-date the SLA module
    // will pick this up on first verify; the freeitsm.sql seed handles fresh.
    if ($tableExists('sla_calendars')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM sla_calendars")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO sla_calendars (name, timezone, is_default) VALUES ('Default Business Hours', 'Europe/London', 1)");
            $newCalId = (int)$conn->lastInsertId();
            if ($newCalId && $tableExists('sla_calendar_hours')) {
                $stmt = $conn->prepare("INSERT INTO sla_calendar_hours (calendar_id, weekday, start_time, end_time) VALUES (?, ?, '09:00:00', '17:00:00')");
                foreach ([1, 2, 3, 4, 5] as $wd) { $stmt->execute([$newCalId, $wd]); }
            }
            $results[] = ['table' => 'sla_calendars', 'status' => 'seeded', 'details' => ['Inserted default Mon-Fri 09:00-17:00 calendar (Europe/London)']];
        }
    }

    // Seed default system_settings rows for the SLA toggles. INSERT IGNORE
    // so they only land on first run; existing values aren't overwritten.
    if ($tableExists('system_settings')) {
        $defaults = [
            'sla_enforce_from'                => null, // NULL = SLA enforcement disabled
            'sla_priority_change_behaviour'   => 'forward',
            'sla_reopen_behaviour'            => 'reset',
            'sla_warning_threshold_percent'   => '80',
            'sla_notify_assignee_at_warning'  => '1',
            'sla_notify_lead_at_breach'       => '1',
            'sla_first_response_definition'   => 'either',
            // Shared secret for HTTP-triggered cron worker; random per install
            'sla_cron_token'                  => bin2hex(random_bytes(16)),
            // Min seconds between successful cron runs — protects against
            // accidental double-scheduling, runaway loops, or token-leak abuse
            'sla_cron_min_interval_seconds'   => '30',
            // How many days to keep rows in sla_cron_runs before pruning
            'sla_cron_log_retention_days'     => '30',
            // Outbound-webhook delivery worker (cron/webhook_deliveries.php):
            // shared secret for HTTP invocation + min seconds between runs +
            // how long to keep delivered/dead rows before pruning.
            'webhook_cron_token'              => bin2hex(random_bytes(16)),
            'webhook_cron_min_interval_seconds' => '20',
            'webhook_delivery_retention_days' => '30',
            // Time-based workflow triggers (cron/workflow_scheduled.php): emits
            // contract.expiring / asset.warranty_expiring. Expiry windows move once
            // a day, so a 5-minute floor is generous — hourly scheduling is plenty.
            // (sla.warning / sla.breached come from the SLA cron instead.)
            'workflow_cron_token'             => bin2hex(random_bytes(16)),
            'workflow_cron_min_interval_seconds' => '300',
            // External issue trackers (cron/integration_poll.php): refreshes the
            // cached status of every linked issue. A 60s floor rather than the
            // webhook worker's 20s because this makes an outbound API call per
            // connection and trackers rate-limit — there is no benefit in being
            // more eager than the poll_interval_minutes set per connection.
            'integration_cron_token'          => bin2hex(random_bytes(16)),
            'integration_cron_min_interval_seconds' => '60',
            // Watchtower: flag tickets stuck in a paused-SLA status longer than this
            // (wall-clock hours since last status change). Guardrail against analysts
            // parking tickets in On Hold to escape the SLA clock.
            'watchtower_paused_too_long_hours' => '24',
            // Tasks calendar: how multi-day tasks render — deadline | span | repeat
            'tasks_calendar_span_mode'        => 'deadline',
            // CSAT (customer satisfaction surveys on ticket closure)
            // mode: off | auto | manual
            //   off    — feature disabled, no survey ever sent
            //   auto   — survey email queued immediately on close (or after csat_delay_minutes)
            //   manual — analyst clicks 'Request feedback' from the ticket toolbar
            'csat_mode'                       => 'off',
            // Wait this many minutes after close before sending the survey email,
            // so the user has a chance to verify the fix actually held. 0 = send immediately.
            'csat_delay_minutes'              => '0',
            // Render the survey UI as 5 stars (⭐⭐⭐⭐⭐) or 5 emojis (😡 🙁 😐 🙂 😀)
            // Same 1-5 data model either way, dashboards/averages work identically.
            'csat_scale'                      => 'stars',
            // If 1, a reopened-then-closed ticket only gets a new survey when the analyst
            // manually triggers it (stops survey-spamming a flaky ticket). If 0, every close fires.
            'csat_one_per_ticket'             => '1',
            // Shared HMAC secret for tokenising survey URLs. Random per install — leaking it
            // would let anyone post ratings on behalf of users, so it stays in system_settings
            // rather than going to a public file or being printed in error pages.
            'csat_token_secret'                => bin2hex(random_bytes(32)),
            // What to do with an inbound attachment whose type we do not accept
            // (see includes/uploads.php). 'store' keeps it under a name of our own
            // with an inert .bin extension, download-only; 'drop' does not write it
            // at all. Either way the outcome is written onto the ticket, so a file
            // never disappears silently. Defaults to keeping, because losing
            // somebody's file is the worse surprise of the two.
            'attachment_rejected_behaviour'   => 'store',

            // Which attachment types are accepted. Seeded EMPTY on purpose: empty means
            // "the whole catalogue in includes/uploads.php", so an install picks up new
            // safe types when the product adds them instead of being frozen at whatever
            // the list happened to be on the day it was installed. An administrator who
            // wants to narrow it types the extensions they want; attachmentAllowedTypes()
            // intersects that with the catalogue, so it can only ever subtract.
            'attachment_allowed_extensions'   => '',

            // ── Brute-force protection ──────────────────────────────────────────
            // None of these were ever seeded, and both call sites read a missing
            // value as 0 and 0 as "off" — so every fresh install shipped with
            // account lockout and IP banning entirely disabled. Worse, System →
            // Security pre-filled "5" and "2" in the boxes from a hardcoded
            // fallback, so an administrator looking at the page reasonably concluded
            // the protection was on. It only became true if they happened to press
            // Save. Seeding the values the UI was already claiming makes the screen
            // honest and turns the protection on by default.
            //
            // The trade-off is real and deliberate: an attacker who knows a username
            // can lock that account for 30 minutes. That is preferable to unlimited
            // password guessing, and the IP ban below is what handles the broader
            // sweep across many usernames.
            'max_failed_logins'               => '5',
            'lockout_duration_minutes'        => '30',
            'max_ip_attempts'                 => '5',
            'min_ip_attempts'                 => '2',
            // 0 = passwords never expire. Left off on purpose: forced rotation on a
            // timer is no longer considered good practice (it drives predictable
            // increments), and unlike the settings above, turning it on silently at
            // upgrade would lock people out of their own service desk.
            'password_expiry_days'            => '0',
        ];
        // Secrets are seeded already encrypted. The whole block is best-effort: an
        // install that has no encryption key yet must still be able to build its
        // schema, so a missing key downgrades to plaintext with a warning rather
        // than aborting the verify.
        $encryptionUsable = true;
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $k => $v) {
            $toStore = $v;
            if ($v !== null && $v !== '' && isEncryptedSettingKey($k) && $encryptionUsable) {
                try {
                    $toStore = encryptValue($v);
                } catch (Exception $e) {
                    $encryptionUsable = false;
                    $results[] = ['table' => 'system_settings', 'status' => 'warning',
                                  'details' => ['Could not encrypt seeded secrets (' . $e->getMessage() . ') — stored as-is. Fix the encryption key and re-run.']];
                }
            }
            $stmt->execute([$k, $toStore]);
        }

        // Migrate secrets that were stored before isEncryptedSettingKey() covered
        // them. csat_token_secret and the four *_cron_token values were seeded in
        // plaintext by every release up to f7f1e9dd, and are never re-saved through
        // the settings UI, so without this they would stay in the clear forever.
        // Idempotent: anything already carrying the ENC: prefix is skipped.
        if ($encryptionUsable) {
            try {
                $reencrypted = [];
                $updSecret = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                foreach ($conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $k = $row['setting_key'];
                    $v = $row['setting_value'];
                    if ($v === null || $v === '') continue;
                    if (!isEncryptedSettingKey($k)) continue;
                    if (strpos($v, ENCRYPTION_PREFIX) === 0) continue;   // already ciphertext
                    $updSecret->execute([encryptValue($v), $k]);
                    $reencrypted[] = $k;
                }
                if ($reencrypted) {
                    $results[] = ['table' => 'system_settings', 'status' => 'migrated',
                                  'details' => ['Encrypted ' . count($reencrypted) . ' secret(s) that were stored in plaintext: ' . implode(', ', $reencrypted)]];
                }
            } catch (Exception $e) {
                $results[] = ['table' => 'system_settings', 'status' => 'warning',
                              'details' => ['Could not re-encrypt existing secrets: ' . $e->getMessage()]];
            }
        }
    }

    // Attachments written before the ingest paths were fixed still carry the
    // sender's own extension on disk — a real install had a .html sitting under
    // tickets/attachments/. The new directory rules deny those on Apache and IIS and
    // get_attachment.php forces a download, but nginx reads neither file, so the only
    // way to be sure is to take the dangerous extension away. The displayed filename
    // is a separate column and is untouched, so the attachment still downloads under
    // the name the sender gave it. Idempotent: .bin files no longer match.
    if ($tableExists('email_attachments') && $colExists('email_attachments', 'file_path')) {
        try {
            // ⚠️ This one sweep is a DENYLIST, unlike the ingest side, which is an
            // allow-list and stays that way. A denylist is only defensible here because
            // it is looking at names that already exist on disk rather than deciding what
            // may arrive — but it still has to be reasonably complete. .phar and .pht are
            // executed by common PHP configurations; .hta and .cer are executable on IIS;
            // .mhtml is a same-origin document; and .xml/.xsl/.xslt matter because a
            // legacy .xml served as text/xml can carry a self-referencing xml-stylesheet
            // processing instruction and run script in our own origin. All added after
            // Erlend Volden pointed out the gaps.
            $riskyExt = '[.](php|phtml|pht|php[0-9]|phps|phar|cgi|pl|py|jsp|asp|aspx|cer|sh|shtml|hta|htaccess|html|htm|mhtml|svg|svgz|xhtml|xml|xsl|xslt)$';
            $risky = $conn->query("SELECT id, file_path FROM email_attachments WHERE file_path REGEXP '$riskyExt'")->fetchAll(PDO::FETCH_ASSOC);
            $renamed = 0;
            $missing = 0;
            $attachRoot = dirname(dirname(__DIR__)) . '/tickets/attachments/';
            $updPath = $conn->prepare("UPDATE email_attachments SET file_path = ? WHERE id = ?");
            foreach ($risky as $att) {
                $rel = (string)$att['file_path'];
                $newRel = preg_replace('/\.[^.\/\\\\]+$/', '.' . ATTACHMENT_QUARANTINE_EXT, $rel);
                if ($newRel === null || $newRel === $rel) continue;

                $oldAbs = $attachRoot . $rel;
                $newAbs = $attachRoot . $newRel;
                if (!file_exists($oldAbs)) {
                    // Row points at a file that is already gone — still worth
                    // correcting the path so nothing re-derives an extension from it.
                    $updPath->execute([$newRel, $att['id']]);
                    $missing++;
                    continue;
                }

                // ⚠️ Two attachments on one email can collide here even though they never
                // collided on disk: report.htm and report.html both want report.bin, as do
                // index.html and index.php. rename() overwrites silently on POSIX, so one
                // file was destroyed and BOTH rows were then pointed at the survivor —
                // rare, and unrecoverable when it landed. Find a free name instead. The
                // displayed filename is a separate column, so the suffix is never seen by
                // anyone; it exists only to keep the two files apart.
                if (file_exists($newAbs)) {
                    $base = preg_replace('/\.[^.\/\\\\]+$/', '', $newRel);
                    $suffix = 1;
                    do {
                        $candidate = $base . '-' . $suffix . '.' . ATTACHMENT_QUARANTINE_EXT;
                        $suffix++;
                    } while ($suffix < 1000 && file_exists($attachRoot . $candidate));
                    if (file_exists($attachRoot . $candidate)) continue;   // gave up; leave it alone
                    $newRel = $candidate;
                    $newAbs = $attachRoot . $newRel;
                }

                if (@rename($oldAbs, $newAbs)) {
                    $updPath->execute([$newRel, $att['id']]);
                    $renamed++;
                }
            }
            if ($renamed > 0 || $missing > 0) {
                $detail = "Renamed $renamed existing attachment(s) with an executable or web extension to ." . ATTACHMENT_QUARANTINE_EXT;
                if ($missing > 0) $detail .= " ($missing row(s) had no file on disk)";
                $results[] = ['table' => 'email_attachments', 'status' => 'migrated', 'details' => [$detail]];
            }
        } catch (Exception $e) {
            $results[] = ['table' => 'email_attachments', 'status' => 'warning',
                          'details' => ['Could not rename legacy attachments: ' . $e->getMessage()]];
        }
    }

    // Same migration for target_mailboxes. token_data was absent from
    // ENCRYPTED_MAILBOX_COLUMNS, so on an existing install the azure_* columns are
    // ciphertext while the access + refresh tokens minted with them sit in the next
    // column in the clear. Writers encrypt from now on, but a mailbox only rewrites
    // token_data when its token refreshes — a dormant or app-only mailbox could stay
    // plaintext indefinitely, so bring the existing rows across here.
    // Covers every column in the list, not just token_data, so any future addition
    // migrates itself. Idempotent: ENC: values are skipped.
    if ($tableExists('target_mailboxes')) {
        try {
            $mbCols = array_values(array_filter(ENCRYPTED_MAILBOX_COLUMNS, fn($c) => $colExists('target_mailboxes', $c)));
            if ($mbCols) {
                $encryptedCount = 0;
                $touchedCols = [];
                $mbRows = $conn->query("SELECT id, `" . implode('`, `', $mbCols) . "` FROM target_mailboxes")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($mbRows as $mbRow) {
                    foreach ($mbCols as $col) {
                        $v = $mbRow[$col] ?? null;
                        if ($v === null || $v === '') continue;
                        if (strpos($v, ENCRYPTION_PREFIX) === 0) continue;   // already ciphertext
                        $conn->prepare("UPDATE target_mailboxes SET `$col` = ? WHERE id = ?")
                             ->execute([encryptValue($v), $mbRow['id']]);
                        $encryptedCount++;
                        $touchedCols[$col] = true;
                    }
                }
                if ($encryptedCount > 0) {
                    $results[] = ['table' => 'target_mailboxes', 'status' => 'migrated',
                                  'details' => ["Encrypted $encryptedCount mailbox value(s) previously stored in plaintext (" . implode(', ', array_keys($touchedCols)) . ')']];
                }
            }
        } catch (Exception $e) {
            $results[] = ['table' => 'target_mailboxes', 'status' => 'warning',
                          'details' => ['Could not re-encrypt mailbox credentials: ' . $e->getMessage()]];
        }
    }

    // Backfill tickets.status_id from legacy tickets.status
    if ($tableExists('tickets') && $colExists('tickets', 'status') && $colExists('tickets', 'status_id')) {
        // Insert any unknown status names into ticket_statuses so the FK can be set
        $conn->exec("INSERT IGNORE INTO ticket_statuses (name, display_order)
                     SELECT DISTINCT t.status, 999
                     FROM tickets t
                     LEFT JOIN ticket_statuses s ON LOWER(s.name) = LOWER(t.status)
                     WHERE t.status IS NOT NULL AND t.status <> '' AND s.id IS NULL");

        $upd = $conn->exec("UPDATE tickets t
                            JOIN ticket_statuses s ON LOWER(s.name) = LOWER(t.status)
                            SET t.status_id = s.id
                            WHERE t.status_id IS NULL AND t.status IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated', 'details' => ["Backfilled status_id for $upd ticket(s)"]];
        }

        // Set status_id to default for any ticket still missing one
        $conn->exec("UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE is_default = 1 LIMIT 1) WHERE status_id IS NULL");
    }

    // Backfill tickets.priority_id from legacy tickets.priority
    if ($tableExists('tickets') && $colExists('tickets', 'priority') && $colExists('tickets', 'priority_id')) {
        $conn->exec("INSERT IGNORE INTO ticket_priorities (name, display_order)
                     SELECT DISTINCT t.priority, 999
                     FROM tickets t
                     LEFT JOIN ticket_priorities p ON LOWER(p.name) = LOWER(t.priority)
                     WHERE t.priority IS NOT NULL AND t.priority <> '' AND p.id IS NULL");

        $upd = $conn->exec("UPDATE tickets t
                            JOIN ticket_priorities p ON LOWER(p.name) = LOWER(t.priority)
                            SET t.priority_id = p.id
                            WHERE t.priority_id IS NULL AND t.priority IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated', 'details' => ["Backfilled priority_id for $upd ticket(s)"]];
        }

        $conn->exec("UPDATE tickets SET priority_id = (SELECT id FROM ticket_priorities WHERE is_default = 1 LIMIT 1) WHERE priority_id IS NULL");
    }

    // Repair tickets left with no status/priority at all. This deliberately sits
    // OUTSIDE the two legacy blocks above: those are gated on the old
    // tickets.status / tickets.priority columns, which a fresh install has never
    // had, so their identical repairs could never run on the installs that hit
    // #79 — intake paths inserted a NULL status_id whenever the default status
    // had been renamed (e.g. translated to 'Offen'). The row then showed no
    // status chip and an empty Status dropdown.
    if ($tableExists('tickets') && $colExists('tickets', 'status_id')) {
        $upd = $conn->exec("UPDATE tickets
                               SET status_id = (SELECT id FROM ticket_statuses WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1)
                             WHERE status_id IS NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated',
                          'details' => ["Set the default status on $upd ticket(s) that had none"]];
        }
    }
    if ($tableExists('tickets') && $colExists('tickets', 'priority_id')) {
        $upd = $conn->exec("UPDATE tickets
                               SET priority_id = (SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1)
                             WHERE priority_id IS NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated',
                          'details' => ["Set the default priority on $upd ticket(s) that had none"]];
        }
    }

    // asset_history.field_name normalisation: legacy rows stored an English label
    // (e.g. "Purchase date"). New writes store a stable key so the history view can
    // localise it via t('asset-management.field.<key>'). Exact-match + idempotent.
    if ($tableExists('asset_history') && $colExists('asset_history', 'field_name')) {
        $assetHistoryLabelMap = [
            'Type' => 'type', 'Status' => 'status', 'Location' => 'location',
            'Purchase date' => 'purchase_date', 'Purchase cost' => 'purchase_cost',
            'Supplier' => 'supplier', 'Order number' => 'order_number',
            'Warranty expiry' => 'warranty_expiry', 'Assigned User' => 'assigned_user',
        ];
        $assetHistoryNormalised = 0;
        foreach ($assetHistoryLabelMap as $old => $new) {
            $u = $conn->prepare("UPDATE asset_history SET field_name = ? WHERE field_name = ?");
            $u->execute([$new, $old]);
            $assetHistoryNormalised += $u->rowCount();
        }
        if ($assetHistoryNormalised > 0) {
            $results[] = ['table' => 'asset_history', 'status' => 'migrated', 'details' => ["Normalised field_name to localisable keys on $assetHistoryNormalised row(s)"]];
        }
    }

    // Backfill tickets.user_id from legacy requester_email / requester_name
    if ($tableExists('tickets') && $tableExists('users') && $colExists('tickets', 'requester_email')) {
        // Match existing users by email
        $upd1 = $conn->exec("UPDATE tickets t
                             JOIN users u ON LOWER(u.email) = LOWER(t.requester_email)
                             SET t.user_id = u.id
                             WHERE t.user_id IS NULL AND t.requester_email IS NOT NULL AND t.requester_email <> ''");

        // Create users for any orphan emails
        $conn->exec("INSERT IGNORE INTO users (email, display_name, created_at)
                     SELECT t.requester_email, COALESCE(NULLIF(t.requester_name, ''), t.requester_email), UTC_TIMESTAMP()
                     FROM tickets t
                     LEFT JOIN users u ON LOWER(u.email) = LOWER(t.requester_email)
                     WHERE t.user_id IS NULL
                       AND t.requester_email IS NOT NULL AND t.requester_email <> ''
                       AND u.id IS NULL
                     GROUP BY t.requester_email");

        // Re-link any tickets that just had users created
        $upd2 = $conn->exec("UPDATE tickets t
                             JOIN users u ON LOWER(u.email) = LOWER(t.requester_email)
                             SET t.user_id = u.id
                             WHERE t.user_id IS NULL AND t.requester_email IS NOT NULL AND t.requester_email <> ''");

        $totalLinked = (int)$upd1 + (int)$upd2;
        if ($totalLinked > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'migrated', 'details' => ["Backfilled user_id for $totalLinked ticket(s) from requester_email/requester_name"]];
        }
    }

    // Add foreign keys + indexes for new ticket columns (only if missing)
    if ($tableExists('tickets')) {
        $alters = [
            ['ix_tickets_status_id',          "ALTER TABLE tickets ADD KEY ix_tickets_status_id (status_id)",                                       'index'],
            ['ix_tickets_priority_id',        "ALTER TABLE tickets ADD KEY ix_tickets_priority_id (priority_id)",                                   'index'],
            ['ix_tickets_assigned_analyst_id',"ALTER TABLE tickets ADD KEY ix_tickets_assigned_analyst_id (assigned_analyst_id)",                   'index'],
            ['ix_tickets_department_id',      "ALTER TABLE tickets ADD KEY ix_tickets_department_id (department_id)",                               'index'],
            ['ix_tickets_created_datetime',   "ALTER TABLE tickets ADD KEY ix_tickets_created_datetime (created_datetime)",                         'index'],
            ['fk_tickets_status',             "ALTER TABLE tickets ADD CONSTRAINT fk_tickets_status FOREIGN KEY (status_id) REFERENCES ticket_statuses (id)",     'fk'],
            ['fk_tickets_priority',           "ALTER TABLE tickets ADD CONSTRAINT fk_tickets_priority FOREIGN KEY (priority_id) REFERENCES ticket_priorities (id)", 'fk'],
        ];
        foreach ($alters as [$name, $sql, $kind]) {
            $present = $kind === 'fk' ? $fkExists('tickets', $name) : $idxExists('tickets', $name);
            if (!$present) {
                try { $conn->exec($sql); } catch (Exception $e) { /* may already exist under another name */ }
            }
        }
    }

    // Drop legacy ticket columns once backfill is complete (no NULLs remain)
    if ($tableExists('tickets')) {
        $orphanStatus    = $colExists('tickets', 'status')           ? (int) $conn->query("SELECT COUNT(*) FROM tickets WHERE status_id IS NULL")->fetchColumn() : 0;
        $orphanPriority  = $colExists('tickets', 'priority')         ? (int) $conn->query("SELECT COUNT(*) FROM tickets WHERE priority_id IS NULL")->fetchColumn() : 0;
        $orphanRequester = $colExists('tickets', 'requester_email')  ? (int) $conn->query("SELECT COUNT(*) FROM tickets WHERE user_id IS NULL AND requester_email IS NOT NULL AND requester_email <> ''")->fetchColumn() : 0;

        $dropped = [];
        if ($colExists('tickets', 'status') && $orphanStatus === 0) {
            try { $conn->exec("ALTER TABLE tickets DROP COLUMN `status`"); $dropped[] = 'status'; } catch (Exception $e) { $tableResult['details'][] = 'Drop status failed: '.$e->getMessage(); }
        }
        if ($colExists('tickets', 'priority') && $orphanPriority === 0) {
            try { $conn->exec("ALTER TABLE tickets DROP COLUMN `priority`"); $dropped[] = 'priority'; } catch (Exception $e) {}
        }
        if ($colExists('tickets', 'requester_email') && $orphanRequester === 0) {
            try { $conn->exec("ALTER TABLE tickets DROP COLUMN `requester_email`"); $dropped[] = 'requester_email'; } catch (Exception $e) {}
        }
        if ($colExists('tickets', 'requester_name')) {
            // requester_name is safe to drop whenever requester_email was (or never existed)
            $stillNeed = $colExists('tickets', 'requester_email') ? true : false;
            if (!$stillNeed) {
                try { $conn->exec("ALTER TABLE tickets DROP COLUMN `requester_name`"); $dropped[] = 'requester_name'; } catch (Exception $e) {}
            }
        }
        if (count($dropped) > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'updated', 'details' => ['Dropped legacy columns: '.implode(', ', $dropped)]];
        }
        $stillOrphan = [];
        if ($orphanStatus > 0)    $stillOrphan[] = "status ($orphanStatus rows)";
        if ($orphanPriority > 0)  $stillOrphan[] = "priority ($orphanPriority rows)";
        if ($orphanRequester > 0) $stillOrphan[] = "requester ($orphanRequester rows)";
        if (count($stillOrphan) > 0) {
            $results[] = ['table' => 'tickets', 'status' => 'pending', 'details' => ['Cannot drop legacy columns yet — orphans remain: '.implode(', ', $stillOrphan)]];
        }
    }

    // ----------------------------------------------------------------------
    // Rota locations: lookup table, backfill, drop legacy column
    // ----------------------------------------------------------------------

    // Seed default rota locations if table is empty
    if ($tableExists('rota_locations')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM rota_locations")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO rota_locations (name, colour, is_default, display_order) VALUES
                ('Office', '#1a73e8', 1, 10),
                ('WFH',    '#1e8e3e', 0, 20)");
            $results[] = ['table' => 'rota_locations', 'status' => 'seeded', 'details' => ['Inserted 2 default rota locations']];
        }
    }

    // Backfill ticket_rota_entries.location_id from legacy location string
    if ($tableExists('ticket_rota_entries') && $colExists('ticket_rota_entries', 'location') && $colExists('ticket_rota_entries', 'location_id')) {
        // Map legacy slugs to canonical names. Anything else gets inserted as a new location row.
        $conn->exec("INSERT IGNORE INTO rota_locations (name, display_order)
                     SELECT DISTINCT
                         CASE
                             WHEN LOWER(e.location) = 'office' THEN 'Office'
                             WHEN LOWER(e.location) = 'wfh'    THEN 'WFH'
                             ELSE e.location
                         END AS name,
                         999
                     FROM ticket_rota_entries e
                     LEFT JOIN rota_locations l ON LOWER(l.name) = LOWER(
                         CASE
                             WHEN LOWER(e.location) = 'office' THEN 'Office'
                             WHEN LOWER(e.location) = 'wfh'    THEN 'WFH'
                             ELSE e.location
                         END)
                     WHERE e.location IS NOT NULL AND e.location <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE ticket_rota_entries e
                            JOIN rota_locations l ON LOWER(l.name) = LOWER(
                                CASE
                                    WHEN LOWER(e.location) = 'office' THEN 'Office'
                                    WHEN LOWER(e.location) = 'wfh'    THEN 'WFH'
                                    ELSE e.location
                                END)
                            SET e.location_id = l.id
                            WHERE e.location_id IS NULL AND e.location IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'ticket_rota_entries', 'status' => 'migrated', 'details' => ["Backfilled location_id for $upd rota entry/entries"]];
        }

        // Default any still-null rows to the default location
        $conn->exec("UPDATE ticket_rota_entries SET location_id = (SELECT id FROM rota_locations WHERE is_default = 1 LIMIT 1) WHERE location_id IS NULL");
    }

    // Add FK + index for location_id if missing
    if ($tableExists('ticket_rota_entries') && $colExists('ticket_rota_entries', 'location_id')) {
        if (!$idxExists('ticket_rota_entries', 'ix_rota_entries_location_id')) {
            try { $conn->exec("ALTER TABLE ticket_rota_entries ADD KEY ix_rota_entries_location_id (location_id)"); } catch (Exception $e) {}
        }
        if (!$fkExists('ticket_rota_entries', 'fk_rota_location') && $tableExists('rota_locations')) {
            try { $conn->exec("ALTER TABLE ticket_rota_entries ADD CONSTRAINT fk_rota_location FOREIGN KEY (location_id) REFERENCES rota_locations (id)"); } catch (Exception $e) {}
        }
    }

    // Drop legacy location column once everything has a location_id
    if ($tableExists('ticket_rota_entries') && $colExists('ticket_rota_entries', 'location')) {
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM ticket_rota_entries WHERE location_id IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE ticket_rota_entries DROP COLUMN `location`");
                $results[] = ['table' => 'ticket_rota_entries', 'status' => 'updated', 'details' => ['Dropped legacy location column']];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => 'ticket_rota_entries', 'status' => 'pending', 'details' => ["Cannot drop legacy location column yet — $orphan row(s) still missing location_id"]];
        }
    }

    // ----------------------------------------------------------------------
    // Change Management: lookups for type / status / priority / impact
    // ----------------------------------------------------------------------

    // Seed default change types
    if ($tableExists('change_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_types (name, colour, is_default, display_order) VALUES
                ('Standard',  '#16a34a', 0, 10),
                ('Normal',    '#2563eb', 1, 20),
                ('Emergency', '#dc2626', 0, 30)");
            $results[] = ['table' => 'change_types', 'status' => 'seeded', 'details' => ['Inserted 3 default change types']];
        }
    }

    // Seed default change statuses
    if ($tableExists('change_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('Draft',            0, '#9e9e9e', 1, 10),
                ('Submitted',        0, '#2563eb', 0, 20),
                ('Pending Approval', 0, '#e65100', 0, 30),
                ('Approved',         0, '#2e7d32', 0, 40),
                ('Rejected',         1, '#c62828', 0, 50),
                ('Scheduled',        0, '#9333ea', 0, 60),
                ('In Progress',      0, '#1565c0', 0, 70),
                ('Completed',        1, '#1b5e20', 0, 80),
                ('Failed',           1, '#c62828', 0, 90),
                ('Cancelled',        1, '#bdbdbd', 0, 100)");
            $results[] = ['table' => 'change_statuses', 'status' => 'seeded', 'details' => ['Inserted 10 default change statuses']];
        }
    }

    // Seed default change priorities
    if ($tableExists('change_priorities')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_priorities")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_priorities (name, colour, is_default, display_order) VALUES
                ('Low',      '#16a34a', 0, 10),
                ('Medium',   '#2563eb', 1, 20),
                ('High',     '#f59e0b', 0, 30),
                ('Critical', '#dc2626', 0, 40)");
            $results[] = ['table' => 'change_priorities', 'status' => 'seeded', 'details' => ['Inserted 4 default change priorities']];
        }
    }

    // Seed default problem statuses (the Problem Management lifecycle).
    if ($tableExists('problem_statuses')) {
        if ((int) $conn->query("SELECT COUNT(*) FROM problem_statuses")->fetchColumn() === 0) {
            $conn->exec("INSERT INTO problem_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('New',                   0, '#2563eb', 1, 10),
                ('Investigating',         0, '#1565c0', 0, 20),
                ('Root Cause Identified', 0, '#9333ea', 0, 30),
                ('Known Error',           0, '#e65100', 0, 40),
                ('Resolved',              1, '#1b5e20', 0, 50),
                ('Closed',                1, '#607d8b', 0, 60)");
            $results[] = ['table' => 'problem_statuses', 'status' => 'seeded', 'details' => ['Inserted 6 default problem statuses']];
        }
    }

    // Seed default problem priorities.
    if ($tableExists('problem_priorities')) {
        if ((int) $conn->query("SELECT COUNT(*) FROM problem_priorities")->fetchColumn() === 0) {
            $conn->exec("INSERT INTO problem_priorities (name, colour, is_default, display_order) VALUES
                ('Low',      '#16a34a', 0, 10),
                ('Medium',   '#2563eb', 1, 20),
                ('High',     '#f59e0b', 0, 30),
                ('Critical', '#dc2626', 0, 40)");
            $results[] = ['table' => 'problem_priorities', 'status' => 'seeded', 'details' => ['Inserted 4 default problem priorities']];
        }
    }

    // Seed default morning check statuses. Matches the three hardcoded
    // statuses (Green / Amber / Red) that the dashboard used before the
    // statuses became configurable, so existing historical results
    // continue to render with the right colour and the dashboard keeps
    // working out of the box.
    if ($tableExists('morningChecks_Statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM morningChecks_Statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO morningChecks_Statuses (Label, Colour, RequiresNotes, SortOrder, IsActive) VALUES
                ('Green', '#28a745', 0, 10, 1),
                ('Amber', '#ffc107', 1, 20, 1),
                ('Red',   '#dc3545', 1, 30, 1)");
            $results[] = ['table' => 'morningChecks_Statuses', 'status' => 'seeded', 'details' => ['Inserted 3 default morning-check statuses (Green / Amber / Red)']];
        }
    }

    // Normalise morningChecks_Results — switch from label-string-only to a
    // proper StatusID FK while keeping the Status column as a label
    // snapshot for orphans. Idempotent: each step is guarded so re-runs
    // are no-ops.
    if ($tableExists('morningChecks_Results') && $tableExists('morningChecks_Statuses')) {
        // Step 1: relax morningChecks_Results.Status to NULL (was
        // VARCHAR(50) NOT NULL). New normalised writes set Status = NULL.
        try {
            $col = $conn->prepare(
                "SELECT IS_NULLABLE FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = 'morningChecks_Results' AND column_name = 'Status'"
            );
            $col->execute([$dbName]);
            $row = $col->fetch(PDO::FETCH_ASSOC);
            if ($row && strtoupper($row['IS_NULLABLE']) === 'NO') {
                $conn->exec("ALTER TABLE `morningChecks_Results` MODIFY `Status` VARCHAR(50) NULL");
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'updated', 'details' => ['Status: NOT NULL → NULL (StatusID is now the source of truth)']];
            }
        } catch (Exception $e) { /* shrug */ }

        // Step 2: backfill StatusID from the Status label where it
        // matches a row in morningChecks_Statuses. Targets rows where
        // StatusID is NULL — newly migrated rows OR newly added column
        // (the schema loop above just created it).
        try {
            $stmt = $conn->exec(
                "UPDATE morningChecks_Results r
                 JOIN morningChecks_Statuses s ON s.Label = r.Status
                 SET r.StatusID = s.StatusID
                 WHERE r.StatusID IS NULL"
            );
            if ($stmt > 0) {
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'migrated', 'details' => ["Backfilled StatusID for $stmt result row(s) from existing label strings"]];
            }
        } catch (Exception $e) { /* shrug */ }

        // Step 3: add the FK. ON DELETE SET NULL preserves the result
        // row (and its label snapshot in Status) when a status is
        // deleted — the dashboard banner + normalisation tool then
        // surfaces the orphan to the admin.
        $hasFk = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = 'morningChecks_Results'
               AND constraint_name = 'fk_results_status' AND constraint_type = 'FOREIGN KEY'"
        );
        $hasFk->execute([$dbName]);
        if ((int)$hasFk->fetchColumn() === 0) {
            try {
                $conn->exec(
                    "ALTER TABLE morningChecks_Results
                     ADD CONSTRAINT fk_results_status
                     FOREIGN KEY (StatusID) REFERENCES morningChecks_Statuses (StatusID)
                     ON DELETE SET NULL"
                );
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'updated', 'details' => ['Added fk_results_status (StatusID → morningChecks_Statuses.StatusID, ON DELETE SET NULL)']];
            } catch (Exception $e) { /* shrug — possibly mismatched engine */ }
        }

        // Step 4: the check FK (fk_results_checks in freeitsm.sql) was never
        // backfilled here, so installs grown via Database Verification could
        // hold results pointing at deleted checks. Remove any such orphans
        // (a result without its check is meaningless — the UI deletes a
        // check's results with the check), then add the constraint.
        $hasCheckFk = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = ? AND table_name = 'morningChecks_Results'
               AND constraint_name = 'fk_results_checks' AND constraint_type = 'FOREIGN KEY'"
        );
        $hasCheckFk->execute([$dbName]);
        if ((int)$hasCheckFk->fetchColumn() === 0 && $tableExists('morningChecks_Checks')) {
            try {
                $orphans = $conn->exec(
                    "DELETE r FROM morningChecks_Results r
                     LEFT JOIN morningChecks_Checks c ON c.CheckID = r.CheckID
                     WHERE c.CheckID IS NULL"
                );
                $conn->exec(
                    "ALTER TABLE morningChecks_Results
                     ADD CONSTRAINT fk_results_checks
                     FOREIGN KEY (CheckID) REFERENCES morningChecks_Checks (CheckID)"
                );
                $details = ['Added fk_results_checks (CheckID → morningChecks_Checks.CheckID)'];
                if ($orphans > 0) {
                    $details[] = "Removed $orphans orphaned result row(s) whose check no longer exists";
                }
                $results[] = ['table' => 'morningChecks_Results', 'status' => 'updated', 'details' => $details];
            } catch (Exception $e) { /* shrug — possibly mismatched engine */ }
        }
    }

    // Seed default change impacts
    if ($tableExists('change_impacts')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_impacts")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_impacts (name, colour, is_default, display_order) VALUES
                ('Low',    '#16a34a', 0, 10),
                ('Medium', '#2563eb', 1, 20),
                ('High',   '#f59e0b', 0, 30)");
            $results[] = ['table' => 'change_impacts', 'status' => 'seeded', 'details' => ['Inserted 3 default change impacts']];
        }
    }

    // Seed default change form sections + per-field layout.
    // The sections + initial field-to-section assignment mirror what was
    // previously hardcoded in change-management/settings/index.php's
    // FIELD_SECTIONS const. Field visibility is migrated from the old
    // system_settings.field_visibility JSON blob if present (so admins
    // who had toggled fields off don't lose their setting).
    if ($tableExists('change_field_sections') && $tableExists('change_field_layout')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM change_field_sections")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO change_field_sections (id, name, display_order) VALUES
                (1, 'General information', 10),
                (2, 'People',              20),
                (3, 'Schedule',            30),
                (4, 'Details',             40),
                (5, 'Attachments',         50)");
            // Field catalogue mirrors api/change-management/get_field_layout.php
            // — single source of truth lives there. We seed in this order.
            $defaultLayout = [
                // section_id, field_key, display_order
                [1, 'title',        10],
                [1, 'change_type',  20],
                [1, 'status',       30],
                [1, 'priority',     40],
                [1, 'impact',       50],
                [1, 'category',     60],
                [2, 'requester',    10],
                [2, 'assigned_to',  20],
                [2, 'approver',     30],
                [2, 'cab',          40],
                [3, 'work_start',   10],
                [3, 'work_end',     20],
                [3, 'outage_start', 30],
                [3, 'outage_end',   40],
                [4, 'description',  10],
                [4, 'reason',       20],
                [4, 'risk',         30],
                [4, 'testplan',     40],
                [4, 'rollback',     50],
                [4, 'pir',          60],
                [5, 'attachments',  10],
            ];
            // Pull any pre-existing visibility from system_settings so we keep
            // the admin's earlier toggles. Best-effort — silent fallback if
            // the row / column doesn't exist on this install.
            $visMap = [];
            try {
                $stmt = $conn->query("SELECT settings_json FROM system_settings WHERE id = 1");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($row && !empty($row['settings_json'])) {
                    $decoded = json_decode($row['settings_json'], true);
                    if (is_array($decoded) && isset($decoded['field_visibility']) && is_array($decoded['field_visibility'])) {
                        $visMap = $decoded['field_visibility'];
                    }
                }
            } catch (Exception $e) {
                // Fine — no previous layout to migrate
            }
            $insertStmt = $conn->prepare(
                "INSERT INTO change_field_layout (field_key, section_id, display_order, is_visible) VALUES (?, ?, ?, ?)"
            );
            foreach ($defaultLayout as [$sectionId, $fieldKey, $order]) {
                $isVisible = array_key_exists($fieldKey, $visMap) ? ($visMap[$fieldKey] ? 1 : 0) : 1;
                $insertStmt->execute([$fieldKey, $sectionId, $order, $isVisible]);
            }
            $results[] = [
                'table' => 'change_field_sections',
                'status' => 'seeded',
                'details' => ['Inserted 5 default sections + ' . count($defaultLayout) . ' field placements (visibility migrated from system_settings.field_visibility where present)']
            ];
        }
    }

    // Backfill changes.{change_type_id, status_id, priority_id, impact_id} and change_templates equivalents
    $changeBackfills = [
        ['changes',           'change_type', 'change_type_id', 'change_types'],
        ['changes',           'status',      'status_id',      'change_statuses'],
        ['changes',           'priority',    'priority_id',    'change_priorities'],
        ['changes',           'impact',      'impact_id',      'change_impacts'],
        ['change_templates',  'change_type', 'change_type_id', 'change_types'],
        ['change_templates',  'priority',    'priority_id',    'change_priorities'],
        ['change_templates',  'impact',      'impact_id',      'change_impacts'],
    ];
    foreach ($changeBackfills as [$tbl, $oldCol, $newCol, $lkTbl]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol) || !$colExists($tbl, $newCol) || !$tableExists($lkTbl)) continue;

        // Insert any unrecognised values into the lookup so the FK can be satisfied
        $conn->exec("INSERT IGNORE INTO `$lkTbl` (name, display_order)
                     SELECT DISTINCT t.`$oldCol`, 999
                     FROM `$tbl` t
                     LEFT JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                     WHERE t.`$oldCol` IS NOT NULL AND t.`$oldCol` <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE `$tbl` t
                            JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                            SET t.`$newCol` = l.id
                            WHERE t.`$newCol` IS NULL AND t.`$oldCol` IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => $tbl, 'status' => 'migrated', 'details' => ["Backfilled $newCol for $upd row(s) from legacy $oldCol"]];
        }

        // Default any still-null rows to the configured default lookup row
        $conn->exec("UPDATE `$tbl` SET `$newCol` = (SELECT id FROM `$lkTbl` WHERE is_default = 1 LIMIT 1) WHERE `$newCol` IS NULL");
    }

    // Add FKs + indexes for new change columns if missing
    $changeFks = [
        ['changes',          'fk_changes_status',      "ALTER TABLE changes ADD CONSTRAINT fk_changes_status FOREIGN KEY (status_id) REFERENCES change_statuses (id)"],
        ['changes',          'fk_changes_priority',    "ALTER TABLE changes ADD CONSTRAINT fk_changes_priority FOREIGN KEY (priority_id) REFERENCES change_priorities (id)"],
        ['changes',          'fk_changes_change_type', "ALTER TABLE changes ADD CONSTRAINT fk_changes_change_type FOREIGN KEY (change_type_id) REFERENCES change_types (id)"],
        ['changes',          'fk_changes_impact',      "ALTER TABLE changes ADD CONSTRAINT fk_changes_impact FOREIGN KEY (impact_id) REFERENCES change_impacts (id)"],
        ['changes',          'fk_changes_tenant',      "ALTER TABLE changes ADD CONSTRAINT fk_changes_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"],
        ['change_templates', 'fk_template_change_type',"ALTER TABLE change_templates ADD CONSTRAINT fk_template_change_type FOREIGN KEY (change_type_id) REFERENCES change_types (id)"],
        ['change_templates', 'fk_template_priority',   "ALTER TABLE change_templates ADD CONSTRAINT fk_template_priority FOREIGN KEY (priority_id) REFERENCES change_priorities (id)"],
        ['change_templates', 'fk_template_impact',     "ALTER TABLE change_templates ADD CONSTRAINT fk_template_impact FOREIGN KEY (impact_id) REFERENCES change_impacts (id)"],
        ['change_field_layout', 'fk_cfl_section',      "ALTER TABLE change_field_layout ADD CONSTRAINT fk_cfl_section FOREIGN KEY (section_id) REFERENCES change_field_sections (id) ON DELETE CASCADE"],
    ];
    foreach ($changeFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }
    $changeIndexes = [
        ['changes', 'ix_changes_status_id',      'status_id'],
        ['changes', 'ix_changes_priority_id',    'priority_id'],
        ['changes', 'ix_changes_change_type_id', 'change_type_id'],
        ['changes', 'ix_changes_impact_id',      'impact_id'],
        ['changes', 'ix_changes_tenant_id',      'tenant_id'],
    ];
    foreach ($changeIndexes as [$tbl, $name, $col]) {
        if (!$tableExists($tbl) || $idxExists($tbl, $name)) continue;
        try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
    }

    // Problem Management foreign keys + indexes.
    $problemFks = [
        ['problems',        'fk_problems_status',   "ALTER TABLE problems ADD CONSTRAINT fk_problems_status FOREIGN KEY (status_id) REFERENCES problem_statuses (id)"],
        ['problems',        'fk_problems_priority', "ALTER TABLE problems ADD CONSTRAINT fk_problems_priority FOREIGN KEY (priority_id) REFERENCES problem_priorities (id)"],
        ['problems',        'fk_problems_tenant',   "ALTER TABLE problems ADD CONSTRAINT fk_problems_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL"],
        ['problem_tickets', 'fk_ptickets_problem',  "ALTER TABLE problem_tickets ADD CONSTRAINT fk_ptickets_problem FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE"],
        ['problem_tickets', 'fk_ptickets_ticket',   "ALTER TABLE problem_tickets ADD CONSTRAINT fk_ptickets_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['change_tickets',  'fk_ctickets_change',   "ALTER TABLE change_tickets ADD CONSTRAINT fk_ctickets_change FOREIGN KEY (change_id) REFERENCES changes (id) ON DELETE CASCADE"],
        ['change_tickets',  'fk_ctickets_ticket',   "ALTER TABLE change_tickets ADD CONSTRAINT fk_ctickets_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_links',    'fk_ticket_links_source', "ALTER TABLE ticket_links ADD CONSTRAINT fk_ticket_links_source FOREIGN KEY (source_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_links',    'fk_ticket_links_target', "ALTER TABLE ticket_links ADD CONSTRAINT fk_ticket_links_target FOREIGN KEY (target_ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['problem_audit',   'fk_paudit_problem',    "ALTER TABLE problem_audit ADD CONSTRAINT fk_paudit_problem FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE"],
        ['problem_notes',   'fk_pnotes_problem',    "ALTER TABLE problem_notes ADD CONSTRAINT fk_pnotes_problem FOREIGN KEY (problem_id) REFERENCES problems (id) ON DELETE CASCADE"],
    ];
    foreach ($problemFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }
    $problemIndexes = [
        ['problems', 'ix_problems_status_id',  'status_id'],
        ['problems', 'ix_problems_tenant_id',  'tenant_id'],
        ['problem_tickets', 'ix_ptickets_ticket', 'ticket_id'],
        ['change_tickets',  'ix_ctickets_ticket', 'ticket_id'],
        ['ticket_links',    'ix_ticket_links_target', 'target_ticket_id'],
        ['problem_notes', 'ix_pnotes_problem', 'problem_id'],
    ];
    foreach ($problemIndexes as [$tbl, $name, $col]) {
        if (!$tableExists($tbl) || $idxExists($tbl, $name)) continue;
        try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
    }

    // SSO / OIDC foreign keys (db_verify $schema only builds columns + PK; FKs added here)
    $ssoFks = [
        ['analyst_sso_identities', 'fk_sso_identity_analyst',  "ALTER TABLE analyst_sso_identities ADD CONSTRAINT fk_sso_identity_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id) ON DELETE CASCADE"],
        ['analyst_sso_identities', 'fk_sso_identity_provider', "ALTER TABLE analyst_sso_identities ADD CONSTRAINT fk_sso_identity_provider FOREIGN KEY (provider_id) REFERENCES auth_providers (id) ON DELETE CASCADE"],
        ['analysts',               'fk_analysts_auth_provider', "ALTER TABLE analysts ADD CONSTRAINT fk_analysts_auth_provider FOREIGN KEY (auth_provider_id) REFERENCES auth_providers (id) ON DELETE SET NULL"],
        // Self-service requester SSO (mirror of the analyst tables, one layer down).
        ['user_sso_identities',    'fk_user_sso_identity_user',     "ALTER TABLE user_sso_identities ADD CONSTRAINT fk_user_sso_identity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE"],
        ['user_sso_identities',    'fk_user_sso_identity_provider', "ALTER TABLE user_sso_identities ADD CONSTRAINT fk_user_sso_identity_provider FOREIGN KEY (provider_id) REFERENCES auth_providers (id) ON DELETE CASCADE"],
        ['users',                  'fk_users_auth_provider',        "ALTER TABLE users ADD CONSTRAINT fk_users_auth_provider FOREIGN KEY (auth_provider_id) REFERENCES auth_providers (id) ON DELETE SET NULL"],
        // Multi-tenant portal SSO: a provider can be owned by a client company.
        ['auth_providers',         'fk_auth_providers_tenant',      "ALTER TABLE auth_providers ADD CONSTRAINT fk_auth_providers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE"],
    ];
    foreach ($ssoFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Knowledge foreign keys (db_verify $schema only builds columns + PK; a
    // grown install was missing all of these, which orphaned tag links on
    // hard delete). Names + delete rules match freeitsm.sql. The article-tags
    // FKs won't add while orphaned junction rows exist (MySQL refuses); the
    // endpoints now clean children explicitly, so orphans stop accumulating.
    $knowledgeFks = [
        ['knowledge_articles',         'fk_knowledge_articles_author',      "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_author FOREIGN KEY (author_id) REFERENCES analysts (id)"],
        ['knowledge_articles',         'fk_knowledge_articles_owner',       "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_owner FOREIGN KEY (owner_id) REFERENCES analysts (id)"],
        ['knowledge_articles',         'fk_knowledge_articles_archived_by', "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_archived_by FOREIGN KEY (archived_by_id) REFERENCES analysts (id)"],
        // Deliberately NOT "ON DELETE SET NULL" (which fk_assets_tenant uses): for
        // knowledge, NULL means "shared with every company", so SET NULL would turn a
        // deleted company's private articles into everyone's. Restrict instead.
        ['knowledge_articles',         'fk_knowledge_articles_tenant',      "ALTER TABLE knowledge_articles ADD CONSTRAINT fk_knowledge_articles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)"],
        ['knowledge_article_versions', 'fk_kav_article',                    "ALTER TABLE knowledge_article_versions ADD CONSTRAINT fk_kav_article FOREIGN KEY (article_id) REFERENCES knowledge_articles (id)"],
        ['knowledge_article_versions', 'fk_kav_saved_by',                   "ALTER TABLE knowledge_article_versions ADD CONSTRAINT fk_kav_saved_by FOREIGN KEY (saved_by_id) REFERENCES analysts (id)"],
        ['knowledge_article_tags',     'fk_article_tags_article',           "ALTER TABLE knowledge_article_tags ADD CONSTRAINT fk_article_tags_article FOREIGN KEY (article_id) REFERENCES knowledge_articles (id) ON DELETE CASCADE"],
        ['knowledge_article_tags',     'fk_article_tags_tag',               "ALTER TABLE knowledge_article_tags ADD CONSTRAINT fk_article_tags_tag FOREIGN KEY (tag_id) REFERENCES knowledge_tags (id) ON DELETE CASCADE"],
    ];
    foreach ($knowledgeFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // CMDB foreign keys (db_verify $schema only builds columns + PK; grown
    // installs had NONE of these, so the module's cascade-delete design
    // silently didn't apply there). Names + delete rules match freeitsm.sql.
    $cmdbFks = [
        ['cmdb_classes',                'fk_cmdb_classes_icon',    "ALTER TABLE cmdb_classes ADD CONSTRAINT fk_cmdb_classes_icon FOREIGN KEY (icon_id) REFERENCES cmdb_icons (id) ON DELETE SET NULL"],
        ['cmdb_class_properties',       'fk_cmdb_cp_class',        "ALTER TABLE cmdb_class_properties ADD CONSTRAINT fk_cmdb_cp_class FOREIGN KEY (class_id) REFERENCES cmdb_classes (id) ON DELETE CASCADE"],
        ['cmdb_class_properties',       'fk_cmdb_cp_target_class', "ALTER TABLE cmdb_class_properties ADD CONSTRAINT fk_cmdb_cp_target_class FOREIGN KEY (target_class_id) REFERENCES cmdb_classes (id)"],
        ['cmdb_class_property_options', 'fk_cmdb_cpo_property',    "ALTER TABLE cmdb_class_property_options ADD CONSTRAINT fk_cmdb_cpo_property FOREIGN KEY (property_id) REFERENCES cmdb_class_properties (id) ON DELETE CASCADE"],
        ['cmdb_objects',                'fk_cmdb_objects_class',   "ALTER TABLE cmdb_objects ADD CONSTRAINT fk_cmdb_objects_class FOREIGN KEY (class_id) REFERENCES cmdb_classes (id)"],
        ['cmdb_objects',                'fk_cmdb_objects_parent',  "ALTER TABLE cmdb_objects ADD CONSTRAINT fk_cmdb_objects_parent FOREIGN KEY (parent_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_properties',      'fk_cmdb_op_object',       "ALTER TABLE cmdb_object_properties ADD CONSTRAINT fk_cmdb_op_object FOREIGN KEY (object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_properties',      'fk_cmdb_op_property',     "ALTER TABLE cmdb_object_properties ADD CONSTRAINT fk_cmdb_op_property FOREIGN KEY (property_id) REFERENCES cmdb_class_properties (id) ON DELETE CASCADE"],
        ['cmdb_object_properties',      'fk_cmdb_op_value_object', "ALTER TABLE cmdb_object_properties ADD CONSTRAINT fk_cmdb_op_value_object FOREIGN KEY (value_object_id) REFERENCES cmdb_objects (id) ON DELETE SET NULL"],
        ['cmdb_object_relationships',   'fk_cmdb_or_from',         "ALTER TABLE cmdb_object_relationships ADD CONSTRAINT fk_cmdb_or_from FOREIGN KEY (from_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_relationships',   'fk_cmdb_or_to',           "ALTER TABLE cmdb_object_relationships ADD CONSTRAINT fk_cmdb_or_to FOREIGN KEY (to_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['cmdb_object_relationships',   'fk_cmdb_or_type',         "ALTER TABLE cmdb_object_relationships ADD CONSTRAINT fk_cmdb_or_type FOREIGN KEY (relationship_type_id) REFERENCES cmdb_relationship_types (id)"],
        ['ticket_cmdb_objects',         'fk_tco_ticket',           "ALTER TABLE ticket_cmdb_objects ADD CONSTRAINT fk_tco_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_cmdb_objects',         'fk_tco_cmdb_object',      "ALTER TABLE ticket_cmdb_objects ADD CONSTRAINT fk_tco_cmdb_object FOREIGN KEY (cmdb_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['ticket_cmdb_objects',         'fk_tco_analyst',          "ALTER TABLE ticket_cmdb_objects ADD CONSTRAINT fk_tco_analyst FOREIGN KEY (created_by_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        // Attachment text (#53). CASCADE: the extracted text is meaningless once
        // the attachment it came from has gone.
        ['attachment_text',             'fk_attachment_text_attachment', "ALTER TABLE attachment_text ADD CONSTRAINT fk_attachment_text_attachment FOREIGN KEY (attachment_id) REFERENCES email_attachments (id) ON DELETE CASCADE"],
        // Tickets ↔ assets (#57)
        ['table_views',                 'fk_tv_owner',             "ALTER TABLE table_views ADD CONSTRAINT fk_tv_owner FOREIGN KEY (owner_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['table_views',                 'fk_tv_team',              "ALTER TABLE table_views ADD CONSTRAINT fk_tv_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL"],
        ['contract_assets',             'fk_ca_contract',          "ALTER TABLE contract_assets ADD CONSTRAINT fk_ca_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE"],
        ['contract_assets',             'fk_ca_asset',             "ALTER TABLE contract_assets ADD CONSTRAINT fk_ca_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE"],
        ['contract_assets',             'fk_ca_analyst',           "ALTER TABLE contract_assets ADD CONSTRAINT fk_ca_analyst FOREIGN KEY (linked_by_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['ticket_assets',               'fk_ta_ticket',            "ALTER TABLE ticket_assets ADD CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
        ['ticket_assets',               'fk_ta_asset',             "ALTER TABLE ticket_assets ADD CONSTRAINT fk_ta_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE"],
        ['ticket_assets',               'fk_ta_analyst',           "ALTER TABLE ticket_assets ADD CONSTRAINT fk_ta_analyst FOREIGN KEY (created_by_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['ticket_assets',               'fk_ta_user',              "ALTER TABLE ticket_assets ADD CONSTRAINT fk_ta_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL"],
    ];
    foreach ($cmdbFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Software-module foreign keys (db_verify $schema only builds columns +
    // PK). Names + rules match freeitsm.sql; note the RESTRICT (no rule) FKs
    // deliberately block deleting an app while installs/licences reference it.
    $softwareFks = [
        ['software_inventory_detail',        'fk_software_detail_app',      "ALTER TABLE software_inventory_detail ADD CONSTRAINT fk_software_detail_app FOREIGN KEY (app_id) REFERENCES software_inventory_apps (id)"],
        ['software_licences',                'fk_software_licences_app',    "ALTER TABLE software_licences ADD CONSTRAINT fk_software_licences_app FOREIGN KEY (app_id) REFERENCES software_inventory_apps (id)"],
        ['software_licences',                'fk_software_licences_analyst', "ALTER TABLE software_licences ADD CONSTRAINT fk_software_licences_analyst FOREIGN KEY (created_by) REFERENCES analysts (id)"],
        ['software_dashboard_widgets',       'fk_sdw_app',                  "ALTER TABLE software_dashboard_widgets ADD CONSTRAINT fk_sdw_app FOREIGN KEY (app_id) REFERENCES software_inventory_apps (id) ON DELETE SET NULL"],
        ['analyst_software_dashboard_widgets', 'fk_asdw_analyst',           "ALTER TABLE analyst_software_dashboard_widgets ADD CONSTRAINT fk_asdw_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id)"],
        ['analyst_software_dashboard_widgets', 'fk_asdw_widget',            "ALTER TABLE analyst_software_dashboard_widgets ADD CONSTRAINT fk_asdw_widget FOREIGN KEY (widget_id) REFERENCES software_dashboard_widgets (id)"],
    ];
    foreach ($softwareFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Calendar foreign keys (db_verify $schema only builds columns + PK) —
    // names + rules match freeitsm.sql. The category FK has no delete rule
    // (RESTRICT), backstopping delete_category.php's in-use guard.
    $calendarFks = [
        ['calendar_events', 'fk_calendar_events_category', "ALTER TABLE calendar_events ADD CONSTRAINT fk_calendar_events_category FOREIGN KEY (category_id) REFERENCES calendar_categories (id)"],
        ['calendar_events', 'fk_calendar_events_contract', "ALTER TABLE calendar_events ADD CONSTRAINT fk_calendar_events_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE SET NULL"],
    ];
    foreach ($calendarFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Contracts-domain foreign keys (db_verify $schema only builds columns +
    // PK; this domain historically had NO FKs anywhere — not even in
    // freeitsm.sql — so contract deletes orphaned term values). Names + rules
    // match the constraints now in freeitsm.sql. Lookups use SET NULL to
    // preserve the existing delete-freely settings behaviour.
    $contractFks = [
        ['suppliers',            'fk_suppliers_type',             "ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_type FOREIGN KEY (supplier_type_id) REFERENCES supplier_types (id) ON DELETE SET NULL"],
        ['suppliers',            'fk_suppliers_status',           "ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_status FOREIGN KEY (supplier_status_id) REFERENCES supplier_statuses (id) ON DELETE SET NULL"],
        ['contacts',             'fk_contacts_supplier',          "ALTER TABLE contacts ADD CONSTRAINT fk_contacts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_supplier',         "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_owner',            "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_owner FOREIGN KEY (contract_owner_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_status',           "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_status FOREIGN KEY (contract_status_id) REFERENCES contract_statuses (id) ON DELETE SET NULL"],
        ['contracts',            'fk_contracts_payment_schedule', "ALTER TABLE contracts ADD CONSTRAINT fk_contracts_payment_schedule FOREIGN KEY (payment_schedule_id) REFERENCES payment_schedules (id) ON DELETE SET NULL"],
        ['contract_term_values', 'fk_ctv_contract',               "ALTER TABLE contract_term_values ADD CONSTRAINT fk_ctv_contract FOREIGN KEY (contract_id) REFERENCES contracts (id) ON DELETE CASCADE"],
        ['contract_term_values', 'fk_ctv_term_tab',               "ALTER TABLE contract_term_values ADD CONSTRAINT fk_ctv_term_tab FOREIGN KEY (term_tab_id) REFERENCES contract_term_tabs (id) ON DELETE CASCADE"],
    ];
    foreach ($contractFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Forms-module foreign keys (db_verify $schema only builds columns + PK;
    // grown installs had NONE of the four freeitsm.sql constraints, and
    // parent_form_id — the #442 version chain — never had one anywhere).
    // Orphans are cleaned first so the constraints can attach: fields /
    // submissions / data of deleted parents go, dangling version-chain
    // pointers become chain roots (SET NULL).
    if ($tableExists('forms')) {
        try {
            if ($tableExists('form_fields')) {
                $conn->exec("DELETE ff FROM form_fields ff LEFT JOIN forms f ON f.id = ff.form_id WHERE f.id IS NULL");
            }
            if ($tableExists('form_submission_data')) {
                $conn->exec("DELETE sd FROM form_submission_data sd LEFT JOIN form_submissions s ON s.id = sd.submission_id WHERE s.id IS NULL");
                $conn->exec("DELETE sd FROM form_submission_data sd LEFT JOIN form_fields ff ON ff.id = sd.field_id WHERE ff.id IS NULL");
            }
            if ($tableExists('form_submissions')) {
                $conn->exec("DELETE s FROM form_submissions s LEFT JOIN forms f ON f.id = s.form_id WHERE f.id IS NULL");
            }
            $conn->exec("UPDATE forms c LEFT JOIN forms p ON p.id = c.parent_form_id SET c.parent_form_id = NULL WHERE c.parent_form_id IS NOT NULL AND p.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    $formsFks = [
        ['forms',                'fk_forms_parent',              "ALTER TABLE forms ADD CONSTRAINT fk_forms_parent FOREIGN KEY (parent_form_id) REFERENCES forms (id)"],
        ['form_fields',          'fk_form_fields_form',          "ALTER TABLE form_fields ADD CONSTRAINT fk_form_fields_form FOREIGN KEY (form_id) REFERENCES forms (id) ON DELETE CASCADE"],
        ['form_submissions',     'fk_form_submissions_form',     "ALTER TABLE form_submissions ADD CONSTRAINT fk_form_submissions_form FOREIGN KEY (form_id) REFERENCES forms (id)"],
        ['form_submission_data', 'fk_submission_data_submission', "ALTER TABLE form_submission_data ADD CONSTRAINT fk_submission_data_submission FOREIGN KEY (submission_id) REFERENCES form_submissions (id) ON DELETE CASCADE"],
        ['form_submission_data', 'fk_submission_data_field',     "ALTER TABLE form_submission_data ADD CONSTRAINT fk_submission_data_field FOREIGN KEY (field_id) REFERENCES form_fields (id)"],
    ];
    foreach ($formsFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Workflow-module foreign keys (db_verify $schema only builds columns +
    // PK; these tables historically had NO FKs anywhere — deliberately, so
    // execution rows survive workflow deletion as an audit trail). The intent
    // is kept but made referentially sound: workflow_id becomes nullable with
    // ON DELETE SET NULL, and the workflow_name snapshot (backfilled from
    // still-live parents, stamped by the engine on every new run) keeps
    // orphaned runs attributable. Existing dangling workflow_ids are detached
    // (no rows deleted).
    if ($tableExists('workflow_executions')) {
        try {
            if ($tableExists('workflows')) {
                $conn->exec("UPDATE workflow_executions we JOIN workflows w ON w.id = we.workflow_id
                             SET we.workflow_name = w.name WHERE we.workflow_name IS NULL");
                $conn->exec("UPDATE workflow_executions we LEFT JOIN workflows w ON w.id = we.workflow_id
                             SET we.workflow_id = NULL WHERE we.workflow_id IS NOT NULL AND w.id IS NULL");
            }
            // Grown installs created workflow_id as NOT NULL — the SET NULL
            // FK can't attach until it's nullable ($schema only adds columns).
            $conn->exec("ALTER TABLE workflow_executions MODIFY workflow_id INT NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    if ($tableExists('workflows') && $tableExists('analysts')) {
        try {
            $conn->exec("UPDATE workflows w LEFT JOIN analysts a ON a.id = w.created_by
                         SET w.created_by = NULL WHERE w.created_by IS NOT NULL AND a.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    // webhook_deliveries.workflow_id can dangle if a workflow is deleted while a
    // delivery is queued; detach (SET NULL) before attaching the FK.
    if ($tableExists('webhook_deliveries') && $tableExists('workflows')) {
        try {
            $conn->exec("UPDATE webhook_deliveries wd LEFT JOIN workflows w ON w.id = wd.workflow_id
                         SET wd.workflow_id = NULL WHERE wd.workflow_id IS NOT NULL AND w.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    // response_snippet was originally TEXT (64KB). We now store the full endpoint
    // response for the Webhooks queue log, so widen it to MEDIUMTEXT (matching
    // request_body). MODIFY is a no-op if it's already MEDIUMTEXT.
    if ($tableExists('webhook_deliveries')) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM webhook_deliveries LIKE 'response_snippet'")->fetch(PDO::FETCH_ASSOC);
            if ($col && stripos($col['Type'], 'mediumtext') === false) {
                $conn->exec("ALTER TABLE webhook_deliveries MODIFY `response_snippet` MEDIUMTEXT NULL");
            }
        } catch (Exception $e) { /* shrug */ }
    }
    // Seed the built-in webhook message formats (Slack / Teams / Discord).
    // INSERT IGNORE on the unique format_key, so this is idempotent and never
    // clobbers an install's own rows. The engine carries the same three as a
    // hardcoded fallback, so webhooks work even before this runs.
    if ($tableExists('webhook_message_formats')) {
        try {
            require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
            $ins = $conn->prepare(
                "INSERT IGNORE INTO webhook_message_formats
                 (format_key, label, body_template, url_pattern, markdown_hint, is_builtin, display_order)
                 VALUES (?, ?, ?, ?, ?, 1, ?)"
            );
            $order = 10;
            $seeded = 0;
            foreach (WorkflowEngine::BUILTIN_WEBHOOK_FORMATS as $key => $f) {
                $ins->execute([$key, $f['label'], $f['body_template'], $f['url_pattern'], $f['markdown_hint'], $order]);
                $seeded += $ins->rowCount();
                $order += 10;
            }
            if ($seeded > 0) {
                $results[] = ['table' => 'webhook_message_formats', 'status' => 'seeded',
                              'details' => ["Inserted $seeded built-in webhook message format(s)"]];
            }
        } catch (Exception $e) { /* shrug — engine falls back to its built-ins */ }
    }

    // url was VARCHAR(1000). It is now ENCRYPTED at rest, and AES-256-GCM +
    // base64 inflates a string by ~1/3 + 28 bytes — so a max-length 1000-char
    // URL becomes ~1377 chars. At VARCHAR(1000) MySQL would silently TRUNCATE
    // the ciphertext, and a truncated ciphertext can never be decrypted again.
    // This widen MUST happen before anything is encrypted. No-op once widened.
    if ($tableExists('webhook_deliveries')) {
        try {
            $col = $conn->query("SHOW COLUMNS FROM webhook_deliveries LIKE 'url'")->fetch(PDO::FETCH_ASSOC);
            if ($col && !preg_match('/varchar\((\d+)\)/i', $col['Type'], $m0)) {
                // not a varchar at all — leave it alone
            } elseif ($col && isset($m0[1]) && (int)$m0[1] < 2000) {
                $conn->exec("ALTER TABLE webhook_deliveries MODIFY `url` VARCHAR(2000) NOT NULL");
                $results[] = ['table' => 'webhook_deliveries', 'status' => 'altered',
                              'details' => ['Widened url to VARCHAR(2000) — required headroom for encryption at rest']];
            }
        } catch (Exception $e) { /* shrug */ }
    }
    $workflowFks = [
        ['workflows',           'fk_workflows_created_by', "ALTER TABLE workflows ADD CONSTRAINT fk_workflows_created_by FOREIGN KEY (created_by) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['workflow_executions', 'fk_we_workflow',          "ALTER TABLE workflow_executions ADD CONSTRAINT fk_we_workflow FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE SET NULL"],
        ['webhook_deliveries',  'fk_wd_workflow',          "ALTER TABLE webhook_deliveries ADD CONSTRAINT fk_wd_workflow FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE SET NULL"],
    ];
    foreach ($workflowFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Network Mapper foreign keys (db_verify $schema only builds columns + PK;
    // freeitsm.sql has had 7 of these 8 since the module shipped but grown
    // installs got NONE — so delete_diagram.php's reliance on CASCADE orphaned
    // nodes/connectors there, and deleting a CMDB object left its diagram
    // nodes dangling). Orphans are cleaned first so the constraints attach:
    // nodes of dead diagrams/objects go (CASCADE semantics, matching fresh
    // installs), connectors of dead diagrams/nodes go, dangling provenance /
    // parent / author pointers become NULL (SET NULL semantics).
    if ($tableExists('network_diagrams')) {
        try {
            if ($tableExists('network_diagram_nodes')) {
                $conn->exec("DELETE n FROM network_diagram_nodes n LEFT JOIN network_diagrams d ON d.id = n.diagram_id WHERE d.id IS NULL");
                if ($tableExists('cmdb_objects')) {
                    $conn->exec("DELETE n FROM network_diagram_nodes n LEFT JOIN cmdb_objects o ON o.id = n.cmdb_object_id WHERE o.id IS NULL");
                }
            }
            if ($tableExists('network_diagram_connectors')) {
                $conn->exec("DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagrams d ON d.id = c.diagram_id WHERE d.id IS NULL");
                $conn->exec("DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagram_nodes n ON n.id = c.from_node_id WHERE n.id IS NULL");
                $conn->exec("DELETE c FROM network_diagram_connectors c LEFT JOIN network_diagram_nodes n ON n.id = c.to_node_id WHERE n.id IS NULL");
                if ($tableExists('cmdb_object_relationships')) {
                    $conn->exec("UPDATE network_diagram_connectors c LEFT JOIN cmdb_object_relationships r ON r.id = c.cmdb_relationship_id
                                 SET c.cmdb_relationship_id = NULL WHERE c.cmdb_relationship_id IS NOT NULL AND r.id IS NULL");
                }
            }
            $conn->exec("UPDATE network_diagrams d LEFT JOIN network_diagrams p ON p.id = d.parent_diagram_id
                         SET d.parent_diagram_id = NULL WHERE d.parent_diagram_id IS NOT NULL AND p.id IS NULL");
            $conn->exec("UPDATE network_diagrams d LEFT JOIN analysts a ON a.id = d.created_by_analyst_id
                         SET d.created_by_analyst_id = NULL WHERE d.created_by_analyst_id IS NOT NULL AND a.id IS NULL");
        } catch (Exception $e) { /* shrug */ }
    }
    $networkFks = [
        ['network_diagrams',           'fk_net_diag_parent', "ALTER TABLE network_diagrams ADD CONSTRAINT fk_net_diag_parent FOREIGN KEY (parent_diagram_id) REFERENCES network_diagrams (id) ON DELETE SET NULL"],
        ['network_diagrams',           'fk_net_diag_author', "ALTER TABLE network_diagrams ADD CONSTRAINT fk_net_diag_author FOREIGN KEY (created_by_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['network_diagram_nodes',      'fk_net_node_diag',   "ALTER TABLE network_diagram_nodes ADD CONSTRAINT fk_net_node_diag FOREIGN KEY (diagram_id) REFERENCES network_diagrams (id) ON DELETE CASCADE"],
        ['network_diagram_nodes',      'fk_net_node_cmdb',   "ALTER TABLE network_diagram_nodes ADD CONSTRAINT fk_net_node_cmdb FOREIGN KEY (cmdb_object_id) REFERENCES cmdb_objects (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_diag',   "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_diag FOREIGN KEY (diagram_id) REFERENCES network_diagrams (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_from',   "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_from FOREIGN KEY (from_node_id) REFERENCES network_diagram_nodes (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_to',     "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_to FOREIGN KEY (to_node_id) REFERENCES network_diagram_nodes (id) ON DELETE CASCADE"],
        ['network_diagram_connectors', 'fk_net_conn_rel',    "ALTER TABLE network_diagram_connectors ADD CONSTRAINT fk_net_conn_rel FOREIGN KEY (cmdb_relationship_id) REFERENCES cmdb_object_relationships (id) ON DELETE SET NULL"],
    ];
    foreach ($networkFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // REST API v1 key foreign keys (db_verify $schema only builds columns + PK)
    $apiKeyFks = [
        ['api_keys',            'fk_api_keys_analyst',        "ALTER TABLE api_keys ADD CONSTRAINT fk_api_keys_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id)"],
        ['api_keys',            'fk_api_keys_created_by',     "ALTER TABLE api_keys ADD CONSTRAINT fk_api_keys_created_by FOREIGN KEY (created_by) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['api_key_rate_limits', 'fk_api_key_rate_limits_key', "ALTER TABLE api_key_rate_limits ADD CONSTRAINT fk_api_key_rate_limits_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE CASCADE"],
    ];
    foreach ($apiKeyFks as [$tbl, $name, $sql]) {
        if (!$tableExists($tbl) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
    }

    // Ticket child foreign keys (db_verify $schema only builds columns + PK; FKs
    // added here so installs grown via db_verify match a fresh freeitsm.sql).
    // These have NO cascade, so delete_ticket.php removes the children explicitly.
    // NON-DESTRUCTIVE: db_verify never deletes rows. MySQL refuses to add a FK
    // while orphaned child rows exist (e.g. attachments left behind when a
    // pre-fix delete removed an email but not its email_attachments); when that
    // happens we leave the data untouched and report it as 'pending' so the
    // admin can clear the orphans deliberately, then re-run.
    // [child table, constraint name, child FK column, parent table, ADD sql]
    $ticketChildFks = [
        ['email_attachments',   'fk_email_attachments_email', 'email_id',  'emails',  "ALTER TABLE email_attachments ADD CONSTRAINT fk_email_attachments_email FOREIGN KEY (email_id) REFERENCES emails (id)"],
        ['ticket_notes',        'fk_notes_tickets',           'ticket_id', 'tickets', "ALTER TABLE ticket_notes ADD CONSTRAINT fk_notes_tickets FOREIGN KEY (ticket_id) REFERENCES tickets (id)"],
        ['ticket_audit',        'fk_ticket_audit_ticket',     'ticket_id', 'tickets', "ALTER TABLE ticket_audit ADD CONSTRAINT fk_ticket_audit_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id)"],
        ['ticket_time_entries', 'fk_time_entries_tickets',    'ticket_id', 'tickets', "ALTER TABLE ticket_time_entries ADD CONSTRAINT fk_time_entries_tickets FOREIGN KEY (ticket_id) REFERENCES tickets (id)"],
        ['ticket_ai_summaries', 'fk_ticket_ai_summaries_ticket', 'ticket_id', 'tickets', "ALTER TABLE ticket_ai_summaries ADD CONSTRAINT fk_ticket_ai_summaries_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE"],
    ];
    // Plain-English description of what an orphan in each table actually is.
    $orphanLabel = [
        'email_attachments'   => 'email attachment(s) whose email has been deleted but the attachment rows were left behind',
        'ticket_notes'        => 'note(s) whose ticket no longer exists',
        'ticket_audit'        => 'audit record(s) whose ticket no longer exists',
        'ticket_time_entries' => 'time entry/entries whose ticket no longer exists',
        'ticket_ai_summaries' => 'AI summary/summaries whose ticket no longer exists',
    ];
    foreach ($ticketChildFks as [$tbl, $name, $col, $parent, $sql]) {
        if (!$tableExists($tbl) || !$tableExists($parent) || $fkExists($tbl, $name)) continue;
        try { $conn->exec($sql); } catch (Exception $e) {}
        // Still missing? Orphaned rows are blocking it — report (never delete) and
        // attach 'fix' metadata so the UI can offer a one-click cleanup button.
        if (!$fkExists($tbl, $name)) {
            try {
                $n = (int)$conn->query("SELECT COUNT(*) FROM `$tbl` c LEFT JOIN `$parent` p ON p.id = c.`$col` WHERE p.id IS NULL")->fetchColumn();
                if ($n > 0) {
                    $what = $orphanLabel[$tbl] ?? "orphaned row(s) referencing a missing $parent";
                    $results[] = [
                        'table'   => $tbl,
                        'status'  => 'pending',
                        'details' => ["Found $n $what. The foreign key can't be added until these are removed. Click Fix to delete them and re-check."],
                        'fix'     => ['type' => 'delete_orphans', 'table' => $tbl, 'count' => $n],
                    ];
                }
            } catch (Exception $e) {}
        }
    }

    // Drop legacy change columns once each tablet's rows are fully backfilled
    foreach ([['changes', 'change_type', 'change_type_id'],
              ['changes', 'status',      'status_id'],
              ['changes', 'priority',    'priority_id'],
              ['changes', 'impact',      'impact_id'],
              ['change_templates', 'change_type', 'change_type_id'],
              ['change_templates', 'priority',    'priority_id'],
              ['change_templates', 'impact',      'impact_id']] as [$tbl, $oldCol, $newCol]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol)) continue;
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM `$tbl` WHERE `$newCol` IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE `$tbl` DROP COLUMN `$oldCol`");
                $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Dropped legacy $oldCol column"]];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => $tbl, 'status' => 'pending', 'details' => ["Cannot drop $oldCol yet — $orphan row(s) still missing $newCol"]];
        }
    }

    // ----------------------------------------------------------------------
    // Tasks: lookups for status / priority
    // ----------------------------------------------------------------------

    if ($tableExists('task_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM task_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO task_statuses (name, is_closed, colour, is_default, display_order) VALUES
                ('To Do',       0, '#6b7280', 1, 10),
                ('In Progress', 0, '#9333ea', 0, 20),
                ('Blocked',     0, '#f59e0b', 0, 30),
                ('Done',        1, '#16a34a', 0, 40),
                ('Cancelled',   1, '#bdbdbd', 0, 50)");
            $results[] = ['table' => 'task_statuses', 'status' => 'seeded', 'details' => ['Inserted 5 default task statuses']];
        }
    }

    if ($tableExists('task_priorities')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM task_priorities")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO task_priorities (name, colour, is_default, display_order) VALUES
                ('Low',    '#16a34a', 0, 10),
                ('Medium', '#2563eb', 1, 20),
                ('High',   '#f59e0b', 0, 30),
                ('Urgent', '#dc2626', 0, 40)");
            $results[] = ['table' => 'task_priorities', 'status' => 'seeded', 'details' => ['Inserted 4 default task priorities']];
        }
    }

    if ($tableExists('task_tags')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM task_tags")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO task_tags (name, colour, display_order) VALUES
                ('Security',    '#dc2626', 10),
                ('ISO',         '#2563eb', 20),
                ('Environment', '#16a34a', 30)");
            $results[] = ['table' => 'task_tags', 'status' => 'seeded', 'details' => ['Inserted 3 default task tags']];
        }
    }

    // Resolution codes (#1540). Seeded on an EMPTY table only, so an install that
    // has curated its own list never has these pushed back in.
    //
    // ⚠️ ticket_categories is deliberately NOT seeded alongside this. A resolution
    // code list is near enough universal across service desks; a CATEGORY tree is
    // the one thing every organisation has to own, and a pre-seeded taxonomy is
    // just someone else's wrong answer that has to be deleted first.
    if ($tableExists('ticket_resolution_codes')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM ticket_resolution_codes")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO ticket_resolution_codes (name, description, display_order) VALUES
                ('Fixed remotely',       'Resolved without visiting the user',              10),
                ('Fixed on site',        'Resolved in person',                              20),
                ('Hardware replaced',    'The faulty item was swapped out',                 30),
                ('Configuration change', 'Settings changed on a system, device or account', 40),
                ('Training given',       'Nothing was broken - the user was shown how',     50),
                ('Access granted',       'A permission, licence or account was provided',   60),
                ('No fault found',       'Investigated and working as expected',            70),
                ('Duplicate',            'Already covered by another ticket',               80),
                ('Withdrawn',            'The requester no longer needs it',                90),
                ('Referred to supplier', 'Passed to a third party to resolve',             100)");
            $results[] = ['table' => 'ticket_resolution_codes', 'status' => 'seeded', 'details' => ['Inserted 10 default resolution codes']];
        }
    }

    if ($tableExists('process_step_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM process_step_types")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO process_step_types (name, slug, shape, color, display_order, is_active, is_builtin) VALUES
                ('Process',  'process',  'rounded',  '#0078d4', 10, 1, 1),
                ('Decision', 'decision', 'diamond',  '#f59e0b', 20, 1, 1),
                ('Terminal', 'start',    'pill',     '#10b981', 30, 1, 1),
                ('Document', 'document', 'document', '#8764b8', 40, 1, 1)");
            $results[] = ['table' => 'process_step_types', 'status' => 'seeded', 'details' => ['Inserted 4 default step types']];
        }
    }

    foreach ([['tasks', 'status',   'status_id',   'task_statuses'],
              ['tasks', 'priority', 'priority_id', 'task_priorities']] as [$tbl, $oldCol, $newCol, $lkTbl]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol) || !$colExists($tbl, $newCol) || !$tableExists($lkTbl)) continue;

        $conn->exec("INSERT IGNORE INTO `$lkTbl` (name, display_order)
                     SELECT DISTINCT t.`$oldCol`, 999
                     FROM `$tbl` t
                     LEFT JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                     WHERE t.`$oldCol` IS NOT NULL AND t.`$oldCol` <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE `$tbl` t
                            JOIN `$lkTbl` l ON LOWER(l.name) = LOWER(t.`$oldCol`)
                            SET t.`$newCol` = l.id
                            WHERE t.`$newCol` IS NULL AND t.`$oldCol` IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => $tbl, 'status' => 'migrated', 'details' => ["Backfilled $newCol for $upd row(s)"]];
        }

        $conn->exec("UPDATE `$tbl` SET `$newCol` = (SELECT id FROM `$lkTbl` WHERE is_default = 1 LIMIT 1) WHERE `$newCol` IS NULL");
    }

    // FK + index for the asset location tree (self-referencing parent) and the
    // assets -> location link.
    foreach ([
        ['asset_locations', 'fk_asset_locations_parent', "ALTER TABLE asset_locations ADD CONSTRAINT fk_asset_locations_parent FOREIGN KEY (parent_id) REFERENCES asset_locations (id)"],
        ['assets', 'fk_assets_location', "ALTER TABLE assets ADD CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES asset_locations (id) ON DELETE SET NULL"],
        ['assets', 'fk_assets_supplier', "ALTER TABLE assets ADD CONSTRAINT fk_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL"],
        ['asset_checkout_log', 'fk_acl_asset', "ALTER TABLE asset_checkout_log ADD CONSTRAINT fk_acl_asset FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE CASCADE"],
    ] as [$tbl, $name, $sql]) {
        if ($tableExists($tbl) && !$fkExists($tbl, $name)) {
            try { $conn->exec($sql); } catch (Exception $e) {}
        }
    }
    foreach ([
        ['asset_locations', 'idx_asset_locations_parent', 'parent_id'],
        ['assets', 'idx_assets_location', 'location_id'],
        ['assets', 'idx_assets_supplier', 'supplier_id'],
        ['asset_checkout_log', 'idx_acl_asset', 'asset_id'],
    ] as [$tbl, $name, $col]) {
        if ($tableExists($tbl) && !$idxExists($tbl, $name)) {
            try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
        }
    }

    // Migrate legacy free-text assets.supplier -> normalised supplier_id (FK to
    // the shared suppliers registry), then drop the old column. Each distinct
    // free-text value becomes (or matches) a suppliers row flagged supplies_assets.
    if ($tableExists('assets') && $colExists('assets', 'supplier') && $colExists('assets', 'supplier_id') && $tableExists('suppliers')) {
        try {
            $names = $conn->query("SELECT DISTINCT supplier FROM assets WHERE supplier IS NOT NULL AND supplier <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $nm) {
                $sel = $conn->prepare("SELECT id FROM suppliers WHERE legal_name = ? LIMIT 1");
                $sel->execute([$nm]);
                $sid = $sel->fetchColumn();
                if (!$sid) {
                    $ins = $conn->prepare("INSERT INTO suppliers (legal_name, supplies_assets, is_active) VALUES (?, 1, 1)");
                    $ins->execute([$nm]);
                    $sid = (int)$conn->lastInsertId();
                } else {
                    $conn->prepare("UPDATE suppliers SET supplies_assets = 1 WHERE id = ?")->execute([$sid]);
                }
                $conn->prepare("UPDATE assets SET supplier_id = ? WHERE supplier = ? AND supplier_id IS NULL")->execute([$sid, $nm]);
            }
            $conn->exec("ALTER TABLE assets DROP COLUMN supplier");
        } catch (Exception $e) { /* leave the legacy column in place if migration fails */ }
    }

    // FKs and indexes for tasks (full set matching freeitsm.sql — grown
    // installs were missing the parent/comments cascades, which orphaned
    // subtasks and comments on delete)
    foreach ([
        ['tasks', 'fk_tasks_status',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_status FOREIGN KEY (status_id) REFERENCES task_statuses (id)"],
        ['tasks', 'fk_tasks_priority', "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_priority FOREIGN KEY (priority_id) REFERENCES task_priorities (id)"],
        ['tasks', 'fk_tasks_analyst',  "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_analyst FOREIGN KEY (assigned_analyst_id) REFERENCES analysts (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_team',     "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_team FOREIGN KEY (assigned_team_id) REFERENCES teams (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_parent',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_task_id) REFERENCES tasks (id) ON DELETE CASCADE"],
        ['tasks', 'fk_tasks_ticket',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_change',   "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_change FOREIGN KEY (change_id) REFERENCES changes (id) ON DELETE SET NULL"],
        ['tasks', 'fk_tasks_created_by', "ALTER TABLE tasks ADD CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by_id) REFERENCES analysts (id)"],
        ['task_comments', 'fk_task_comments_task',    "ALTER TABLE task_comments ADD CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE"],
        ['task_comments', 'fk_task_comments_analyst', "ALTER TABLE task_comments ADD CONSTRAINT fk_task_comments_analyst FOREIGN KEY (analyst_id) REFERENCES analysts (id)"],
        ['task_tag_map', 'fk_task_tag_map_task', "ALTER TABLE task_tag_map ADD CONSTRAINT fk_task_tag_map_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE"],
        ['task_tag_map', 'fk_task_tag_map_tag',  "ALTER TABLE task_tag_map ADD CONSTRAINT fk_task_tag_map_tag FOREIGN KEY (tag_id) REFERENCES task_tags (id) ON DELETE CASCADE"],
    ] as [$tbl, $name, $sql]) {
        if ($tableExists($tbl) && !$fkExists($tbl, $name)) {
            try { $conn->exec($sql); } catch (Exception $e) {}
        }
    }
    foreach ([
        ['tasks', 'ix_tasks_status_id',   'status_id'],
        ['tasks', 'ix_tasks_priority_id', 'priority_id'],
    ] as [$tbl, $name, $col]) {
        if ($tableExists($tbl) && !$idxExists($tbl, $name)) {
            try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
        }
    }

    // Drop legacy task columns
    foreach ([['tasks', 'status',   'status_id'],
              ['tasks', 'priority', 'priority_id']] as [$tbl, $oldCol, $newCol]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol)) continue;
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM `$tbl` WHERE `$newCol` IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE `$tbl` DROP COLUMN `$oldCol`");
                $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Dropped legacy $oldCol column"]];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => $tbl, 'status' => 'pending', 'details' => ["Cannot drop $oldCol yet — $orphan row(s) still missing $newCol"]];
        }
    }

    // ----------------------------------------------------------------------
    // Service Status: incident-status and impact-level lookups
    // ----------------------------------------------------------------------

    if ($tableExists('service_incident_statuses')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM service_incident_statuses")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO service_incident_statuses (name, is_resolved, colour, is_default, display_order) VALUES
                ('Investigating', 0, '#dc2626', 1, 10),
                ('Identified',    0, '#f59e0b', 0, 20),
                ('Monitoring',    0, '#0891b2', 0, 30),
                ('3rd Party',     0, '#9333ea', 0, 40),
                ('Resolved',      1, '#16a34a', 0, 50)");
            $results[] = ['table' => 'service_incident_statuses', 'status' => 'seeded', 'details' => ['Inserted 5 default incident statuses']];
        }
    }

    if ($tableExists('service_impact_levels')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM service_impact_levels")->fetchColumn();
        if ($cnt === 0) {
            $conn->exec("INSERT INTO service_impact_levels (name, colour, is_default, severity_order, display_order, counts_as_downtime) VALUES
                ('Major Outage',   '#dc2626', 0, 1, 10, 1),
                ('Partial Outage', '#f59e0b', 0, 2, 20, 1),
                ('Degraded',       '#eab308', 0, 3, 30, 1),
                ('Maintenance',    '#0891b2', 0, 4, 40, 0),
                ('Operational',    '#16a34a', 1, 5, 50, 0),
                ('No Disruption',  '#9ca3af', 0, 6, 60, 0)");
            $results[] = ['table' => 'service_impact_levels', 'status' => 'seeded', 'details' => ['Inserted 6 default impact levels']];
        }
    }

    // Uptime (discussion #59): the new counts_as_downtime column defaults to 1, which
    // is the conservative answer for a level nobody has ruled on. For the three SHIPPED
    // levels where it is plainly wrong it is cleared once, on the upgrade path only.
    //
    // ⚠️ Guarded by a marker row rather than by "is it still 1?", because an
    // administrator who deliberately decides that maintenance DOES count for them must
    // not have that choice reverted on the next verification. Running once is the
    // difference between a migration and a policy.
    if ($tableExists('service_impact_levels') && $colExists('service_impact_levels', 'counts_as_downtime')) {
        $already = (int) $conn->query(
            "SELECT COUNT(*) FROM system_settings WHERE setting_key = 'status_downtime_defaults_applied'"
        )->fetchColumn();
        if ($already === 0) {
            $n = $conn->exec(
                "UPDATE service_impact_levels SET counts_as_downtime = 0
                  WHERE name IN ('Maintenance', 'Operational', 'No Disruption')"
            );
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('status_downtime_defaults_applied', '1')")
                 ->execute();
            $results[] = ['table' => 'service_impact_levels', 'status' => 'updated',
                          'details' => ["Excluded {$n} level(s) from downtime (Maintenance / Operational / No Disruption)"]];
        }
    }

    // Backfill status_incidents.status_id from legacy status string
    if ($tableExists('status_incidents') && $colExists('status_incidents', 'status') && $colExists('status_incidents', 'status_id') && $tableExists('service_incident_statuses')) {
        $conn->exec("INSERT IGNORE INTO service_incident_statuses (name, display_order)
                     SELECT DISTINCT i.status, 999
                     FROM status_incidents i
                     LEFT JOIN service_incident_statuses s ON LOWER(s.name) = LOWER(i.status)
                     WHERE i.status IS NOT NULL AND i.status <> '' AND s.id IS NULL");

        $upd = $conn->exec("UPDATE status_incidents i
                            JOIN service_incident_statuses s ON LOWER(s.name) = LOWER(i.status)
                            SET i.status_id = s.id
                            WHERE i.status_id IS NULL AND i.status IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'status_incidents', 'status' => 'migrated', 'details' => ["Backfilled status_id for $upd incident(s)"]];
        }
        $conn->exec("UPDATE status_incidents SET status_id = (SELECT id FROM service_incident_statuses WHERE is_default = 1 LIMIT 1) WHERE status_id IS NULL");
    }

    // Backfill status_incident_services.impact_level_id from legacy impact_level string
    if ($tableExists('status_incident_services') && $colExists('status_incident_services', 'impact_level') && $colExists('status_incident_services', 'impact_level_id') && $tableExists('service_impact_levels')) {
        $conn->exec("INSERT IGNORE INTO service_impact_levels (name, severity_order, display_order)
                     SELECT DISTINCT sis.impact_level, 99, 999
                     FROM status_incident_services sis
                     LEFT JOIN service_impact_levels l ON LOWER(l.name) = LOWER(sis.impact_level)
                     WHERE sis.impact_level IS NOT NULL AND sis.impact_level <> '' AND l.id IS NULL");

        $upd = $conn->exec("UPDATE status_incident_services sis
                            JOIN service_impact_levels l ON LOWER(l.name) = LOWER(sis.impact_level)
                            SET sis.impact_level_id = l.id
                            WHERE sis.impact_level_id IS NULL AND sis.impact_level IS NOT NULL");
        if ($upd > 0) {
            $results[] = ['table' => 'status_incident_services', 'status' => 'migrated', 'details' => ["Backfilled impact_level_id for $upd row(s)"]];
        }
        $conn->exec("UPDATE status_incident_services SET impact_level_id = (SELECT id FROM service_impact_levels WHERE is_default = 1 LIMIT 1) WHERE impact_level_id IS NULL");
    }

    // FKs and indexes
    foreach ([
        ['status_incidents',          'fk_status_incidents_status', "ALTER TABLE status_incidents ADD CONSTRAINT fk_status_incidents_status FOREIGN KEY (status_id) REFERENCES service_incident_statuses (id)"],
        ['status_incident_services',  'fk_sis_impact_level',        "ALTER TABLE status_incident_services ADD CONSTRAINT fk_sis_impact_level FOREIGN KEY (impact_level_id) REFERENCES service_impact_levels (id)"],
    ] as [$tbl, $name, $sql]) {
        if ($tableExists($tbl) && !$fkExists($tbl, $name)) {
            try { $conn->exec($sql); } catch (Exception $e) {}
        }
    }
    foreach ([
        ['status_incidents',          'ix_status_incidents_status_id', 'status_id'],
        ['status_incident_services',  'ix_sis_impact_level_id',         'impact_level_id'],
    ] as [$tbl, $name, $col]) {
        if ($tableExists($tbl) && !$idxExists($tbl, $name)) {
            try { $conn->exec("ALTER TABLE `$tbl` ADD KEY `$name` (`$col`)"); } catch (Exception $e) {}
        }
    }

    // Drop legacy columns once everything's backfilled
    foreach ([
        ['status_incidents',         'status',       'status_id'],
        ['status_incident_services', 'impact_level', 'impact_level_id'],
    ] as [$tbl, $oldCol, $newCol]) {
        if (!$tableExists($tbl) || !$colExists($tbl, $oldCol)) continue;
        $orphan = (int) $conn->query("SELECT COUNT(*) FROM `$tbl` WHERE `$newCol` IS NULL")->fetchColumn();
        if ($orphan === 0) {
            try {
                $conn->exec("ALTER TABLE `$tbl` DROP COLUMN `$oldCol`");
                $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Dropped legacy $oldCol column"]];
            } catch (Exception $e) {}
        } else {
            $results[] = ['table' => $tbl, 'status' => 'pending', 'details' => ["Cannot drop $oldCol yet — $orphan row(s) still missing $newCol"]];
        }
    }

    // Seed / top-up the curated CMDB icon library so the class form has a
    // picker source. Used to fire only when the table was empty; now uses
    // INSERT IGNORE per row so existing installs pick up library expansions
    // (e.g. the Network Mapper per-node icon override set) on next verify.
    // The uq_cmdb_icons_key unique index (asserted further down) makes the
    // IGNORE safe — duplicates are skipped, existing rows untouched.
    if ($tableExists('cmdb_icons')) {
        $icons = [
            // Original 20 (chunk A foundation)
            ['server',         'Server',            10],
            ['database',       'Database',          20],
            ['application',    'Application',       30],
            ['service',        'Service',           40],
            ['website',        'Website',           50],
            ['api',            'API',               60],
            ['vm',             'Virtual Machine',   70],
            ['container',      'Container',         80],
            ['cloud',          'Cloud Resource',    90],
            ['network',        'Network Device',   100],
            ['firewall',       'Firewall',         110],
            ['router',         'Router',           120],
            ['switch',         'Switch',           130],
            ['storage',        'Storage',          140],
            ['workstation',    'Workstation',      150],
            ['printer',        'Printer',          160],
            // Peripherals & displays (#1146) — added so ASSET TYPES have
            // something to pick. ⚠️ 'display' is the screen; 'monitor' below is
            // the monitoring GAUGE, which is why it is labelled that way.
            ['display',        'Display / screen', 170],
            ['television',     'Television',       171],
            ['projector',      'Projector',        172],
            ['webcam',         'Webcam',           173],
            ['headset',        'Headset',          174],
            ['keyboard',       'Keyboard',         175],
            ['mouse',          'Mouse',            176],
            ['speaker',        'Speaker',          177],
            ['dock',           'Docking station',  178],
            ['scanner',        'Scanner',          179],
            ['camera',         'Camera',           180],
            ['desk-phone',     'Desk phone',       181],
            ['ups',            'UPS',              182],
            ['person',         'Person',           170],
            ['team',           'Team',             180],
            ['document',       'Document',         190],
            ['box',            'Generic',          200],
            // Extended set (Network Mapper per-node icon override). Display
            // orders interleaved so related variants group together.
            ['server-rack',    'Server (rack)',     11],
            ['server-blade',   'Server (blade)',    12],
            ['server-tower',   'Server (tower)',    13],
            ['mainframe',      'Mainframe',         14],
            ['function',       'Function',          71],
            ['database-cluster', 'Database cluster', 21],
            ['database-cache', 'Database (cache)',  22],
            ['storage-san',    'SAN',              141],
            ['storage-tape',   'Tape backup',      142],
            ['backup',         'Backup',           143],
            ['load-balancer',  'Load balancer',    111],
            ['proxy',          'Proxy',            112],
            ['vpn',            'VPN',              113],
            ['gateway',        'Gateway',          114],
            ['wireless-ap',    'Wireless AP',      131],
            ['modem',          'Modem',            132],
            ['cdn',            'CDN',              115],
            ['dns',            'DNS',              116],
            ['shield',         'Shield',           117],
            ['lock',           'Lock',             118],
            ['key',            'Key',              119],
            ['ids',            'IDS / IPS',        121],
            ['siem',           'SIEM',             122],
            ['cloud-private',  'Private cloud',     91],
            ['cloud-public',   'Public cloud',      92],
            ['cloud-hybrid',   'Hybrid cloud',      93],
            ['region',         'Region',            94],
            ['container-pod',  'Pod',               81],
            ['kubernetes',     'Kubernetes',        82],
            ['registry',       'Registry',          83],
            ['microservice',   'Microservice',      31],
            ['queue',          'Message queue',     32],
            ['cache',          'Cache',             33],
            ['dashboard',      'Dashboard',         34],
            ['laptop',         'Laptop',           151],
            ['mobile',         'Mobile',           152],
            ['tablet',         'Tablet',           153],
            ['iot',            'IoT device',       154],
            ['monitor',        'Monitor / gauge',  161],
            ['alert',          'Alert',            162],
            ['log',            'Log',              163],
            ['org',            'Org',              181],
            ['folder',         'Folder',           191],
            ['globe',          'Globe',            192],
            ['mail',           'Mail',             193],
            ['calendar',       'Calendar',         194],
        ];
        $before = (int) $conn->query("SELECT COUNT(*) FROM cmdb_icons")->fetchColumn();
        $ins = $conn->prepare("INSERT IGNORE INTO cmdb_icons (icon_key, label, display_order) VALUES (?, ?, ?)");
        foreach ($icons as $row) { $ins->execute($row); }
        $after = (int) $conn->query("SELECT COUNT(*) FROM cmdb_icons")->fetchColumn();
        $added = $after - $before;
        if ($before === 0) {
            $results[] = ['table' => 'cmdb_icons', 'status' => 'seeded', 'details' => ["Inserted $after default CMDB icons"]];
        } elseif ($added > 0) {
            $results[] = ['table' => 'cmdb_icons', 'status' => 'updated', 'details' => ["Topped up icon library — added $added new icons"]];
        }
    }

    // Seed default CMDB relationship types so the module has something usable on first run
    if ($tableExists('cmdb_relationship_types')) {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM cmdb_relationship_types")->fetchColumn();
        if ($cnt === 0) {
            // Only 'depends on' carries impact by default — see freeitsm.sql.
            $conn->exec("INSERT INTO cmdb_relationship_types (verb, inverse_verb, description, impact_direction, display_order) VALUES
                ('depends on',  'is depended on by', 'A needs B in order to function',     'to_from', 10),
                ('connects to', 'is connected from', 'A has a network or data link to B',  'none',    20),
                ('managed by',  'manages',           'A is administered by B',             'none',    30)");
            $results[] = ['table' => 'cmdb_relationship_types', 'status' => 'seeded', 'details' => ['Inserted 3 default CMDB relationship types']];
        }
    }

    // Ensure unique indexes exist on LMS tables (db_verify only creates columns, not indexes)
    $uniqueIndexes = [
        ['webhook_message_formats', 'uq_wmf_key', '(`format_key`)'],
        // THE fire-once guarantee for time-based triggers. Without this UNIQUE key
        // the INSERT IGNORE is meaningless and a breached SLA would re-escalate on
        // every cron run, forever. It is not an optimisation — it is the feature.
        ['workflow_scheduled_emissions', 'uq_wse_once', '(`trigger_event`, `entity_key`, `fingerprint`)'],
        ['lms_cmi_data', 'uq_lcd_progress_element', '(`progress_id`, `element`)'],
        // ⚠️ lms_progress / lms_course_assignments are DELIBERATELY ABSENT from
        // this list now. Their unique keys moved when a learner stopped having to
        // be an analyst — (learner_type, learner_id, course_id) and
        // (course_id, target_type, group_id) — and both new keys are in the
        // generated backfill further down. Re-adding the old ones here would put
        // back the two keys the repair block below exists to remove, and on a run
        // where this loop happened to come after it, permanently.
        ['rbac_role_capabilities', 'uq_rrc_role_capability', '(`role_id`, `capability_key`)'],
        ['rbac_analyst_roles', 'uq_rar_analyst_role', '(`analyst_id`, `role_id`)'],
        ['rbac_team_roles', 'uq_rtr_team_role', '(`team_id`, `role_id`)'],
        ['lms_learning_group_members', 'uq_lgm_group_analyst', '(`group_id`, `analyst_id`)'],
        ['intune_devices', 'uq_intune_devices_intune_id', '(`intune_id`)'],
        ['rfp_departments', 'uq_rfp_departments_name', '(`name`)'],
        ['rfp_consolidated_sources', 'uq_rfp_consolidated_sources', '(`consolidated_id`, `extracted_id`)'],
        ['rfp_invited_suppliers', 'uq_rfp_invited_suppliers', '(`rfp_id`, `supplier_id`)'],
        ['rfp_scores', 'uq_rfp_scores', '(`rfp_id`, `supplier_id`, `analyst_id`, `consolidated_id`)'],
        ['user_preferences', 'uq_user_pref', '(`analyst_id`, `preference_key`)'],
        ['cmdb_icons', 'uq_cmdb_icons_key', '(`icon_key`)'],
        ['cmdb_classes', 'uq_cmdb_classes_key', '(`class_key`)'],
        ['cmdb_class_properties', 'uq_cmdb_class_property_key', '(`class_id`, `property_key`)'],
        ['cmdb_object_properties', 'uq_cmdb_op_obj_prop', '(`object_id`, `property_id`)'],
        ['cmdb_relationship_types', 'uq_cmdb_rel_type_verb', '(`verb`)'],
        ['cmdb_object_relationships', 'uq_cmdb_or_triple', '(`from_object_id`, `to_object_id`, `relationship_type_id`)'],
        ['ticket_cmdb_objects', 'uq_ticket_cmdb_obj', '(`ticket_id`, `cmdb_object_id`)'],
        ['process_step_types', 'uq_process_step_types_slug', '(`slug`)'],
        ['change_field_layout', 'uq_cfl_field_key', '(`field_key`)'],
        ['analyst_sso_identities', 'uq_sso_provider_subject', '(`provider_id`, `subject`)'],
        ['analyst_sso_identities', 'uq_sso_provider_analyst', '(`provider_id`, `analyst_id`)'],
        ['user_sso_identities', 'uq_user_sso_provider_subject', '(`provider_id`, `subject`)'],
        ['user_sso_identities', 'uq_user_sso_provider_user', '(`provider_id`, `user_id`)'],
        ['freemail_domains', 'uq_freemail_domains_domain', '(`domain`)'],
        ['tenant_channel_senders', 'uq_tenant_channel_sender_identifier', '(`identifier`)'],
        ['webchat_widgets', 'uq_webchat_widget_key', '(`widget_key`)'],
        ['webchat_widgets', 'uq_webchat_widget_channel', '(`channel_id`)'],
        ['webchat_conversations', 'uq_webchat_conversation_token', '(`token`)'],
        ['problem_tickets', 'uq_problem_ticket', '(`problem_id`, `ticket_id`)'],
        ['change_tickets',  'uq_change_ticket',  '(`change_id`, `ticket_id`)'],
        ['ticket_links',    'uq_ticket_link',    '(`source_ticket_id`, `target_ticket_id`, `relation_type`)'],
        ['api_keys', 'uq_api_keys_hash', '(`key_hash`)'],
        ['api_key_rate_limits', 'uq_api_key_window', '(`api_key_id`, `window_start`)'],
        ['contract_term_values', 'uq_ctv_contract_tab', '(`contract_id`, `term_tab_id`)'],
        ['morningChecks_Results', 'uq_check_date', '(`CheckID`, `CheckDate`)'],
        // 🔴 CALENDAR SYNC — these are not tidiness, they ARE the upsert.
        // Both tables are written with INSERT ... ON DUPLICATE KEY UPDATE, which
        // without the UNIQUE key never matches: every save appends another row and
        // every read takes the first one, so nothing an analyst or an admin
        // changes appears to stick. A fresh install gets these from freeitsm.sql;
        // only an upgrade needs them here, which is exactly the install that would
        // have been silently broken.
        ['calendar_enrolments',  'uniq_calendar_enrolment_analyst',  '(`analyst_id`)'],
        ['calendar_sync_events', 'uniq_calendar_sync_ticket_analyst', '(`ticket_id`, `analyst_id`)'],
    ];

    foreach ($uniqueIndexes as [$tbl, $idxName, $cols]) {
        try {
            // Check if table exists
            $tblCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = ?");
            $tblCheck->execute([DB_NAME, $tbl]);
            if ((int)$tblCheck->fetch(PDO::FETCH_ASSOC)['cnt'] === 0) continue;

            // Check if index exists
            $idxCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?");
            $idxCheck->execute([DB_NAME, $tbl, $idxName]);
            if ((int)$idxCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0) continue;

            // Calendar sync: anyone who ran the build where the UNIQUE key was
            // missing has a pile of duplicate rows, and ADD UNIQUE KEY refuses
            // outright while they exist. Keep the NEWEST per analyst / per
            // ticket-and-analyst — that is the one carrying whatever they last
            // chose, and the older rows are failed writes, not history.
            if ($tbl === 'calendar_enrolments') {
                $conn->exec("DELETE e1 FROM calendar_enrolments e1
                             INNER JOIN calendar_enrolments e2
                             ON e1.analyst_id = e2.analyst_id AND e1.id < e2.id");
            }
            if ($tbl === 'calendar_sync_events') {
                $conn->exec("DELETE s1 FROM calendar_sync_events s1
                             INNER JOIN calendar_sync_events s2
                             ON s1.ticket_id = s2.ticket_id AND s1.analyst_id = s2.analyst_id AND s1.id < s2.id");
            }
            // For lms_cmi_data: clean up duplicates before adding unique key
            if ($tbl === 'lms_cmi_data') {
                $conn->exec("DELETE d1 FROM lms_cmi_data d1
                             INNER JOIN lms_cmi_data d2
                             ON d1.progress_id = d2.progress_id AND d1.element = d2.element AND d1.id < d2.id");
            }
            // For process_step_types: drop duplicate slugs (keep lowest id) before the unique key
            if ($tbl === 'process_step_types') {
                $conn->exec("DELETE t1 FROM process_step_types t1
                             INNER JOIN process_step_types t2
                             ON t1.slug = t2.slug AND t1.id > t2.id");
            }
            // For contract_term_values: the old select-then-insert upsert could
            // double-insert under concurrency — keep the newest row per
            // (contract, tab) before adding the unique key
            if ($tbl === 'contract_term_values') {
                $conn->exec("DELETE t1 FROM contract_term_values t1
                             INNER JOIN contract_term_values t2
                             ON t1.contract_id = t2.contract_id AND t1.term_tab_id = t2.term_tab_id AND t1.id < t2.id");
            }
            // For morningChecks_Results: same select-then-insert upsert shape —
            // keep the newest row per (check, date) before adding the unique key
            if ($tbl === 'morningChecks_Results') {
                $conn->exec("DELETE t1 FROM morningChecks_Results t1
                             INNER JOIN morningChecks_Results t2
                             ON t1.CheckID = t2.CheckID AND t1.CheckDate = t2.CheckDate AND t1.ResultID < t2.ResultID");
            }

            $conn->exec("ALTER TABLE `$tbl` ADD UNIQUE KEY `$idxName` $cols");
            $results[] = ['table' => $tbl, 'status' => 'updated', 'details' => ["Added unique index $idxName"]];
        } catch (Exception $e) {
            // Index may already exist under a different name — ignore
        }
    }

    // One-off: bump user_preferences.preference_value from VARCHAR(500) to TEXT.
    // Larger config blobs (e.g. the asset-management table view's column /
    // sort prefs in #383) can otherwise overflow the original 500-char cap.
    // Idempotent — only fires if the column is still varchar.
    try {
        $col = $conn->prepare(
            "SELECT DATA_TYPE FROM information_schema.columns
             WHERE table_schema = ? AND table_name = 'user_preferences' AND column_name = 'preference_value'"
        );
        $col->execute([DB_NAME]);
        $row = $col->fetch(PDO::FETCH_ASSOC);
        if ($row && strtolower($row['DATA_TYPE']) === 'varchar') {
            $conn->exec("ALTER TABLE `user_preferences` MODIFY `preference_value` TEXT NULL");
            $results[] = [
                'table' => 'user_preferences',
                'status' => 'updated',
                'details' => ['preference_value: VARCHAR(500) → TEXT (allow larger config blobs)']
            ];
        }
    } catch (Exception $e) {
        // Non-fatal — fall through with verification result
    }

    // ---- LMS: a learner may now be a portal user, not only an analyst -------
    //
    // Courses can be assigned to the shared people groups (and to every portal
    // user at once), so lms_progress is keyed on (learner_type, learner_id)
    // rather than analyst_id. See the comments on both tables in freeitsm.sql.
    //
    // 🔴 THIS BLOCK MUST STAY ABOVE THE INDEX BACKFILL BELOW. That pass adds
    // uq_lp_learner_course (learner_type, learner_id, course_id). On a grown
    // install every existing row is an analyst's and its learner_id is still the
    // column default of 0 — so they ALL collide on ('analyst', 0, course_id) and
    // the unique key cannot be created until the backfill here has run. Move this
    // below and the key is silently reported as un-addable on every install that
    // has ever recorded a course. Ordering, not tidiness.
    if ($tableExists('lms_progress') && $colExists('lms_progress', 'learner_id')) {
        try {
            $conn->exec("UPDATE lms_progress
                            SET learner_id = analyst_id, learner_type = 'analyst'
                          WHERE learner_id = 0 AND analyst_id IS NOT NULL");
        } catch (Exception $e) { /* reported by the index pass if it mattered */ }

        // analyst_id is legacy from here on and has to accept NULL, because a
        // portal learner has no analyst record to point at. Probe-then-MODIFY,
        // the same shape as the five precedents earlier in this file; relaxing
        // NOT NULL cannot invalidate an existing row.
        try {
            $col = $conn->query("SHOW COLUMNS FROM `lms_progress` LIKE 'analyst_id'")->fetch(PDO::FETCH_ASSOC);
            if ($col && strtoupper((string)($col['Null'] ?? '')) === 'NO') {
                $conn->exec("ALTER TABLE `lms_progress` MODIFY `analyst_id` INT NULL");
            }
        } catch (Exception $e) { /* non-fatal */ }

        // The old key has to go, not just be superseded: with analyst_id nullable
        // it constrains nothing for portal learners (MySQL allows any number of
        // NULLs in a unique index) while still looking like it does.
        if ($idxExists('lms_progress', 'uq_lp_analyst_course')) {
            try { $conn->exec("ALTER TABLE lms_progress DROP INDEX uq_lp_analyst_course"); } catch (Exception $e) {}
        }
    }

    if ($tableExists('lms_course_assignments') && $colExists('lms_course_assignments', 'target_type')) {
        // 🔴 (course_id, group_id) cannot tell an analyst learning group from a
        // people group. Left in place, assigning a course to people-group 2 when
        // learning-group 2 already has it is refused as a duplicate of something
        // unrelated — and the message would name the wrong group entirely.
        if ($idxExists('lms_course_assignments', 'uq_lca_course_group')) {
            try { $conn->exec("ALTER TABLE lms_course_assignments DROP INDEX uq_lca_course_group"); } catch (Exception $e) {}
        }
    }

    // ---- Comprehensive named-index backfill --------------------------------
    // freeitsm.sql creates every secondary index at CREATE TABLE time, but a
    // GROWN install only ever received the indexes db_verify was explicitly told
    // to add — so an index that was dropped, or never existed on an older
    // install, stays missing, and for a UNIQUE key that silently permits
    // duplicate data (e.g. two users sharing one email). This pass is the
    // backstop: it restores EVERY named index in freeitsm.sql, idempotently —
    // present -> skip, missing -> add. It runs LAST, after the feature-specific
    // FK/index groups above, so anything they added is simply skipped here.
    //
    // A UNIQUE key that can't be added because duplicate rows already exist is
    // REPORTED, never forced: unlike orphaned FK child rows (which have a Fix
    // button), we can't know which duplicate the admin wants to keep — so they
    // resolve it and re-run. The list is generated from freeitsm.sql by
    // scripts/gen_db_verify_indexes.php.
    // Drift guard: the backfill list is a GENERATED mirror of freeitsm.sql, and
    // the failure mode is someone adding an index to freeitsm.sql but forgetting
    // to regenerate — so the mirror silently omits it and grown installs miss it
    // again. Re-parse freeitsm.sql and compare; if they've drifted, say so loudly
    // right here (this page is the ritual after a schema change). Mirrors
    // capSelfCheck().
    //
    // ⚠️ This used to say the check "only ever fires for a developer mid-change,
    // because both files ship from one commit". That was wrong, and it was
    // reported by a user (GH #113): the two files ship from one commit, but
    // nothing checked they AGREED in that commit, so a shipped release carried
    // 16 indexes present in freeitsm.sql and missing from the list. Every
    // administrator who ran Verification saw a developer instruction they could
    // do nothing with, after every update.
    // The check is right; what was missing was running it before shipping —
    // `php scripts/gen_db_verify_indexes.php --check` now does that, and exits
    // non-zero.
    require_once '../../includes/db_verify_index_parse.php';
    $indexListDrift = dbVerifyIndexListSelfCheck();
    if (!empty($indexListDrift)) {
        $results[] = [
            'table'   => 'index backfill list',
            'status'  => 'error',
            'details' => array_merge(
                ['The index list is out of date vs freeitsm.sql — run scripts/gen_db_verify_indexes.php and commit both files.'],
                array_slice($indexListDrift, 0, 12)
            ),
        ];
    }

    // The same idea for COLUMNS. Indexes are the easy case — their list is
    // GENERATED, so drift only means "you forgot to regenerate". freeitsm.sql and
    // includes/db_verify_schema.php are BOTH hand-maintained, so they can
    // disagree in either direction, and each direction breaks a different
    // install: a column only in Verification leaves a FRESH install missing it
    // (this shipped once — asset_locations.tenant_id), while a column only in
    // freeitsm.sql means an EXISTING install never gains it. Silent when in sync.
    require_once '../../includes/db_verify_column_parse.php';
    $columnDrift = dbVerifyColumnSelfCheck();
    if (!empty($columnDrift)) {
        $results[] = [
            'table'   => 'schema column drift',
            'status'  => 'error',
            'details' => array_merge(
                ['freeitsm.sql and Database Verification disagree about which columns exist. '
                 . 'Both are sources of truth — freeitsm.sql builds a NEW install, Verification upgrades an EXISTING one — '
                 . 'so make them match and commit both.'],
                array_slice($columnDrift, 0, 12)
            ),
        ];
    }

    $allNamedIndexes = require '../../includes/db_verify_indexes.php';
    $resultPosByTable = [];
    foreach ($results as $ri => $rr) {
        if (isset($rr['table']) && !isset($resultPosByTable[$rr['table']])) {
            $resultPosByTable[$rr['table']] = $ri;
        }
    }
    foreach ($allNamedIndexes as [$idxTable, $idxName, $idxType, $idxCols]) {
        if (!$tableExists($idxTable) || $idxExists($idxTable, $idxName)) continue;
        // The third element carried a boolean before 2026-08 and a type string
        // after it. dbVerifyIndexTypeOf reads both — guessing here would be worse
        // than an error, because the string 'key' is truthy and a naive ternary
        // would build a UNIQUE index over columns that are not unique.
        $idxType = dbVerifyIndexTypeOf($idxType);
        $keyword = ['unique' => 'UNIQUE KEY', 'fulltext' => 'FULLTEXT KEY'][$idxType] ?? 'KEY';
        $pos = $resultPosByTable[$idxTable] ?? null;
        try {
            // ⚠️ The FIRST full-text index on an InnoDB table rebuilds that table,
            // so it is slow on a populated one. In practice this is fine: db_verify
            // creates a missing table from $schema moments earlier and then indexes
            // it while empty. The slow path only exists if someone drops a full-text
            // index from a table that already holds rows.
            $conn->exec("ALTER TABLE `$idxTable` ADD $keyword `$idxName` $idxCols");
            if ($pos !== null) {
                $results[$pos]['details'][] = 'Restored missing index ' . $idxName;
                if (($results[$pos]['status'] ?? '') === 'ok') $results[$pos]['status'] = 'updated';
            }
        } catch (Exception $e) {
            if ($pos !== null) {
                if ($idxType === 'unique') {
                    $detail = 'Could not add unique index ' . $idxName . ' — duplicate rows exist; resolve them, then re-run';
                } elseif ($idxType === 'fulltext') {
                    // Nearly always the column type: InnoDB full-text indexes only
                    // accept CHAR, VARCHAR and TEXT.
                    $detail = 'Could not add full-text index ' . $idxName
                            . ' — full-text indexes only work on CHAR, VARCHAR or TEXT columns. ' . $e->getMessage();
                } else {
                    $detail = 'Could not add index ' . $idxName . ': ' . $e->getMessage();
                }
                $results[$pos]['details'][] = $detail;
                $results[$pos]['status'] = 'error';
            }
        }
    }

    // Tag each result with its module for the card grid's colour + filter. This
    // is presentation only (a label on a table name); it reads no schema truth
    // and cannot affect verification. Derived from table-name prefixes — see
    // includes/db_verify_modules.php.
    require_once '../../includes/db_verify_modules.php';
    foreach ($results as &$r) {
        $r['module'] = dbVerifyModuleForTable($r['table'] ?? '');
    }
    unset($r);

    echo json_encode([
        'success' => true,
        'results' => $results,
        'total_tables' => count($schema),
        'modules' => dbVerifyModuleMeta()
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
