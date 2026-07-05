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
    /** @param ?array<int> $companyScope null = all companies, else the allowed tenant ids */
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

    /** Build from an authenticated API key row (API adapters). */
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
            return null;
        }
    }
}

/**
 * A business-rule failure raised by a service. $kind drives the HTTP status the
 * API adapter emits; $code + the message are passed through verbatim so the
 * API's existing error bodies stay byte-identical.
 */
class ServiceError extends Exception
{
    /** @var string one of: validation | not_found | forbidden | conflict */
    public string $kind;
    /** @var string machine slug, e.g. 'missing_field', 'invalid_field', 'not_found' (named errorCode to avoid Exception::$code) */
    public string $errorCode;

    public function __construct(string $kind, string $code, string $message)
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->errorCode = $code;
    }
}

/** Map a ServiceError kind to the HTTP status the API adapter should return. */
function serviceErrorHttpStatus(string $kind): int
{
    switch ($kind) {
        case 'not_found':  return 404;
        case 'forbidden':  return 403;
        case 'conflict':   return 409;
        case 'validation':
        default:           return 422;
    }
}
