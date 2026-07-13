<?php
/**
 * Debug Tools registry — single source of truth.
 *
 * Each entry powers a searchable card on the Debug Tools landing
 * (system/debug-tools/index.php) and its own dedicated page
 * (system/debug-tools/<slug>/index.php). The diagnostic itself is a
 * self-contained script under api/system/debug-tools/<file> that outputs a
 * single plain-text report.
 *
 * To add a new diagnostic:
 *   1. Drop api/system/debug-tools/Dnnn_short_name.php (plain text, === SECTION === headers).
 *   2. Add an entry below (id, slug, file, title, category, desc, keywords, icon,
 *      when, checks, duration, persists, optional input).
 *   3. Create system/debug-tools/<slug>/index.php (two lines — see d001/index.php).
 */

/** @return array<int,array<string,mixed>> Ordered list of debug tools. */
function getDebugTools() {
    return [
        [
            'id'       => 'D001',
            'slug'     => 'd001',
            'file'     => 'D001_demo_core_import.php',
            'title'    => 'Demo Core Data Import',
            'category' => 'Demo Data',
            'icon'     => 'demo',
            'desc'     => 'Diagnose a failing "Import Core Data" on the Demo Data screen.',
            'keywords' => 'demo data import core seed sample fixtures populate setup d001',
            'when'     => 'Run this when you click "Import Core Data" on the Demo Data screen and it fails, hangs, or appears to do nothing.',
            'checks'   => [
                'PHP version, OS, loaded extensions, session state, memory & post limits',
                'config.php and db_config.php presence + DB credentials defined',
                'Required files: import_demo_data.php, core.json, functions.php',
                'core.json parses and how many records it would import per table',
                'Database connection — server version, database name, character set',
                'Each of the 9 core tables: exists, row count, actual columns vs expected',
                'Write probe — inserts one sentinel row per table inside a rolled-back transaction',
                'Live import attempt — runs the real import in-process and captures the response + any PHP warnings',
            ],
            'duration' => '~2 seconds',
            'persists' => 'The live-import step will populate demo data if it succeeds. Otherwise nothing persists.',
            'input'    => null,
        ],
        [
            'id'       => 'D002',
            'slug'     => 'd002',
            'file'     => 'D002_delete_ticket.php',
            'title'    => 'Delete Ticket (with full SQL trace)',
            'category' => 'Tickets',
            'icon'     => 'ticket',
            'desc'     => 'Delete a ticket the same way the app does, showing every SQL statement — for foreign-key delete errors.',
            'keywords' => 'ticket delete foreign key fk constraint 1451 email_attachments sql trace destructive d002',
            'when'     => 'Run this when deleting a ticket fails with a foreign-key error (e.g. "1451 Cannot delete or update a parent row" on email_attachments). Enter the ticket reference, and it deletes the ticket the same way the app does — but shows every SQL statement and row count so you can see exactly what happened.',
            'input'    => ['name' => 'ref', 'label' => 'Ticket reference', 'placeholder' => 'e.g. the ticket number shown on the ticket'],
            'checks'   => [
                'Resolves the ticket from the reference (ticket_number, or raw id as a fallback)',
                'Audits every table the delete touches: exists, key columns, and each foreign key + its ON DELETE rule',
                'Pinpoints the fk_email_attachments_email constraint (present? blocking?) and lists the exact email ids + attachment ids / filenames / paths that trigger the error',
                'Counts the child rows that will be removed (attachments, emails, notes, audit, time entries, plus the cascade children)',
                'Performs the delete inside a transaction, echoing every DELETE statement, its parameters and rows affected, then COMMIT',
                'Verifies the ticket and its children are gone, and removes the orphaned attachment files from disk',
            ],
            'duration'    => '~1 second',
            'persists'    => 'DESTRUCTIVE — on success the ticket and all its data are permanently deleted. On any error the transaction is rolled back and nothing changes.',
            'destructive' => true,
        ],
        [
            'id'       => 'D003',
            'slug'     => 'd003',
            'file'     => 'D003_selfservice_sso.php',
            'title'    => 'Self-Service SSO check (by email)',
            'category' => 'Self-Service',
            'icon'     => 'sso',
            'desc'     => 'Type a requester\'s email and check, end to end, whether self-service single sign-on is wired correctly for them.',
            'keywords' => 'self service sso single sign on oidc login email tenant provider entra okta keycloak redirect uri discovery d003',
            'when'     => 'Run this when a self-service portal user can\'t sign in with SSO (or you\'re setting them up and want to confirm the wiring). Enter their email address and it traces the whole path — schema, global SSO config, single vs multi-tenant, how the email maps to a company, the user account state, the predicted login outcome, provider health with a live OIDC discovery test, and the redirect URI.',
            'input'    => ['name' => 'email', 'label' => 'Email address', 'placeholder' => 'e.g. someone@company.com'],
            'checks'   => [
                'Schema readiness for the self-service login + SSO tables/columns (users, user_sso_identities, auth_providers, system_settings) and the multi-tenant routing tables + key constraints',
                'Global SSO config — sso_enabled, local_login_enabled, and counts of enabled global vs company-owned providers',
                'Tenancy mode — single-company or multi-tenant',
                'How the email maps to a company — exact sender-address override, domain mapping, and whether it\'s a freemail/personal domain',
                'The user account — exists / passwordless / TOTP state / which provider it\'s pinned to / linked SSO identities (subject shown masked)',
                'The predicted login outcome (local / sso / choose), mirroring the real resolve_login routing',
                'Provider health + a live, secret-free OIDC discovery test (issuer match, authorization/token/jwks/end-session endpoints reachable)',
                'The exact redirect URI to register in the IdP',
                'A plain-English verdict listing any blockers',
            ],
            'duration' => '~1–5 seconds (depends on how quickly the identity provider answers discovery)',
            'persists' => 'None. Read-only — it performs a live OIDC discovery fetch (an unauthenticated metadata request to the provider) but writes nothing, and never prints secrets (client secrets, TOTP secrets and password hashes are reported only as present/absent).',
        ],
        [
            'id'       => 'D004',
            'slug'     => 'd004',
            'file'     => 'D004_local_login.php',
            'title'    => 'Local login check (password / hash)',
            'category' => 'Login',
            'icon'     => 'key',
            'desc'     => 'Diagnose why a username/email + password login fails — including imported password hashes in the wrong format.',
            'keywords' => 'login password hash bcrypt md5 sha1 import migrate users analyst password_verify lockout mfa totp local d004',
            'when'     => 'Run this when someone can\'t sign in with their password — especially after a bulk import of accounts with password hashes. Pick the account type, enter the username (analyst) or email (self-service user), and optionally the password. It checks the hash format (bcrypt vs an imported MD5/SHA/phpass/Django hash that password_verify can never read), account lockout / expiry / active state, TOTP, SSO pin, and — if you supply the password — verifies it and pinpoints a wrong-hash-type import.',
            'method'   => 'POST',
            'inputs'   => [
                ['name' => 'account_type', 'label' => 'Account type', 'type' => 'select', 'options' => [
                    ['value' => 'user',    'label' => 'Self-service user (signs in by email)'],
                    ['value' => 'analyst', 'label' => 'Analyst (signs in by username)'],
                ]],
                ['name' => 'identifier', 'label' => 'Username or email', 'type' => 'text', 'placeholder' => 'e.g. jbloggs  or  jane@company.com'],
                ['name' => 'password', 'label' => 'Password (optional)', 'type' => 'password', 'placeholder' => 'leave blank to skip the password check', 'optional' => true],
            ],
            'checks'   => [
                'Schema readiness for the chosen account type (analysts or users + the relevant login columns)',
                'Global login config — allow-local-login, SSO enabled, and (analysts) lockout / expiry / IP-ban thresholds',
                'Account lookup by username (analyst, exact) or email (user, case-insensitive)',
                'Password hash forensics — detects bcrypt/argon vs an imported MD5 / SHA-1 / SHA-256 / phpass / Django / LDAP hash that password_verify() can never read, plus whitespace/length anomalies',
                'Optional password verification — runs password_verify(), and if it fails on a raw digest, identifies whether the stored hash is e.g. MD5(password) (the classic wrong-hash-type import)',
                'Account state blockers — inactive, locked, password expired, SSO-pinned (local disabled), TOTP required, active IP bans',
                'Encryption key (for MFA users) — whether the key can actually decrypt this account\'s TOTP secret, since a wrong/missing key fails login after a correct password (password hashes themselves aren\'t encrypted)',
                'A plain-English verdict naming the most likely reason and the fix',
            ],
            'duration' => '~1 second',
            'persists' => 'None. Read-only — writes nothing. POST-only so the password never lands in a URL or log; the password is never echoed and the stored hash is never printed (only its format / cost / length).',
        ],
        [
            'id'       => 'D005',
            'slug'     => 'd005',
            'file'     => 'D005_endpoint_permissions.php',
            'title'    => 'Endpoint permission coverage',
            'category' => 'Security',
            'icon'     => 'key',
            'desc'     => 'Scan every API endpoint and find the ones with no permission check — the holes a type system can never catch.',
            'keywords' => 'security permissions rbac roles capability guard unguarded audit endpoint api coverage hole escalation d005',
            'when'     => 'Run this after adding endpoints, after converting a module to per-tab permissions, and before a release. A type system catches a MISSPELLED permission; nothing catches one somebody simply forgot to write — and that omission is how any logged-in analyst came to be able to rewrite the vCenter credentials and switch off brute-force lockout (#829), trigger a full Intune tenant sync (#830), and read or delete any RFP (#833). Each of those was found by hand, by accident. This is the systematic version.',
            'checks'   => [
                'Every file under api/ — what actually guards it: a capability, administrator-only, module access, an API key, a webhook signature, a URL token, "logged in and nothing more", or nothing at all',
                'CRITICAL — endpoints that CHANGE DATA and have no authentication whatsoever',
                'HIGH — endpoints that change data behind nothing but "are you logged in?", so any analyst can reach them even if their module access is a single unrelated module',
                'Capabilities passed as a bare string instead of a Cap:: constant (a typo in a string fails closed and SILENTLY, and the is_admin bypass hides it from administrators)',
                'Capabilities declared on System → Roles that no endpoint actually enforces — a tick-box that grants nothing',
                'Registry self-check — the Cap:: constants, the capability registry and the module list all agree',
                'A full per-module inventory, so the report is auditable rather than a black box',
            ],
            'duration' => '~2 seconds',
            'persists' => 'None. Read-only — it reads source files and writes nothing. Prints no secrets. It is a STATIC scan, so it cannot prove an endpoint is safe (one may do its own bespoke checking) — findings are ranked "worth a look", not "definitely broken". Endpoints that are public by design (login, self-service, the REST API, inbound webhooks) and those that act only on the caller\'s own account (your own password, your own MFA) are excluded and listed separately.',
        ],
    ];
}

/** Find one tool by its slug (e.g. 'd001'). Returns null if not found. */
function getDebugToolBySlug($slug) {
    foreach (getDebugTools() as $tool) {
        if ($tool['slug'] === $slug) return $tool;
    }
    return null;
}

/**
 * Inline SVG markup for a debug-tool icon key. Unknown keys render a neutral
 * wrench so a typo never breaks the page. Sizing/colour come from CSS.
 */
function debugToolIcon($key) {
    $icons = [
        'demo'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        'ticket' => '<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        'sso'    => '<path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line>',
        'key'    => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"></path>',
    ];
    $inner = $icons[$key] ?? '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
