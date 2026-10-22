<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Explicit local-only load check: creates and removes 10,000 synthetic tickets. */
require __DIR__.'/support/ticket-workspace-fixture.php';
$fixture=null;$exit=1;
try {
    $fixture=ticketWorkspaceFixture();extract($fixture);
    $conn->beginTransaction();
    for($i=1;$i<10000;$i++)$insert('tickets',['ticket_number'=>'ZZL-'.bin2hex(random_bytes(6)),'subject'=>'ZZ load fixture '.$i,'tenant_id'=>$tenant]);
    $conn->commit();
    [$client]=ticketWorkspaceLogin($url,$analysts['full']);
    $start=microtime(true);$page=ticketWorkspaceHttp($client,$url.'/api/tickets/table.php?page_size=50');$pageMs=round((microtime(true)-$start)*1000);
    $start=microtime(true);$legacy=ticketWorkspaceHttp($client,$url.'/api/tickets/get_emails.php');$legacyMs=round((microtime(true)-$start)*1000);
    $ok=($page['json']['total']??0)===10000&&count($page['json']['rows']??[])===50&&count($legacy['json']['emails']??[])===10000;
    echo ($ok?'PASS ':'FAIL ').'10,000 permitted tickets, only 50 queue rows transferred'."\n";
    echo json_encode(['queue_ms'=>$pageMs,'queue_bytes'=>strlen($page['body']),'legacy_ms'=>$legacyMs,'legacy_bytes'=>strlen($legacy['body'])])."\n";
    $exit=$ok?0:1;
} finally {
    if($fixture){if($conn->inTransaction())$conn->rollBack();($fixture['cleanup'])();}
}
exit($exit);
