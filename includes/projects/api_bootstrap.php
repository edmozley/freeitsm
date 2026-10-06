<?php
/**
 * Projects - the start of every api/projects/*.php endpoint.
 *
 * Signed in, allowed into the module, JSON out, a connection and an actor.
 * Kept here rather than repeated in every endpoint so a guard cannot be
 * forgotten on the next one. Lives in includes/ so it cannot be requested on
 * its own. Mirrors includes/domains/api_bootstrap.php.
 */

session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/../services/projects.php';
require_once __DIR__ . '/read.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('projects');

$conn      = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$ctx       = ActorContext::fromSession($conn);

/** The decoded JSON body (never null). */
function projectApiBody(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }
    return $b;
}

function projectApiOk(array $data = []): void
{
    echo json_encode(['success' => true] + $data);
    exit;
}

function projectApiFail(string $message, int $status = 200): void
{
    if ($status !== 200) http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/** Writes are POST only, so a link or an image tag can never change a project. */
function projectApiRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') projectApiFail('POST required.', 405);
}

/**
 * The company a NEW project belongs to: the one the request names (checked
 * against what this analyst may reach), else the analyst's active company.
 */
function projectApiTenantForCreate(PDO $conn, int $analystId, $requested): int
{
    if ($requested !== null && $requested !== '' && (int)$requested > 0 && isMultiTenant($conn)) {
        $t = (int)$requested;
        if (!getTenantById($conn, $t) || !analystCanAccessTenant($conn, $analystId, $t)) {
            projectApiFail('You cannot add projects for that company.');
        }
        return $t;
    }
    return getActiveTenantId($conn, $analystId);
}

/** Run a service call and turn any failure into the UI's {success:false} shape. */
function projectApiRun(callable $fn): void
{
    try {
        $fn();
    } catch (ServiceError $e) {
        projectApiFail($e->getMessage());
    } catch (Throwable $e) {
        error_log('projects api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        projectApiFail('Something went wrong: ' . $e->getMessage());
    }
}
