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
 *
 * @wiki System
 */

/**
 * Every Debug Tool with its card and page metadata - id, slug, backend file,
 * title, category, description, search keywords, when to run it, what it checks.
 *
 * @return array<int,array<string,mixed>> Ordered list of debug tools. */
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
            'method'   => 'POST',   // it imports - the endpoint refuses a GET (csrfRequireWriteMethod)
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
            'method'   => 'POST',   // it deletes - the endpoint refuses a GET (csrfRequireWriteMethod)
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
                'Provider health + a live, secret-free OIDC discovery test — issuer match, protocol, and the ACTUAL published address of each endpoint (not merely present/absent), flagging any that is not an absolute URL or sits on a different host from the issuer',
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
        [
            'id'       => 'D006',
            'slug'     => 'd006',
            'file'     => 'D006_ssl_verify.php',
            'title'    => 'SSL / HTTPS verification check',
            'category' => 'Security',
            'icon'     => 'shield',
            'desc'     => 'Confirm the server can actually make certificate-verified HTTPS calls — and if not, tell you exactly how to fix it.',
            'keywords' => 'ssl tls https certificate verify cainfo cacert ca bundle openssl curl mailbox webhook ai man in the middle unable to get local issuer certificate d006',
            'when'     => 'Run this when outbound HTTPS misbehaves — a mailbox won\'t connect, an AI test fails, a webhook errors with "unable to get local issuer certificate" — or simply to confirm a fresh install is secure. It traces the whole chain: the global SSL_VERIFY_PEER switch, the php.ini CA settings, the shipped includes/cacert.pem, which bundle actually gets used, and a batch of LIVE certificate-verified requests to the real services the app talks to (Microsoft Graph, Anthropic, OpenAI, Google, Slack), ending in a plain-English verdict and the fix.',
            'checks'   => [
                'Environment — PHP version, OS, the libcurl build and its TLS backend, and which PHP (web vs CLI worker) this reflects',
                'The global switch — SSL_VERIFY_PEER (on/off/undefined) and whether SSL_CA_BUNDLE is defined',
                'Where sslApplyCurl() comes from — whether YOUR config.php still carries the SSL block (it is your file, so an upgrade never adds it) and whether the two OAuth callbacks load includes/ssl.php themselves instead of relying on it',
                'php.ini CA configuration — curl.cainfo and openssl.cafile: set or not, and crucially whether a path that IS set points at a file that actually exists',
                'The shipped bundle — includes/cacert.pem: present, readable, its size and certificate count (catching a truncated or HTML-error-page download)',
                'Which CA bundle actually wins, and why (configured php.ini bundle → shipped cert on Windows → the OS trust store on Linux)',
                'Live verification — a certificate-verified request to each real service the app calls, distinguishing a genuine certificate failure from a plain no-network SKIP',
                'A plain-English verdict: working, off, or failing — with the one-line fix (drop a cacert.pem into includes/)',
            ],
            'duration' => '~1–8 seconds (depends on how quickly the tested services answer)',
            'persists' => 'None. Read-only — it makes unauthenticated HEAD requests to public endpoints and writes nothing. Prints no secrets (no API keys, no request bodies) — only certificate/verification signals.',
        ],
        [
            'id'       => 'D007',
            'slug'     => 'd007',
            'file'     => 'D007_search_corpus.php',
            'title'    => 'Search corpus health',
            'category' => 'Database',
            'icon'     => 'database',
            'desc'     => 'Check that searching inside tickets can work — and catch the server settings that make words silently unfindable.',
            'keywords' => 'search corpus full text fulltext index search_documents match against token size stopword indexing attachments notes d007',
            'when'     => 'Run this when a search finds nothing it should have found, after adding the search feature to an installation, or simply to confirm a server is set up so that searching inside ticket content will work at all. Full-text search fails QUIETLY — MySQL has settings that decide which words it will even remember, and when one is wrong nothing errors, searches just come back empty. The worst is a low "longest word" limit, which makes a word like "authentication" unfindable while "printer" works perfectly. This tool looks at the whole chain in one place and ends with a plain-English verdict and the fix.',
            'checks'   => [
                'The corpus table exists, uses InnoDB, and has every column it should',
                'Its indexes — including the two FULL-TEXT ones searching actually depends on, and whether an index of the right NAME is the wrong KIND (the failure that finds nothing while looking fine)',
                'The link to tickets deletes a ticket\'s searchable copy along with the ticket — a privacy matter, not a tidiness one',
                'The MySQL settings that silently decide which words can be found: shortest word, longest word, and whether common words are ignored — each compared against the standard value, in plain English',
                'What the corpus currently holds, broken down by kind, and when it was last updated',
                'A LIVE search — writes one probe row, searches for a word that is in it and one that is not, then deletes it',
                'A plain-English verdict: all good, or a numbered list of problems each with what to do about it',
            ],
            'duration' => '~1 second',
            'persists' => 'Writes ONE probe row and deletes it again (removed in a finally block, and the tool reports if any was left behind). It has to write rather than use a rolled-back transaction, because MySQL does not let a search see rows that have not been committed. The row uses a reserved type that nothing else writes. Nothing else is modified.',
            'method'   => 'POST',   // it writes - the endpoint refuses a GET (csrfRequireWriteMethod)
        ],
        [
            'id'       => 'D008',
            'slug'     => 'd008',
            'file'     => 'D008_war_room.php',
            'title'    => 'War room health',
            'category' => 'Database',
            'icon'     => 'database',
            'desc'     => 'Confirm the war room will work before the day you need it — channels, delete rules, attachments and Warbot\'s lookups.',
            'keywords' => 'war room warroom warbot chat fallback outage teams slack channels dm direct message mentions attachments retention on call incident d008',
            'when'     => 'Run this on a quiet day. The war room is a BREAK-GLASS feature, and that changes what a health check is for: every other module tells you it is broken the moment you try to use it, but this one gets opened for the first time during an incident — when the usual chat is already down and nobody has any appetite for discovering that the attachments folder is unwritable or that the all-hands channel was never created. It checks the things that fail silently and ends in a plain-English verdict.',
            'checks'   => [
                'All seven war room tables exist, their engine, and how much is in each',
                'The foreign key delete rules that differ ON PURPOSE — above all that deleting an ANALYST leaves the conversation intact. A wrong rule here does nothing at all until somebody is deleted, at which point the record of an incident quietly goes with them and nobody finds out until the review',
                'Channels: the all-hands room exists and is not duplicated; how many team, custom and direct-message channels there are; and whether any pair of analysts has ended up with two DM threads, which would mean each is seeing half the conversation',
                'The attachments folder — exists, is writable, and carries both the Apache and the IIS protection file — plus files on disk with no database row, and rows whose file has gone missing',
                'The retention setting, and whether pruning is keeping up with it',
                'Warbot: which tools are registered, and a LIVE run of one of them. A tool whose query names a column that does not exist returns no rows rather than an error, so knowing it is registered proves nothing',
                'Whether an AI provider is configured — which is optional, and reported as neither good nor bad: without one Warbot still answers its slash commands, and those need no internet',
                'An end-to-end test: post a message, read it back, record presence — all inside a transaction that is then rolled back',
                'How many of the interface languages have a war room translation yet',
            ],
            'duration' => '~1 second',
            'persists' => 'Nothing. The end-to-end test runs inside a transaction and is rolled back, so nothing ever appears in somebody\'s open war room, and the tool checks afterwards that the probe really did disappear. (D007 cannot do this because a full-text search cannot see uncommitted rows; nothing here has that problem.) Everything else is read-only.',
        ],
        [
            'id'       => 'D009',
            'slug'     => 'd009',
            'file'     => 'D009_guarded_paths.php',
            'title'    => 'Guarded paths — are uploads really unreachable?',
            'category' => 'Security',
            'icon'     => 'shield',
            'desc'     => 'Check that files people upload cannot be fetched straight from their web address, by actually trying it.',
            'keywords' => 'security attachments uploads htaccess nginx apache allowoverride exposed public leak recordings branding lms rfp guarded deny 403 404 d009',
            'when'     => 'Run it after installing, after any web server change, and after moving host. This is the failure that announces itself least: a missing rewrite rule gives you a 404 within seconds, but a missing deny rule gives you a service desk that works perfectly and quietly publishes every attachment. Nothing looks wrong, nothing is logged, and the first sign of trouble is somebody finding a document they should never have seen. Especially important on nginx, which does not read .htaccess at all, and on Apache configured with AllowOverride None, where the .htaccess files are present and completely ignored.',
            'checks'   => [
                'A POSITIVE CONTROL first — that this tool can fetch something from its own site that definitely should be served. Without it, a firewall or an unreachable hostname would make every folder look beautifully protected, which is the most dangerous wrong answer available. If the control fails the tool reports INCONCLUSIVE and stops, rather than reporting all-clear',
                'Ticket, change and war room attachments, RFP uploads and screen recordings — each tested by writing a harmless probe file and asking the web server for it over HTTP, exactly as a stranger with the URL would',
                'Course content and branding uploads, which ARE served but must never execute — probed with a .php file',
                'The CSAT survey page, reachable only through its /csat address',
                'Whether a .git folder is exposed, which leaks your history and often your credentials. Present only if the install was deployed with git clone — and Apache does not block it either',
                'Which web server is answering, and what that implies: on nginx the protection comes only from the shipped config, on Apache only if AllowOverride permits .htaccess',
                'A plain-English verdict naming any folder that served the probe, what that means, and what to do about it',
            ],
            'duration' => '~2 seconds',
            'persists' => 'Nothing. It writes one small probe file per folder, fetches it, and deletes it again in a finally block — then counts what is left and tells you if any survived. The probes have unpredictable names and contain a single harmless line, because if a folder turns out to be unguarded the file is briefly fetchable, which is the very thing being measured. It reads .htaccess files nowhere: the question is not whether they exist, it is whether the server is reading them, and only a real HTTP request can answer that.',
        ],
        [
            'id'       => 'D010',
            'slug'     => 'd010',
            'file'     => 'D010_signin_methods.php',
            'title'    => 'Sign-in methods, and how you are signed in',
            'category' => 'Login',
            'icon'     => 'people',
            'desc'     => 'List every sign-in method and its state, what the login page will actually offer, and the route you personally used to get in.',
            'keywords' => 'sign in methods sso ldap oidc provider login local password locked out lockout break glass authentication who how signed in masked share d010',
            'when'     => 'Run this when sign-in is behaving unexpectedly, when you are unsure what a login page is offering people, or before changing anything on the Authentication screen. It is also the report to ask somebody else to send you: an administrator looking at that screen has, by definition, already signed in, so "nobody can get in" and "everything is fine" look identical from where they are sitting. Naming the route they personally used settles it. Every username, email address, bind account and DN is masked so the output can be sent on as-is.',
            'checks'   => [
                'Schema readiness — auth_providers and its columns, user_sso_identities, and whether this build even has the directory-sync columns',
                'HOW YOU ARE SIGNED IN — your session, your analyst record, whether a local password exists on it, and therefore which route you actually used',
                'The two master switches as STORED, not as rendered — including the important asymmetry that an absent local_login_enabled means ON',
                'What the login page will therefore offer a visitor: password form, provider buttons, or nothing at all — with the ?local=1 break-glass named if it is nothing',
                'Every sign-in method: protocol, enabled, company, JIT, host or issuer, encryption, bind account, base DN, group gating and attributes (secrets present/absent only)',
                'Per method, a plain CAN PEOPLE SIGN IN WITH IT NOW? line naming every blocker',
                'Counts of who is positioned to use each route — analysts and self-service users with local passwords, linked SSO identities, or imported from a directory',
                'A verdict that distinguishes a genuine lock-out from the perfectly normal "directory is here to import people, not to sign them in" setup',
            ],
            'duration' => '~1 second',
            'persists' => 'None. Read-only, makes no network calls, and writes nothing. Built to be shared: usernames, email local-parts, bind accounts and every DN value are masked (attribute names and the shape of the tree are kept, because that is the diagnostic half), and client secrets, bind passwords and password hashes are reported only as present or absent.',
        ],
        [
            'id'       => 'D011',
            'slug'     => 'd011',
            'file'     => 'D011_directory_sync_readiness.php',
            'title'    => 'Directory sync readiness — what is blocking each method',
            'category' => 'Login',
            'icon'     => 'sync',
            'desc'     => 'For every sign-in method, say whether it could import people from the directory — and if not, name the one thing in the way.',
            'keywords' => 'ldap active directory ad import sync users people readiness blocked ou base dn bind scope schedule cron enabled assets staff d011',
            'when'     => 'Run this when a directory will not import people, when the Importing people tab is missing or refuses to run, or when an import works by hand but nothing seems to happen on a schedule. It asks a different question of the same rows than D010 does: not "can anybody sign in with this" but "could this bring people in". The two are genuinely independent — a directory can exist purely as a source of staff to issue equipment to, with single sign-on switched off entirely, because nothing in the import path consults the global SSO switch.',
            'checks'   => [
                'Install-wide prerequisites that block every method at once — the PHP ldap extension, the sync columns on auth_providers, the run-history table, users.is_managed, and the importer files themselves (which is how an installation older than the feature identifies itself)',
                'How an import is actually triggered, by hand versus scheduled, and the fact that FreeITSM does not schedule it for you yet',
                'THE TRAP: running an import from the interface ignores the provider\'s Enabled flag but the scheduled run requires it, so a directory that imports perfectly when you press the button can be skipped every night with no error',
                'Per method, the gates in the order the code applies them: protocol is LDAP, host set, bind account (anonymous bind flagged, since AD almost always refuses to enumerate anonymously), directory sync switched on',
                'Whether an import scope resolves at all — ticked branches, then the import base DN, then the inherited sign-in base DN — and which of those three it came from',
                'Which attributes resolve for sign-in name, unique id and email, including the flavour defaults used when a field is left blank',
                'The safety settings that shape a run: conflict handling, how many missed runs deactivate somebody, and the percentage drop that trips the brake',
                'A single BLOCKER line per method naming the one thing to fix, rather than a list of everything that happens to be unset',
            ],
            'duration' => '~1 second',
            'persists' => 'None. Read-only, and deliberately does NOT contact the directory — this is a question about configuration, and a tool that hangs for thirty seconds on an unreachable host answers nothing useful. DNs, bind accounts and filters are masked so the report can be sent on.',
        ],
        [
            'id'       => 'D012',
            'slug'     => 'd012',
            'file'     => 'D012_ticket_attachments.php',
            'title'    => 'Ticket attachments — every file, end to end',
            'category' => 'Tickets',
            'icon'     => 'clip',
            'desc'     => 'Name a ticket and check every file on it: the row, the folder, the path, the bytes — and whether it would actually download.',
            'keywords' => 'attachment attachments document documents file files download missing storage recorded but missing note notes recording upload uploads htaccess permissions hash corrupt truncated 410 guard access denied leak tenancy ticket d012',
            'when'     => 'Run this when somebody reports that an attachment will not open — especially "That file is recorded but missing from storage", a download that returns nothing, or a file that used to work and now does not. Enter the ticket reference and it checks every file on that ticket, of every kind. It is also the fastest way to answer the question that usually comes first: WHICH attachment do they mean? A ticket carries files from four separate subsystems — email attachments, documents attached to the ticket, documents attached to a note, and screen recordings — and they share no table, no folder, no reader and no id sequence. The report names the subsystem and the exact URL on every line, so a reporter\'s screenshot can be matched to a row without guessing.',
            'input'    => ['name' => 'ref', 'label' => 'Ticket reference', 'placeholder' => 'e.g. TICKET-000107'],
            'checks'   => [
                'Resolves the ticket from its reference (ticket_number, or the raw id as a fallback) and says if it is in the trash',
                'Whether each of the four attachment subsystems even exists on this install — tables and columns — so an installation older than a feature identifies itself instead of just reporting nothing found',
                'The three storage folders: exist, readable, writable, how many files they hold, and whether the .htaccess and web.config guards are present AND actually contain a deny rule',
                'Every file on the ticket, listed with its subsystem, its database row, the value stored for it, and the exact endpoint URL that serves it',
                'Per file: the path resolved through the application\'s own resolver (not a copy of the rule), containment inside its folder, existence, readability, and the mode on disk',
                'Per file: every byte read through to the end, which is what catches a truncated or locked file that passes every earlier check',
                'Per file: length against the length recorded at upload, and SHA-256 against the hash stored at upload where there is one — so "present" is separated from "intact"',
                'Per file: what Content-Type and disposition the browser would actually receive, and what the leading bytes say the file really is',
                'Per file: a real download round trip — copied to the system temp folder, verified byte-for-byte, deleted, and the deletion confirmed',
                'For anything reported missing, WHY the check failed: genuinely absent, the path is a folder, a broken symlink, present under a different casing (a Windows-to-Linux move, which Linux cares about and Windows does not), a containing folder PHP may not read (where the file is present and the check still says no), or an open_basedir that excludes it',
                'The permission guards themselves — documentCanView() and analystCanAccessTicket() are called for real, and every refusal is paired with a positive control so a guard that refuses everybody cannot pass as a guard that works',
                'Whether the module gate, the company gate and the guessed-id gate each actually refuse, and whether the requester could fetch a note\'s file through the portal — which must be true for a shared note and false for an internal one',
                'A control that cannot run says so rather than passing: on a single-company install there is no second company to be refused, and reporting that as a pass would be a lie about the one thing the section exists to establish',
                'External-link documents and soft-deleted documents are reported as what they are rather than as missing files',
                'A verdict that names the case: all failures missing from one folder decided from the state of the FOLDER, not from this ticket\'s handful of files — an absent or empty folder reads as an upgrade that left the uploads behind, a folder still holding files reads as deletion',
            ],
            'duration' => '~1 second, plus a moment per megabyte attached',
            'persists' => 'None that survive the run. Read-only against the database and the application\'s files; the only write is one temp-folder copy per file, made outside the application, verified, then deleted — and the report says whether each deletion succeeded.',
        ],
        [
            'id'       => 'D013',
            'slug'     => 'd013',
            'file'     => 'D013_storage_persistence.php',
            'title'    => 'Storage persistence — what survives an update',
            'category' => 'Database',
            'icon'     => 'shield',
            'desc'     => 'Under Docker, check which folders holding your files are on storage that outlives a rebuild — and which are destroyed by the next update.',
            'keywords' => 'docker volume volumes container rebuild update upgrade storage persistence persist attachment attachments documents recordings lms branding uploads lost missing recorded but missing from storage encryption key data loss compose docker-compose d013',
            'when'     => 'Run this BEFORE you update, not after — and run it even when nothing is wrong. It is the only tool here that answers a question while it can still be acted on. Under Docker, updating rebuilds the container, and a rebuild discards everything inside it that is not on a volume. The database is on a volume and survives, so what is left afterwards is a complete set of correct records pointing at files that no longer exist, and attachments report "That file is recorded but missing from storage" — which reads as corruption rather than as a missing mount. Once that has happened it cannot be undone: the rebuild removes the old container and the files go with it, so there is nothing to copy back out. This tool names every folder that is exposed, tells you what you would lose from each, and gives you the volume lines to add and the order to do it in. On anything that is not a container it says so and stops, apart from one warning that still applies: a deploy that REPLACES the application folder, rather than pulling into it, removes the same directories.',
            'checks'   => [
                'Whether this server is a container at all (/.dockerenv), so a native WAMP, XAMPP or LAMP install gets a plain "not applicable" rather than an invented verdict',
                'Each of the directories the application writes user files into, checked against the same list .gitignore protects — email attachments, change attachments, documents and asset imports, screen recordings, LMS content, RFP uploads, branding images, war room files',
                'The encryption key directory, resolved from ENCRYPTION_KEY_PATH rather than assumed, and flagged separately because losing it does not corrupt mailbox passwords and credentials, it makes them permanently unreadable',
                'For each: whether it is on storage that outlives the container, decided by comparing its device against the ROOT filesystem rather than against its parent — a directory inside a bind-mounted parent is safe and the more obvious mount-point test would wrongly condemn it',
                'A directory that does not exist yet is reported as a future exposure rather than as an absence, because it will be created on the writable layer unless something is mounted over it',
                'Anything that cannot be determined is reported as unknown and excluded from the verdict rather than counted as a fault, so a failed stat() never raises a false alarm',
                'If anything is exposed: the exact commands to copy the files out FIRST — because adding a volume does not rescue what is already in the container, since Docker fills a new volume from the image rather than from the container it replaces — then the volume lines to add, then how to copy back and fix ownership',
            ],
            'duration' => 'Instant',
            'persists' => 'Nothing. It stats directories and reads no file contents, touches no database row, and writes nothing at all.',
        ],
        [
            // ⚠️ This key was MISSING, and had been since D014 shipped. The
            // landing page renders `htmlspecialchars($t['id'])` into every card's
            // badge, so its absence printed an "Undefined array key" warning and a
            // deprecation notice INTO the card itself, on a page every other tool
            // shares. Worth noting as a class: one malformed row in a registry
            // damages the page, not just its own entry.
            'id'       => 'D014',
            'slug'     => 'd014',
            'file'     => 'D014_ai_connections.php',
            'title'    => 'AI connections — what is sent and what comes back',
            'category' => 'Integrations',
            'icon'     => 'cpu',
            'desc'     => 'For any AI feature, on any provider: make the Test button\'s call and print the exact request FreeITSM sent and the provider\'s exact answer, with the key hidden.',
            'keywords' => 'ai provider anthropic openai openrouter azure foundry openai deployment endpoint api-version api version target uri api key test failed connection 401 404 resource not found DeploymentNotFound content filter max_completion_tokens temperature gpt-5 payload request response headers dev tools inspect d014 171',
            'inputs'   => [
                ['name' => 'ns', 'label' => 'Feature', 'type' => 'select', 'optional' => true, 'options' => debugToolAiFeatureOptions()],
            ],
            'when'     => 'Run this when an AI feature\'s Test button fails, and paste the output into the issue rather than describing the symptom. Browser developer tools cannot show this call, because FreeITSM\'s SERVER makes it - the browser only ever talks to FreeITSM, which is why no API key appears in its headers. This tool records the server\'s own exchange and prints it: the address, the headers, the body, and the provider\'s answer word for word, followed by a reading of what the failure most likely means. For Azure OpenAI it goes further on a 404 or 400, which is usually the API version: it tries the common alternatives and tells you which one this deployment accepts. Choose one feature, or leave it on every feature to test each distinct setup once.',
            'checks'   => [
                'Every AI feature on this install, its provider, its model or deployment, and whether a key is saved — so a feature quietly still on another provider is visible rather than assumed',
                'The setup exactly as saved, and for Azure what looks wrong before anything is sent: an API version that is really the model\'s version date (the GH #171 case), or an endpoint host Azure OpenAI does not normally use',
                'One live call per distinct setup — features sharing a provider, model and key are called once — with every request and answer printed in full: address, headers, body, HTTP status, time taken and the provider\'s own words',
                'Where a model refuses a setting by name (max_tokens, or a temperature newer models do not allow) and FreeITSM puts it right and asks again, both exchanges are shown so the correction is visible',
                'On failure, a reading of what it most likely means: a key from another resource, a deployment name that does not exist, an API version Azure does not recognise, the content filter, a rate limit, or a network problem that never reached the provider',
                'For a failing Azure deployment, the same call with each common API version (including the newer v1 route), and the one that works',
                'An empty answer from a successful call is named separately, because a model that reasons first can spend a small budget before writing anything',
            ],
            'duration' => 'A few seconds per setup. A failing Azure setup adds up to four more short calls.',
            'persists' => 'Nothing is written. It makes the same tiny call the Test button makes, which spends a handful of tokens, and a failing Azure setup a few more. The API key never appears - only its first four characters and its length - and no ticket content is sent: the only prompt is the fixed connection test.',
        ],
        [
            'id'       => 'D015',
            'slug'     => 'd015',
            'file'     => 'D015_carddav_health.php',
            'title'    => 'CardDAV health — can FreeITSM read and write this address book',
            'category' => 'Login',
            'icon'     => 'sync',
            'desc'     => 'Walk the connection to each address book one rung at a time: reach it, sign in, read the chosen book, and ask whether this account may write to it.',
            'keywords' => 'carddav address book contacts write back writeback permission read-only privilege baikal sabredav nextcloud owncloud vcard digest basic 401 etag source_ref cannot write d015',
            'when'     => 'Run this when contacts will not import, or when a change made in FreeITSM is not reaching the address book. Where D011 asks whether a directory is CONFIGURED to import, this asks whether an address book actually WORKS right now — and unlike D011 it does contact the server, because reachability is the whole question here rather than a distraction from it. The four rungs fail for entirely different reasons and an operator told only "it does not work" has four separate things to check, so each is reported on its own. It also names the mismatch that actually bites: write-back switched on while the account holds no write permission, which saves every change locally and has every one of them refused.',
            'checks'   => [
                'Install-wide prerequisites: the PHP curl extension, the two CardDAV files, and the three schema pieces write-back needs (which is how an install older than the feature identifies itself)',
                'Rung 1 — reach the server and sign in, reporting the HTTP status and WHICH authentication scheme the server actually offers, because a stock Baikal ships Digest and answers Basic with a flat 401 that reads exactly like a wrong password',
                'Rung 2 — read the chosen address book, and how many contacts, groups and tags are in it',
                'Rung 3 — whether this account may WRITE, asked of the server as a privilege query. 🔴 It writes nothing: proving it by creating a card and deleting it again risks leaving a contact called "FreeITSM test" in a real address book',
                'The mismatch worth naming on its own: write-back ON with no write permission, and the harmless opposite',
                'FreeITSM\'s own side — how many people this book manages, and how many are missing the card URL or version marker a write-back needs (contacts imported before write-back existed have neither until the next import)',
                'What the write log has actually recorded, per outcome, with the most recent attempt — and a note that "conflict" is the safety net working rather than a fault',
            ],
            'duration' => '~2 seconds per address book, including live calls to the server.',
            'persists' => 'Nothing. Read-only on both sides: it never writes to the address book and never changes a person here. The server address is masked to its shape and the account name to its first letter, so the report can be sent on; the password is reported only as present or absent.',
        ],
        [
            'id'       => 'D016',
            'slug'     => 'd016',
            'file'     => 'D016_carddav_drift.php',
            'title'    => 'CardDAV drift — where FreeITSM and the address book disagree',
            'category' => 'Login',
            'icon'     => 'sync',
            'desc'     => 'Compare every imported person with the card they came from, field by field, and report which details have drifted apart.',
            'keywords' => 'carddav drift compare difference diff reconcile out of sync mismatch contacts address book audit which fields change two-way d016',
            'when'     => 'Run this to answer "are the two lists still the same?" — before switching write-back on, after an import that looked wrong, or whenever somebody says a phone number is out of date and you need to know which side is stale. It is also the tool that measures something nobody could otherwise tell you: which details actually drift, and in which direction. That question was put to the person who asked for two-way sync in #133 and never answered, and this answers it from your own data rather than from anybody\'s recollection.',
            'checks'   => [
                'Every person imported from each address book, compared against their card as it stands right now, across the five details a vCard can hold',
                'Both values shown side by side for each disagreement, FreeITSM\'s first and the card\'s second, with an empty value named as empty rather than left as a confusing blank',
                'People whose card has GONE from the server — kept, not deleted, because FreeITSM never deletes anybody; the import marks them as left after the configured number of missed runs',
                'Contacts on the server that never became people here, which is usually correct rather than wrong — they are outside the group or tag chosen for import',
                'People missing the card URL a write-back needs, which is what an import from before write-back existed leaves behind',
                'A tally of WHICH details drift most, across everybody — the actual evidence for whether two-way sync is worth having, and for which fields',
                'What to do about each kind of disagreement, including the trap that a drifted value is exactly what makes a write-back get refused: import first, then edit',
            ],
            'duration' => 'Slower than the other tools — it reads every card in the book. Seconds for a few hundred contacts.',
            'persists' => 'Nothing, in either direction. It does not write to the address book and does not change anybody here, and it deliberately offers no "fix it" button: which side is right is a judgement a person has to make, and a one-click reconcile across hundreds of contacts is how a careful integration becomes a data-loss incident. ⚠️ Contact names and details DO appear in the output, so treat the report as personal data.',
        ],
        [
            'id'       => 'D017',
            'slug'     => 'd017',
            'file'     => 'D017_config_completeness.php',
            'title'    => 'Config completeness — is anything missing from config.php?',
            'category' => 'Installation',
            'icon'     => 'shield',
            'desc'     => 'Check your config.php against what this version expects, setting by setting, and get the exact line to add for anything missing.',
            'keywords' => 'config config.php db_config settings constants missing upgrade template base_url ssl_verify_peer ssl_ca_bundle encryption_key_path trust_proxy_https cannot redeclare undefined constant 129 d017',
            'when'     => 'Run this after an upgrade, after moving an install to a new server, or whenever a page fails with "undefined constant" or "Cannot redeclare". config.php is your own file - it is edited once and kept, so an upgrade never adds a line to it, and a copy made from an older version can lack a setting a newer version knows about. Most settings now have a safe built-in default, so a missing line usually changes nothing; this tool tells you which ones, and which ones you would want to set yourself.',
            'checks'   => [
                'Every setting this version knows about - whether it is needed, has a built-in default, or is optional - and whether your config.php sets it',
                'The database settings that have no default, which normally live in db_config.php outside the web root',
                'Constants in your config.php that this version does not know about (usually harmless leftovers)',
                'Functions declared inside config.php - the shape that caused GH #129, where an old copy of a function in config.php clashed with the app\'s own',
                'A plain-English verdict, with the exact line to add for anything you want to set',
            ],
            'duration' => 'Instant',
            'persists' => 'Nothing. Read-only - it reads config.php\'s text for the NAMES it defines and checks which settings exist. 🔒 It never prints a value: no password, no path, no address - names and yes/no only, so the report is safe to send on.',
        ],
        [
            'id'       => 'D018',
            'slug'     => 'd018',
            'file'     => 'D018_ticket_folder_counts.php',
            'title'    => 'Ticket folder counts — why do the numbers say 0?',
            'category' => 'Tickets',
            'icon'     => 'count',
            'desc'     => 'Follow every ticket through the filters behind the inbox\'s folder numbers, for one analyst, and see the step where they drop to zero.',
            'keywords' => 'ticket counts totals badge folder numbers zero 0 all tickets my tickets status department analyst team inbox folder list empty d018',
            'inputs'   => [
                ['name' => 'analyst', 'label' => 'Analyst', 'type' => 'select', 'optional' => true, 'options' => debugToolAnalystOptions()],
            ],
            'when'     => 'Run this when somebody\'s inbox folder list shows 0 on All Tickets, the statuses or the departments, while they know there are tickets. Choose the analyst who sees the wrong numbers. The usual cause is not the database but teams: someone in a team gets folders and counts only for that team\'s departments, plus tickets with no department and their own. Up to 3.6.1 a team linked to no department counted nothing at all, which is why "My tickets" could show a number while everything else said 0; 3.7.0 counts the tickets with no department, and this tool shows which departments are being left out and why.',
            'checks'   => [
                'The analyst: active, and allowed into the Tickets module',
                'Every table and column the counts read, with the optional ones (snooze, team grouping, companies) named separately because the counts work without them',
                'Every ticket, then each filter in turn - the Trash, merged tickets, snoozed tickets, the company in use, the departments their teams can see - with how many each step removes',
                'Their teams and the departments each team is linked to, naming a team linked to none, and how many tickets sit in departments they cannot see',
                'Every team on the install: active or not, its members, its departments and how many tickets those hold - so one run covers everybody, not just the analyst chosen',
                'The loose ends: departments no team is linked to, analysts in no team, and team or department links left pointing at something deleted',
                'Statuses: which are active, and tickets with no status, a deleted status or an inactive one, which count in the total but in no status folder',
                'When you run it for yourself, the real counts endpoint is called too, and its numbers printed beside the step-by-step ones',
                'A plain-English verdict with the fix for anything found',
            ],
            'duration' => 'Instant',
            'persists' => 'Nothing. Read-only - it counts tickets and reads table structure. It shows team, department and status names and ticket counts, never ticket content.',
        ],
        [
            // GH #222: the Assets page shipped dead in 4.3.0 - one comment swallowed the
            // code after it, so the page's whole script failed to parse, and nothing
            // that ran on the server could see it. This one runs in the BROWSER.
            'id'       => 'D019',
            'slug'     => 'd019',
            'file'     => 'D019_page_health.php',
            'runner'   => 'page-health',
            'title'    => 'Page health — does every page\'s script load?',
            'category' => 'System',
            'icon'     => 'shield',
            'desc'     => 'Open every page in FreeITSM and check that each one\'s JavaScript can be read by the browser - the fault that left a page stuck on its spinner after an upgrade.',
            'keywords' => 'page health pages javascript script syntax error spinner blank page never loads broken page upgrade missing ) after argument list console check every page d019',
            'when'     => 'Run this after every upgrade, alongside Database Verification - and whenever a page shows its loading spinner forever, a blank area, or buttons that do nothing. If a page\'s script contains one mistake the browser cannot read, the browser runs none of that script: the page looks loaded but nothing on it works. The server cannot see this - the page is sent perfectly well - so only your browser can check it. Paste the report into a bug report as it is.',
            'checks'   => [
                'Every page an analyst can open - found by looking at the files, so a page added in an update is checked the day it arrives',
                'Each page is fetched as you, the way your browser would open it, and every script on it - written into the page or loaded from a file - is read by your browser without being run',
                'A script the browser cannot read is reported with its page, the line, the browser\'s own message and the code on that line',
                'A script file the page asks for that is not there, and a page the server answers with an error (HTTP 500, 404) are reported as problems too',
                'Pages of modules switched off in System -> Modules are skipped, as are pages that send you elsewhere when opened without a record to show (they say why)',
                'A summary per module, and a report to copy or download',
            ],
            'duration' => 'About a minute - every page is fetched, four at a time',
            'persists' => 'Only the result: the date, who ran it and the counts are kept so Database Verification can show when the pages were last checked. No page\'s script is run - each is read by the browser and put aside. Opening a page does whatever opening it normally does on the server, exactly as if you had clicked to it.',
        ],
    ];
}

