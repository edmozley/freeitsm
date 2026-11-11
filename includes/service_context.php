<?php
/**
 * Service-layer shared primitives.
 *
 * The service layer holds a module's business rules ONCE; the UI and the REST
 * API are thin adapters over it (see docs/design/service-layer.md). Auth differs
 * between them (session vs API key) but the rules must not — so each adapter
 * distils its caller into a normalised ActorContext and hands that to the
 * service. The service never sees a session, an API key, or any superglobal.
 *
 * Services report failure by throwing ServiceError (never by emitting HTTP);
 * the adapter maps it to its own response — the API to an HTTP status + code,
 * the UI to {success:false,error}.
 */

/**
 * Who is acting, and what they may see — the only auth-derived facts a service
 * needs. Both adapters produce the same shape from their own transport.
 */
final class ActorContext
{
    /**
     * Who is acting, which companies they may reach, where the call came from (ui or api),
     * their locale and display name.
     *
     * @param int $actorId  the acting analyst's id; 0 for a portal user or the system
     * @param ?array<int> $companyScope null = all companies, else the allowed tenant ids
     * @param string $locale  the actor's language code, e.g. 'en'
     */
    public function __construct(
        public int $actorId,
        public ?array $companyScope = null,
        public string $source = 'api',      // 'ui' | 'api'
        public string $locale = 'en',
        public string $actorName = ''       // the acting analyst's display name (for *_by attribution columns)
    ) {}

    /** Build from the logged-in analyst session (UI adapters). */
    public static function fromSession(PDO $conn): self
    {
        $actorId = (int)($_SESSION['analyst_id'] ?? 0);
        return new self(
            actorId:      $actorId,
            companyScope: self::sessionCompanyScope($conn, $actorId),
            source:       'ui',
            locale:       class_exists('I18n') ? I18n::getLocale() : 'en',
            actorName:    (string)($_SESSION['analyst_name'] ?? '')
        );
    }

    /**
     * Build from a signed-in SELF-SERVICE PORTAL user.
     *
     * 🔴 actorId is 0 ON PURPOSE. It names an ANALYST, and a requester is not
     * one - there is no honest value. Anything that writes an actor must cope
     * with 0 by storing NULL rather than inventing a member of staff, and
     * ticket_audit.analyst_id is nullable for exactly this reason.
     *
     * companyScope is null because a portal user reaches nothing by scope: every
     * portal endpoint is narrowed to rows they own, by user id, before a service
     * is called at all.
     *
     * @param string $name the requester's display name, for the audit trail
     */
    public static function fromPortalUser(string $name = ''): self
    {
        return new self(
            actorId:      0,
            companyScope: null,
            source:       'portal',
            locale:       class_exists('I18n') ? I18n::getLocale() : 'en',
            actorName:    $name
        );
    }

    /**
     * Build for something no person did: an inventory agent, Intune, a cron job
     * (PR #164). actorId 0 is recorded as "no analyst" (NULL) in history, and
     * companyScope null means the caller has already decided the company - an
     * agent's key carries it, Intune's setting names it.
     *
     * @param string $name  what to record as the actor in history, e.g. 'Intune' or 'Inventory agent'
     */
    public static function system(string $name = 'System'): self
    {
        return new self(
            actorId:      0,
            companyScope: null,
            source:       'system',
            locale:       'en',
            actorName:    $name
        );
    }

    /**
     * Build from an authenticated API key row (API adapters).
     *
     * @param array $apiKey  the authenticated API key row from apiAuthenticate(), with analyst_id, company_scope and analyst_name
     */
    public static function fromApiKey(array $apiKey): self
    {
        return new self(
            actorId:      (int)$apiKey['analyst_id'],
            companyScope: $apiKey['company_scope'] ?? null,   // apiAuthenticate() already computes this (null = all)
            source:       'api',
            locale:       'en',
            actorName:    (string)($apiKey['analyst_name'] ?? '')
        );
    }

    /**
     * The analyst's company scope in the API's null-means-all convention:
     * single-company install or an all-tenant analyst → null; otherwise the
     * explicit accessible tenant id list. (Unused by install-wide modules; the
     * first tenant-scoped module to migrate exercises it.)
     */
    private static function sessionCompanyScope(PDO $conn, int $analystId): ?array
    {
        if (!function_exists('isMultiTenant') || !isMultiTenant($conn) || $analystId <= 0) {
            return null;
        }
        try {
            $stmt = $conn->prepare("SELECT can_access_all_tenants FROM analysts WHERE id = ?");
            $stmt->execute([$analystId]);
            if ((int)$stmt->fetchColumn() === 1) return null; // all companies
            return getAccessibleTenantIds($conn, $analystId);
        } catch (Throwable $e) {
            // 🔴 Null means ALL companies, so a lookup that failed - a lock wait, a
            // lost connection - used to widen the scope to everything. Fail closed,
            // except for the one case tenancy.php allows: a genuinely part-migrated
            // install. Reported privately, October 2026.
            return ($e instanceof Exception && function_exists('tenancyDegradeAllowed') && tenancyDegradeAllowed($e)) ? null : [];
        }
    }
}

/**
 * A business-rule failure raised by a service. $kind drives the HTTP status the
 * API adapter emits; $code + the message are passed through verbatim so the
 * API's existing error bodies stay byte-identical.
 *
 * TRAP: a file that throws ServiceError must require_once this file itself.
 *   Relying on the caller to have loaded it works until one caller hasn't - then
 *   the throw is a fatal "Class ServiceError not found" and the page an HTTP 500
 *   (GH #288: a change opened with Enforce approvals on). tests/service-error-loaded.php
 *   fails any file that throws it without loading it.
 */
class ServiceError extends Exception
{
    /** @var string one of: validation | not_found | forbidden | conflict */
    public string $kind;
    /** @var string machine slug, e.g. 'missing_field', 'invalid_field', 'not_found' (named errorCode to avoid Exception::$code) */
    public string $errorCode;

    /**
     * A service failure with a kind (validation, not_found, conflict, forbidden...) that
     * adapters turn into an HTTP status, a machine code and a message.
     *
     * @param string $kind  validation, not_found, forbidden, conflict or bad_request - decides the HTTP status
     * @param string $code  machine slug for the API body, e.g. 'missing_field'
     * @param string $message  the human-readable error
     */
    public function __construct(string $kind, string $code, string $message)
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->errorCode = $code;
    }
}

/**
 * Map a ServiceError kind to the HTTP status the API adapter should return.
 *
 * @param string $kind  the ServiceError kind; anything unknown maps to 422
 */
function serviceErrorHttpStatus(string $kind): int
{
    switch ($kind) {
        case 'bad_request': return 400;  // malformed input (e.g. an unparseable date/time)
        case 'not_found':  return 404;
        case 'forbidden':  return 403;
        case 'conflict':   return 409;
        case 'validation':
        default:           return 422;
    }
}
