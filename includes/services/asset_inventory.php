<?php
/**
 * Source-owned observations in the native inventory tables (GH #194).
 * Explicit asset links use the shared identity resolver. No discovery, hostname
 * updates, last_seen heartbeat or core-field writes happen on this path.
 */
require_once __DIR__ . '/assets.php';

final class AssetInventoryService
{
    private const TABLES = ['software'=>'software_inventory_detail','disks'=>'asset_disks','network_adapters'=>'asset_network_adapters'];

    /** Parse and validate before any write; omitted sections do nothing. */
    private static function normalise(array $in): array
    {
        self::keys($in,['schema_version','source_id','external_device_id','observed_at','sections']);
        if (($in['schema_version'] ?? null)!==1) { self::invalid('Unsupported schema_version; expected integer 1.'); }
        $source=self::text($in,'source_id',48,true);
        if (!preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D',$source)) { self::invalid('source_id must use lowercase ASCII letters, digits, dots, underscores or hyphens.'); }
        $external=self::text($in,'external_device_id',128,true);
        $stamp=self::text($in,'observed_at',35,true);
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D',$stamp)) {
            self::invalid('observed_at must be an RFC3339 timestamp with seconds and an explicit timezone.');
        }
        try { $date=new DateTimeImmutable($stamp); }
        catch (Exception $e) { self::invalid('Invalid observed_at.'); }
        $errors=DateTimeImmutable::getLastErrors();
        if (($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->getTimestamp()>time()+300
            || (int)$date->format('Y')<1000) { self::invalid('observed_at must be a valid observation, at most five minutes in the future.'); }
        $observed=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sections=$in['sections'] ?? null;
        if (!is_array($sections) || !$sections) { self::invalid('Supply at least one supported section.'); }
        self::keys($sections,array_keys(self::TABLES));
        $normal=[];
        foreach ($sections as $name=>$section) {
            if (!is_array($section)) { self::invalid("$name must be an object."); }
            self::keys($section,['complete','items']);
            if (!isset($section['complete']) || !is_bool($section['complete']) || !isset($section['items'])
                || !is_array($section['items']) || !array_is_list($section['items']) || count($section['items'])>10000) {
                self::invalid("$name requires boolean complete and an items list (at most 10000).");
            }
            $items=[]; $seen=[];
            foreach ($section['items'] as $item) {
                if (!is_array($item)) { self::invalid("$name items must be objects."); }
                $row=self::item($name,$item);
                $key=$name==='software' ? mb_strtolower($row['display_name']) . "\0" . mb_strtolower($row['publisher'] ?? '') : $row['external_id'];
                if (isset($seen[$key])) { self::invalid("Duplicate item identity in $name."); }
                $seen[$key]=true; $items[]=$row;
            }
            // Object property order and list order do not change an observation.
            usort($items,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
            $normal[$name]=['complete'=>$section['complete'],'items'=>$items];
        }
        return [$source,$external,$observed,$normal];
    }

    /** Atomically apply each section against its own observation watermark. */
    public static function submit(PDO $conn, ActorContext $ctx, int $assetId, array $in): array
    {
        [$source,$external,$observed,$sections]=self::normalise($in);
        if ($conn->inTransaction()) { throw new LogicException('Inventory submission must own its transaction.'); }
        $conn->beginTransaction();
        try {
            $asset=self::asset($conn,$ctx,$assetId,true);
            $tenant=$asset['tenant_id']===null ? getDefaultTenantId($conn) : (int)$asset['tenant_id'];
            $identity=AssetsService::reconcileAsset($conn,$ctx,['asset_id'=>$assetId],$asset['tenant_id']===null ? null : (int)$asset['tenant_id'],'REST inventory',true);
            if ($identity['asset_id']!==$assetId) { throw new ServiceError('conflict','identity_conflict','Asset identity no longer resolves to this asset.'); }
            // The asset row serializes same-asset submissions; the unique company /
            // source / device index arbitrates simultaneous claims on other assets.
            $find=$conn->prepare('SELECT * FROM asset_inventory_sources WHERE asset_id=? AND source_id=?');
            $find->execute([$assetId,$source]); $binding=$find->fetch(PDO::FETCH_ASSOC);
            if ($binding && ($binding['external_device_id']!==$external || (int)$binding['tenant_id']!==$tenant)) {
                throw new ServiceError('conflict','identity_conflict','This source is already linked to a different external device or company.');
            }
            if ($binding && (int)$binding['owner_analyst_id']!==$ctx->actorId) {
                throw new ServiceError('forbidden','source_owner','This source link belongs to another acting analyst.');
            }
            if (!$binding) {
                try {
                    $conn->prepare('INSERT INTO asset_inventory_sources (asset_id,tenant_id,source_id,external_device_id,owner_analyst_id)
                        VALUES (?,?,?,?,?)')->execute([$assetId,$tenant,$source,$external,$ctx->actorId]);
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? null)===1062) { throw new ServiceError('conflict','identity_conflict','This external device is already linked to an asset.'); }
                    throw $e;
                }
                $bindingId=(int)$conn->lastInsertId();
            } else { $bindingId=(int)$binding['id']; }
            $result=['asset_id'=>$assetId,'source_id'=>$source,'external_device_id'=>$external,'applied'=>[],'stale'=>[],'replayed'=>[]];
            foreach ($sections as $name=>$section) {
                $fingerprint=hash('sha256',json_encode($section,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                $get=$conn->prepare('SELECT observed_at,fingerprint FROM asset_inventory_sections WHERE inventory_source_id=? AND section_name=?');
                $get->execute([$bindingId,$name]); $previous=$get->fetch(PDO::FETCH_ASSOC);
                if ($previous && $observed<$previous['observed_at']) { $result['stale'][]=$name; continue; }
                if ($previous && $observed===$previous['observed_at']) {
                    if ($previous['fingerprint']!==$fingerprint) { throw new ServiceError('conflict','observation_conflict','Same observation time has different content for ' . $name . '.'); }
                    $result['replayed'][]=$name; continue;
                }
                self::applySection($conn,$assetId,'rest:' . $source,$name,$section,$observed);
                $conn->prepare('INSERT INTO asset_inventory_sections (inventory_source_id,section_name,observed_at,fingerprint,imported_at,imported_by_id)
                    VALUES (?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at),fingerprint=VALUES(fingerprint),imported_at=VALUES(imported_at),imported_by_id=VALUES(imported_by_id)')
                    ->execute([$bindingId,$name,$observed,$fingerprint,$ctx->actorId]);
                $result['applied'][]=$name;
            }
            if ($result['applied']) {
                $conn->prepare('INSERT INTO asset_history (asset_id,analyst_id,field_name,old_value,new_value,created_datetime)
                    VALUES (?,?,?,NULL,?,UTC_TIMESTAMP())')->execute([$assetId,$ctx->actorId,'inventory_import',
                    $source . ' (' . implode(', ',$result['applied']) . '), observed ' . $observed . ' UTC']);
            }
            $conn->commit(); return $result;
        } catch (Throwable $e) { if ($conn->inTransaction()) $conn->rollBack(); throw $e; }
    }

    /** Provenance is read-only; actual items stay in the existing inventory APIs. */
    public static function provenance(PDO $conn, ActorContext $ctx, int $assetId): array
    {
        self::asset($conn,$ctx,$assetId,false);
        $stmt=$conn->prepare('SELECT * FROM asset_inventory_sources WHERE asset_id=? ORDER BY source_id'); $stmt->execute([$assetId]);
        $rows=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sections=$conn->prepare('SELECT section_name,observed_at,imported_at,imported_by_id FROM asset_inventory_sections WHERE inventory_source_id=? ORDER BY section_name');
            $sections->execute([$row['id']]); $state=[];
            foreach ($sections->fetchAll(PDO::FETCH_ASSOC) as $section) {
                $state[$section['section_name']]=['observed_at'=>str_replace(' ','T',$section['observed_at']) . 'Z',
                    'imported_at'=>str_replace(' ','T',$section['imported_at']) . 'Z','imported_by_id'=>(int)$section['imported_by_id']];
            }
            $rows[]=['source_id'=>$row['source_id'],'external_device_id'=>$row['external_device_id'],
                'owner_analyst_id'=>(int)$row['owner_analyst_id'],'sections'=>(object)$state];
        }
        return $rows;
    }

    private static function applySection(PDO $conn,int $assetId,string $source,string $name,array $section,string $observed): void
    {
        $table=self::TABLES[$name]; $host=$name==='software' ? 'host_id' : 'asset_id';
        if ($section['complete']) { $conn->prepare("DELETE FROM $table WHERE $host=? AND source=?")->execute([$assetId,$source]); }
        $seenApps=[];
        foreach ($section['items'] as $item) {
            if ($name==='software') {
                // Reuse the native application catalogue, keeping manual metadata.
                $find=$conn->prepare('SELECT id FROM software_inventory_apps WHERE display_name=? AND (publisher IS NULL OR publisher=?)
                    ORDER BY (publisher <=> ?) DESC,id LIMIT 1');
                $find->execute([$item['display_name'],$item['publisher'],$item['publisher']]); $app=$find->fetchColumn();
                if (!$app) {
                    try {
                        $conn->prepare("INSERT INTO software_inventory_apps (display_name,publisher,source) VALUES (?,?,'api')")->execute([$item['display_name'],$item['publisher']]);
                        $app=(int)$conn->lastInsertId();
                    } catch (PDOException $e) {
                        if (($e->errorInfo[1] ?? null)!==1062) throw $e;
                        // A catalogue row may have been discovered by another
                        // ingest path since our read. A new locking read sees it.
                        $latest=$conn->prepare('SELECT id FROM software_inventory_apps WHERE display_name=? AND publisher <=> ? FOR UPDATE');
                        $latest->execute([$item['display_name'],$item['publisher']]); $app=$latest->fetchColumn();
                        if (!$app) throw new ServiceError('conflict','application_conflict','Application identity conflicts with the native catalogue.');
                    }
                }
                if (isset($seenApps[$app])) self::invalid('Two items resolve to the same native application.');
                $seenApps[$app]=true;
                unset($item['display_name'],$item['publisher']); $item=['app_id'=>(int)$app]+$item;
                $item['last_seen']=$observed;
            }
            $identity=$name==='software' ? 'app_id' : 'external_id';
            if (!$section['complete']) { $conn->prepare("DELETE FROM $table WHERE $host=? AND source=? AND $identity=?")->execute([$assetId,$source,$item[$identity]]); }
            $item=[$host=>$assetId,'source'=>$source]+$item+['observed_at'=>$observed,'imported_at'=>gmdate('Y-m-d H:i:s')];
            $columns=implode(',',array_keys($item)); $placeholders=implode(',',array_fill(0,count($item),'?'));
            $conn->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)")->execute(array_values($item));
        }
    }

    private static function asset(PDO $conn,ActorContext $ctx,int $id,bool $lock): array
    {
        $stmt=$conn->prepare('SELECT id,tenant_id FROM assets WHERE id=?' . ($lock ? ' FOR UPDATE' : '')); $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        $tenant=$row && $row['tenant_id']!==null ? (int)$row['tenant_id'] : getDefaultTenantId($conn);
        if (!$row || ($ctx->companyScope!==null && !in_array($tenant,$ctx->companyScope,true))) {
            throw new ServiceError('not_found','not_found','Asset not found.');
        }
        return $row;
    }

    private static function item(string $name,array $in): array
    {
        $strings=match($name) {
            'software'=>['display_name'=>512,'publisher'=>512,'display_version'=>100,'install_date'=>50,'uninstall_string'=>8192,'install_location'=>8192,'estimated_size'=>100],
            'disks'=>['external_id'=>128,'drive'=>10,'label'=>100,'file_system'=>20],
            'network_adapters'=>['external_id'=>128,'name'=>255,'mac_address'=>17,'ip_address'=>45,'subnet_mask'=>45,'gateway'=>45],
        };
        $numbers=$name==='disks' ? ['size_bytes','free_bytes','used_percent'] : [];
        $booleans=match($name) { 'software'=>['system_component'], 'network_adapters'=>['dhcp_enabled'], default=>[] };
        self::keys($in,array_merge(array_keys($strings),$numbers,$booleans)); $out=[];
        foreach ($strings as $field=>$max) $out[$field]=self::text($in,$field,$max,$field===($name==='software' ? 'display_name' : 'external_id'));
        if ($name==='software') { $out['display_name']=trim($out['display_name']); if ($out['publisher']==='') $out['publisher']=null; }
        foreach ($booleans as $field) {
            $value=$in[$field] ?? ($field==='system_component' ? false : null);
            if ($field==='system_component' && array_key_exists($field,$in) && $in[$field]===null) self::invalid('system_component must be boolean.');
            if ($value!==null && !is_bool($value)) self::invalid("$field must be boolean.");
            $out[$field]=$value===null ? null : (int)$value;
        }
        foreach ($numbers as $field) {
            $value=$in[$field] ?? null;
            if ($value!==null && (($field==='used_percent' ? !is_int($value) && !is_float($value) : !is_int($value))
                || $value<0 || ($field==='used_percent' && $value>100))) { self::invalid("Invalid non-negative $field."); }
            $out[$field]=$value;
        }
        if ($name==='disks' && $out['size_bytes']!==null && $out['free_bytes']!==null && $out['free_bytes']>$out['size_bytes']) { self::invalid('free_bytes exceeds size_bytes.'); }
        if ($name==='disks' && $out['used_percent']===null && $out['size_bytes']>0 && $out['free_bytes']!==null) {
            $out['used_percent']=round(100 * ($out['size_bytes']-$out['free_bytes'])/$out['size_bytes'],1);
        }
        if ($name==='network_adapters') {
            foreach (['ip_address','subnet_mask','gateway'] as $key) if ($out[$key]!==null && !filter_var($out[$key],FILTER_VALIDATE_IP)) self::invalid("Invalid $key.");
            if ($out['mac_address']!==null && !filter_var($out['mac_address'],FILTER_VALIDATE_MAC)) self::invalid('Invalid mac_address.');
        }
        return $out;
    }

    private static function text(array $in,string $field,int $max,bool $required=false): ?string
    {
        $value=$in[$field] ?? null;
        if ($value===null && !$required) return null;
        if (!is_string($value) || mb_strlen($value)>$max || str_contains($value,"\0") || ($required && trim($value)==='')) self::invalid("Invalid $field (maximum $max characters).");
        return $value;
    }
    private static function keys(array $in,array $allowed): void
    {
        if ($unknown=array_diff(array_keys($in),$allowed)) self::invalid('Unknown fields: ' . implode(', ',$unknown));
    }
    private static function invalid(string $message): never { throw new ServiceError('validation','invalid_field',$message); }
}