/**
 * Find one tool by its slug (e.g. 'd001'). Returns null if not found.
 *
 * @param mixed $slug  the tool's slug, e.g. 'd001', as the tool page sets it
 */
function getDebugToolBySlug($slug) {
    foreach (getDebugTools() as $tool) {
        if ($tool['slug'] === $slug) return $tool;
    }
    return null;
}

/**
 * D018's analyst picker: "you" first, then every active analyst. An empty list
 * (database unreachable) still leaves "you", so the page always renders.
 */
function debugToolAnalystOptions(): array {
    $opts = [['value' => '', 'label' => 'You']];
    try {
        require_once __DIR__ . '/../../../includes/functions.php';
        $rows = connectToDatabase()->query("SELECT id, full_name FROM analysts WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $opts[] = ['value' => (string)$r['id'], 'label' => $r['full_name']];
    } catch (Throwable) {
    }
    return $opts;
}

/** D014's feature picker: every AI feature, plus "all" (GH #171). */
function debugToolAiFeatureOptions(): array {
    require_once __DIR__ . '/../../../includes/ai_settings.php';
    $opts = [['value' => '', 'label' => 'Every feature (each distinct setup once)']];
    foreach (aiSettingsRegistry() as $ns => $entry) {
        $opts[] = ['value' => $ns, 'label' => $entry['label']];
    }
    return $opts;
}

/**
 * Inline SVG markup for a debug-tool icon key. Unknown keys render a neutral
 * wrench so a typo never breaks the page. Sizing/colour come from CSS.
 *
 * @param mixed $key  the icon key from a tool's entry, e.g. 'ticket', 'sso'; unknown keys draw a wrench
 */
function debugToolIcon($key) {
    $icons = [
        'demo'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        'ticket' => '<polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line>',
        'sso'    => '<path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line>',
        'key'    => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"></path>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline>',
        'people' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'sync'   => '<polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>',
        'count'  => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
        'clip'   => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>',
    ];
    $inner = $icons[$key] ?? '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
