<?php
session_start(['read_and_close'=>true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_list.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();
if(!isset($_SESSION['analyst_id'])){http_response_code(401);exit;}
requireModuleAccessJson('tickets');
$conn=null;
try {
    $query=ticketListQuery($_GET);
    if(!isset($_GET['columns'])||!is_string($_GET['columns']))throw new InvalidArgumentException('Choose export columns');
    $columns=explode(',',$_GET['columns']);$allowed=ticketListExportColumns();
    if(!$columns||count($columns)>count($allowed)||array_diff($columns,array_keys($allowed)))throw new InvalidArgumentException('Invalid export columns');
    $conn=connectToDatabase();
    [$sql,$args]=ticketListSql($conn,(int)$_SESSION['analyst_id'],$query);
    $sql="SELECT * FROM ($sql) queue".ticketListOrder($query);
    // A consistent snapshot, bounded batches and no temporary file in the webroot.
    $conn->beginTransaction();
    $st=$conn->prepare($sql.' LIMIT 500 OFFSET 0');$st->execute($args);
    $out=fopen('php://temp/maxmemory:2097152','w+');
    if($out===false || fwrite($out,"\xEF\xBB\xBF")!==3)throw new RuntimeException('Export buffer unavailable');
    if(fputcsv($out,array_map(fn($key)=>t('tickets.queue.columns.'.$key),$columns),',','"','')===false)throw new RuntimeException('Export buffer unavailable');
    $offset=0;
    do {
        $n=0;
        while($row=$st->fetch(PDO::FETCH_ASSOC)) {
            $cells=array_map(function($key)use($row,$allowed){
                $value=$row[$allowed[$key]];
                // The UI uses the analyst's zone. CSV uses explicit UTC so
                // another reader cannot mistake a stored time for local time.
                if($value && in_array($key,['created_datetime','work_start_datetime','work_end_datetime','snoozed_until'],true))$value=str_replace(' ','T',$value).'Z';
                return ticketListCsvCell($value);
            },$columns);
            if(fputcsv($out,$cells,',','"','')===false)throw new RuntimeException('Export buffer unavailable');
            $n++;
        }
        $offset+=$n;
        if($n===500&&!connection_aborted()){$st=$conn->prepare($sql.' LIMIT 500 OFFSET '.$offset);$st->execute($args);}else break;
    } while(true);
    $conn->commit();
    if(!rewind($out))throw new RuntimeException('Export buffer unavailable');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tickets.csv"');
    header('Cache-Control: no-store');
    fpassthru($out);fclose($out);
} catch(Throwable $error) {
    if($conn && $conn->inTransaction())$conn->rollBack();
    if(!headers_sent()) { http_response_code($error instanceof InvalidArgumentException || $error instanceof JsonException?400:503);header('Content-Type: application/json');echo json_encode(['success'=>false,'error'=>'Export unavailable']); }
}
