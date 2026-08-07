<?php
/**
 * A narrow guard against cross-site request forgery via "simple" requests.
 *
 * ⚠️ WHY THIS EXISTS. FreeITSM has no CSRF token mechanism. JSON endpoints get
 * partial protection from the browser's preflight — a cross-site fetch with
 * `Content-Type: application/json` is not a CORS-simple request, so the browser asks
 * permission first and never sends the real one. But 369 endpoints read php://input
 * WITHOUT checking what the Content-Type actually was, and this is a CORS-simple
 * request that needs no preflight at all:
 *
 *     <form method="POST" action="https://desk.example/api/tickets/save_user.php"
 *           enctype="text/plain">
 *       <input name='{"id":1,"password":"x"}' value='' >
 *     </form>
 *
 * The body arrives as valid JSON, json_decode() is happy, and the endpoint acts on
 * it with the victim's cookie attached. Chrome's SameSite=Lax default blunts this;
 * Firefox and Safari have no such default, which is why the session cookie now
 * states SameSite=Lax explicitly (see includes/session_security.php) — that is the
 * main fix. This is the second line.
 *
 * ── Why it is this narrow ────────────────────────────────────────────────────
 * The thorough answer is a token on every state-changing endpoint, which means
 * auditing 369 files and touching every fetch() in the front end. That is a
 * deliberate follow-up, not something to bolt on inside a security fix.
 *
 * What is safe to do centrally today is refuse the one Content-Type that has no
 * legitimate use here. Our own front end sends `application/json` and nothing else
 * (149 fetch call sites, all of them). Browsers and integrations send
 * application/json, multipart/form-data or application/x-www-form-urlencoded. None
 * of them send text/plain — it exists in this context only as a way to dodge a
 * preflight. Refusing it costs nothing and closes the exact attack above.
 *
 * The other two CORS-simple types are NOT refused, because ordinary HTML forms and
 * file uploads use them; SameSite=Lax is what covers those.
 */

/**
 * Refuse a state-changing request that declares text/plain.
 *
 * Runs on include. Silent on CLI (cron has no request), and on GET/HEAD/OPTIONS,
 * which carry no body to forge.
 */
function rejectSimpleRequestForgery(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }

    // Content-Type carries parameters (charset=…); only the type itself matters.
    $raw  = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $type = strtolower(trim(explode(';', $raw)[0]));

    if ($type !== 'text/plain') {
        return;
    }

    http_response_code(415);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Unsupported Content-Type. Send application/json.',
    ]);
    exit;
}

rejectSimpleRequestForgery();
