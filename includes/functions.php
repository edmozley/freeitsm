<?php
/**
 * Shared Functions
 * Include this file in any script that needs common functionality
 *
 * Usage: require_once '../includes/functions.php'; (from api folder)
 *        require_once 'includes/functions.php'; (from root folder)
 */

/**
 * Connect to MySQL database using PDO
 *
 * @return PDO Database connection
 * @throws Exception If connection fails
 */
function connectToDatabase() {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}

/**
 * Get the list of modules an analyst is allowed to access.
 *
 * @param PDO $conn Database connection
 * @param int $analyst_id Analyst ID
 * @return array|null Null means all access; array of module_key strings if restricted
 */
function getAnalystAllowedModules($conn, $analyst_id) {
    $sql = "SELECT module_key FROM analyst_modules WHERE analyst_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analyst_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        return null; // No restrictions — full access
    }

    return $rows;
}

/**
 * Is this analyst an administrator? Admins are the only accounts allowed into the
 * System module (analyst/team/company management, SSO, security, DB verify, etc.).
 * Authoritative DB check — use this for server-side enforcement (no session lag).
 */
function analystIsAdmin(PDO $conn, int $analystId): bool {
    if ($analystId <= 0) return false;
    $stmt = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Cosmetic (UI-layer) admin check for pages: "should this analyst see System?".
 * Reads $_SESSION['is_admin']; if absent (e.g. a session created before this
 * upgrade) it self-heals by looking the flag up once and caching it in the
 * session, so no login-path changes are needed and no admin is ever locked out.
 * Requires a writable session (not read_and_close). For hard enforcement on APIs
 * use requireAdminJson() instead, which re-checks the DB every call.
 */
function sessionIsAdmin(): bool {
    if (!isset($_SESSION['analyst_id'])) return false;
    if (!array_key_exists('is_admin', $_SESSION)) {
        try {
            $conn = connectToDatabase();
            $_SESSION['is_admin'] = analystIsAdmin($conn, (int) $_SESSION['analyst_id']) ? 1 : 0;
        } catch (Throwable $e) {
            return false; // fail closed
        }
    }
    return !empty($_SESSION['is_admin']);
}

/**
 * Hard gate for System JSON APIs: refuse non-admins with a 403. Authoritative
 * (DB-checked) so a just-demoted analyst can't keep acting on a stale session.
 * Call right after connecting, e.g. requireAdminJson($conn);
 */
function requireAdminJson(PDO $conn): void {
    $id = (int) ($_SESSION['analyst_id'] ?? 0);
    if (!$id || !analystIsAdmin($conn, $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Administrator access required']);
        exit;
    }
}

/**
 * Fire a settings/CRUD workflow event ({entity}.{created|updated|deleted}) from a
 * UI settings endpoint, without each file having to require the workflow engine.
 * Lazily loads the engine and is fully self-safe — swallows any Throwable
 * (including a missing engine on a minimal install) so it can NEVER affect the
 * save it follows. Funnels into the exact same WorkflowEngine::dispatch() path as
 * every other event, so a webhook fires identically however it was emitted.
 */
function wf_emit(string $entity, string $action, int $id, ?string $name = null): void
{
    try {
        require_once __DIR__ . '/../workflow/includes/engine.php';
        WorkflowEngine::emitCrud($entity, $action, $id, $name);
    } catch (Throwable $e) {
        error_log('wf_emit(' . $entity . '.' . $action . ') error: ' . $e->getMessage());
    }
}
?>
