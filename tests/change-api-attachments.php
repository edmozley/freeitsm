<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GH #194: an evidence-only integration must be able to attach a file without
 * gaining Change update/approval/delete rights. Retries must not double evidence.
 * Uses real multipart HTTP uploads, the configured development database, and
 * exact fixture cleanup. Run with FREEITSM_TEST_URL set to that site's base URL.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/changes.php';
require_once __DIR__ . '/../api/v1/lib/openapi.php';
$base = rtrim(getenv('FREEITSM_TEST_URL') ?: '', '/');
if (!$base) { fwrite(STDERR,"Set FREEITSM_TEST_URL to a development site using this database.\n"); exit(1); }
$conn=connectToDatabase();
$actor=(int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin=1 AND is_active=1')->fetchColumn();
$ctx=new ActorContext($actor,null,'api');
$prefix='zz_att_' . bin2hex(random_bytes(5));
$changeId=0; $keyIds=[]; $readActor=0; $temp=tempnam(sys_get_temp_dir(),'fitsm-evidence-');
$pass=0; $fail=0;
$ok=function(string $label,bool $condition) use (&$pass,&$fail): void {
    if ($condition) { ++$pass; echo "PASS $label\n"; }
    else { ++$fail; echo "FAIL $label\n"; }
};
try {
    $openapi=apiV1BuildOpenApi();
    $post=$openapi['paths']['/changes/{id}/attachments']['post'] ?? [];
    $ok('OpenAPI describes a multipart binary upload',
        ($post['requestBody']['content']['multipart/form-data']['schema']['properties']['file']['format'] ?? '')==='binary');
    $ok('OpenAPI documents created and retry responses', isset($post['responses'][200],$post['responses'][201]));
    $download=$openapi['paths']['/changes/{id}/attachments/{attachment_id}/content']['get'] ?? [];
    $ok('OpenAPI describes a binary download',
        ($download['responses'][200]['content']['application/octet-stream']['schema']['format'] ?? '')==='binary');
    $changeId=ChangesService::createChange($conn,$ctx,getDefaultTenantId($conn),['title'=>$prefix]);
    $before=$conn->query('SELECT status_id,test_plan,approver_id FROM changes WHERE id=' . $changeId)->fetch(PDO::FETCH_ASSOC);
    $conn->prepare('INSERT INTO analysts (username,password_hash,full_name,email,is_active,is_admin,can_access_all_modules)
        VALUES (?,?,?,?,1,0,0)')->execute([$prefix,password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),$prefix,$prefix . '@example.invalid']);
    $readActor=(int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO analyst_modules (analyst_id,module_key,access_level) VALUES (?,'changes','read')")->execute([$readActor]);
    $readOnlyBlocked=false;
    try { ChangesService::uploadAttachment($conn,new ActorContext($readActor),$changeId,[]); }
    catch (ServiceError $e) { $readOnlyBlocked=$e->errorCode==='read_only'; }
    $ok('shared upload service respects the analyst read-only role before file handling', $readOnlyBlocked);
    $keys=[];
    foreach (['evidence','read'] as $kind) {
        $keys[$kind]='fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name,key_prefix,key_hash,analyst_id,permissions) VALUES (?,?,?,?,?)')
            ->execute([$prefix . $kind,substr($keys[$kind],0,12),hash('sha256',$keys[$kind]),$actor,
                json_encode(['change_attachments'=>$kind==='evidence' ? ['read','create'] : ['read']])]);
        $keyIds[]=(int)$conn->lastInsertId();
    }
    $request=function(string $method,string $path,string $key,?array $body=null) use ($base): array {
        $ch=curl_init($base . '/api/v1/index.php?path=/' . rawurlencode($path));
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $key]];
        if ($body!==null) $options[CURLOPT_POSTFIELDS]=$body;
        curl_setopt_array($ch,$options); $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if ($raw===false) throw new RuntimeException('HTTP connection failed: ' . curl_error($ch));
        curl_close($ch); return [$code,json_decode($raw,true),$raw];
    };
    file_put_contents($temp,"Synthetic evidence for $prefix\n");
    $upload=['file'=>new CURLFile($temp,'text/plain','evidence.txt'),'external_reference'=>'report-1'];
    [$code,$data]=$request('POST',"changes/$changeId/attachments",$keys['read'],$upload);
    $ok('read-only key cannot upload', $code===403);
    [$code,$data]=$request('POST',"changes/$changeId/attachments",$keys['evidence'],$upload);
    $id=(int)($data['data']['id'] ?? 0);
    $ok('evidence-only key uploads native evidence with digest and stable reference', $code===201 && $id>0
        && ($data['data']['sha256'] ?? '')===hash_file('sha256',$temp)
        && ($data['data']['external_reference'] ?? '')==='report-1');
    [$code,$data]=$request('POST',"changes/$changeId/attachments",$keys['evidence'],$upload);
    $ok('retry returns original attachment instead of duplicating', $code===200 && ($data['data']['id'] ?? 0)===$id);
    [$code,$data]=$request('GET',"changes/$changeId/attachments",$keys['evidence']);
    $ok('evidence can be reconciled through scoped listing', $code===200 && count($data['data'] ?? [])===1);
    if ($id) {
        [$code,,$raw]=$request('GET',"changes/$changeId/attachments/$id/content",$keys['evidence']);
        $ok('authorized content round-trips', $code===200 && $raw===file_get_contents($temp));
        $original=$conn->query('SELECT file_path FROM change_attachments WHERE id=' . $id)->fetchColumn();
        try {
            $conn->prepare('UPDATE change_attachments SET file_path=? WHERE id=?')->execute(['../../config.php',$id]);
            [$code]=$request('GET',"changes/$changeId/attachments/$id/content",$keys['evidence']);
            $ok('download refuses stored paths outside attachment root', $code===404);
        } finally { $conn->prepare('UPDATE change_attachments SET file_path=? WHERE id=?')->execute([$original,$id]); }
    }
    file_put_contents($temp,"Different evidence\n");
    [$code]=$request('POST',"changes/$changeId/attachments",$keys['evidence'],$upload);
    $ok('same reference with different bytes conflicts', $code===409);
    [$code]=$request('POST',"changes/$changeId/attachments",$keys['evidence'],
        ['file'=>new CURLFile($temp,'text/plain','danger.php')]);
    $ok('native extension policy rejects executable upload', $code===422);
    [$code]=$request('POST',"changes/$changeId/attachments",$keys['evidence'],
        ['file'=>new CURLFile($temp,'image/png','renamed.png')]);
    $ok('native MIME validation rejects renamed content', $code===422);
    [$code]=$request('POST',"changes/$changeId/attachments",$keys['evidence'],['external_reference'=>'no-file']);
    $ok('missing multipart file is rejected', $code===422);
    [$code]=$request('POST',"changes/2147483647/attachments",$keys['evidence'],$upload);
    $ok('unknown change is rejected before writing bytes', $code===404);
    [$code]=$request('PATCH',"changes/$changeId",$keys['evidence'],['title'=>'forbidden']);
    $ok('evidence permission cannot update the change', $code===403);
    [$code]=$request('DELETE',"changes/$changeId",$keys['evidence']);
    $ok('evidence permission cannot delete the change', $code===403);
    $after=$conn->query('SELECT status_id,test_plan,approver_id FROM changes WHERE id=' . $changeId)->fetch(PDO::FETCH_ASSOC);
    $ok('evidence leaves status, approval and planning unchanged', $before===$after);
    $count=(int)$conn->query("SELECT COUNT(*) FROM change_audit WHERE change_id=$changeId AND action_type='attachment'")->fetchColumn();
    $ok('retry and rejected uploads create no duplicate audit', $count===1);
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($changeId) {
        $files=$conn->query('SELECT file_path FROM change_attachments WHERE change_id=' . $changeId)->fetchAll(PDO::FETCH_COLUMN);
        $root=realpath(__DIR__ . '/../change-management/attachments');
        foreach ($files as $relative) {
            $path=realpath($root . '/' . $relative);
            if ($root && $path && str_starts_with($path,$root . DIRECTORY_SEPARATOR)) unlink($path);
        }
        $conn->prepare('DELETE FROM changes WHERE id=?')->execute([$changeId]);
        $dir=__DIR__ . '/../change-management/attachments/' . $changeId;
        if (is_dir($dir)) {
            foreach (['.htaccess','web.config'] as $guard) if (is_file($dir . '/' . $guard)) unlink($dir . '/' . $guard);
            @rmdir($dir);
        }
    }
    foreach ($keyIds as $id) {
        $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id=?')->execute([$id]);
        $conn->prepare('DELETE FROM api_keys WHERE id=?')->execute([$id]);
    }
    if ($readActor) {
        $conn->prepare('DELETE FROM analyst_modules WHERE analyst_id=?')->execute([$readActor]);
        $conn->prepare('DELETE FROM analysts WHERE id=?')->execute([$readActor]);
    }
    if (is_file($temp)) unlink($temp);
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
