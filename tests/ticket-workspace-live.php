<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Native local page, real editor and native writes. No outgoing services are used.
 * Optional FREEITSM_SCREENSHOTS is a private directory for synthetic screenshots.
 */
require __DIR__.'/support/ticket-workspace-fixture.php';
require __DIR__.'/support/ticket-workspace-browser.php';
$fixture=null;$restore=null;$exit=1;
try {
    $fixture=ticketWorkspaceFixture();extract($fixture);
    // This test exercises time recording even when the install default is off.
    // Only its disposable company is changed; the fixture removes this row too.
    $insert('tenant_settings',['tenant_id'=>$tenant,'setting_key'=>'time_tracking_enabled','setting_value'=>'1']);
    $insert('emails',['exchange_message_id'=>'<zz-workspace-'.bin2hex(random_bytes(6)).'@example.invalid>',
        'ticket_id'=>$ticket,'from_address'=>'requester@example.invalid','from_name'=>'ZZ Requester',
        'to_recipients'=>'desk@example.invalid','subject'=>'ZZ Workspace ticket',
        'body_content'=>'<p>ZZ Please check the service and record the outcome in the linked task.</p>',
        'body_type'=>'html','received_datetime'=>gmdate('Y-m-d H:i:s'),'direction'=>'Inbound','is_initial'=>1]);
    $restore = ticketWorkspaceSetting($conn, 'ticket_workspace_mode', 'selected');
    $conn->prepare('UPDATE tickets SET is_workspace = 1 WHERE id = ?')->execute([$ticket]);
    $exit=ticketWorkspaceBrowser([],['url'=>$url.'/auth/login.php','verify'=>function(string $ws)use($fixture):array {
        extract($fixture);
        $credentials=json_encode(['username'=>$analysts['full']['username'],'password'=>$analysts['full']['password']]);
        cdpEvaluate($ws,"fetch('login.php',{method:'POST',body:new URLSearchParams($credentials)}).then(r=>r.ok?'ok':'failed')",20);
        cdpEvaluate($ws,'',10,['method'=>'Page.addScriptToEvaluateOnNewDocument','params'=>['source'=>"window.__workspaceErrors=[];window.addEventListener('error',e=>__workspaceErrors.push(e.message));window.addEventListener('unhandledrejection',e=>__workspaceErrors.push(String(e.reason)));"]]);
        cdpEvaluate($ws,'',10,['method'=>'Page.navigate','params'=>['url'=>$url.'/tickets/?ticket_id='.$ticket]]);
        usleep(500000);
        $checks=json_decode(cdpEvaluate($ws,<<<JS
(async()=>{
 window.__workspaceErrors??=[];
 const checks=[],check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
 window.__workspaceChecks=checks;
 const wait=async(fn)=>{for(let i=0;i<200;i++){if(await fn())return true;await new Promise(r=>setTimeout(r,100));}return false;};
 await wait(()=>document.querySelector('.tw-grid'));
 await wait(()=>ticketPendingWrites===0);
 check('native ticket page loads the selected workspace',!!document.querySelector('.tw-grid'));
 check('native Link to action remains directly available',!!document.querySelector('.tw-header .link-add-wrap'));
 await setTicketWorkspace(false);await wait(()=>!document.querySelector('.tw-grid')&&ticketPendingWrites===0);
 const flagOff=!document.querySelector('.tw-grid')&&Number(currentEmail.is_workspace)===0;
 document.querySelector('.pane-more-btn').click();
 const make=document.querySelector('#paneMoreMenu [onclick="setTicketWorkspace(true)"]');
 if(make)make.click();await wait(()=>document.querySelector('.tw-grid')&&ticketPendingWrites===0);
 check('native More action switches the persisted ticket flag in both directions',flagOff&&!!make&&!!document.querySelector('.tw-grid')&&Number(currentEmail.is_workspace)===1);

 let reportUrl='';const nativeOpen=window.open;window.open=url=>{reportUrl=url;};
 document.querySelector('.tw-header [onclick="openCaseReport()"]').click();window.open=nativeOpen;
 check('native ticket report remains available in the workspace',new URL(reportUrl,location.href).pathname.endsWith('/tickets/case-report.php')&&new URL(reportUrl,location.href).searchParams.get('id')==='$ticket');
 TicketWorkspace.activate('work');
 await wait(()=>document.querySelector('#twTaskList a'));
 const taskLink=document.querySelector('#twTaskList a');
 check('Work opens the native task separately in this contribution',!!taskLink&&new URL(taskLink.href).searchParams.get('task')==='$task'&&taskLink.target==='_blank');
 TicketWorkspace.activate('conversation');
 openNoteModal();document.getElementById('noteText').textContent='ZZ internal workspace evidence';await saveNote(); /* #noteText is an editable box since GH #224 (pictures in notes): .value set nothing, and the note was refused as empty */
 await wait(()=>document.getElementById('notesContainer')?.textContent.includes('ZZ internal workspace evidence'));
 check('native internal note saves in the same ticket',document.getElementById('notesContainer').textContent.includes('ZZ internal workspace evidence'));
 TicketWorkspace.activate('time');await wait(()=>document.getElementById('timeEntryMinutes'));
 if(document.getElementById('timeEntryMinutes')){
   document.getElementById('timeEntryMinutes').value='7';document.getElementById('timeEntryNotes').value='ZZ workspace time';await saveTimeEntry();
   await wait(()=>document.getElementById('timeEntriesContainer').textContent.includes('ZZ workspace time'));
   check('native time entry saves from its workspace tab',document.getElementById('timeEntriesContainer').textContent.includes('ZZ workspace time'));
 }else {
   const lookup=await fetch('../api/tickets/get_time_entries.php?ticket_id=$ticket').then(r=>r.json());
   check('native time entry form is available',false,{section:document.getElementById('timeEntriesContainer')?.textContent,success:lookup.success,disabled:lookup.disabled,render:ticketRenderGeneration});
 }
 document.getElementById('tw-tab-history').click();
 await wait(()=>document.getElementById('twAuditBody')?.getAttribute('aria-busy')==='false');
 check('native audit content renders on opening History',!!document.getElementById('twAuditBody')?.textContent.trim()&&!document.querySelector('#tw-panel-history button'));
 check('page has no uncaught JavaScript errors',!window.__workspaceErrors?.length,window.__workspaceErrors);
 return JSON.stringify(checks);
})().catch(error=>JSON.stringify([...window.__workspaceChecks,{label:'native workflow completes',pass:false,detail:String(error)}]))
JS,80),true);
        if (array_filter($checks,fn($check)=>!$check['pass'])) return $checks;
        foreach([1440,1024,768,390] as $width) {
            $emulate=['method'=>'Emulation.setDeviceMetricsOverride','params'=>['width'=>$width,'height'=>1000,'deviceScaleFactor'=>1,'mobile'=>false]];
            $before=[['method'=>'Page.enable','params'=>new stdClass()],$emulate];
            if($width===390)$before[]=['method'=>'Page.reload','params'=>new stdClass()];
            $layout=json_decode(cdpEvaluate($ws,"(async()=>{for(let i=0;i<100&&!document.querySelector('.tw-grid');i++)await new Promise(r=>setTimeout(r,100));TicketWorkspace.activate('conversation');document.body.dataset.mobilePane='reading';await new Promise(r=>setTimeout(r,500));return JSON.stringify({width:innerWidth,overflow:document.querySelector('.main-container').getBoundingClientRect().right>innerWidth+2,tabs:[...document.querySelectorAll('.tw-tab')].every(e=>e.getBoundingClientRect().width>0),errors:window.__workspaceErrors});})()",15,null,$before),true);
            $checks[]=['label'=>'workspace is reachable at '.$width.'px','pass'=>$layout['width']===$width&&!$layout['overflow']&&$layout['tabs'],'detail'=>$layout];
            $directory=getenv('FREEITSM_SCREENSHOTS');
            if($directory && is_dir($directory)) {
                $capture=json_decode(cdpEvaluate($ws,'',10,['method'=>'Page.captureScreenshot','params'=>['format'=>'png']],[$emulate,['method'=>'Runtime.evaluate','params'=>['expression'=>"new Promise(r=>setTimeout(()=>r('ready'),500))",'awaitPromise'=>true]]]),true);
                if(isset($capture['data']))file_put_contents($directory.'/workspace-'.$width.'.png',base64_decode($capture['data']));
            }
        }
        $checks[]=json_decode(cdpEvaluate($ws,"(async()=>{let error='';try{TicketWorkspace.activate('history')}catch(e){error=String(e)}for(let i=0;i<100&&document.getElementById('twAuditBody')?.getAttribute('aria-busy')==='true';i++)await new Promise(r=>setTimeout(r,50));return JSON.stringify({label:'phone History renders inline without the legacy modal',pass:innerWidth===390&&!error&&!!document.getElementById('twAuditBody')?.textContent.trim()&&getComputedStyle(document.querySelector('.mobile-sheet-audit')).display==='none',detail:error});})()",10,null,[$emulate]),true);
        $zoom=['method'=>'Emulation.setDeviceMetricsOverride','params'=>['width'=>720,'height'=>500,'deviceScaleFactor'=>2,'mobile'=>false]];
        $checks[]=json_decode(cdpEvaluate($ws,"(async()=>{document.documentElement.dataset.theme='dark';document.body.dataset.mobilePane='reading';await new Promise(r=>setTimeout(r,400));return JSON.stringify({label:'dark theme and 200 percent equivalent CSS viewport keep actions reachable',pass:!!document.querySelector('.tw-header .link-add-wrap')&&[...document.querySelectorAll('.tw-tab')].every(e=>e.clientWidth>0)});})()",5,null,[$zoom]),true);
        $checks[]=json_decode(cdpEvaluate($ws,"(async()=>{const idle=async()=>{for(let i=0;i<100&&ticketPendingWrites;i++)await new Promise(r=>setTimeout(r,50));};await idle();await TicketWorkspace.requestPersonal(false);const legacy=!document.querySelector('.ticket-workspace')&&Number(currentEmail.ticket_id)===$ticket;await idle();await TicketWorkspace.requestPersonal(true);return JSON.stringify({label:'switching layouts retains the same native ticket',pass:legacy&&!!document.querySelector('.ticket-workspace')&&Number(currentEmail.ticket_id)===$ticket});})()",15),true);
        return $checks;
    }]);
} finally {if($restore)$restore();if($fixture)($fixture['cleanup'])();}
exit($exit);
