<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GH #194: snapshots are observations, not heartbeats or wholesale ownership of
 * an asset. Exercises REST with real keys and isolated fixtures in the configured
 * development database. FREEITSM_TEST_URL must point to that database's site.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/assets.php';
require_once __DIR__ . '/../includes/services/asset_inventory.php';
require_once __DIR__ . '/../api/v1/lib/openapi.php';
$base=rtrim(getenv('FREEITSM_TEST_URL') ?: '', '/');
if (!$base) { fwrite(STDERR,"Set FREEITSM_TEST_URL to the matching development site.\n"); exit(1); }
$conn=connectToDatabase(); $ids=[]; $keyIds=[]; $appIds=[]; $legacyKeyId=0;
$actor=(int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin=1 AND is_active=1')->fetchColumn();
$ctx=new ActorContext($actor); $prefix='zz_inv_' . bin2hex(random_bytes(5)); $pass=0; $fail=0;
$ok=function(string $label,bool $cond) use (&$pass,&$fail): void {
    if ($cond) { ++$pass; echo "PASS $label\n"; } else { ++$fail; echo "FAIL $label\n"; }
};
try {
    $api=apiV1BuildOpenApi();
    $ok('OpenAPI publishes versioned snapshot input',
        ($api['paths']['/assets/{id}/inventory']['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? '')==='#/components/schemas/AssetInventorySnapshot');
    $ok('native inventory schemas expose observation provenance', isset($api['components']['schemas']['AssetDisk']['properties']['observed_at'],
        $api['components']['schemas']['AssetNetworkAdapter']['properties']['source'], $api['components']['schemas']['AssetSoftware']['properties']['imported_at']));
    foreach (['one','two'] as $suffix) $ids[]=AssetsService::createAsset($conn,$ctx,['hostname'=>$prefix . $suffix],'Synthetic inventory fixture');
    $id=$ids[0];
    $conn->prepare('INSERT INTO asset_disks (asset_id,drive,source) VALUES (?, ?, ?)')->execute([$id,'manual','manual']);
    $conn->prepare('INSERT INTO software_inventory_apps (display_name,publisher) VALUES (?,?)')->execute([$prefix,'Synthetic']);
    $appIds[]=$appId=(int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO software_inventory_detail (host_id,app_id,display_version,source) VALUES (?,?,?,'manual')")->execute([$id,$appId,'manual-version']);
    $keys=[];
    foreach (['write','read'] as $kind) {
        $keys[$kind]='fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name,key_prefix,key_hash,analyst_id,permissions) VALUES (?,?,?,?,?)')
            ->execute([$prefix . $kind,substr($keys[$kind],0,12),hash('sha256',$keys[$kind]),$actor,
                json_encode(['asset_inventory'=>$kind==='write' ? ['read','create'] : ['read'],'software_inventory'=>['read']])]);
        $keyIds[]=(int)$conn->lastInsertId();
    }
    $request=function(string $method,int $assetId,array $body,string $kind='write') use ($base,$keys): array {
        $ch=curl_init($base . '/api/v1/index.php?path=/assets/' . $assetId . '/inventory');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer ' . $keys[$kind]]]);
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if ($raw===false) throw new RuntimeException('HTTP connection failed: ' . curl_error($ch));
        curl_close($ch); return [$code,json_decode($raw,true)];
    };
    $snapshot=['schema_version'=>1,'source_id'=>'synthetic','external_device_id'=>$prefix,
        'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400), 'sections'=>[
            'software'=>['complete'=>true,'items'=>[['display_name'=>$prefix,'publisher'=>'Synthetic','display_version'=>'1.0']]],
            'disks'=>['complete'=>true,'items'=>[['external_id'=>'c','drive'=>'C:','size_bytes'=>100,'free_bytes'=>40]]],
            'network_adapters'=>['complete'=>true,'items'=>[['external_id'=>'eth0','name'=>'Ethernet','mac_address'=>'00:11:22:33:44:55','ip_address'=>'192.0.2.10']]],
        ]];
    $core=$conn->query('SELECT hostname,last_seen,service_tag FROM assets WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
    [$code,$result]=$request('POST',$id,$snapshot,'read'); $ok('read permission cannot submit snapshots', $code===403);
    [$code,$result]=$request('POST',$id,$snapshot);
    $ok('source snapshot accepted', $code===200 && count($result['data']['applied'] ?? [])===3);
    if ($code!==200) { echo 'Snapshot diagnostic: ' . $code . ' ' . json_encode($result['error'] ?? []) . "\n"; }
    else {
        $count=fn(string $table)=>(int)$conn->query("SELECT COUNT(*) FROM $table WHERE " . ($table==='software_inventory_detail' ? 'host_id' : 'asset_id') . '=' . $id)->fetchColumn();
        $ok('native software can coexist with same application from another source', $count('software_inventory_detail')===2);
        $ok('manual disk is preserved', $count('asset_disks')===2);
        $ok('snapshot import never changes heartbeat or identity', $core===$conn->query('SELECT hostname,last_seen,service_tag FROM assets WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC));
        [$code,$result]=$request('POST',$id,$snapshot);
        $ok('identical retry creates no duplicates', $code===200 && count($result['data']['replayed'] ?? [])===3 && $count('asset_disks')===2);
        $different=$snapshot; $different['sections']['disks']['items'][0]['label']='conflict';
        [$code]=$request('POST',$id,$different); $ok('same observation time with changed content conflicts', $code===409);
        $new=$snapshot; $new['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()-3600);
        $new['sections']=['disks'=>['complete'=>false,'items'=>[['external_id'=>'d','drive'=>'D:']]]];
        [$code]=$request('POST',$id,$new); $ok('partial section preserves omitted items and sections', $code===200 && $count('asset_disks')===3 && $count('software_inventory_detail')===2);
        [$code,$result]=$request('POST',$id,$snapshot);
        $ok('older report cannot replace newer section', $code===200 && in_array('disks',$result['data']['stale'] ?? [],true) && $count('asset_disks')===3);
        $second=$snapshot; $second['source_id']='other';
        [$code]=$request('POST',$id,$second); $ok('independent sources coexist', $code===200 && $count('asset_disks')===4);
        $clear=$new; $clear['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()-60); $clear['sections']=['disks'=>['complete'=>true,'items'=>[]]];
        [$code]=$request('POST',$id,$clear); $ok('complete empty section clears only its source', $code===200 && $count('asset_disks')===2);
        [$code]=$request('POST',$ids[1],$snapshot); $ok('external identity cannot move to another asset', $code===409);
        $invalid=$clear; $invalid['sections']['software']=['complete'=>true,'items'=>[['display_name'=>$prefix,'publisher'=>'Synthetic','system_component'=>'invalid']]];
        [$code]=$request('POST',$id,$invalid); $ok('one invalid section rejects whole report', $code===422 && $count('software_inventory_detail')===3);
        $invalid=$clear; $invalid['schema_version']=999;
        [$code]=$request('POST',$id,$invalid); $ok('unsupported schema version is rejected', $code===422);
        $invalid=$clear; unset($invalid['sections']['disks']['complete']);
        [$code]=$request('POST',$id,$invalid); $ok('completeness must be explicit', $code===422);
        [$code,$result]=$request('GET',$id,[],'read');
        $ok('provenance exposes observation and import times separately', $code===200 && count($result['data'] ?? [])===2
            && isset($result['data'][0]['sections']['disks']['observed_at'],$result['data'][0]['sections']['disks']['imported_at']));
        $denied=false;
        try { AssetInventoryService::submit($conn,new ActorContext($actor,[-1]),$id,$snapshot); }
        catch (ServiceError $e) { $denied=$e->kind==='not_found'; }
        $ok('company boundary is enforced by the shared service', $denied);
        $denied=false;
        try { AssetInventoryService::submit($conn,ActorContext::system(),$id,$snapshot); }
        catch (ServiceError $e) { $denied=$e->errorCode==='source_owner'; }
        $ok('a source binding cannot be reused by a different acting identity', $denied);
        $rollback=$clear; $rollback['sections']=[
            'software'=>['complete'=>true,'items'=>[['display_name'=>$prefix,'publisher'=>'Synthetic','display_version'=>'must-rollback']]],
            'disks'=>['complete'=>false,'items'=>[['external_id'=>'conflicting','drive'=>'X:']]],
        ];
        [$code]=$request('POST',$id,$rollback);
        $version=$conn->query("SELECT display_version FROM software_inventory_detail WHERE host_id=$id AND source='rest:synthetic'")->fetchColumn();
        $ok('late observation conflict rolls back an earlier section write', $code===409 && $version==='1.0');
        $invalid=$clear; $invalid['observed_at']=gmdate('Y-m-d\TH:i:s\Z',time()+3600);
        [$code]=$request('POST',$id,$invalid); $ok('future observations are rejected', $code===422);

        // Real legacy clients must not wipe REST or manual observations. No
        // protocol change or connector/network service is involved in this test.
        $legacy='zz_' . bin2hex(random_bytes(20));
        $conn->prepare('INSERT INTO apikeys (apikey,label,active) VALUES (?,?,1)')->execute([$legacy,$prefix]);
        $legacyKeyId=(int)$conn->lastInsertId();
        $agent=function(string $path,array $body) use ($base,$legacy): array {
            $ch=curl_init($base . '/api/external/' . $path . '/submit/');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: ' . $legacy]]);
            $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            return [$code,json_decode($raw,true)];
        };
        [$code,$body]=$agent('system-info',['hostname'=>$prefix . 'one',
            'disks'=>['logical'=>[['drive'=>'A:','label'=>'Agent']]],
            'network_adapters'=>[['name'=>'Agent adapter','ip_addresses'=>['192.0.2.20']]],
        ]);
        $ok('legacy system-info client preserves manual and REST disks/adapters', $code===200 && ($body['asset_id'] ?? 0)===$id
            && $count('asset_disks')===3 && $count('asset_network_adapters')===3);
        [$code,$body]=$agent('software-inventory',['Hostname'=>$prefix . 'one','Software'=>[
            ['Display Name'=>$prefix,'Publisher'=>'Synthetic','Display Version'=>'agent-version'],
        ]]);
        $manual=$conn->query("SELECT display_version FROM software_inventory_detail WHERE host_id=$id AND source='manual'")->fetchColumn();
        $ok('legacy software client writes only its own source', $code===200 && $count('software_inventory_detail')===4 && $manual==='manual-version');
        require_once __DIR__ . '/../includes/intune.php';
        intuneUpsertSoftwareForAsset($conn,$id,[['displayName'=>$prefix,'publisher'=>'Synthetic','version'=>'connector-version']]);
        $ok('existing connector also coexists with agent, manual and REST observations', $count('software_inventory_detail')===5);
        $readPath=function(string $path) use ($base,$keys): array {
            $ch=curl_init($base . '/api/v1/index.php?path=/' . rawurlencode($path));
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $keys['read']]]);
            $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$code,json_decode($raw,true)];
        };
        foreach (['disks','network-adapters','software'] as $part) {
            [$code,$data]=$readPath("assets/$id/$part");
            $ok('native ' . $part . ' reader exposes every observation with its source', $code===200
                && count($data['data'] ?? [])===($part==='software' ? 5 : 3)
                && count(array_filter($data['data'] ?? [],fn($row)=>isset($row['source'])))===count($data['data'] ?? []));
        }
        [$code,$data]=$readPath("software/apps/$appId/machines");
        $ok('application machine observations expose provenance without inventing extra devices', $code===200
            && count($data['data'] ?? [])===5 && count(array_unique(array_column($data['data'] ?? [],'asset_id')))===1);
        $conn->prepare("INSERT INTO software_inventory_apps (display_name,publisher,source,notes) VALUES (?,NULL,'manual','Keep curated metadata')")
            ->execute([$prefix . '_curated']);
        $appIds[]=$curated=(int)$conn->lastInsertId();
        $adopt=$snapshot; $adopt['observed_at']=gmdate('Y-m-d\TH:i:s\Z');
        $adopt['sections']=['software'=>['complete'=>false,'items'=>[['display_name'=>$prefix . '_curated','publisher'=>'Observed publisher']]]];
        [$code]=$request('POST',$id,$adopt);
        $curatedRow=$conn->query('SELECT source,publisher,notes FROM software_inventory_apps WHERE id=' . $curated)->fetch(PDO::FETCH_ASSOC);
        $ok('native manual catalogue matching is reused without rewriting curated metadata', $code===200
            && $curatedRow===['source'=>'manual','publisher'=>null,'notes'=>'Keep curated metadata']
            && (int)$conn->query("SELECT COUNT(*) FROM software_inventory_detail WHERE host_id=$id AND app_id=$curated")->fetchColumn()===1);
        $parallel=function(array $targets,array $payload) use ($base,$keys): array {
            $multi=curl_multi_init(); $handles=[];
            foreach ($targets as $target) {
                $ch=curl_init($base . '/api/v1/index.php?path=/assets/' . $target . '/inventory');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer ' . $keys['write']]]);
                curl_multi_add_handle($multi,$ch); $handles[]=$ch;
            }
            do { $status=curl_multi_exec($multi,$running); if ($running) curl_multi_select($multi,1); } while ($running && $status===CURLM_OK);
            $results=[];
            foreach ($handles as $ch) {
                $results[]=[(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),json_decode(curl_multi_getcontent($ch),true)];
                curl_multi_remove_handle($multi,$ch); curl_close($ch);
            }
            curl_multi_close($multi); return $results;
        };
        $race=$snapshot; $race['source_id']='concurrent'; $race['external_device_id']=$prefix . '_race';
        $race['sections']=['disks'=>['complete'=>true,'items'=>[['external_id'=>'race','drive'=>'R:']]]];
        $results=$parallel([$ids[1],$ids[1]],$race);
        $ok('simultaneous identical submissions serialize into one applied and one replay',
            array_column($results,0)===[200,200] && array_sum(array_map(fn($r)=>count($r[1]['data']['applied'] ?? []),$results))===1
            && array_sum(array_map(fn($r)=>count($r[1]['data']['replayed'] ?? []),$results))===1);
        $race['source_id']='identity-race';
        $results=$parallel($ids,$race); $codes=array_column($results,0); sort($codes);
        $ok('simultaneous competing asset links yield one success and one identity conflict', $codes===[200,409]);
    }
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    foreach ($ids as $id) {
        foreach (['asset_inventory_sections','asset_inventory_sources'] as $table) {
            $exists=$conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'); $exists->execute([$table]);
            if ($exists->fetchColumn()) {
                if ($table==='asset_inventory_sections') $conn->prepare('DELETE s FROM asset_inventory_sections s JOIN asset_inventory_sources p ON p.id=s.inventory_source_id WHERE p.asset_id=?')->execute([$id]);
                else $conn->prepare('DELETE FROM asset_inventory_sources WHERE asset_id=?')->execute([$id]);
            }
        }
        foreach (['software_inventory_detail','software_inventory_log','asset_disks','asset_network_adapters','asset_history'] as $table)
            $conn->prepare("DELETE FROM $table WHERE " . (str_starts_with($table,'software_inventory_') ? 'host_id' : 'asset_id') . '=?')->execute([$id]);
        $conn->prepare('DELETE FROM assets WHERE id=?')->execute([$id]);
    }
    foreach ($appIds as $id) $conn->prepare('DELETE FROM software_inventory_apps WHERE id=?')->execute([$id]);
    foreach ($keyIds as $id) {
        $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id=?')->execute([$id]);
        $conn->prepare('DELETE FROM api_keys WHERE id=?')->execute([$id]);
    }
    if ($legacyKeyId) $conn->prepare('DELETE FROM apikeys WHERE id=?')->execute([$legacyKeyId]);
}
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
