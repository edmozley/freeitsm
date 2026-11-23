<?php
/**
 * IssueTrackerProvider — the provider-agnostic contract for an external issue
 * tracker (Jira first; GitHub, GitLab and Azure DevOps behind the same door).
 *
 * Everything above this line — the link registry, the outbound queue, the
 * inbound event pipeline, echo suppression, the workflow action, the settings
 * and ticket UI — talks to this interface and never knows which tracker is live.
 * That is the same split as includes/messaging/MessagingProvider.php, which
 * already puts Twilio and Meta Cloud behind one contract, and it is deliberately
 * copied rather than reinvented.
 *
 * Concrete providers are constructed with a DECRYPTED integration_connections
 * row (credentials already a PHP array).
 *
 * ── Conventions, both borrowed from MessagingProvider ────────────────────────
 *
 *  1. Only the genuinely universal methods are abstract. Optional ones default
 *     to throwing "not supported for this provider", so the contract never
 *     collapses to a lowest common denominator just because one tracker lacks a
 *     concept.
 *
 *  2. supports() answers the same question for the UI. A thrown exception is the
 *     wrong way for a settings screen to discover that GitHub has no issue
 *     types — it needs to know BEFORE it renders the field.
 *
 * ── The canonical event shape (what normaliseEvent/pollChanges return) ───────
 *
 *   [
 *     'event_id'   => 'jira-evt-8837',   // provider's id, for dedupe; required
 *     'type'       => 'status_changed',  // status_changed | comment_added |
 *                                        // issue_created | field_changed
 *     'external_id'=> '10042',           // the issue this is about
 *     'status_name'     => 'In Progress',       // status_changed only
 *     'status_category' => 'in_progress',       // status_changed only; see below
 *     'comment_id'      => '10101',             // comment_added only
 *     'comment_body'    => 'Need repro steps',  // comment_added only
 *     'author_identity' => 'svc@acme.com',      // WHO did it — drives echo suppression
 *     'occurred_at'     => 1785600000,          // unix seconds, or null
 *   ]
 *
 * ⚠️ status_category is one of the four STATUS_* constants and nothing else.
 * Every decision in the system keys off it; status_name is for display only.
 * Jira statuses are per-project and freely renamed, so branching on the name is
 * the same mistake as keying a rule on a ticket status name — see the comment on
 * tickets.merged_into_id for the last time that bit us.
 */

abstract class IssueTrackerProvider
{
    const STATUS_TODO        = 'todo';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_DONE        = 'done';
    const STATUS_CANCELLED   = 'cancelled';

    /** Capability keys for supports(). */
    const CAP_ISSUE_TYPES = 'issue_types';
    const CAP_PRIORITIES  = 'priorities';
    const CAP_ATTACHMENTS = 'attachments';
    const CAP_WEBHOOKS    = 'webhooks';
    const CAP_POLLING     = 'polling';
    const CAP_CUSTOM_FIELDS = 'custom_fields';

    /** @var array decrypted integration_connections row */
    protected $connection;

    /**
     * Keeps the decrypted integration_connections row the connector works with.
     *
     * @param array $connection  the integration_connections row, with its credentials already decrypted to an array
     */
    public function __construct(array $connection)
    {
        $this->connection = $connection;
    }

    /** The provider key from the connection row ('jira' or 'azuredevops'). */
    public function getProvider(): string
    {
        return $this->connection['provider'] ?? '';
    }

    // ------------------------------------------------------------- outbound

    /**
     * Create an issue and return the bits we store on the link.
     *
     * @param array    $target  provider-shaped destination — Jira: ['project'=>'OPS',
     *                          'issue_type'=>'Bug']; GitHub: ['repo'=>'org/name'];
     *                          DevOps: ['project'=>…,'area_path'=>…,'work_item_type'=>…].
     *                          Opaque to core on purpose: the arity genuinely differs.
     * @param string   $summary plain text
     * @param IssueDoc $body    the description, rendered for the provider by renderDoc()
     * @param array    $fields  extra mapped fields (V3+); [] in V1
     * @return array ['external_id'=>…, 'external_key'=>…, 'external_url'=>…,
     *                'status_name'=>…, 'status_category'=>…]
     */
    abstract public function createIssue(array $target, string $summary, IssueDoc $body, array $fields = []): array;

