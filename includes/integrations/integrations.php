<?php
/**
 * Integrations service — loading connections, escalating a work item to an
 * external tracker, and refreshing the status of what we have already linked.
 *
 * This is the ONE place an escalation happens. The workflow action
 * (escalate_to_tracker) and the manual right-click both call
 * integrationsEscalate(); there is deliberately not a second implementation for
 * the "automatic" path, because the guards below would then exist twice and one
 * copy would eventually drift.
 *
 * ⚠️ SECURITY: integrationsCompaniesCompatible() is an isolation boundary, not a
 * validation nicety. Read its docblock before changing anything here.
 */

require_once __DIR__ . '/IssueTrackerProvider.php';
require_once __DIR__ . '/IssueDoc.php';
require_once __DIR__ . '/JiraProvider.php';
require_once __DIR__ . '/AzureDevOpsProvider.php';

// ⚠️ Self-contained on purpose. These were originally left to the caller, which
// worked only because the first callers (the settings endpoints) happened to
// require them already — the workflow engine does not, and the escalate action
// fataled on decryptValue() the first time it was actually run. A shared service
// cannot assume anything about who called it.
require_once __DIR__ . '/../encryption.php';   // decryptValue()
require_once __DIR__ . '/../ssl.php';          // sslApplyCurl(), used by every provider's httpRequest()
require_once __DIR__ . '/../tenancy.php';      // getDefaultTenantId(), for the company guard
require_once __DIR__ . '/../public_url.php';   // publicAbsoluteUrl(), behind integrationsAbsoluteUrl()

/**
 * Are the integration tables present?
 *
 * Every read below goes through this. An install that has not run Database
 * Verification yet has no tables, and an unguarded query would throw inside the
 * ticket view — which is how a missing gate once produced an empty inbox.
 * "Not set up" must look like "no linked issues", never like an error.
 */
function integrationsSchemaReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query("SELECT 1 FROM integration_connections LIMIT 1");
        $conn->query("SELECT 1 FROM integration_links LIMIT 1");
        return $ready = true;
    } catch (Exception) {
        return $ready = false;
    }
}

/**
 * The provider registry — the ONE list adding a tracker touches at this layer.
 *
 * `credential_fields` drives the settings form, so a provider whose auth looks
 * nothing like Jira's needs no change to the page: it declares its own fields and
 * the form renders them. That is the difference between "adding GitHub is one
 * file" and "adding GitHub means editing the settings screen too".
 *
 * `url_hint` / `url_label` exist because "Site URL" means something different per
 * tracker and a wrong-shaped URL is the most likely setup mistake.
 *
 * `settings_fields` (optional) is for a NON-SECRET per-connection choice, and it
 * is deliberately a separate list from `credential_fields` rather than a `type`
 * on one. The two behave in opposite ways on an edit:
 *
 *   credential_fields — blanked when the form opens, and an empty box means
 *                       "keep the stored secret". Correct for a token; nobody
 *                       should be shown one they already saved.
 *   settings_fields   — shown WITH the current value and always submitted.
 *
 * Putting a dropdown in credential_fields would inherit the blanking rule, so
 * every save would quietly reset the choice back to its default — a silent
 * behaviour change nobody would connect to having pressed Save.
 */
function integrationsAvailableProviders(): array
{
    return [
        'jira' => [
            'key'         => 'jira',
            'kind'        => 'tracker',
            'name'        => 'Jira',
            'blurb'       => 'system.integrations.jira_blurb',
            'url_label'   => 'system.integrations.jira_url_label',
            'url_hint'    => 'https://yourcompany.atlassian.net',
            'credential_fields' => [
                // Cloud wants email + API token; Data Center wants only a PAT. Both
                // are offered and the connector uses whichever its flavour needs.
                ['key' => 'email',     'label' => 'system.integrations.field_email',  'type' => 'text',     'required' => false],
                ['key' => 'api_token', 'label' => 'system.integrations.field_token',  'type' => 'password', 'required' => true],
            ],
        ],
        'azuredevops' => [
            'key'         => 'azuredevops',
            'kind'        => 'tracker',
            'name'        => 'Azure DevOps',
            'blurb'       => 'system.integrations.azuredevops_blurb',
            'url_label'   => 'system.integrations.azuredevops_url_label',
            'url_hint'    => 'https://dev.azure.com/yourorg',
            'credential_fields' => [
                // A Personal Access Token, sent as HTTP Basic with an EMPTY
                // username. There is no email half: unlike Jira Cloud, Azure
                // DevOps ignores the username entirely.
                //
                // ⚠️ Its own label, not the shared 'field_token'. Azure DevOps has
                // no "API token" — somebody hunting for one will not find it,
                // because it is a *personal access token* in a different menu.
                // The stored key stays `api_token` so nothing downstream changes.
                ['key' => 'api_token', 'label' => 'system.integrations.field_pat', 'type' => 'password', 'required' => true],
            ],
            // See settings_fields' note above: not a secret, so it is shown with
            // its current value and never blanked.
            'settings_fields' => [
                [
                    'key'     => 'resolved_means',
                    'label'   => 'system.integrations.field_resolved_means',
                    'hint'    => 'system.integrations.field_resolved_means_hint',
                    'type'    => 'select',
                    'default' => 'in_progress',
                    'options' => [
                        ['value' => 'in_progress', 'label' => 'system.integrations.resolved_in_progress'],
                        ['value' => 'done',        'label' => 'system.integrations.resolved_done'],
                    ],
                ],
            ],
        ],

        // ── Slack ────────────────────────────────────────────────────────────
        // ⚠️ Listed here because this is where a user looks for "integrations",
        // but it is NOT a tracker and shares none of that engine: Slack has no
        // work items and no states. It is a messaging CHANNEL — the same engine
        // as WhatsApp and web chat — so it stores its connections in
        // `messaging_channels`, not `integration_connections`, and it has its own
        // page rather than the registry-rendered credential form.
        //
        // Anything reading this registry must therefore check `kind` before
        // assuming a provider can be escalated to. `integrationsTrackerProviders()`
        // below is the safe list for that.
        // Document text extraction (discussion #53, tier 2). Not a tracker and
        // not a messaging channel: there is one endpoint, no credentials, and
        // nothing is ever sent TO it except a file to read.
        //
        // It lives here rather than under System → Search because it is a named
        // third-party product you point at with a URL, which is what this screen
        // is for. The *search backend* selector is a different question and
        // deliberately does not live here — see the wiki's Full-Text-Search §8.4.
        'tika' => [
            'key'         => 'tika',
            'kind'        => 'extractor',
            'name'        => 'Apache Tika',
            'blurb'       => 'system.integrations.tika_blurb',
            'page'        => 'tika.php',   // its own page; see provider.php
            'credential_fields' => [],
        ],
        // Zabbix — monitoring problems become incidents (contributed by Carl
        // Steynvaard of Open Resilience, Oct 2026). A "monitoring" kind: nothing
        // is sent TO it, it calls in; one token, so it reads as a state like Tika.
        'zabbix' => [
            'key'         => 'zabbix',
            'kind'        => 'monitoring',
            'name'        => 'Zabbix',
            'blurb'       => 'system.integrations.zabbix_blurb',
            'page'        => 'zabbix.php',   // its own page; see provider.php
            'credential_fields' => [],
        ],
        // 3CX — the phone system (contributed by Carl Steynvaard of Open
        // Resilience, Oct 2026). A "telephony" kind: like Zabbix it calls in,
        // with one token, so it reads as a state rather than a count.
        'threecx' => [
            'key'         => 'threecx',
            'kind'        => 'telephony',
            'name'        => '3CX',
            'blurb'       => 'system.integrations.threecx_blurb',
            'page'        => 'threecx.php',   // its own page; see provider.php
            'credential_fields' => [],
        ],
        // Microsoft Teams channel alerts (contributed by Carl Steynvaard of
        // Open Resilience, Oct 2026). An "alerts" kind: it only posts, and each
        // channel is one connection, so the card counts them. The Teams chat
        // BOT is a messaging channel under Tickets → Settings → Messaging.
        'msteams' => [
            'key'         => 'msteams',
            'kind'        => 'alerts',
            'name'        => 'Microsoft Teams',
            'blurb'       => 'system.integrations.msteams_blurb',
            'page'        => 'msteams.php',   // its own page; see provider.php
            'credential_fields' => [],
        ],
        'slack' => [
            'key'         => 'slack',
            'kind'        => 'messaging',
            'name'        => 'Slack',
            'blurb'       => 'system.integrations.slack_blurb',
            'page'        => 'slack.php',   // its own page; see provider.php
            'credential_fields' => [],      // rendered by that page, not the registry
        ],
    ];
}

