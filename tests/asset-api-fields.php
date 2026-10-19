<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GH #194: REST asset writes must not silently discard the `fields` object.
 * Exercises the shared service used by POST/PATCH, including rollback when a
 * valid core edit is combined with an invalid custom value. The controls use
 * the existing UI field service and reject an unrelated company scope.
 *
 * Run from the repository root: php tests/asset-api-fields.php
 * Set FREEITSM_TEST_URL to the matching development site's base URL to also
 * exercise real HTTP authentication, permissions, company scope and responses.
 * Needs a development database. Creates uniquely named fixtures and deletes
 * only their exact ids/hostnames in finally, including after a failed assertion.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/assets.php';
require_once __DIR__ . '/../includes/services/asset_fields.php';
require_once __DIR__ . '/../api/v1/lib/openapi.php';

$conn = connectToDatabase();
$actor = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin=1')->fetchColumn();
if (!$actor) { fwrite(STDERR, "A development administrator is required.\n"); exit(1); }
$ctx = new ActorContext($actor, null, 'api', 'en', 'Asset API field test');
$prefix = 'zz_api_fields_' . bin2hex(random_bytes(5));
$typeIds = []; $fieldIds = []; $setId = null; $hostnames = []; $keyIds = [];
$passed = 0; $failed = 0;
$ok = function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) { ++$passed; echo "PASS $name\n"; }
    else { ++$failed; echo "FAIL $name\n"; }
};
$rejects = function (callable $call): bool {
    try { $call(); return false; } catch (ServiceError $e) { return true; }
};
$openapi = apiV1BuildOpenApi();
foreach (['/assets'=>'post','/assets/{id}'=>'patch'] as $path=>$method) {
    $body = $openapi['paths'][$path][$method]['requestBody']['content']['application/json']['schema'] ?? [];
    $ok('OpenAPI describes the optional fields object for ' . $method,
        ($body['properties']['fields']['type'] ?? null)==='object' && !in_array('fields',$body['required'] ?? [],true));
}
try {
    foreach (['type', 'other_type'] as $suffix) {
        $conn->prepare('INSERT INTO asset_types (name,is_active) VALUES (?,1)')->execute([$prefix . $suffix]);
        $typeIds[] = (int)$conn->lastInsertId();
    }
    $fields = [];
    foreach (['text', 'number', 'boolean'] as $kind) {
        $field = AssetFieldsService::saveField($conn, $ctx, ['label'=>$prefix . $kind, 'field_type'=>$kind]);
        $fields[$kind] = $field['field_key']; $fieldIds[] = (int)$field['id'];
    }
    $setId = (int)AssetFieldsService::saveSet($conn, $ctx, ['name'=>$prefix])['id'];
    AssetFieldsService::setSetFields($conn, $setId, array_map(function ($id) { return ['field_id'=>$id]; }, $fieldIds));
    AssetFieldsService::setTypeSets($conn, $typeIds[0], [$setId]);
    $create = function (string $suffix, array $values) use ($conn, $ctx, $prefix, &$hostnames, $typeIds): int {
        $hostname = $prefix . $suffix; $hostnames[] = $hostname;
        return AssetsService::createAsset($conn, $ctx, array_merge([
            'hostname'=>$hostname, 'asset_type_id'=>$typeIds[0],
        ], $values), 'Synthetic API field test');
    };
    $values = function (int $id) use ($conn, $typeIds): array {
        return AssetFieldsService::valuesForAsset($conn, $id, AssetFieldsService::fieldsForAsset($conn, $id, $typeIds[0]));
    };
    $id = $create('asset', ['fields'=>[$fields['text']=>'initial', $fields['number']=>12.5, $fields['boolean']=>false]]);
    $got = $values($id);
    $ok('POST saves all supplied custom values, including false',
        ($got[$fields['text']] ?? null)==='initial' && ($got[$fields['number']] ?? null)===12.5
        && array_key_exists($fields['boolean'],$got) && $got[$fields['boolean']]===false);

    // Negative control: the old UI service really can save these definitions.
    AssetFieldsService::saveValues($conn, $ctx, $id, [$fields['text']=>'control']);
    $ok('CONTROL: existing UI service saves the same field', ($values($id)[$fields['text']] ?? null)==='control');
    AssetsService::updateFields($conn, $ctx, $id, ['fields'=>[$fields['text']=>'patched']]);
    $got = $values($id);
    $ok('PATCH updates one field without clearing omitted values',
        ($got[$fields['text']] ?? null)==='patched' && ($got[$fields['number']] ?? null)===12.5
        && array_key_exists($fields['boolean'],$got) && $got[$fields['boolean']]===false);
    AssetsService::updateFields($conn, $ctx, $id, ['fields'=>[$fields['text']=>null]]);
    $ok('explicit null clears only that custom field', !array_key_exists($fields['text'],$values($id)));
    $before = $values($id);
    AssetsService::updateFields($conn, $ctx, $id, ['fields'=>[]]);
    $ok('empty fields object is a no-op', $values($id)===$before);

    $ok('invalid custom type rejects the whole PATCH', $rejects(function () use ($conn,$ctx,$id,$fields) {
        AssetsService::updateFields($conn,$ctx,$id,['manufacturer'=>'must not persist','fields'=>[$fields['text']=>'must roll back',$fields['number']=>'not a number']]);
    }));
    $manufacturer = $conn->query('SELECT manufacturer FROM assets WHERE id=' . $id)->fetchColumn();
    $ok('failed PATCH does not persist a valid core edit or custom changes', $manufacturer===null && $values($id)===$before);
    $ok('unknown field rejects POST', $rejects(function () use ($create) { $create('invalid', ['fields'=>['unknown_field'=>'value']]); }));
    $stmt=$conn->prepare('SELECT COUNT(*) FROM assets WHERE hostname=?'); $stmt->execute([$prefix . 'invalid']);
    $ok('failed POST leaves no asset behind', (int)$stmt->fetchColumn()===0);
    foreach ([null, 'invalid', 42] as $malformed) {
        $ok('malformed fields object rejected', $rejects(function () use ($conn,$ctx,$id,$malformed) {
            AssetsService::updateFields($conn,$ctx,$id,['fields'=>$malformed]);
        }));
    }
    $restricted = new ActorContext($actor, [-1], 'api');
    $ok('CONTROL: company boundary still enforced for custom-only writes', $rejects(function () use ($conn,$restricted,$id,$fields) {
        AssetsService::updateFields($conn,$restricted,$id,['fields'=>[$fields['text']=>'forbidden']]);
    }));
    $ok('changing type cannot smuggle in a field from the previous type', $rejects(function () use ($conn,$ctx,$id,$typeIds,$fields) {
        AssetsService::updateFields($conn,$ctx,$id,['asset_type_id'=>$typeIds[1],'fields'=>[$fields['text']=>'invalid']]);
    }));
    $ok('failed type/custom edit rolls back the type', (int)$conn->query('SELECT asset_type_id FROM assets WHERE id=' . $id)->fetchColumn()===$typeIds[0]);
    $audit=$conn->prepare('SELECT COUNT(*) FROM asset_history WHERE asset_id=? AND field_name=?');
    $audit->execute([$id,'field:' . $fields['number']]);
    $ok('API custom values use existing field history', (int)$audit->fetchColumn()===1);
    if ($base = getenv('FREEITSM_TEST_URL')) {
        $keys = [];
        foreach (['write', 'read', 'outside'] as $kind) {
            $keys[$kind] = 'fitsm_' . bin2hex(random_bytes(24));
            $conn->prepare('INSERT INTO api_keys (name,key_prefix,key_hash,analyst_id,permissions,company_ids,created_datetime)
                VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([
                $prefix . $kind, substr($keys[$kind],0,12), hash('sha256',$keys[$kind]), $actor,
                json_encode(['assets'=>$kind==='read' ? ['read'] : ['read','create','update']]),
                $kind==='outside' ? '[]' : null,
            ]);
            $keyIds[] = (int)$conn->lastInsertId();
        }
        $request = function (string $method, string $path, string $key, array $body) use ($base): array {
            // Explicit front-controller path works without optional rewrite rules.
            $ch=curl_init(rtrim($base,'/') . '/api/v1/index.php?path=/' . rawurlencode($path));
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,
                CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>json_encode($body),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer ' . $key]]);
            $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            if ($raw===false) { throw new RuntimeException('HTTP test connection failed: ' . curl_error($ch)); }
            curl_close($ch);
            return [$code,json_decode($raw,true)];
        };
        $hostname=$prefix . 'http'; $hostnames[]=$hostname;
        [$code,$data]=$request('POST','assets',$keys['write'],['hostname'=>$hostname,'asset_type_id'=>$typeIds[0],
            'fields'=>[$fields['text']=>'http',$fields['boolean']=>false]]);
        $httpId=(int)($data['data']['id'] ?? 0);
        if ($code!==201) { echo 'HTTP POST diagnostic: ' . $code . ' ' . json_encode($data['error'] ?? []) . "\n"; }
        $ok('HTTP POST persists and serializes typed custom fields', $code===201 && $httpId>0
            && ($data['data']['fields'][$fields['text']] ?? null)==='http'
            && ($data['data']['fields'][$fields['boolean']] ?? null)===false);
        if ($httpId) {
            [$code]=$request('PATCH','assets/' . $httpId,$keys['read'],['fields'=>[$fields['text']=>'forbidden']]);
            $ok('HTTP read-only key cannot write custom fields', $code===403);
            if (isMultiTenant($conn)) {
                [$code]=$request('PATCH','assets/' . $httpId,$keys['outside'],['fields'=>[$fields['text']=>'forbidden']]);
                $ok('HTTP company-scoped key cannot access another company', $code===404);
            } else { echo "SKIP HTTP company scope (single-company install); service scope control ran above\n"; }
            [$code]=$request('PATCH','assets/' . $httpId,$keys['write'],['manufacturer'=>'rollback','fields'=>[$fields['number']=>'bad']]);
            $ok('HTTP invalid custom value is 422 and core edit rolls back', $code===422
                && $conn->query('SELECT manufacturer FROM assets WHERE id=' . $httpId)->fetchColumn()===null);
            [$code,$data]=$request('PATCH','assets/' . $httpId,$keys['write'],['fields'=>[$fields['text']=>null]]);
            $ok('HTTP PATCH clear preserves omitted false', $code===200
                && !array_key_exists($fields['text'],$data['data']['fields'] ?? [])
                && ($data['data']['fields'][$fields['boolean']] ?? null)===false);
        }
    } else { echo "SKIP HTTP checks (set FREEITSM_TEST_URL to a development site using this database)\n"; }
} finally {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    foreach ($keyIds as $keyId) {
        $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id=?')->execute([$keyId]);
        $conn->prepare('DELETE FROM api_keys WHERE id=?')->execute([$keyId]);
    }
    foreach ($hostnames as $hostname) {
        $find=$conn->prepare('SELECT id FROM assets WHERE hostname=?'); $find->execute([$hostname]);
        foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $assetId) {
            foreach (['asset_history','asset_field_values','asset_field_set_assets'] as $table) {
                $conn->prepare("DELETE FROM $table WHERE asset_id=?")->execute([$assetId]);
            }
            $conn->prepare('DELETE FROM assets WHERE id=?')->execute([$assetId]);
        }
    }
    foreach ($typeIds as $typeId) { $conn->prepare('DELETE FROM asset_type_field_sets WHERE asset_type_id=?')->execute([$typeId]); }
    if ($setId) {
        $conn->prepare('DELETE FROM asset_field_set_fields WHERE set_id=?')->execute([$setId]);
        $conn->prepare('DELETE FROM asset_field_sets WHERE id=?')->execute([$setId]);
    }
    foreach ($fieldIds as $fieldId) { $conn->prepare('DELETE FROM asset_fields WHERE id=?')->execute([$fieldId]); }
    foreach ($typeIds as $typeId) { $conn->prepare('DELETE FROM asset_types WHERE id=?')->execute([$typeId]); }
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
