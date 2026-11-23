<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #201: the asset <-> configuration item link.
 *
 * Through the page endpoint as a forged admin, through REST with throwaway keys, and
 * through the service for the parts with no page of their own. Covers: link and the
 * idempotent retry, one live link per side, Replace refused when the link changed since
 * it was looked at, unlink, who sees what (restricted - no name, no id - on the card, in
 * REST, in the history and in conflict messages), write access to both sides, the
 * company rule, a CI delete (link ends, name kept, custom link fields cleared) and a
 * merge (both linked: a choice is required; the original asset is kept on the row; no
 * CI is touched).
 *
 * Everything is prefixed ZZCL and removed in `finally`.
 *
 * Run:  php tests/asset-ci-link.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/cmdb.php';
require_once __DIR__ . '/../includes/services/asset_ci_links.php';
require_once __DIR__ . '/../includes/services/asset_merge.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nAsset <-> configuration item link (GH #201)\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$default = getDefaultTenantId($conn);
$otherTenant = (int)$conn->query("SELECT id FROM tenants WHERE id <> $default ORDER BY id LIMIT 1")->fetchColumn();
$admin = 1;
$sid = 'zzcl' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$assets = []; $cis = []; $keys = []; $fieldId = 0;
$ctx = new ActorContext($admin, null, 'ui', 'en', 'Administrator');
$full = ['assets_read' => true, 'cmdb_read' => true, 'assets_write' => true, 'cmdb_write' => true];

function page(string $base, string $sid, string $csrf, string $path, ?array $post = null) {
    $ch = curl_init($base . '/' . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 30];
    if ($post !== null) {
        $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    return json_decode($body, true) ?? ['raw' => substr($body, 0, 400)];
}

function rest(string $base, string $key, string $method, string $path, ?array $body = null): array {
    $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $opts);
    $res = json_decode((string)curl_exec($ch), true) ?? [];
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $res];
}