    /**
     * Read one issue's current state. Same return shape as createIssue().
     * Throws if the issue is gone — the caller decides whether that orphans the link.
      *
      * @param string $externalId  the tracker's own id for the issue, as stored in integration_links.external_id
     */
    abstract public function fetchIssue(string $externalId): array;

    /**
     * Read several issues at once. The default loops fetchIssue(), which is
     * always correct; providers that can do it in one call (Jira via JQL) should
     * override, because the poll cron is the hot path.
     *
     * A single issue failing must not lose the rest — its id is simply absent
     * from the result, and the caller decides what a missing issue means.
     *
     * @param  string[] $externalIds  tracker ids of the issues to read (integration_links.external_id values)
     * @return array    external_id => issue array (same shape as fetchIssue)
     */
    public function fetchIssues(array $externalIds): array
    {
        $out = [];
        foreach ($externalIds as $id) {
            try {
                $out[$id] = $this->fetchIssue($id);
            } catch (Exception) {
                // Skipped deliberately — see the docblock.
            }
        }
        return $out;
    }

    /**
     * Post a comment. Returns the provider's comment id (recorded for echo suppression).
     *
     * @param string $externalId  the tracker id of the issue to comment on
     * @param IssueDoc $body  the comment, rendered for the provider by renderDoc()
     */
    public function addComment(string $externalId, IssueDoc $body): string
    {
        throw new Exception('Comments are not supported for this provider.');
    }

    /**
     * Attaches a file to an external issue. The default throws; connectors that declare
     * CAP_ATTACHMENTS override it.
     *
      * @param string $externalId  the tracker id of the issue to attach the file to
     * @param array $file ['data'=>binary, 'filename'=>string, 'content_type'=>string]
     */
    public function addAttachment(string $externalId, array $file): void
    {
        throw new Exception('Attachments are not supported for this provider.');
    }

    /**
     * Updates fields on an external issue. The default throws; a connector that supports it overrides it.
     *
     * @param string $externalId  the tracker id of the issue to update
     * @param array $fields  provider field name => new value, in the provider's own field naming
     */
    public function updateFields(string $externalId, array $fields): void
    {
        throw new Exception('Field updates are not supported for this provider.');
    }

    // -------------------------------------------------------------- inbound

    /**
     * Verify an inbound webhook really came from the provider. Return false to
     * reject — the endpoint 403s.
     *
     * ⚠️ Verify against the RAW body, before json_decode: the provider signs
     * bytes, and a re-encoded array is not the same bytes.
      *
      * @param string $rawBody  the request body exactly as received, before any JSON decoding
      * @param array $headers  the inbound request's HTTP headers
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        throw new Exception('Webhooks are not supported for this provider.');
    }

    /**
     * Raw webhook payload → zero or more canonical events (see the file header).
     *
     * @param array $raw  the webhook payload, JSON-decoded
     */
    public function normaliseEvent(array $raw): array
    {
        throw new Exception('Webhooks are not supported for this provider.');
    }

    /**
     * The firewalled-install path: ask the provider what changed since $since
     * and return the SAME canonical events a webhook would have produced.
     *
     * Polling is a degraded webhook, not a second pipeline — everything
     * downstream of here cannot tell which one produced the event.
     *
     * $externalIds is the watch list: the issues we actually hold links to.
     * Polling is scoped to them rather than to "everything that changed", because
     * an unscoped poll on a busy Jira site returns thousands of issues we have no
     * interest in. (V4, where an issue raised in Jira creates a ticket, is the
     * case that will need an unscoped variant — it can pass an empty list and a
     * project scope then.)
     *
     * @param string|null $since       provider-native watermark; null = first run
     * @param string[]    $externalIds issues to check
     */
    public function pollChanges(?string $since, array $externalIds = []): array
    {
        throw new Exception('Polling is not supported for this provider.');
    }

