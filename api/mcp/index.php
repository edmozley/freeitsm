<?php
/**
 * FreeITSM MCP server (3.2.0) - the Model Context Protocol, so an AI assistant
 * (Claude Code, Claude Desktop, any MCP client) can read FreeITSM as the
 * analyst an API key belongs to.
 *
 * TRANSPORT: MCP "Streamable HTTP", stateless. One URL; the client POSTs a
 * JSON-RPC 2.0 message and gets a JSON reply (no event stream - every tool here
 * answers at once, so there is nothing to stream). No session is kept: each
 * request is authenticated on its own, exactly like the REST API.
 *
 *   initialize                 -> protocol version, capabilities (tools), server info
 *   notifications/initialized  -> 202, no body
 *   ping                       -> {}
 *   tools/list                 -> the tools THIS key may use (includes/mcp/tools.php)
 *   tools/call                 -> runs one; the answer is text content
 *
 * AUTH: the REST API's keys (System -> API), as "Authorization: Bearer <key>",
 * needing the `mcp.read` permission. Same expiry, rate limit and last-used stamp
 * (apiAuthenticate). What a key may then see is decided per tool - the
 * analyst's module access and capabilities, and the key's company scope - in
 * includes/mcp/tools.php. Read-only: nothing here writes.
 *
 * ⚠️ ORIGIN: a request from a web page carrying an Origin that is not this
 * server's own Host is refused; MCP clients send none. Said plainly (security
 * review): this does NOT stop DNS rebinding - there the Origin and the Host are
 * both the attacker's name. What protects this endpoint from a browser is that
 * it takes ONLY a bearer key: no cookies, no CORS headers, OPTIONS is 405, so a
 * page can never send a credential here. This check is a second, cheap fence.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tenancy.php';
require_once dirname(__DIR__) . '/v1/lib/response.php';
require_once dirname(__DIR__) . '/v1/lib/permissions.php';
require_once dirname(__DIR__) . '/v1/lib/auth.php';
require_once dirname(__DIR__, 2) . '/includes/mcp/tools.php';
require_once dirname(__DIR__, 2) . '/includes/system_name.php';

ini_set('display_errors', '0');
const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_SERVER_VERSION = '1.0.0';

function mcpReply(int $status, ?array $body = null): void
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}
function mcpResult($id, $result): void { mcpReply(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]); }
function mcpRpcError($id, int $code, string $message): void { mcpReply(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]); }

set_exception_handler(function ($e) {
    error_log('MCP uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    mcpReply(500, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']]);
});

// ---- Origin (DNS rebinding) ------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $self = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));   // IPv6-safe
    if ($host === '' || $host !== $self) mcpReply(403, ['error' => 'Origin not allowed.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' || $method === 'DELETE') {
    // No server-to-client stream and no sessions to end: the spec answer is 405.
    header('Allow: POST');
    mcpReply(405);
}
if ($method !== 'POST') { header('Allow: POST'); mcpReply(405); }

// ---- Auth (the REST API's keys) ---------------------------------------------
// A missing key is answered here with the challenge MCP clients look for; the
// rest - unknown, disabled, expired, rate-limited - by apiAuthenticate().
if (apiExtractKey() === null) {
    header('WWW-Authenticate: Bearer realm="FreeITSM MCP"');
    mcpReply(401, ['error' => 'Send an API key from System - API as "Authorization: Bearer <key>". It needs the MCP permission.']);
}
$conn = connectToDatabase();
$apiKey = apiAuthenticate($conn);
// Never wider than the analyst the key acts as (includes/mcp/tools.php).
$apiKey['company_scope'] = mcpEffectiveScope($conn, $apiKey);
$perms = $apiKey['permissions'] ?? [];
if (!in_array('read', $perms['mcp'] ?? [], true)) {
    mcpReply(403, ['error' => "This API key does not have the 'mcp.read' permission (System - API)."]);
}

// ---- JSON-RPC -------------------------------------------------------------------
$raw = file_get_contents('php://input');
$msg = json_decode((string)$raw, true);
if (!is_array($msg)) mcpRpcError(null, -32700, 'Parse error');
if ($msg !== [] && array_keys($msg) === range(0, count($msg) - 1)) mcpRpcError(null, -32600, 'Batches are not supported (MCP 2025-06-18 removed them).');
if (($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method']) || !is_string($msg['method'])) mcpRpcError($msg['id'] ?? null, -32600, 'Invalid Request');

$isNotification = !array_key_exists('id', $msg);
$id = $msg['id'] ?? null;
$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

// A notification (or a response the client sends back) gets 202 and nothing else.
if ($isNotification) mcpReply(202);

switch ($msg['method']) {
    case 'initialize':
        $asked = (string)($params['protocolVersion'] ?? '');
        mcpResult($id, [
            'protocolVersion' => in_array($asked, MCP_PROTOCOL_VERSIONS, true) ? $asked : MCP_PROTOCOL_VERSIONS[0],
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'freeitsm', 'title' => systemName(), 'version' => MCP_SERVER_VERSION],
            'instructions'    => 'FreeITSM, read-only, as ' . ($apiKey['analyst_name'] ?? 'the key\'s analyst') . '. '
                               . 'You see what that analyst may see. Projects are referred to by code (PRJ-0042); tickets by their number. '
                               . 'Health in Projects is worked out from the plan - green, amber or red - and the tools say why.',
        ]);
        // no break: mcpResult exits
    case 'ping':
        mcpResult($id, new stdClass());
    case 'tools/list':
        mcpResult($id, ['tools' => mcpToolsFor($conn, $apiKey)]);
    case 'tools/call':
        $name = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $r = mcpRunTool($conn, $apiKey, $name, $args);
        if ($r === null) mcpRpcError($id, -32602, 'Unknown tool: ' . $name);
        mcpResult($id, ['content' => [['type' => 'text', 'text' => $r[0]]], 'isError' => $r[1]]);
    default:
        mcpRpcError($id, -32601, 'Method not found: ' . $msg['method']);
}