try {
    if (!AssetCiLinkService::ready($conn)) { throw new RuntimeException('Run Database Verification first: asset_ci_links is missing.'); }
    file_put_contents($sessFile, 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');

    $mkAsset = function (string $name, ?int $tenant = null) use ($conn, &$assets): int {
        $conn->prepare('INSERT INTO assets (hostname, service_tag, tenant_id) VALUES (?, ?, ?)')->execute([$name, 'ZZCL-' . bin2hex(random_bytes(3)), $tenant]);
        return $assets[] = (int)$conn->lastInsertId();
    };
    // An active class whose required properties can be filled without pointing at another
    // CI (no required object_ref), and a valid value for each of them.
    $classId = (int)$conn->query("SELECT c.id FROM cmdb_classes c WHERE c.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM cmdb_class_properties p WHERE p.class_id = c.id AND p.is_required = 1 AND p.property_type = 'object_ref')
        ORDER BY c.id LIMIT 1")->fetchColumn();
    if (!$classId) { throw new RuntimeException('No active CMDB class to test with.'); }
    $required = [];
    foreach ($conn->query("SELECT id, property_key, property_type FROM cmdb_class_properties WHERE class_id = $classId AND is_required = 1")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $opt = $conn->prepare('SELECT option_value FROM cmdb_class_property_options WHERE property_id = ? ORDER BY display_order, id LIMIT 1');
        $opt->execute([$p['id']]);
        $required[$p['property_key']] = match ($p['property_type']) {
            'number' => 1, 'date' => gmdate('Y-m-d'), 'boolean' => false, 'dropdown' => $opt->fetchColumn() ?: 'x', default => 'ZZCL',
        };
    }
    $mkCi = function (string $name, ?int $tenant = null) use ($conn, $ctx, $classId, $required, &$cis): int {
        $r = CmdbService::saveObject($conn, $ctx, ['name' => $name, 'class_id' => $classId, 'tenant_id' => $tenant, 'properties' => $required]);
        return $cis[] = (int)$r['id'];
    };
    $a1 = $mkAsset('ZZCL-SRV-01'); $a2 = $mkAsset('ZZCL-SRV-02');
    $c1 = $mkCi('ZZCL CI one'); $c2 = $mkCi('ZZCL CI two');
    $q = fn(string $sql) => $conn->query($sql)->fetchColumn();

    // ---- Link through the page -------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?asset_id=' . $a1);
    ok('an unlinked asset reads as no link, and the admin may change it', ($r['success'] ?? false) && $r['current'] === null && $r['can_change'] === true, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?search=cis&asset_id=' . $a1 . '&q=ZZCL');
    ok('the CI picker finds CIs in the asset\'s company', count(array_filter($r['results'] ?? [], fn($x) => in_array($x['id'], [$c1, $c2], true))) === 2, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'link', 'asset_id' => $a1, 'cmdb_object_id' => $c1]);
    ok('Link joins the asset and the CI', ($r['success'] ?? false) && !$r['unchanged'], json_encode($r));
    $first = (int)($r['link_id'] ?? 0);
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'link', 'asset_id' => $a1, 'cmdb_object_id' => $c1]);
    ok('linking the same pair again changes nothing', ($r['success'] ?? false) && $r['unchanged'] === true && (int)$r['link_id'] === $first, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?cmdb_object_id=' . $c1);
    ok('the CI sees the asset, with details read from it', ($r['current']['name'] ?? '') === 'ZZCL-SRV-01' && isset($r['current']['details']['serial']), json_encode($r['current'] ?? $r));
    ok('...and the link was made by the admin, through the page', ($r['current']['link_source'] ?? '') === 'ui' && ($r['current']['linked_by'] ?? '') !== '');

    // ---- One live link per side; Replace with a fresh review ------------------------------------
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'link', 'asset_id' => $a2, 'cmdb_object_id' => $c1]);
    ok('🔴 a CI already linked to another asset is refused, naming it', ($r['code'] ?? '') === 'ci_already_linked' && str_contains($r['error'] ?? '', 'ZZCL-SRV-01'), json_encode($r));
    ok('...and the refusal carries the link it would end', ($r['conflicts'] ?? []) === [$first], json_encode($r['conflicts'] ?? null));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'link', 'asset_id' => $a2, 'cmdb_object_id' => $c1, 'replace' => true, 'replaces' => [$first + 999]]);
    ok('🔴 Replace naming a link that is not the live one is refused (link_changed)', ($r['code'] ?? '') === 'link_changed', json_encode($r));
    ok('...and the original link is still live', (int)$q("SELECT COUNT(*) FROM asset_ci_links WHERE id = $first AND unlinked_at IS NULL") === 1);
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'link', 'asset_id' => $a2, 'cmdb_object_id' => $c1, 'replace' => true, 'replaces' => [$first]]);
    ok('Replace naming exactly the live link moves the CI to the other asset', ($r['success'] ?? false) && ($r['replaced'] ?? []) === [$first], json_encode($r));
    $second = (int)($r['link_id'] ?? 0);
    ok('...the old period ends as replaced, with the CI\'s name kept', $q("SELECT CONCAT(unlink_reason, '|', ci_name) FROM asset_ci_links WHERE id = $first") === 'replaced|ZZCL CI one');
    ok('...and both assets\' histories say so', (int)$q("SELECT COUNT(*) FROM asset_history WHERE field_name = 'cmdb_link' AND asset_id IN ($a1, $a2)") === 3);
    ok('🔴 the history holds the CI id, never its name', (int)$q("SELECT COUNT(*) FROM asset_history WHERE field_name = 'cmdb_link' AND asset_id IN ($a1, $a2) AND (old_value LIKE '%ZZCL%' OR new_value LIKE '%ZZCL%')") === 0);
    $h = page($base, $sid, $csrf, 'api/assets/get_asset_history.php?asset_id=' . $a1);
    $names = array_column(array_filter($h['history'] ?? [], fn($e) => $e['field_name'] === 'cmdb_link'), 'new_value');
    ok('...which the history page names for a reader who can open the CMDB', in_array('ZZCL CI one', $names, true), json_encode($names));

    // ---- Unlink -----------------------------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'unlink', 'cmdb_object_id' => $c1, 'link_id' => $first]);
    ok('🔴 Unlink of a link that is no longer live is refused', ($r['code'] ?? '') === 'link_changed', json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'unlink', 'cmdb_object_id' => $c1, 'link_id' => $second]);
    ok('Unlink from the CI ends the live link', ($r['success'] ?? false) === true, json_encode($r));
    ok('...and keeps both records', (int)$q("SELECT COUNT(*) FROM assets WHERE id = $a2") === 1 && (int)$q("SELECT COUNT(*) FROM cmdb_objects WHERE id = $c1") === 1);
    ok('...recording who ended it and how', $q("SELECT CONCAT(unlink_reason, '|', unlink_source, '|', unlinked_by_id) FROM asset_ci_links WHERE id = $second") === "unlinked|ui|$admin");

    // ---- Who may see and change it ---------------------------------------------------------------
    AssetCiLinkService::link($conn, $ctx, $full, $a1, $c1);
    $noCmdb = ['assets_read' => true, 'cmdb_read' => false, 'assets_write' => true, 'cmdb_write' => false];
    $v = AssetCiLinkService::forAsset($conn, $ctx, $noCmdb, $a1);
    ok('🔴 a reader who cannot open the CMDB sees "restricted" - no id, no name', $v['current'] === ['restricted' => true], json_encode($v['current']));
    ok('🔴 ...and the history names no CI', !array_filter($v['history'], fn($e) => !($e['other']['restricted'] ?? false)), json_encode($v['history']));
    ok('...and may not change it', $v['can_change'] === false);
    $refused = null;
    try { AssetCiLinkService::link($conn, $ctx, $noCmdb, $a2, $c2); } catch (ServiceError $e) { $refused = $e->errorCode; }
    ok('🔴 linking without write access to both sides is refused', $refused === 'read_only', (string)$refused);
    $noAssets = ['assets_read' => false, 'cmdb_read' => true, 'assets_write' => true, 'cmdb_write' => true];
    $msg = '';
    try { AssetCiLinkService::link($conn, $ctx, $noAssets + ['assets_write' => true], $a2, $c1); } catch (ServiceError $e) { $msg = $e->getMessage(); }
    ok('🔴 a conflict message never names an asset the reader cannot open', $msg !== '' && !str_contains($msg, 'ZZCL-SRV-01') && str_contains($msg, 'cannot open'), $msg);
    $v = AssetCiLinkService::forCi($conn, $ctx, $noAssets, $c1);
    ok('🔴 the CI side, without Assets, sees "restricted" and no details', $v['current'] === ['restricted' => true], json_encode($v['current']));
    ok('history values read as restricted without the CMDB', AssetCiLinkService::historyValue($conn, 'ci:' . $c1, false) === 'a restricted configuration item'
        && AssetCiLinkService::historyValue($conn, 'ci:' . $c1, false, false) === 'restricted');

    // ---- The asset card setting only narrows -----------------------------------------------------
    $before = $q("SELECT setting_value FROM system_settings WHERE setting_key = 'cmdb_asset_card_fields'");
    AssetCiLinkService::saveCardFields($conn, ['serial']);
    $v = AssetCiLinkService::forCi($conn, $ctx, $full, $c1);
    ok('the Asset card setting chooses which details show', array_keys($v['current']['details'] ?? []) === ['serial'], json_encode($v['current']['details'] ?? null));
    $v = AssetCiLinkService::forCi($conn, $ctx, $noAssets, $c1);
    ok('🔴 ...and never shows them to someone who cannot open the asset', $v['current'] === ['restricted' => true]);
    if ($before === false) { $conn->exec("DELETE FROM system_settings WHERE setting_key = 'cmdb_asset_card_fields'"); }
    else { $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'cmdb_asset_card_fields'")->execute([$before]); }

    // ---- Companies --------------------------------------------------------------------------------
    if ($otherTenant) {
        $far = $mkCi('ZZCL far CI', $otherTenant);
        $code = null;
        try { AssetCiLinkService::link($conn, $ctx, $full, $a2, $far); } catch (ServiceError $e) { $code = $e->errorCode; }
        ok('🔴 an asset and a CI in different companies are never linked', $code === 'different_company', (string)$code);
        $scoped = new ActorContext($admin, [$default], 'ui');
        $code = null;
        try { AssetCiLinkService::forCi($conn, $scoped, $full, $far); } catch (ServiceError $e) { $code = $e->kind; }
        ok('🔴 another company\'s CI is not found for a reader scoped to this one', $code === 'not_found', (string)$code);
    }

    // ---- REST ---------------------------------------------------------------------------------------
    $mkKey = function (array $perms) use ($conn, $admin, &$keys): string {
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
            ->execute(['ZZCL key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode($perms)]);
        $keys[] = (int)$conn->lastInsertId();
        return $key;
    };
    $kFull = $mkKey(['asset_ci_links' => ['read', 'create', 'delete'], 'assets' => ['read'], 'cmdb_objects' => ['read']]);
    $kNoCmdb = $mkKey(['asset_ci_links' => ['read'], 'assets' => ['read']]);
    [$code, $res] = rest($base, $kFull, 'GET', "/assets/$a1/ci-link");
    ok('REST: GET /assets/{id}/ci-link shows the live CI', $code === 200 && ($res['data']['current']['id'] ?? 0) === $c1, $code . ' ' . json_encode($res));
    [$code, $res] = rest($base, $kNoCmdb, 'GET', "/assets/$a1/ci-link");
    ok('🔴 REST: without cmdb_objects.read the CI end is restricted', $code === 200 && ($res['data']['current'] ?? null) === ['restricted' => true], $code . ' ' . json_encode($res));
    [$code, $res] = rest($base, $kNoCmdb, 'GET', "/assets/$a1");
    ok('🔴 REST: GET /assets/{id} carries linked_ci, restricted for that key', $code === 200 && ($res['data']['linked_ci'] ?? null) === ['restricted' => true], $code . ' ' . json_encode($res['data']['linked_ci'] ?? $res));
    [$code, $res] = rest($base, $kFull, 'POST', "/assets/$a2/ci-link", ['cmdb_object_id' => $c1]);
    ok('REST: a conflict is 409 with the live link ids', $code === 409 && ($res['error']['code'] ?? '') === 'ci_already_linked' && count($res['error']['details']['conflicts'] ?? []) === 1, $code . ' ' . json_encode($res));
    [$code, $res] = rest($base, $kFull, 'POST', "/assets/$a2/ci-link", ['cmdb_object_id' => $c2]);
    ok('REST: POST links, recorded as made by the key', $code === 200 && $q("SELECT link_source FROM asset_ci_links WHERE asset_id = $a2 AND unlinked_at IS NULL") === 'api:ZZCL key', $code . ' ' . json_encode($res));
    [$code, $res] = rest($base, $kNoCmdb, 'POST', "/assets/$a2/ci-link", ['cmdb_object_id' => $c2]);
    ok('REST: a key without asset_ci_links.create cannot link', $code === 403, (string)$code);
    [$code, $res] = rest($base, $kFull, 'DELETE', "/assets/$a2/ci-link");
    ok('REST: DELETE ends the link', $code === 200, $code . ' ' . json_encode($res));
    [$code, $res] = rest($base, $kFull, 'DELETE', "/assets/$a2/ci-link");
    ok('REST: DELETE with nothing linked is 404', $code === 404, (string)$code);

    // ---- Deleting a CI ----------------------------------------------------------------------------
    AssetCiLinkService::link($conn, $ctx, $full, $a2, $c2);
    $conn->prepare("INSERT INTO asset_fields (field_key, label, field_type, config, tenant_id) VALUES ('zzcl_ci_ref', 'ZZCL CI ref', 'ref', ?, NULL)")
        ->execute([json_encode(['ref_kind' => 'cmdb_object'])]);
    $fieldId = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO asset_field_values (asset_id, field_id, seq, value_ref_id) VALUES (?, ?, 0, ?)')->execute([$a1, $fieldId, $c2]);
    $r = page($base, $sid, $csrf, 'api/cmdb/delete_object.php', ['id' => $c2]);
    ok('a linked CI can still be deleted', ($r['success'] ?? false) === true, json_encode($r));
    ok('🔴 ...its link ends as ci_deleted inside the delete, with its name kept', $q("SELECT CONCAT(unlink_reason, '|', ci_name) FROM asset_ci_links WHERE cmdb_object_id = $c2 ORDER BY id DESC LIMIT 1") === 'ci_deleted|ZZCL CI two');
    ok('...the asset is kept and reads as unlinked', (int)$q("SELECT COUNT(*) FROM assets WHERE id = $a2") === 1 && AssetCiLinkService::forAsset($conn, $ctx, $full, $a2)['current'] === null);
    ok('...and its history still names the deleted CI', AssetCiLinkService::historyValue($conn, 'ci:' . $c2, true) === 'ZZCL CI two');
    ok('🔴 a custom link field pointing at the deleted CI is cleared (it used to show #id)', (int)$q("SELECT COUNT(*) FROM asset_field_values WHERE field_id = $fieldId") === 0);
    ok('...with a history line on that asset', (int)$q("SELECT COUNT(*) FROM asset_history WHERE asset_id = $a1 AND field_name = 'field:zzcl_ci_ref' AND old_value = '$c2'") === 1);
    $cis = array_values(array_diff($cis, [$c2]));

    // ---- Merging two linked assets ----------------------------------------------------------------
    $a3 = $mkAsset('ZZCL-MERGE-KEEP'); $a4 = $mkAsset('ZZCL-MERGE-GONE');
    $c3 = $mkCi('ZZCL CI three'); $c4 = $mkCi('ZZCL CI four');
    $l3 = AssetCiLinkService::link($conn, $ctx, $full, $a3, $c3)['link_id'];
    $l4 = AssetCiLinkService::link($conn, $ctx, $full, $a4, $c4)['link_id'];
    $preview = AssetCiLinkService::mergePreview($conn, $ctx, $full, $a3, $a4);
    ok('the merge preview shows both links and asks for a choice', $preview['choice_needed'] === true && $preview['seen'] === [min($l3, $l4), max($l3, $l4)], json_encode($preview));
    $code = null;
    try { AssetMergeService::merge($conn, $ctx, $a3, $a4); } catch (ServiceError $e) { $code = $e->errorCode; }
    ok('🔴 a merge of two linked assets without a choice is refused', $code === 'ci_link_choice_required', (string)$code);
    ok('...and nothing was merged', (int)$q("SELECT COUNT(*) FROM assets WHERE id = $a4") === 1);
    $code = null;
    try { AssetMergeService::merge($conn, $ctx, $a3, $a4, null, ['seen' => [$l3], 'keep_link_id' => $l4]); } catch (ServiceError $e) { $code = $e->errorCode; }
    ok('🔴 a merge whose window showed different links is refused', $code === 'ci_link_changed', (string)$code);
    $rels = (int)$q("SELECT COUNT(*) FROM cmdb_object_relationships WHERE from_object_id IN ($c3, $c4) OR to_object_id IN ($c3, $c4)");
    AssetMergeService::merge($conn, $ctx, $a3, $a4, null, ['seen' => $preview['seen'], 'keep_link_id' => $l4]);
    ok('the chosen link (from the merged-away asset) survives on the kept asset', (int)$q("SELECT asset_id FROM asset_ci_links WHERE id = $l4 AND unlinked_at IS NULL") === $a3);
    ok('🔴 ...still recording the asset it was made on', $q("SELECT CONCAT(original_asset_id, '|', asset_name) FROM asset_ci_links WHERE id = $l4") === "$a4|ZZCL-MERGE-GONE");
    ok('the other link ended as asset_merged and stays in the history', $q("SELECT unlink_reason FROM asset_ci_links WHERE id = $l3") === 'asset_merged');
    ok('🔴 neither CI was changed or deleted, nor their relationships', (int)$q("SELECT COUNT(*) FROM cmdb_objects WHERE id IN ($c3, $c4)") === 2
        && (int)$q("SELECT COUNT(*) FROM cmdb_object_relationships WHERE from_object_id IN ($c3, $c4) OR to_object_id IN ($c3, $c4)") === $rels);
    $v = AssetCiLinkService::forAsset($conn, $ctx, $full, $a3);
    ok('the kept asset\'s card shows the whole history, saying where a link was made', count($v['history']) === 2
        && count(array_filter($v['history'], fn($e) => ($e['made_on']['name'] ?? '') === 'ZZCL-MERGE-GONE')) === 1, json_encode($v['history']));
    $assets = array_values(array_diff($assets, [$a4]));

    // ---- Part C: Create linked CI ---------------------------------------------------------------
    $conn->prepare("INSERT INTO asset_types (name, is_active, display_order, cmdb_class_id) VALUES ('ZZCL type', 1, 0, ?)")->execute([$classId]);
    $typeId = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO asset_types (name, is_active, display_order) VALUES ('ZZCL unmapped', 1, 0)")->execute();
    $plainType = (int)$conn->lastInsertId();
    $a5 = $mkAsset('ZZCL-NEW-01');
    $conn->prepare('UPDATE assets SET asset_type_id = ? WHERE id = ?')->execute([$typeId, $a5]);
    $a6 = $mkAsset('ZZCL-NEW-02');
    $conn->prepare('UPDATE assets SET asset_type_id = ? WHERE id = ?')->execute([$plainType, $a6]);
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?asset_id=' . $a5);
    ok('an unlinked asset of a mapped type offers Create linked CI, naming the class', ($r['create_class']['id'] ?? 0) === $classId, json_encode($r['create_class'] ?? $r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?asset_id=' . $a6);
    ok('...and an unmapped type does not', array_key_exists('create_class', $r) && $r['create_class'] === null, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?create_preview=1&asset_id=' . $a5);
    ok('the preview lists the class\'s required properties', count($r['required'] ?? []) === count($required), json_encode($r));
    $same = $mkCi('zzcl-new-01');   // same name as the asset, different case
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php?create_preview=1&asset_id=' . $a5);
    ok('a same-name CI of that class is offered as a possible match, ignoring case', array_column($r['matches'] ?? [], 'id') === [$same], json_encode($r['matches'] ?? $r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'create_ci', 'asset_id' => $a5, 'properties' => $required]);
    ok('🔴 creating with a possible match unseen is refused - never linked on a name', ($r['code'] ?? '') === 'possible_matches'
        && AssetCiLinkService::forAsset($conn, $ctx, $full, $a5)['current'] === null, json_encode($r));
    $before = (int)$q("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE 'ZZCL%' OR name LIKE 'zzcl%'");
    $bad = array_map(fn($v) => is_bool($v) ? 'not-a-bool' : (is_int($v) ? 'NaN' : $v), $required);
    $bad['zzcl_no_such_property'] = 'x';
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'create_ci', 'asset_id' => $a5, 'properties' => $bad, 'ignore_matches' => true]);
    ok('🔴 a create the CMDB refuses leaves no CI and no link behind', ($r['success'] ?? true) === false
        && (int)$q("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE 'ZZCL%' OR name LIKE 'zzcl%'") === $before
        && AssetCiLinkService::forAsset($conn, $ctx, $full, $a5)['current'] === null, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'create_ci', 'asset_id' => $a5, 'name' => 'ZZCL created CI', 'properties' => $required, 'ignore_matches' => true]);
    $made = (int)($r['cmdb_object_id'] ?? 0);
    if ($made) { $cis[] = $made; }
    ok('Create linked CI makes the CI and the link together', ($r['success'] ?? false) && $r['created'] === true
        && (int)$q("SELECT class_id FROM cmdb_objects WHERE id = $made") === $classId
        && (AssetCiLinkService::forAsset($conn, $ctx, $full, $a5)['current']['id'] ?? 0) === $made, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'create_ci', 'asset_id' => $a5, 'properties' => $required, 'ignore_matches' => true]);
    ok('🔴 a retry creates nothing and returns the linked CI', ($r['success'] ?? false) && $r['created'] === false && (int)$r['cmdb_object_id'] === $made, json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/ci_link.php', ['action' => 'create_ci', 'asset_id' => $a6, 'properties' => $required]);
    ok('an unmapped type is refused (no_class_mapped)', ($r['code'] ?? '') === 'no_class_mapped', json_encode($r));
    ok('the possible match was left alone, unlinked', AssetCiLinkService::forCi($conn, $ctx, $full, $same)['current'] === null);
    [$code, $res] = rest($base, $kFull, 'POST', "/assets/$a6/ci-link/create-ci", ['properties' => $required]);
    ok('REST: create-ci also needs cmdb_objects.create', $code === 403, $code . ' ' . json_encode($res));
    $kCreate = $mkKey(['asset_ci_links' => ['read', 'create'], 'cmdb_objects' => ['read', 'create'], 'assets' => ['read']]);
    $conn->prepare('UPDATE assets SET asset_type_id = ? WHERE id = ?')->execute([$typeId, $a6]);
    $same2 = $mkCi('ZZCL-NEW-02');
    [$code, $res] = rest($base, $kCreate, 'POST', "/assets/$a6/ci-link/create-ci", ['properties' => $required]);
    ok('REST: with the same name already used, 409 possible_matches lists them', $code === 409 && array_column($res['error']['details']['matches'] ?? [], 'id') === [$same2], $code . ' ' . json_encode($res));
    $conn->prepare('UPDATE assets SET hostname = ? WHERE id = ?')->execute(['ZZCL-NEW-03', $a6]);
    [$code, $res] = rest($base, $kCreate, 'POST', "/assets/$a6/ci-link/create-ci", ['properties' => $required]);
    if (!empty($res['data']['cmdb_object_id'])) { $cis[] = (int)$res['data']['cmdb_object_id']; }
    ok('REST: create-ci answers 201 with the new CI, linked by the key', $code === 201 && ($res['data']['created'] ?? false) === true
        && $q("SELECT link_source FROM asset_ci_links WHERE asset_id = $a6 AND unlinked_at IS NULL") === 'api:ZZCL key', $code . ' ' . json_encode($res));
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    $ids = array_merge($assets, isset($a4) ? [$a4] : []);
    foreach ($ids as $id) {
        try { $conn->prepare('DELETE FROM asset_ci_links WHERE asset_id = ? OR original_asset_id = ?')->execute([$id, $id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_history WHERE asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_field_values WHERE asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_merges WHERE into_asset_id = ? OR merged_asset_id = ?')->execute([$id, $id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZCL-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    foreach ($cis as $ci) {
        try { $conn->prepare('DELETE FROM asset_ci_links WHERE cmdb_object_id = ?')->execute([$ci]); } catch (Throwable $e) {}
        try { CmdbService::deleteObject($conn, $ctx, $ci); } catch (Throwable $e) {}
    }
    try { $conn->exec("DELETE FROM asset_ci_links WHERE asset_name LIKE 'ZZCL-%' OR ci_name LIKE 'ZZCL %'"); } catch (Throwable $e) {}
    try { $conn->exec("DELETE FROM asset_types WHERE name IN ('ZZCL type', 'ZZCL unmapped')"); } catch (Throwable $e) {}
    if ($fieldId) { try { $conn->prepare('DELETE FROM asset_fields WHERE id = ?')->execute([$fieldId]); } catch (Throwable $e) {} }
    foreach ($keys as $k) {
        try { $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ?')->execute([$k]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$k]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    echo '  cleanup: ' . (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZCL-%'")->fetchColumn() . ' ZZCL assets, '
        . (int)$conn->query("SELECT COUNT(*) FROM cmdb_objects WHERE name LIKE 'ZZCL %'")->fetchColumn() . ' ZZCL CIs, '
        . (int)$conn->query("SELECT COUNT(*) FROM asset_ci_links")->fetchColumn() . " links left\n";
}

echo str_repeat('-', 70) . "\n  {$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