    // ------------------------------------------------------ discovery / setup

    /**
     * Verify the connection with a lightweight read-only call and return BOTH a
     * human-readable detail for the settings screen and the authenticated
     * account's identity.
     *
     * ⚠️ account_identity is not cosmetic. It is half of echo suppression: an
     * inbound event authored by this identity is our own write coming back, and
     * must be dropped rather than re-imported. It is captured in V1 — before
     * anything reads it — because back-filling it for links that already exist
     * is miserable.
     *
     * @return array ['detail' => 'Connected to Acme Jira as svc@acme.com',
     *                'account_identity' => 'svc@acme.com']
     */
    abstract public function testConnection(): array;

    /**
     * Lists the tracker's projects for the mapping dropdowns. The default throws;
     * connectors override it.
     *
     * @return array [['key'=>'OPS','name'=>'Operations'], …]
     */
    public function listProjects(): array
    {
        throw new Exception('Project discovery is not supported for this provider.');
    }

    /**
     * Lists the issue types available in a project. The default throws; connectors that
     * declare CAP_ISSUE_TYPES override it.
     *
      * @param string $project  the project's key, as listProjects() returns it
     * @return array [['id'=>'10001','name'=>'Bug'], …]
     */
    public function listIssueTypes(string $project): array
    {
        throw new Exception('Issue types are not supported for this provider.');
    }

    // ------------------------------------------------------------- rendering

    /**
     * Render a body into whatever this provider's API wants. Usually one line
     * delegating to IssueDoc — $doc->toAdf(), ->toMarkdown(), ->toHtml(),
     * ->toWikiMarkup() — which is why adding a provider rarely means writing a
     * renderer.
     *
      * @param IssueDoc $doc  the description or comment to render
     * @return mixed array (ADF) or string (Markdown / HTML / wiki markup)
     */
    abstract public function renderDoc(IssueDoc $doc);

    // ---------------------------------------------------------- capabilities

    /**
     * Does this provider have $capability? Default: no.
     *
     * @param string $capability  one of the CAP_* constants
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * The optional features this connector supports. The base class declares none.
     *
     * @return string[] the CAP_* keys this provider offers
     */
    protected function capabilities(): array
    {
        return [];
    }

    // ---------------------------------------------------------------- helper

    /**
     * Shared cURL. Returns [httpCode, bodyString]; an HTTP error code is NOT an
     * exception, because the caller knows what a 404 means for the call it made.
     *
     * ⚠️ sslApplyCurl() is mandatory — it honours the app-wide SSL_VERIFY_PEER
     * setting and ships the CA bundle. Never write raw CURLOPT_SSL_VERIFYPEER.
     *
     * `body` is a STRING — JSON for the usual calls, or a pre-built
     * `multipart/form-data` payload for a file upload, in which case the caller
     * supplies the matching `Content-Type: multipart/form-data; boundary=…`
     * header itself. Building the multipart body in the provider rather than
     * handing cURL an array keeps it deterministic, avoids a temp file per
     * upload, and means the exact bytes are assertable without a network.
     *
      * @param string $url  the full request URL, query string included
     * @param array $opts ['method'=>'POST','headers'=>[],'body'=>string|array,'auth'=>'user:pass','timeout'=>int]
     */
    protected function httpRequest(string $url, array $opts = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Uploads need longer than a JSON call: a few MB over a slow link will
        // outlast the default and look like an outage.
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)($opts['timeout'] ?? 30));
        sslApplyCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opts['method'] ?? 'GET');
        if (!empty($opts['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
        }
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        if (!empty($opts['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $opts['auth']);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new Exception('Network error talking to the issue tracker: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, $body];
    }
}
