<?php
/**
 * Starters & leavers - the start of every api/starters/*.php endpoint.
 *
 * Signed in, allowed into the module, JSON out, a connection and an actor.
 * Kept here rather than repeated in every endpoint so a guard cannot be
 * forgotten on the next one. Lives in includes/ so it cannot be requested on
 * its own. Mirrors includes/projects/api_bootstrap.php.
 */

session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/service.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('starters');

$conn      = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$ctx       = ActorContext::fromSession($conn);

if (!StartersService::ready($conn)) {
    echo json_encode(['success' => false, 'error' => 'Run Database Verification (System -> Database Verification) to set up Starters & leavers.']);
    exit;
}

/** The decoded JSON body (never null). */
function startersApiBody(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }
    return $b;
}

/**
 * Sends a success JSON reply ({success: true} plus $data) and ends the request.
 *
 * @param array $data  extra keys to send beside success: true
 */
function startersApiOk(array $data = []): void
{
    echo json_encode(['success' => true] + $data);
    exit;
}

/**
 * Sends a failure JSON reply ({success: false, error}) and ends the request.
 *
 * @param string $message  the error text
 * @param int $status  the HTTP status; 200 leaves it alone
 */
function startersApiFail(string $message, int $status = 200): void
{
    if ($status !== 200) http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/** Writes are POST only, so a link or an image tag can never change a case. */
function startersApiRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') startersApiFail('POST required.', 405);
}

/**
 * Run a service call and turn any failure into the UI's {success:false} shape.
 *
 * @param callable $fn  the work to run
 */
function startersApiRun(callable $fn): void
{
    try {
        $fn();
    } catch (ServiceError $e) {
        startersApiFail($e->getMessage());
    } catch (Throwable $e) {
        error_log('starters api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        startersApiFail('Something went wrong: ' . $e->getMessage());
    }
}
