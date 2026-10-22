<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Local HTTP integration: task embedding must retain both module and company gates.
 * FREEITSM_TEST_URL=http://localhost:8088 FREEITSM_TEST_DATABASE=freeitsm_dev php tests/task-embedded-workspace.php
 * Generates accounts/records and removes only their IDs. No mail or workflows.
 */
require __DIR__.'/support/ticket-workspace-fixture.php';
$fixture = null; $failed = 0; $passed = 0;
$check = function (string $name, bool $ok) use (&$failed,&$passed): void {
    if ($ok) $passed++; else $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ').$name."\n";
};
try {
    $fixture = ticketWorkspaceFixture();
    extract($fixture);
    [$full,$csrf] = ticketWorkspaceLogin($url,$analysts['full']);
    $target = $url.'/tasks/embedded.php?task='.$task.'&ticket_id='.$ticket;
    $page = ticketWorkspaceHttp($full,$target);
    $check('linked task opens using the native editor', $page['status']===200 && str_contains($page['body'],'TASK_EMBED_CONTEXT'));
    $check('board is not rendered in the embedded document', $page['status']===200 && !str_contains($page['body'],'id="boardView"'));
    $check('different ticket relationship is refused', ticketWorkspaceHttp($full,$url.'/tasks/embedded.php?task='.$task.'&ticket_id='.$otherTicket)['status']===404);
    $check('foreign company task is refused', ticketWorkspaceHttp($full,$url.'/tasks/embedded.php?task='.$otherTask.'&ticket_id='.$otherTicket)['status']===404);
    $check('invalid ID is refused', ticketWorkspaceHttp($full,$url.'/tasks/embedded.php?task=0&ticket_id='.$ticket)['status']===404);
    $child=$insert('tasks',['title'=>'ZZ child','parent_task_id'=>$task,'tenant_id'=>$tenant,'created_by_id'=>$analysts['full']['id']]);
    $childPage=ticketWorkspaceHttp($full,$url.'/api/tasks/get.php?id='.$child.'&embed_root_id='.$task.'&ticket_id='.$ticket);
    $check('linked root allows its accessible native subtask',!empty($childPage['json']['success']));
    $foreignPage=ticketWorkspaceHttp($full,$url.'/api/tasks/get.php?id='.$otherTask.'&embed_root_id='.$task.'&ticket_id='.$ticket);
    $check('embedded read cannot escape to a different task tree',empty($foreignPage['json']['success']));
    [$read,$readCsrf] = ticketWorkspaceLogin($url,$analysts['read']);
    $check('read-only analyst can inspect the linked task', ticketWorkspaceHttp($read,$target)['status']===200);
    $write = ticketWorkspaceHttp($read,$url.'/api/tasks/save.php',['id'=>$task,'title'=>'ZZ must not save'],$readCsrf);
    $check('native task API still refuses read-only writes', empty($write['json']['success']));
    [$none] = ticketWorkspaceLogin($url,$analysts['none']);
    $check('missing Tasks permission is refused', ticketWorkspaceHttp($none,$target)['status']!==200);
    $fixture['conn']->prepare('UPDATE tasks SET ticket_id = NULL WHERE id = ?')->execute([$task]);
    $check('removed relationship is refused on the next request', ticketWorkspaceHttp($full,$target)['status']===404);
} catch (Throwable $error) {
    $failed++; echo 'FAIL integration setup or execution: '.str_replace(getenv('USERPROFILE') ?: '__none__','[profile]',$error->getMessage())."\n";
} finally {
    if ($fixture) ($fixture['cleanup'])();
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
