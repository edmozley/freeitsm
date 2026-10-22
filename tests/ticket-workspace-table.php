<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Local-only: FREEITSM_TEST_URL and FREEITSM_TEST_DATABASE are required.
require __DIR__.'/support/ticket-workspace-fixture.php';
$fixture=null; $passed=0; $failed=0;
$check=function(string $name,bool $ok)use(&$passed,&$failed){if($ok)$passed++;else $failed++;echo ($ok?'PASS ':'FAIL ').$name."\n";};
try {
    $fixture=ticketWorkspaceFixture(); extract($fixture);
    [$client]=ticketWorkspaceLogin($url,$analysts['full']);
    $ids=[$ticket];
    for($i=1;$i<=124;$i++) $ids[]=$insert('tickets',['ticket_number'=>'ZZQ-'.bin2hex(random_bytes(6)),'subject'=>$i===1?'=ZZ formula':'ZZ queue '.$i,'tenant_id'=>$tenant]);
    $read=fn(array $q=[])=>ticketWorkspaceHttp($client,$url.'/api/tickets/table.php?'.http_build_query($q));
    $one=$read(['page'=>1,'page_size'=>50]);
    $check('server queue returns a bounded page and full count',$one['status']===200&&($one['json']['total']??0)===125&&count($one['json']['rows']??[])===50);
    if(empty($one['json']['rows'])) throw new RuntimeException('Table page is unavailable.');
    $all=[];for($page=1;$page<=3;$page++) foreach($read(['page'=>$page,'page_size'=>50])['json']['rows'] as $row)$all[]=(int)$row['ticket_id'];
    $legacy=ticketWorkspaceHttp($client,$url.'/api/tickets/get_emails.php');$legacyIds=array_map('intval',array_column($legacy['json']['emails'],'ticket_id'));sort($all);sort($legacyIds);sort($ids);
    $check('all pages match legacy permissions and include tickets without email',$all===$ids&&$all===$legacyIds&&!in_array($otherTicket,$all,true));
    $row=$one['json']['rows'][0];$check('row identity separates inbox and ticket IDs',(int)$row['inbox_id']===-(int)$row['ticket_id']);
    $bad=$read(['sort'=>'(SELECT password_hash FROM analysts)']);$check('unknown SQL sort expressions are rejected',$bad['status']===400);
    $check('unknown filter keys are rejected',$read(['filters'=>'{"tenant_id":["1"]}'])['status']===400);
    $filtered=$read(['q'=>'ZZ formula']);$check('search is applied before paging',($filtered['json']['total']??0)===1);
    $csv=ticketWorkspaceHttp($client,$url.'/api/tickets/table_export.php?'.http_build_query(['columns'=>'ticket_number,subject']));
    $stream=fopen('php://memory','r+');fwrite($stream,$csv['body']);rewind($stream);$lines=[];while(($line=fgetcsv($stream,0,',','"',''))!==false)$lines[]=$line;fclose($stream);
    $check('CSV exports the whole filter and only requested fields',$csv['status']===200&&count($lines)===126&&count($lines[1]??[])===2);
    $check('CSV neutralises spreadsheet formulas',str_contains($csv['body'],"'=ZZ formula"));
    $dates=ticketWorkspaceHttp($client,$url.'/api/tickets/table_export.php?columns=created_datetime');
    $check('CSV timestamps explicitly identify UTC',$dates['status']===200&&preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z/',$dates['body'])===1);
    $conn->prepare('UPDATE tickets SET deleted_datetime=NOW() WHERE id=?')->execute([$ticket]);
    $trash=$read(['view'=>'trash']);$check('trash is a separate permitted queue',($trash['json']['total']??0)===1&&(int)$trash['json']['rows'][0]['ticket_id']===$ticket);
    $conn->prepare('UPDATE tickets SET snoozed_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id=?')->execute([$ids[1]]);
    $sleep=$read(['view'=>'snoozed']);$check('snoozed tickets are only in their own queue',($sleep['json']['total']??0)===1&&($read()['json']['total']??0)===123);
    $anon=curl_init();$check('anonymous table and export are refused',ticketWorkspaceHttp($anon,$url.'/api/tickets/table.php')['status']===401&&ticketWorkspaceHttp($anon,$url.'/api/tickets/table_export.php')['status']===401);
    require_once dirname(__DIR__).'/includes/table_views.php';
    $team=$insert('teams',['name'=>'ZZ Workspace view team']);
    foreach(['full','read'] as $member)$conn->prepare('INSERT INTO analyst_teams (analyst_id,team_id) VALUES (?,?)')->execute([$analysts[$member]['id'],$team]);
    $views=[];
    try {
        foreach(['private','team','public'] as $visibility)$views[$visibility]=tableViewSave($conn,$analysts['full']['id'],[
            'table_key'=>'tickets','name'=>'ZZ Workspace '.$visibility,'visibility'=>$visibility,'team_id'=>$team,'config'=>['cols'=>[['k'=>'subject','v'=>1]]]]);
        $visible=array_column(tableViewList($conn,$analysts['read']['id'],'tickets'),'id');
        $otherVisible=array_column(tableViewList($conn,$analysts['none']['id'],'tickets'),'id');
        $check('private ticket views remain private',!in_array($views['private'],$visible)&&!in_array($views['private'],$otherVisible));
        $check('team views follow existing membership',in_array($views['team'],$visible)&&!in_array($views['team'],$otherVisible));
        $check('public views can be reused without broadening ticket scope',in_array($views['public'],$visible)&&!in_array($otherTicket,$all));
    } finally {foreach($views as $id)$conn->prepare('DELETE FROM table_views WHERE id=?')->execute([$id]);}
} catch(Throwable $error){$failed++;echo 'FAIL '.$error->getMessage()."\n";} finally {if($fixture)($fixture['cleanup'])();}
echo "$passed passed, $failed failed\n";exit($failed?1:0);