/**
 * Providers that are genuinely issue trackers — the ones an escalation, a
 * "raise an issue" button or a workflow action can target.
 *
 * Use this rather than integrationsAvailableProviders() anywhere the answer
 * "can we push a ticket into it?" matters, or Slack will be offered as somewhere
 * to raise a work item and fail at the moment somebody tries.
 */
function integrationsTrackerProviders(): array
{
    return array_filter(
        integrationsAvailableProviders(),
        function ($meta) { return ($meta['kind'] ?? 'tracker') === 'tracker'; }
    );
}

/**
 * An ABSOLUTE url for a path in this install, for links we send OUT.
 *
 * ⚠️ `BASE_URL` is deliberately a path — `/` or `/freeitsm-app/` — because every
 * internal page link wants that. Put it in a Jira issue or an Azure DevOps work
 * item and the developer gets `/tickets/?id=409`, which resolves against
 * *their* tracker's host and 404s. The whole reason we put the link there is so
 * somebody can click back to the ticket, so it has never done its job.
 *
 * The resolution itself now lives in includes/public_url.php, because the
 * [ticket_url] merge code needs the identical answer (discussion #80) and two
 * copies of "how is this install addressed" is exactly the pair that drifts.
 * This name is kept: it is what every provider calls, and it reads correctly at
 * those call sites.
 *
 * @param string $path  a path inside this install, e.g. 'tickets/?id=409' - a leading slash is optional
 */
function integrationsAbsoluteUrl(PDO $conn, string $path): string
{
    return publicAbsoluteUrl($conn, $path);
}

/**
 * The non-secret setting keys a provider declares, for use as a whitelist.
 *
 * ⚠️ This is what keeps `settings` safe to hand to the browser. The values live
 * in the same encrypted credentials blob as the API token, so anything reading
 * them out for display MUST filter by this list rather than emitting what it
 * finds — see integrationsListConnections().
 *
 * @param string $providerKey  the provider key from integrationsAvailableProviders(), e.g. 'jira' or 'azuredevops'
 * @return array key => default value
 */
function integrationsSettingKeys(string $providerKey): array
{
    $meta = integrationsProviderMeta($providerKey);
    $out  = [];
    foreach ((array)($meta['settings_fields'] ?? []) as $f) {
        if (!empty($f['key'])) $out[$f['key']] = $f['default'] ?? '';
    }
    return $out;
}

/**
 * One registry entry, or null if the key is not a provider we ship.
 *
 * @param string $key  the provider key, e.g. 'jira' or 'azuredevops'
 */
function integrationsProviderMeta(string $key): ?array
{
    $all = integrationsAvailableProviders();
    return $all[$key] ?? null;
}

/**
 * An email body reduced to text worth putting in an issue description.
 *
 * ⚠️ `strip_tags()` alone is NOT enough, and this is not theoretical — the first
 * real ticket tried produced a Jira description containing several hundred lines
 * of the email's CSS. strip_tags removes the TAGS but keeps the CONTENT of
 * <style> and <script>, so a marketing-styled email dumps its entire stylesheet
 * into the description.
 *
 * So: kill those elements outright, turn block ends into newlines so paragraphs
 * survive, then collapse the whitespace an HTML mail is full of (&nbsp; runs,
 * tabs, CRLF pairs).
 *
 * $maxChars is a sanity cap, not a policy — a 200KB newsletter helps nobody, and
 * a dev reading the issue wants the request, not the footer. The link back to
 * the ticket is always there for the full thread.
 *
 * @param ?string $raw  the email body as stored; null or '' gives ''
 * @param string $bodyType  'html' or 'text'; a body containing <html or <div is treated as HTML whatever this says
 * @param int $maxChars  the most characters kept before a "truncated" note is appended
 */
function integrationsBodyToText(?string $raw, string $bodyType = 'text', int $maxChars = 8000): string
{
    $s = (string) $raw;
    if ($s === '') return '';

    if (strtolower($bodyType) === 'html' || stripos($s, '<html') !== false || stripos($s, '<div') !== false) {
        // Content-bearing elements that must go entirely, not just lose their tags.
        $s = preg_replace('#<(style|script|head|title)\b[^>]*>.*?</\1>#is', ' ', $s);
        // Block boundaries become newlines so the text does not run together.
        $s = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // NBSP (both the entity-decoded char and the raw byte) reads as a space.
    $s = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $s);
    $s = preg_replace('/[ \t]+/u', ' ', $s);          // runs of spaces
    $s = preg_replace('/ *\n */u', "\n", $s);         // trim around newlines
    $s = preg_replace('/\n{3,}/u', "\n\n", $s);       // never more than one blank line
    $s = trim($s);

    if (mb_strlen($s) > $maxChars) {
        $s = mb_substr($s, 0, $maxChars) . "\n\n… (truncated — see the ticket for the full thread)";
    }
    return $s;
}

/**
 * provider string → concrete connector.
 *
 * @param array $connection  a connection row as loaded by integrationsLoadConnection(); its 'provider' picks the class
 */
function integrationsProviderFor(array $connection): IssueTrackerProvider
{
    switch ($connection['provider'] ?? '') {
        case 'jira':
            return new JiraProvider($connection);
        case 'azuredevops':
            return new AzureDevOpsProvider($connection);
        default:
            throw new Exception('Unknown integration provider: ' . ($connection['provider'] ?? '?'));
    }
}

/**
 * Decrypt + JSON-decode a stored credentials blob (never throws).
 *
 * @param mixed $stored  the encrypted credentials column as stored; null or '' gives []
 */
