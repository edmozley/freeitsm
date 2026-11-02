<?php
/**
 * API: find Entra groups for the pickers on system/sso/entra.php.
 *
 * POST { provider_id, q?, ids?, ...connection overrides }
 *
 *   q   — search by name (Graph $search, so "fin" finds "Finance UK"); an
 *         object id pasted in is looked up directly. Empty lists the first 50.
 *   ids — resolve stored object ids to names, so the page can show what is
 *         chosen. A group that no longer exists comes back with missing: true,
 *         which is exactly the thing an administrator needs to see.
 *
 * Read-only.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/entra_sync.php';

csrfRequireWriteMethod();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];

/**
 * The fields the picker shows, from one Graph group.
 *
 * @param array $g  one item of /groups
 */
function entraPickerGroup(array $g): array
{
    return [
        'id'          => strtolower((string)($g['id'] ?? '')),
        'name'        => (string)($g['displayName'] ?? ''),
        'description' => (string)($g['description'] ?? ''),
        'kind'        => entraGroupKind($g),
    ];
}

try {
    $conn = connectToDatabase();
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'oidc'");
    $s->execute([(int)($data['provider_id'] ?? 0)]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    // Entra only: the stored secret goes to Microsoft, and another provider's must not.
    if (!$row || !entraIsEntraIssuer((string)$row['issuer_url'])) {
        echo json_encode(['success' => false, 'error' => 'Provider not found']);
        exit;
    }
    $cfg = entraConfig(entraApplyFormOverrides($row, $data));

    $out = [];
    if (isset($data['ids'])) {
        foreach (array_slice(entraIdList(is_array($data['ids']) ? implode("\n", $data['ids']) : (string)$data['ids']), 0, 200) as $gid) {
            try {
                $out[] = entraPickerGroup(entraGet($cfg, '/groups/' . rawurlencode($gid) . '?$select=' . ESYNC_GROUP_SELECT));
            } catch (EntraGraphError $e) {
                if ($e->status !== 404) throw $e;
                $out[] = ['id' => $gid, 'name' => '', 'description' => '', 'kind' => '', 'missing' => true];
            }
        }
    } else {
        $q = trim((string)($data['q'] ?? ''));
        if (entraIsGuid($q)) {
            $out[] = entraPickerGroup(entraGet($cfg, '/groups/' . rawurlencode(strtolower($q)) . '?$select=' . ESYNC_GROUP_SELECT));
        } else {
            // Quotes and backslashes would end the $search phrase early.
            $q = str_replace(['"', '\\'], '', mb_substr($q, 0, 100));
            $path = '/groups?$select=' . ESYNC_GROUP_SELECT . '&$top=50'
                  . ($q !== '' ? '&$search=' . rawurlencode('"displayName:' . $q . '"') : '');
            foreach (entraGet($cfg, $path)['value'] ?? [] as $g) $out[] = entraPickerGroup($g);
            usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }
    }

    echo json_encode(['success' => true, 'groups' => $out]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => entraErrorForAdmin($e, 'search_entra_groups')]);
}