function integrationsDecodeCredentials($stored): array
{
    if ($stored === null || $stored === '') return [];
    try {
        $plain = decryptValue($stored);
    } catch (Exception) {
        return [];
    }
    $decoded = json_decode((string) $plain, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Load a connection with its secrets decrypted. Returns null if missing or if
 * the schema is not there yet.
 *
 * @param mixed $connectionId  the integration_connections id (cast to int)
 */
function integrationsLoadConnection(PDO $conn, $connectionId): ?array
{
    if (!integrationsSchemaReady($conn)) return null;
    try {
        $stmt = $conn->prepare("SELECT * FROM integration_connections WHERE id = ?");
        $stmt->execute([(int) $connectionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception) {
        return null;
    }
    if (!$row) return null;

    $row['credentials'] = integrationsDecodeCredentials($row['credentials'] ?? null);
    // decryptValue returns the value unchanged without the ENC: prefix, so
    // pre-encryption rows still work (migration-safe).
    if (!empty($row['webhook_secret'])) {
        try { $row['webhook_secret'] = decryptValue($row['webhook_secret']); } catch (Exception) { /* leave */ }
    }
    $row['is_active'] = (bool) ($row['is_active'] ?? 1);
    return $row;
}

/**
 * List connections for the admin UI.
 *
 * ⚠️ DELIBERATELY UNFILTERED by company. This is a CONNECTION-shaped table (the
 * same shape as messaging_channels and mailboxes), where tenant_id NULL means
 * SHARED across every company rather than "the Default company's". An admin
 * configuring routing needs to see every connection at once. Scoping this with
 * activeTenantFilter() would hide every shared connection from every client
 * company — see Multi-Tenancy-Developer-Guide §1 for all three meanings of NULL.
 *
 * ⚠️ Secrets are NEVER returned. `has_credentials` is a boolean and that is all
 * the UI gets. A read that hands back credentials needs the same care as a
 * write, and an unfiltered list that leaked tokens would be a cross-company
 * credential leak rather than a convenience.
 *
 * @param bool $activeOnly  true to leave out connections switched off (is_active = 0)
 */
function integrationsListConnections(PDO $conn, bool $activeOnly = false): array
{
    if (!integrationsSchemaReady($conn)) return [];
    // `credentials` is selected but NEVER returned — only the handful of
    // non-secret setting keys the provider declares are lifted out of it below.
    $sql = "SELECT id, name, provider, base_url, auth_type, ingress_mode, inbound_enabled, send_attachments,
                   poll_interval_minutes, account_identity, tenant_id, is_active,
                   last_poll_datetime, created_datetime, credentials,
                   (credentials IS NOT NULL AND credentials <> '') AS has_credentials,
                   (webhook_secret IS NOT NULL AND webhook_secret <> '') AS has_webhook_secret
            FROM integration_connections";
    if ($activeOnly) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY name";
    try {
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception) {
        return [];
    }
    foreach ($rows as &$r) {
        $r['has_credentials']     = (bool) $r['has_credentials'];
        $r['has_webhook_secret']  = (bool) $r['has_webhook_secret'];
        $r['is_active']           = (bool) $r['is_active'];
        $r['inbound_enabled']     = (bool) $r['inbound_enabled'];
        $r['send_attachments']    = (bool) $r['send_attachments'];

        // ⚠️ Non-secret settings share the encrypted blob with the API token, so
        // this filters by the provider's declared keys rather than emitting what
        // it finds. Anything not on that list — the token above all — never
        // leaves this function. The blob itself is dropped immediately after.
        $stored   = integrationsDecodeCredentials($r['credentials'] ?? null);
        $settings = [];
        foreach (integrationsSettingKeys((string)$r['provider']) as $key => $default) {
            $settings[$key] = array_key_exists($key, $stored) && is_scalar($stored[$key])
                ? (string) $stored[$key]
                : $default;
        }
        $r['settings'] = $settings;
        unset($r['credentials']);
    }
    return $rows;
}

/**
 * ⚠️ THE ISOLATION BOUNDARY. Read this before touching it.
 *
 * May a work item belonging to company $entityTenantId be escalated into
 * $connectionTenantId's tracker?
 *
 *   connection tenant NULL  → SHARED: serves every company. Anything may go there
 *                             (an MSP's own Jira, used for all their clients).
 *   connection tenant set   → PINNED: only that company's work items.
 *
 * A NULL work item means "unrouted, treated as the Default company's", so it is
 * resolved to the Default company id before comparing — otherwise a Default
 * ticket could never reach a connection pinned to Default.
 *
 * Why this exists: escalation is driven by a WORKFLOW, and a workflow's
 * conditions are editable by anyone who can author workflows. The wiki is blunt
 * that company routing must never be a workflow rule
 * (Multi-Tenancy-Developer-Guide §1 — "a hardcoded synchronous membrane"). This
 * is the outbound twin of that rule: without it, one mis-scoped workflow
 * escalates Acme's ticket content into Globex's Jira. So the check lives HERE,
 * in code, and never in anything an admin can edit.
 *
 * On a single-company install every call is trivially true, which is correct
 * rather than a bypass — there is only one company for anything to leak to.
 *
 * @param ?int $entityTenantId  the work item's company; null means the Default company
 * @param ?int $connectionTenantId  the connection's company; null means shared by every company
 * @param ?int $defaultTenantId  the Default company's id, used in place of a null $entityTenantId
 */
function integrationsCompaniesCompatible(?int $entityTenantId, ?int $connectionTenantId, ?int $defaultTenantId = null): bool
{
    if ($connectionTenantId === null) {
        return true;                       // shared connection: serves everyone
    }
    $effective = $entityTenantId ?? $defaultTenantId;   // NULL work item = the Default company's
    if ($effective === null) {
        return false;                      // unknown owner + pinned target = refuse, never guess
    }
    return $effective === $connectionTenantId;
}

/**
 * The tenant_id of a ticket / problem / change, or null.
 *
 * @param string $entityType  'ticket', 'problem' or 'change'; anything else gives null
 * @param int $entityId  the id of that work item
 */
function integrationsEntityTenantId(PDO $conn, string $entityType, int $entityId): ?int
{
    $table = [
        'ticket'  => 'tickets',
        'problem' => 'problem_tickets',
        'change'  => 'change_tickets',
    ][$entityType] ?? null;
    if ($table === null) return null;
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM `$table` WHERE id = ?");
        $stmt->execute([$entityId]);
        $v = $stmt->fetchColumn();
    } catch (Exception) {
        return null;
    }
    return ($v === false || $v === null) ? null : (int) $v;
}

/**
 * Links for one work item, newest first. Empty when the schema is not ready.
 *
 * @param string $entityType  'ticket', 'problem' or 'change'
 * @param int $entityId  the id of that work item
 */
function integrationsLinksFor(PDO $conn, string $entityType, int $entityId): array
{
    if (!integrationsSchemaReady($conn)) return [];
    try {
        $stmt = $conn->prepare(
            "SELECT l.*, c.name AS connection_name, c.provider, c.is_active AS connection_active
             FROM integration_links l
             JOIN integration_connections c ON c.id = l.connection_id
             WHERE l.entity_type = ? AND l.entity_id = ?
             ORDER BY l.created_datetime DESC"
        );
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception) {
        return [];
    }
}

/**
 * Is this work item already linked to this connection? (drives skip_if_linked)
 *
 * @param int $connectionId  the integration_connections id
 * @param string $entityType  'ticket', 'problem' or 'change'
 * @param int $entityId  the id of that work item
 */
function integrationsAlreadyLinked(PDO $conn, int $connectionId, string $entityType, int $entityId): bool
{
    if (!integrationsSchemaReady($conn)) return false;
    try {
        $stmt = $conn->prepare(
            "SELECT 1 FROM integration_links
             WHERE connection_id = ? AND entity_type = ? AND entity_id = ? LIMIT 1"
        );
        $stmt->execute([$connectionId, $entityType, $entityId]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception) {
        return false;
    }
}

/**
 * Escalate a work item into an external tracker: create the issue, record the
 * link, return it.
 *
 * The single entry point for BOTH the workflow action and the manual button.
 *
 * @param array $opts  ['entity_type'=>'ticket', 'entity_id'=>1042,
 *                      'connection_id'=>3, 'target'=>['project'=>'OPS','issue_type'=>'Bug'],
 *                      'summary'=>'…', 'body'=>IssueDoc, 'analyst_id'=>7,
 *                      'skip_if_linked'=>true, 'dry_run'=>false]
 * @return array the created link row, or ['skipped'=>true, …] / ['dry_run'=>true, …]
 */
function integrationsEscalate(PDO $conn, array $opts): array
{
    $entityType = (string)($opts['entity_type'] ?? 'ticket');
    $entityId   = (int)($opts['entity_id'] ?? 0);
    $connId     = (int)($opts['connection_id'] ?? 0);
    $body       = $opts['body'] ?? null;

    if (!integrationsSchemaReady($conn)) {
        throw new Exception('Integrations are not set up on this install yet — run Database Verification.');
    }
    if ($entityId <= 0 || $connId <= 0) {
        throw new Exception('Escalation needs a work item and a connection.');
    }
    if (!$body instanceof IssueDoc) {
        throw new Exception('Escalation needs an IssueDoc body.');
    }

    $connection = integrationsLoadConnection($conn, $connId);
    if (!$connection)                 throw new Exception('That integration connection no longer exists.');
    if (empty($connection['is_active'])) throw new Exception('That integration connection is switched off.');

    // ── The isolation boundary. Before any network call, before any write. ──
    $entityTenant  = integrationsEntityTenantId($conn, $entityType, $entityId);
    $connTenant    = isset($connection['tenant_id']) && $connection['tenant_id'] !== null
                        ? (int) $connection['tenant_id'] : null;
    $defaultTenant = function_exists('getDefaultTenantId') ? @getDefaultTenantId($conn) : null;
    if (!integrationsCompaniesCompatible($entityTenant, $connTenant, $defaultTenant)) {
        throw new Exception(
            'That tracker belongs to a different company than this ticket, so it cannot be escalated there.'
        );
    }

    if (!empty($opts['skip_if_linked'])
        && integrationsAlreadyLinked($conn, $connId, $entityType, $entityId)) {
        return ['skipped' => true, 'reason' => 'already_linked'];
    }

    $provider = integrationsProviderFor($connection);
    $summary  = (string)($opts['summary'] ?? '');
    $target   = (array)($opts['target'] ?? []);
    $fields   = (array)($opts['fields'] ?? []);

    // ── Mapping (V3). What the caller supplied always wins; mapping only fills
    // the gaps, so an explicit project on a workflow rule is never overridden by
    // a routing rule somebody added later.
    $maps = integrationsLoadMaps($conn, $connId);
    if ($maps) {
        $routing = integrationsEntityRouting($conn, $entityType, $entityId);

        if (trim((string)($target['project'] ?? '')) === '') {
            $mapped = integrationsResolveProject($maps, $routing['tenant_id'], $routing['department_id']);
            if ($mapped !== null) $target['project'] = $mapped;
        }
        if (trim((string)($target['issue_type'] ?? '')) === '') {
            $mapped = integrationsResolveIssueType($maps, $routing['ticket_type_id']);
            if ($mapped !== null) $target['issue_type'] = $mapped;
        }
        // Priority is a FIELD, not part of the target — V1 deliberately sent none
        // and it travelled as text in the description. It is only sent once an
        // admin has stated what our High means in their Jira.
        if (!isset($fields['priority'])) {
            $mapped = integrationsResolvePriority($maps, $routing['priority_id']);
            if ($mapped !== null) $fields['priority'] = ['name' => $mapped];
        }
    }

    // Dry run describes, never creates. A workflow test that mints a real Jira
    // issue is an unacceptable surprise, so this returns BEFORE the network call.
    if (!empty($opts['dry_run'])) {
        // ⚠️ The preview lists the FILES too. "You cannot unsend it" applies
        // doubly to an attachment: a screenshot may hold a password, a customer
        // name, a whole spreadsheet nobody meant to send outside. An analyst
        // must see exactly what is about to leave, not just the text.
        $files = [];
        if (!empty($connection['send_attachments']) && $entityType === 'ticket') {
            foreach (integrationsTicketAttachments($conn, $entityId) as $a) {
                $files[] = [
                    'filename'    => $a['filename'],
                    'size'        => $a['size'],
                    'size_human'  => integrationsFormatBytes($a['size']),
                    'skip_reason' => $a['skip_reason'],
                ];
            }
        }
        return [
            'dry_run'     => true,
            'connection'  => $connection['name'],
            'target'      => $target,
            'summary'     => $summary,
            'preview'     => $body->toPlainText(),
            'attachments' => $files,
        ];
    }

    // ⚠️ A REJECTED PRIORITY MUST NOT LOSE THE ESCALATION.
    //
    // Jira priorities are defined per project, so a project whose scheme renamed
    // "Highest" to "P1" rejects our mapped value and 400s the whole create. The
    // rule, decided when mapping was designed: losing a priority is cosmetic;
    // losing the escalation because somebody renamed a priority on one project
    // is not. So retry once without it and record why on the link.
    $priorityDropped = false;
    try {
        $issue = $provider->createIssue($target, $summary, $body, $fields);
    } catch (Exception $e) {
        if (!isset($fields['priority']) || !integrationsLooksLikePriorityRejection($e->getMessage())) {
            throw $e;
        }
        unset($fields['priority']);
        $priorityDropped = $e->getMessage();
        $issue = $provider->createIssue($target, $summary, $body, $fields);
    }

    $stmt = $conn->prepare(
        "INSERT INTO integration_links
            (connection_id, entity_type, entity_id, external_id, external_key, external_url,
             status_name, status_category, assignee_name, last_synced_datetime, last_error, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?)"
    );
    $stmt->execute([
        $connId, $entityType, $entityId,
        $issue['external_id'], $issue['external_key'] ?? null, $issue['external_url'] ?? null,
        $issue['status_name'] ?? null, $issue['status_category'] ?? null,
        $issue['assignee_name'] ?? null,
        // Visible on the link so an admin can see the mapping needs attention,
        // rather than the dropped priority being silent.
        $priorityDropped ? mb_substr('Priority not sent: ' . $priorityDropped, 0, 500) : null,
        ($opts['analyst_id'] ?? null) ?: null,
    ]);

    $linkRow = array_merge($issue, [
        'id'            => (int) $conn->lastInsertId(),
        'connection_id' => $connId,
        'entity_type'   => $entityType,
        'entity_id'     => $entityId,
    ]);

    // Attachments, if this connection sends them. AFTER the link row exists, so
    // that a file failing cannot cost us the record of an issue that is already
    // in the tracker.
    if (!empty($connection['send_attachments']) && $entityType === 'ticket') {
        $files = integrationsTicketAttachments($conn, $entityId);
        if ($files) {
            $res = integrationsSendAttachments($conn, $connection, $linkRow, $files);
            $linkRow['attachments'] = $res;
            if ($res['failed'] || $res['skipped']) {
                // Visible on the link rather than only in a log, so an analyst
                // can see the screenshot did not travel.
                $msg = 'Attachments: ' . $res['sent'] . ' sent, ' . $res['failed'] . ' failed, '
                     . $res['skipped'] . ' skipped — ' . implode('; ', array_slice($res['notes'], 0, 3));
                try {
                    $conn->prepare("UPDATE integration_links SET last_error = ? WHERE id = ?")
                         ->execute([mb_substr($msg, 0, 500), $linkRow['id']]);
                } catch (Exception) { /* cosmetic */ }
            }
        }
    }

    // ⚠️ After the link row exists, so a workflow reacting to this can read the
    // link. Note this fires for a MANUAL escalate too — the point of §2 is that
    // "what happens when a ticket is escalated" is a rule the user writes, and
    // that should not depend on which button started it.
    integrationsEmitTrackerEvent($conn, 'tracker.issue_linked', $connection, $linkRow);

    return $linkRow;
}

// =====================================================================
//  Attachments — the screenshot IS the bug report
// =====================================================================

/** Biggest single file we will push. Jira's own default ceiling is 10MB. */
const INTEGRATION_ATTACH_MAX_BYTES = 10485760;   // 10 MB

/** Most files one escalation will push, newest first. */
const INTEGRATION_ATTACH_MAX_FILES = 10;

require_once dirname(__DIR__) . '/storage/stores.php';   // ticket attachments: a server folder or S3

/**
 * The attachments on a ticket that are worth sending to a tracker.
 *
 * ⚠️ **Inline images are excluded, deliberately.** An HTML email carries every
 * signature logo, tracking pixel and marketing graphic as an inline attachment —
 * on this install the inline files run from 501 bytes upward and are almost all
 * exactly that. Pushing them would put a dozen junk images on every issue, and
 * an integration that cries wolf gets ignored. `is_inline = 0` means somebody
 * deliberately attached the file, which is precisely the signal we want.
 *
 * The cost is a screenshot *pasted into* an email body rather than attached: it
 * arrives inline and will not travel. The description always links back to the
 * ticket, where it is visible.
 *
 * ⚠️ Internal notes have no attachments at all in this schema, so the rule that
 * internal content never crosses holds here for free rather than by a check.
 *
 * @param int $maxFiles  how many files may be sent; the rest are returned with skip_reason 'over_limit'
 * @return array [['id','filename','content_type','size','path','skip_reason'], …]
 *               `skip_reason` set means: show it, do not send it.
 */
function integrationsTicketAttachments(PDO $conn, int $ticketId, int $maxFiles = INTEGRATION_ATTACH_MAX_FILES): array
{
    try {
        $stmt = $conn->prepare(
            "SELECT a.id, a.filename, a.content_type, a.file_size, a.file_path
               FROM email_attachments a
               JOIN emails e ON e.id = a.email_id
              WHERE e.ticket_id = ? AND a.is_inline = 0
           ORDER BY a.id DESC"
        );
        $stmt->execute([$ticketId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('integrationsTicketAttachments(' . $ticketId . '): ' . $e->getMessage());
        return [];
    }

    $out = [];
    $taken = 0;
    foreach ($rows as $r) {
        $key    = (string)$r['file_path'];
        $size   = (int)$r['file_size'];
        $reason = null;
        try {
            $there = storeSize($conn, 'tickets', $key) !== null;   // a server folder or S3
        } catch (StorageUnavailable $e) {
            $there = true;   // cannot tell now: the send says so if it really cannot read it
        }

        if (!$there) {
            // The row outlived the file — a restored database, a pruned disk.
            $reason = 'missing_on_disk';
        } elseif ($size > INTEGRATION_ATTACH_MAX_BYTES) {
            $reason = 'too_large';
        } elseif ($taken >= $maxFiles) {
            $reason = 'over_limit';
        }

        if ($reason === null) $taken++;

        $out[] = [
            'id'           => (int)$r['id'],
            'filename'     => (string)$r['filename'],
            'content_type' => (string)($r['content_type'] ?: 'application/octet-stream'),
            'size'         => $size,
            'key'          => $key,
            'skip_reason'  => $reason,
        ];
    }
    return $out;
}

/**
 * "1.4 MB" / "812 KB" — for the preview and the log line.
 *
 * @param int $bytes  a size in bytes
 */
function integrationsFormatBytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/**
 * Push a ticket's attachments onto an issue that has just been created.
 *
 * ⚠️ **A failed attachment must never lose the escalation.** The issue already
 * exists in the tracker by the time this runs; throwing here would surface as
 * "escalation failed" and an analyst would raise a second one. Same rule as the
 * rejected priority in §7e — the expensive thing is already done.
 *
 * @param array $connection  the connection row as loaded by integrationsLoadConnection()
 * @param array $link  the integration_links row for the issue just created (needs external_id)
 * @param array $attachments  the list from integrationsTicketAttachments(); entries with a skip_reason are counted, not sent
 * @return array ['sent'=>int, 'failed'=>int, 'skipped'=>int, 'notes'=>string[]]
 */
function integrationsSendAttachments(PDO $conn, array $connection, array $link, array $attachments): array
{
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'notes' => []];
    if (!$attachments) return $out;

    try {
        $provider = integrationsProviderFor($connection);
        if (!$provider->supports(IssueTrackerProvider::CAP_ATTACHMENTS)) {
            return $out;
        }
    } catch (Exception $e) {
        return $out;
    }

    foreach ($attachments as $a) {
        if (!empty($a['skip_reason'])) {
            $out['skipped']++;
            $out['notes'][] = $a['filename'] . ' (' . $a['skip_reason'] . ')';
            continue;
        }
        try {
            try {
                $data = storeGetString($conn, 'tickets', (string)$a['key']);
            } catch (Throwable $e) {
                throw new Exception('could not read the file');
            }
            $provider->addAttachment((string)$link['external_id'], [
                'data'         => $data,
                'filename'     => $a['filename'],
                'content_type' => $a['content_type'],
            ]);
            $out['sent']++;
        } catch (Exception $e) {
            // One bad file must not lose the rest, and none of them may lose
            // the issue that already exists.
            $out['failed']++;
            $out['notes'][] = $a['filename'] . ': ' . $e->getMessage();
            error_log('integrationsSendAttachments(' . ($link['external_key'] ?? '?') . '): '
                    . $a['filename'] . ': ' . $e->getMessage());
        }
    }
    return $out;
}

// =====================================================================
//  Emitting tracker.* workflow events
// =====================================================================

/**
 * The ticket block every tracker.* event carries, in the same shape the tickets
 * service dispatches — so `{{ticket.requester_email}}` means the same thing
 * whichever trigger a workflow hangs off.
 */
function integrationsTicketPayload(PDO $conn, int $ticketId): array
{
    // ⚠️ This query is a COPY of the canonical one in
    // includes/services/tickets.php (search "canonical post-update payload").
    // Keep them identical — three of these columns are aliases, not real column
    // names, and getting that wrong is silent:
    //   type_id         is  t.ticket_type_id
    //   created_by      is  t.user_id
    //   requester_email is  u.email, via a LEFT JOIN to users — it is NOT on
    //                       the tickets table at all
    // The first version of this selected `created_by` and `requester_email`
    // straight off `tickets` and threw; the catch below swallowed it and every
    // workflow would have received a payload of nothing but an id.
    try {
        $stmt = $conn->prepare(
            "SELECT t.id, t.subject, t.priority_id, t.status_id, t.department_id,
                    t.ticket_type_id AS type_id, t.assigned_analyst_id, t.owner_id,
                    t.origin_id, t.user_id AS created_by, u.email AS requester_email
             FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?"
        );
        $stmt->execute([$ticketId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Log it. A silently empty payload is how a workflow ends up firing with
        // nothing to act on and no clue why.
        error_log('integrationsTicketPayload(' . $ticketId . '): ' . $e->getMessage());
        return ['id' => $ticketId];
    }
    if (!$r) return ['id' => $ticketId];

    return [
        'id'                  => (int)$r['id'],
        'subject'             => $r['subject'],
        'priority_id'         => $r['priority_id'] !== null ? (int)$r['priority_id'] : null,
        'status_id'           => $r['status_id'] !== null ? (int)$r['status_id'] : null,
        'department_id'       => $r['department_id'] !== null ? (int)$r['department_id'] : null,
        'type_id'             => $r['type_id'] !== null ? (int)$r['type_id'] : null,
        'assigned_analyst_id' => $r['assigned_analyst_id'] !== null ? (int)$r['assigned_analyst_id'] : null,
        'owner_id'            => $r['owner_id'] !== null ? (int)$r['owner_id'] : null,
        'origin_id'           => $r['origin_id'] !== null ? (int)$r['origin_id'] : null,
        'created_by'          => $r['created_by'] !== null ? (int)$r['created_by'] : null,
        'requester_email'     => $r['requester_email'] ?? null,
    ];
}

/**
 * Fire a `tracker.*` workflow event.
 *
 * ⚠️ These are NOT time-based triggers and must not go through
 * `workflow_scheduled_emissions`. The test on the Time-Based Triggers wiki page
 * is *"did something happen, or did time merely pass?"* — here something
 * happened: a developer moved the issue or wrote a comment. The poll is only
 * **how we find out**, because a self-hosted install cannot be called back.
 *
 * That distinction is not pedantry, it changes behaviour. Each of these fires
 * from the point where the new state is **already persisted** — a status move is
 * only reported once the link row has been updated, a comment only once its map
 * row exists — so they are edge-triggered by construction and cannot repeat. A
 * fingerprint ledger would be actively WRONG: `todo → in_progress → todo →
 * in_progress` is three real transitions, and a fingerprint on current state
 * would silently swallow the third.
 *
 * Never throws: a workflow problem must not break a poll, exactly as a workflow
 * problem must not break a ticket save.
 *
 * @param string $event  the workflow event name, e.g. 'tracker.issue_status_changed'
 * @param array $connection  the connection row (provider, name, id are copied into the payload)
 * @param array $link  the integration_links row; only entity_type 'ticket' fires anything
 * @param array $extra  more fields merged into the payload's 'tracker' block
 */
function integrationsEmitTrackerEvent(PDO $conn, string $event, array $connection, array $link, array $extra = []): void
{
    try {
        // Only tickets have the payload a workflow can act on. Problems and
        // changes are linkable but have no ticket block, so emitting for them
        // would hand workflows a payload their actions cannot use.
        if ((string)($link['entity_type'] ?? 'ticket') !== 'ticket') return;

        require_once __DIR__ . '/../../workflow/includes/engine.php';
        if (!class_exists('WorkflowEngine')) return;

        $key = (string)($link['external_key'] ?: $link['external_id']);
        WorkflowEngine::dispatch($event, [
            'ticket'  => integrationsTicketPayload($conn, (int)$link['entity_id']),
            'tracker' => array_merge([
                'key'             => $key,
                'url'             => $link['external_url'] ?? null,
                'provider'        => $connection['provider'] ?? null,
                'connection_name' => $connection['name'] ?? null,
                'connection_id'   => isset($connection['id']) ? (int)$connection['id'] : null,
                'external_id'     => $link['external_id'] ?? null,
            ], $extra),
        ]);
    } catch (Exception $e) {
        error_log('integrationsEmitTrackerEvent(' . $event . '): ' . $e->getMessage());
    }
}

// =====================================================================
//  Mapping — what our values mean in the tracker's vocabulary (V3)
// =====================================================================

const INTEGRATION_MAP_PROJECT    = 'project';
const INTEGRATION_MAP_ISSUE_TYPE = 'issue_type';
const INTEGRATION_MAP_PRIORITY   = 'priority';

/** The routing fallback key: "anything not matched above". */
const INTEGRATION_MAP_ANY = '*';

/**
 * Is the mapping table present? Separate gate again, for the same reason as the
 * comment map: an install that ran Database Verification for an earlier release
 * has the connections but not this, and must degrade to "no mapping configured"
 * rather than throwing inside an escalation.
 */
function integrationsMapSchemaReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query("SELECT 1 FROM integration_field_maps LIMIT 1");
        return $ready = true;
    } catch (Exception) {
        return $ready = false;
    }
}

/**
 * Every mapping for one connection, as [map_type][local_key] => external_key.
 * Empty array when nothing is configured or the table is missing — mapping is
 * always optional, and an unmapped install must keep working exactly as before.
 *
 * @param int $connectionId  the integration_connections id
 */
function integrationsLoadMaps(PDO $conn, int $connectionId): array
{
    if (!integrationsMapSchemaReady($conn)) return [];
    try {
        $stmt = $conn->prepare(
            "SELECT map_type, local_key, external_key FROM integration_field_maps WHERE connection_id = ?"
        );
        $stmt->execute([$connectionId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['map_type']][(string)$r['local_key']] = (string)$r['external_key'];
        }
        return $out;
    } catch (Exception) {
        return [];
    }
}

/**
 * Replace every mapping of the given types for a connection.
 *
 * Whole-types-at-once rather than row-by-row because the screen edits them as a
 * set: a mapping the admin deleted has to disappear, and diffing individual rows
 * to work that out is more code and more ways to be wrong.
 *
 * @param int $connectionId  the integration_connections id whose mappings are replaced
 * @param array $maps [map_type => [local_key => external_key]]
 */
function integrationsSaveMaps(PDO $conn, int $connectionId, array $maps): void
{
    if (!integrationsMapSchemaReady($conn)) {
        throw new Exception('Run Database Verification first — the mapping table does not exist yet.');
    }
    // ⚠️ Only own the transaction if nobody else already does. PDO throws
    // "There is already an active transaction" on a nested beginTransaction(),
    // so a caller that wraps this in its own transaction — a bulk import, a
    // test harness — would fatal. Same lesson as this file requiring its own
    // dependencies: a shared service cannot assume anything about its caller.
    $ownsTransaction = !$conn->inTransaction();
    if ($ownsTransaction) $conn->beginTransaction();
    try {
        foreach ($maps as $type => $rows) {
            $conn->prepare("DELETE FROM integration_field_maps WHERE connection_id = ? AND map_type = ?")
                 ->execute([$connectionId, (string)$type]);

            $ins = $conn->prepare(
                "INSERT INTO integration_field_maps (connection_id, map_type, local_key, external_key)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ((array)$rows as $local => $external) {
                $local    = trim((string)$local);
                $external = trim((string)$external);
                // A blank target means "no mapping", which is the absence of a
                // row, not a row pointing at "". Storing it would later resolve
                // to an empty project key and 400 the escalation.
                if ($local === '' || $external === '') continue;
                $ins->execute([$connectionId, (string)$type, $local, $external]);
            }
        }
        if ($ownsTransaction) $conn->commit();
    } catch (Exception $e) {
        if ($ownsTransaction) $conn->rollBack();
        throw $e;
    }
}

/**
 * Which Jira project should this work item's issue go in?
 *
 * ⚠️ Precedence, most specific first, and it matters:
 *
 *   1. department  — a team with its own board is the sharpest signal there is
 *   2. company     — an MSP client with their own project
 *   3. `*`         — the connection's default
 *   4. null        — no mapping; the caller keeps whatever it was given
 *
 * Returning null rather than guessing is the point. An escalation with no
 * resolvable project must surface as "tell me the project", never as an issue
 * quietly filed in whatever project happened to be first.
 *
 * Pure: no database, so the precedence is provable without fixtures.
 *
 * @param array $maps  the connection's mappings as returned by integrationsLoadMaps()
 * @param ?int $departmentId  the work item's department; null skips the department rule
 */
function integrationsResolveProject(array $maps, ?int $tenantId, ?int $departmentId): ?string
{
    $rows = $maps[INTEGRATION_MAP_PROJECT] ?? [];
    if (!$rows) return null;

    if ($departmentId !== null && !empty($rows['dept:' . $departmentId]))   return $rows['dept:' . $departmentId];
    if ($tenantId     !== null && !empty($rows['tenant:' . $tenantId]))     return $rows['tenant:' . $tenantId];
    if (!empty($rows[INTEGRATION_MAP_ANY]))                                 return $rows[INTEGRATION_MAP_ANY];
    return null;
}

/**
 * Our ticket type → the tracker's issue type. Falls back to the `*` row, so an
 * install can say "everything is a Task" without listing every type.
 *
 * @param array $maps  the connection's mappings as returned by integrationsLoadMaps()
 * @param ?int $ticketTypeId  the ticket's ticket_type_id; null falls straight to the '*' row
 */
function integrationsResolveIssueType(array $maps, ?int $ticketTypeId): ?string
{
    $rows = $maps[INTEGRATION_MAP_ISSUE_TYPE] ?? [];
    if (!$rows) return null;
    if ($ticketTypeId !== null && !empty($rows[(string)$ticketTypeId])) return $rows[(string)$ticketTypeId];
    return !empty($rows[INTEGRATION_MAP_ANY]) ? $rows[INTEGRATION_MAP_ANY] : null;
}

/**
 * Our priority → the tracker's priority name.
 *
 * ⚠️ No `*` fallback here, deliberately, unlike the two above. "Everything is a
 * Task" is a reasonable thing to mean; "every priority is Highest" is not — it
 * would silently mark a dev team's whole backlog urgent. An unmapped priority
 * simply travels as text in the description, exactly as it did before mapping.
 *
 * @param array $maps  the connection's mappings as returned by integrationsLoadMaps()
 * @param ?int $priorityId  the ticket's priority_id; null gives null
 */
function integrationsResolvePriority(array $maps, ?int $priorityId): ?string
{
    $rows = $maps[INTEGRATION_MAP_PRIORITY] ?? [];
    if (!$rows || $priorityId === null) return null;
    return !empty($rows[(string)$priorityId]) ? $rows[(string)$priorityId] : null;
}

/**
 * Does this error look like the tracker rejecting our priority?
 *
 * Used to decide whether to retry the create without one. Deliberately narrow:
 * retrying blindly on any failure could turn a genuine error into an issue we
 * did not mean to raise.
 *
 * @param string $message  the error message the tracker's create call threw
 */
function integrationsLooksLikePriorityRejection(string $message): bool
{
    return stripos($message, 'priority') !== false;
}

/**
 * The company, department, type and priority of a work item, for routing.
 *
 * ⚠️ The column is `ticket_type_id`, not `type_id` — the workflow engine's lookup
 * key is `ticket.type_id`, which is not the column name and is an easy way to
 * write a query that silently returns nothing.
 *
 * Only tickets route today; problems and changes return an empty routing set, so
 * they fall through to whatever the caller supplied.
 *
 * @param string $entityType  'ticket', 'problem' or 'change'; only 'ticket' returns anything but nulls
 * @param int $entityId  the id of that work item
 */
function integrationsEntityRouting(PDO $conn, string $entityType, int $entityId): array
{
    $empty = ['tenant_id' => null, 'department_id' => null, 'ticket_type_id' => null, 'priority_id' => null];
    if ($entityType !== 'ticket') return $empty;
    try {
        $stmt = $conn->prepare("SELECT tenant_id, department_id, ticket_type_id, priority_id FROM tickets WHERE id = ?");
        $stmt->execute([$entityId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception) {
        return $empty;
    }
    if (!$r) return $empty;
    foreach (array_keys($empty) as $k) {
        $r[$k] = isset($r[$k]) && $r[$k] !== null ? (int)$r[$k] : null;
    }
    return $r;
}

// =====================================================================
//  Comments coming back — the inbound half
// =====================================================================

/**
 * Is the comment map present? Separate from integrationsSchemaReady() because it
 * arrived a release later: an install that ran Database Verification for V1 but
 * not since has the links but not the map, and must degrade to "no comments come
 * back" rather than throwing inside the poll or the ticket view.
 */
function integrationsCommentSchemaReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query("SELECT 1 FROM integration_comment_map LIMIT 1");
        return $ready = true;
    } catch (Exception) {
        return $ready = false;
    }
}

/**
 * Is this inbound comment authored by the account WE authenticate as?
 *
 * ⚠️ NOT USED TO SUPPRESS COMMENTS. Read this before wiring it back in.
 *
 * This was originally echo suppression's "guard 2", on the reasoning that
 * anything written by our own account must be our own write coming back. That
 * reasoning is wrong for comments, and it failed on the very first live test:
 * the person who creates the API token is usually also a person who comments in
 * Jira, so *their own* comments were silently swallowed. On a small team that is
 * not an edge case, it is the normal case — and it fails invisibly, which is the
 * worst way to fail.
 *
 * It is redundant anyway. Guard 1 — `integration_comment_map` plus its UNIQUE
 * key — records the id of every comment we push, so an echo is caught by **id**
 * no matter who appears to have written it. That is exact, and it closes the
 * loop on its own: a note we pushed is recognised on the way back regardless of
 * author.
 *
 * Kept, and still reachable through `integrationsCommentSkipReason()`'s
 * `$suppressByAuthor` flag, for two reasons: the events guard 1 genuinely cannot
 * cover (edits, attachments, field changes — none of which we process yet), and
 * so that "ignore comments from the connection's own account" can become a
 * per-connection setting by flipping one argument rather than rewriting this.
 *
 * ⚠️ An UNKNOWN identity is never treated as ours. A tracker that stops sending
 * an author must not silently swallow every comment — it must let them through
 * and be noisy, because the failure is then visible instead of invisible.
 *
 * Pure on purpose: the whole decision is provable without a database.
 *
 * @param array $event  a canonical comment event from the provider's pollChanges() (reads author_identity)
 * @param ?string $accountIdentity  the connection's account_identity - the account the token signs in as; null if unknown
 */
function integrationsCommentIsEcho(array $event, ?string $accountIdentity): bool
{
    $author = trim((string)($event['author_identity'] ?? ''));
    $us     = trim((string)($accountIdentity ?? ''));
    if ($author === '' || $us === '') return false;
    return $author === $us;
}

/**
 * Should this comment event become a note? The complete decision, pure.
 *
 * Echo suppression happens on the `already_imported` line: the id of everything
 * we push is recorded, so our own writes are recognised coming back. That is the
 * whole guard, and it is exact.
 *
 * @param array $event  a canonical comment event from the provider's pollChanges() (type, comment_id, comment_body, author_*)
 * @param ?string $accountIdentity  the connection's account_identity; null if unknown
 * @param string[] $knownCommentIds  ids already imported for this link (guard 1)
 * @param string $entityType  the linked work item's type; only 'ticket' is imported
 * @param bool     $suppressByAuthor also drop anything authored by our own
 *                 account. **Off**, and deliberately so — see
 *                 integrationsCommentIsEcho(). This is the seam a future
 *                 per-connection setting flips; it is not a default to restore.
 * @return string '' to import, otherwise the reason it was dropped
 */
function integrationsCommentSkipReason(array $event, ?string $accountIdentity, array $knownCommentIds, string $entityType = 'ticket', bool $suppressByAuthor = false): string
{
    if (($event['type'] ?? '') !== 'comment_added')                return 'not_a_comment';
    if ($entityType !== 'ticket')                                  return 'unsupported_entity';
    if (trim((string)($event['comment_body'] ?? '')) === '')       return 'empty';
    if ((string)($event['comment_id'] ?? '') === '')               return 'no_comment_id';
    if ($suppressByAuthor && integrationsCommentIsEcho($event, $accountIdentity)) {
        return 'echo';
    }
    if (in_array((string)$event['comment_id'], array_map('strval', $knownCommentIds), true)) {
        return 'already_imported';
    }
    return '';
}

/**
 * Comment ids already mapped for one link, in either direction.
 *
 * @param int $linkId  the integration_links id
 */
function integrationsKnownCommentIds(PDO $conn, int $linkId): array
{
    if (!integrationsCommentSchemaReady($conn)) return [];
    try {
        $stmt = $conn->prepare("SELECT external_comment_id FROM integration_comment_map WHERE link_id = ?");
        $stmt->execute([$linkId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception) {
        return [];
    }
}

/**
 * Record a comment we PUSHED, so it is recognised if it comes back.
 *
 * Guard 1 of echo suppression. Called by send_note_to_tracker straight after the
 * provider returns the new comment's id. Never throws: failing to record an echo
 * marker must not fail the workflow run that successfully posted the comment.
 *
 * @param int $linkId  the integration_links id the comment was posted on
 * @param string $externalCommentId  the tracker's id for the new comment; '' records nothing
 * @param ?int $localNoteId  the ticket_notes id that was pushed, or null
 */
function integrationsRecordOutboundComment(PDO $conn, int $linkId, string $externalCommentId, ?int $localNoteId = null): void
{
    if ($externalCommentId === '' || !integrationsCommentSchemaReady($conn)) return;
    try {
        $conn->prepare(
            "INSERT INTO integration_comment_map
                (link_id, direction, external_comment_id, local_note_id, created_datetime)
             VALUES (?, 'out', ?, ?, UTC_TIMESTAMP())"
        )->execute([$linkId, $externalCommentId, $localNoteId ?: null]);
    } catch (Exception) {
        // Duplicate key or a missing table — neither is worth failing a send for.
    }
}

/**
 * How an imported comment reads on the ticket.
 *
 * The attribution lives in the note TEXT, not only in the display join, because
 * plenty of things read note_text directly — the REST API, the portal, the AI
 * write-up. A note that says only "any update?" with no hint it came from a dev
 * in Jira is worse than no note.
 *
 * @param array $event  a canonical comment event (author_name, comment_body)
 * @param array $link  the integration_links row (external_key, or external_id when there is no key)
 */
function integrationsCommentNoteText(array $event, array $link): string
{
    $ref    = (string)($link['external_key'] ?: $link['external_id']);
    $author = trim((string)($event['author_name'] ?? ''));
    $header = $ref . ($author !== '' ? ' · comment from ' . $author : ' · new comment');
    return $header . "\n\n" . trim((string)($event['comment_body'] ?? ''));
}

/**
 * Apply one canonical comment event: an internal note on the ticket, plus the
 * map row that stops it ever being imported twice.
 *
 * ⚠️ THE MAP ROW IS WRITTEN FIRST, and its UNIQUE (link_id, external_comment_id)
 * key is what actually guarantees "once". Writing the note first and the marker
 * second would let two overlapping cron runs both pass the "already imported?"
 * read and both post the note — the classic check-then-act race. Here the second
 * writer loses on the key before any note exists.
 *
 * ⚠️ Always internal. A Jira comment is dev-to-analyst; it is written by people
 * who do not know a customer can see it, and it must never reach the requester
 * except by an analyst deciding so.
 *
 * @param array $connection  the connection row as loaded by integrationsLoadConnection()
 * @param array $link  the integration_links row the comment belongs to
 * @param array $event  a canonical comment event from the provider's pollChanges()
 * @return string '' if imported, otherwise the reason it was skipped
 */
function integrationsApplyCommentEvent(PDO $conn, array $connection, array $link, array $event): string
{
    $entityType = (string)($link['entity_type'] ?? 'ticket');
    // The last argument is where "ignore comments from our own account" would be
    // read from the connection, if it ever becomes a setting. It stays false:
    // suppressing by author swallowed the token owner's own comments the first
    // time this ran for real. See integrationsCommentIsEcho().
    $reason = integrationsCommentSkipReason(
        $event,
        $connection['account_identity'] ?? null,
        integrationsKnownCommentIds($conn, (int)$link['id']),
        $entityType,
        false
    );
    if ($reason !== '') return $reason;

    // TRAP: the map row and the note are written together or not at all. The map
    // row is what marks a comment "already imported"; written alone, a note that
    // was then refused (GitHub #281: analyst_id 0 against fk_notes_analysts) left
    // the comment marked imported with no note, so it was skipped for ever. The
    // unique key still settles the race: a second writer waits on this row and
    // then loses on it - or wins, if this one rolled back.
    $ownsTransaction = !$conn->inTransaction();
    if ($ownsTransaction) $conn->beginTransaction();
    try {
        try {
            $conn->prepare(
                "INSERT INTO integration_comment_map
                    (link_id, direction, external_comment_id, author_identity, author_name, created_datetime)
                 VALUES (?, 'in', ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([
                (int)$link['id'],
                (string)$event['comment_id'],
                (string)($event['author_identity'] ?? '') ?: null,
                (string)($event['author_name'] ?? '') ?: null,
            ]);
        } catch (Exception) {
            // Lost the race (or the row was already there) — the other writer owns it.
            if ($ownsTransaction) $conn->rollBack();
            return 'already_imported';
        }
        $mapId = (int) $conn->lastInsertId();

        // analyst_id NULL = System: a Jira comment has no FreeITSM author (#281 -
        // it was 0, which the foreign key refuses). Readers LEFT JOIN analysts and
        // name the connection instead - see api/tickets/get_notes.php.
        $conn->prepare(
            "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
             VALUES (?, NULL, ?, 1, UTC_TIMESTAMP())"
        )->execute([(int)$link['entity_id'], integrationsCommentNoteText($event, $link)]);
        $noteId = (int) $conn->lastInsertId();

        $conn->prepare("UPDATE integration_comment_map SET local_note_id = ? WHERE id = ?")
             ->execute([$noteId, $mapId]);
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int)$link['entity_id']]);
        if ($ownsTransaction) $conn->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $conn->inTransaction()) $conn->rollBack();
        throw $e;   // the poll keeps its window and tries again next time
    }

    // Fired AFTER the map row exists, so the unique key has already guaranteed
    // this comment is imported once — the event inherits that guarantee and
    // cannot double-fire.
    //
    // ⚠️ The loop this could create: a workflow on this event pushes a comment
    // back, which returns on the next poll and fires again. It terminates
    // because `send_note_to_tracker` records every comment it pushes in the same
    // map, so the returning copy is dropped as already_imported before it ever
    // reaches here. That is the only thing stopping it — do not remove the
    // recording in the workflow action.
    integrationsEmitTrackerEvent($conn, 'tracker.issue_comment_added', $connection, $link, [
        'comment_author' => (string)($event['author_name'] ?? ''),
        'comment_body'   => (string)($event['comment_body'] ?? ''),
    ]);

    return '';
}

/**
 * Pull new comments for every link on one connection. The second half of the
 * poll cron's unit of work.
 *
 * The watermark is stamped from BEFORE the provider call, not after, so a comment
 * posted while the poll was running lands inside the next window rather than in
 * the gap between them. It is only advanced on success: a failed pull must be
 * retried against the same window, not skipped past.
 *
 * @param array $connection  the connection row as loaded by integrationsLoadConnection(); inbound_enabled must be set
 * @return array ['pulled'=>int, 'imported'=>int, 'skipped'=>['echo'=>n, …]]
 */
function integrationsPullComments(PDO $conn, array $connection): array
{
    $out = ['pulled' => 0, 'imported' => 0, 'skipped' => []];

    if (!integrationsSchemaReady($conn) || !integrationsCommentSchemaReady($conn)) return $out;
    if (empty($connection['inbound_enabled'])) return $out;   // off by default; nothing is written until an admin says so

    $provider = integrationsProviderFor($connection);
    if (!$provider->supports(IssueTrackerProvider::CAP_POLLING)) return $out;

    $stmt = $conn->prepare("SELECT * FROM integration_links WHERE connection_id = ?");
    $stmt->execute([(int)$connection['id']]);
    $links = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $links[(string)$l['external_id']] = $l;
    }

    $startedAt = gmdate('Y-m-d H:i:s');
    $watermark = !empty($connection['last_poll_watermark']) ? (string)$connection['last_poll_watermark'] : null;

    if ($links) {
        $events = $provider->pollChanges($watermark, array_keys($links));
        $out['pulled'] = count($events);

        foreach ($events as $event) {
            $link = $links[(string)($event['external_id'] ?? '')] ?? null;
            if (!$link) continue;   // an event about an issue we no longer track
            $reason = integrationsApplyCommentEvent($conn, $connection, $link, $event);
            if ($reason === '') {
                $out['imported']++;
            } else {
                $out['skipped'][$reason] = ($out['skipped'][$reason] ?? 0) + 1;
            }
        }
    }

    $conn->prepare("UPDATE integration_connections SET last_poll_watermark = ? WHERE id = ?")
         ->execute([$startedAt, (int)$connection['id']]);

    return $out;
}

/**
 * Refresh the cached status of every link on one connection. The poll cron's
 * unit of work.
 *
 * Batched through fetchIssues() — one JQL call rather than one per link — and
 * only rows whose status actually moved are written, so a quiet day costs one
 * read and no writes.
 *
 * @param array $connection  the connection row as loaded by integrationsLoadConnection()
 * @return array ['checked'=>int, 'changed'=>int, 'changes'=>[ ['link'=>row,
 *                'from'=>?string, 'to'=>?string], … ]]
 */
function integrationsRefreshConnection(PDO $conn, array $connection): array
{
    $out = ['checked' => 0, 'changed' => 0, 'changes' => []];
    if (!integrationsSchemaReady($conn)) return $out;

    $stmt = $conn->prepare("SELECT * FROM integration_links WHERE connection_id = ?");
    $stmt->execute([(int) $connection['id']]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$links) return $out;

    $provider = integrationsProviderFor($connection);
    $issues   = $provider->fetchIssues(array_column($links, 'external_id'));
    $out['checked'] = count($links);

    foreach ($links as $link) {
        $fresh = $issues[$link['external_id']] ?? null;
        if (!$fresh) continue;   // absent from the batch: leave the cached value rather than blanking it

        $movedCategory = ($fresh['status_category'] ?? null) !== ($link['status_category'] ?? null);
        $movedName     = ($fresh['status_name'] ?? null)     !== ($link['status_name'] ?? null);
        $movedAssignee = ($fresh['assignee_name'] ?? null)   !== ($link['assignee_name'] ?? null);
        if (!$movedCategory && !$movedName && !$movedAssignee) continue;

        $upd = $conn->prepare(
            "UPDATE integration_links
             SET status_name = ?, status_category = ?, assignee_name = ?,
                 last_synced_datetime = UTC_TIMESTAMP()
             WHERE id = ?"
        );
        $upd->execute([
            $fresh['status_name'] ?? null,
            $fresh['status_category'] ?? null,
            $fresh['assignee_name'] ?? null,
            $link['id'],
        ]);

        $out['changed']++;
        // Only a CATEGORY move is worth telling the rest of the app about. A
        // rename from "In Progress" to "In progress" is not an event, and firing
        // a workflow for it would wake the requester up for nothing.
        if ($movedCategory) {
            $out['changes'][] = [
                'link' => $link,
                'from' => $link['status_category'] ?? null,
                'to'   => $fresh['status_category'] ?? null,
            ];
            // Emitted AFTER the UPDATE above, so the link already holds the new
            // category — the next poll compares against it and finds nothing to
            // report. Edge-triggered by construction, no ledger needed.
            //
            // `previous_category` is in the payload so "only when it becomes
            // done" is a plain condition rather than something the workflow has
            // to work out for itself.
            integrationsEmitTrackerEvent($conn, 'tracker.issue_status_changed', $connection, $link, [
                'status_name'       => $fresh['status_name'] ?? null,
                'status_category'   => $fresh['status_category'] ?? null,
                'previous_category' => $link['status_category'] ?? null,
            ]);
        }
    }
    return $out;
}
