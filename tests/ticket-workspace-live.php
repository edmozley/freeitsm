<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Native local page, real editor and native writes. No outgoing services are used.
 * Optional FREEITSM_SCREENSHOTS is a private directory for synthetic screenshots.
 */
require __DIR__.'/support/ticket-workspace-fixture.php';
require __DIR__.'/support/ticket-workspace-browser.php';
$fixture=null;$exit=1;
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
    for($i=1;$i<75;$i++)$insert('tickets',['ticket_number'=>'ZZUI-'.bin2hex(random_bytes(5)),'subject'=>'ZZ Queue selection '.$i,'tenant_id'=>$tenant]);
    foreach(['tickets_detail_layout'=>'workspace','tickets_list_layout'=>'messages'] as $key=>$value)
        $conn->prepare('INSERT INTO user_preferences (analyst_id,preference_key,preference_value) VALUES (?,?,?)')->execute([$analysts['full']['id'],$key,$value]);
    $exit=ticketWorkspaceBrowser([],['url'=>$url.'/auth/login.php','verify'=>function(string $ws)use($fixture):array {
        extract($fixture);
        $credentials=json_encode(['username'=>$analysts['full']['username'],'password'=>$analysts['full']['password']]);
        cdpEvaluate($ws,"fetch('/auth/login.php',{method:'POST',body:new URLSearchParams($credentials)}).then(r=>r.ok?'ok':'failed')",20);
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
 let initialTableQuery=null;
 const nativeFetch=window.fetch;
 window.fetch=(input,init)=>{if(String(input).includes('tickets/table.php'))initialTableQuery??=new URL(String(input),location.href);return nativeFetch(input,init);};
 currentFilter={type:'unassigned'};folderGrouping='analyst';
 await TicketTable.requestMode('table');
 check('first table request preserves the selected folder',JSON.parse(initialTableQuery?.searchParams.get('filters')||'{}').assignee_id?.[0]==='unassigned');
 await wait(()=>document.querySelector('.tw-grid')&&document.querySelector('#dtBody .dt-row'));
 check('native ticket page loads both optional views',!!document.querySelector('.tw-grid')&&!!document.querySelector('#dtBody .dt-row'));
 check('native Link to action remains directly available',!!document.querySelector('.tw-header .link-add-wrap'));
 let reportUrl='';const nativeOpen=window.open;window.open=url=>{reportUrl=url;};
 document.querySelector('.tw-header [onclick="openCaseReport()"]').click();window.open=nativeOpen;
 check('native ticket report remains available in the workspace',new URL(reportUrl,location.href).pathname==='/tickets/case-report.php'&&new URL(reportUrl,location.href).searchParams.get('id')==='$ticket');
 await TicketWorkspace.openTask($ticket,$task);
 await wait(()=>document.querySelector('.tw-task-frame')?.contentDocument?.getElementById('detailTitle'));
 const frame=document.querySelector('.tw-task-frame');const doc=frame?.contentDocument;
 check('native task fields initialise inside the ticket',!!doc?.getElementById('detailTitle'));
 await wait(()=>doc?.querySelector('.task-embed-open')&&document.querySelector('.tw-task-controls')?.hidden);
 const external=doc.querySelector('.task-embed-open');
 check('compact task actions share the native header',external?.closest('.detail-panel-actions')&&new URL(external.href).searchParams.get('task')==='$task'&&external.target==='_blank'&&external.getBoundingClientRect().height<=32&&document.querySelector('.tw-task-controls').hidden);
 const nativeTaskOpen=frame.contentWindow.open;frame.contentWindow.open=url=>{reportUrl=url;};
 doc.querySelector('[onclick="openTaskCaseReport()"]').click();frame.contentWindow.open=nativeTaskOpen;
 check('native task report remains available in the embedded editor',new URL(reportUrl,location.href).pathname==='/tasks/case-report.php'&&new URL(reportUrl,location.href).searchParams.get('id')==='$task');
 if(doc?.getElementById('detailTitle')){
     const title=doc.getElementById('detailTitle');title.value='ZZ Workspace saved in ticket';title.dispatchEvent(new Event('change',{bubbles:true}));
     const saved=await wait(async()=>{
       const data=await fetch('../api/tasks/get.php?id=$task').then(r=>r.json());
       return data.task?.title==='ZZ Workspace saved in ticket';
     });
     check('embedded editor saves through the native task API',saved);
     TicketWorkspace.activate('conversation');TicketWorkspace.activate('work');
     check('switching registers retains the native editor',document.querySelector('.tw-task-frame')===frame);
     await loadTicketById($ticket);
     check('refreshing the same ticket retains the native task document',document.querySelector('.tw-task-frame')===frame);
     const comment=doc.getElementById('newCommentInput');
     check('native comment editor is available',!!comment);
     if(comment){comment.value='ZZ unsent comment';comment.dispatchEvent(new Event('input',{bubbles:true}));frame.contentWindow.showConfirm=async()=>false;
       check('native unsent task comment prevents unconfirmed navigation',!await TicketWorkspace.canLeave());
       await frame.contentWindow.closeDetailPanel();
       check('native Close also preserves an unconfirmed task draft',document.querySelector('.tw-task-frame')===frame&&comment.value==='ZZ unsent comment');
       comment.value='';comment.dispatchEvent(new Event('input',{bubbles:true}));}
 }
 doc.querySelector('[onclick="closeDetailPanel()"]').click();await wait(()=>!document.querySelector('.tw-task-frame'));
 openNoteModal();document.getElementById('noteText').value='ZZ internal workspace evidence';await saveNote();
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
 const rows=[...document.querySelectorAll('#dtBody .dt-row')];
 check('native queue paints only its first 50 tickets',rows.length===50);
 multiSelectPaneMode='keep';
 await handleEmailRowClick({ctrlKey:false,metaKey:false,shiftKey:false},Number(rows[0].dataset.emailId));
 await wait(()=>Number(currentEmail?.ticket_id)===Number(rows[0].dataset.ticketId));
 await handleEmailRowClick({ctrlKey:true,metaKey:false,shiftKey:false},Number(rows[1].dataset.emailId));
 await handleEmailRowClick({ctrlKey:false,metaKey:false,shiftKey:true},Number(rows[3].dataset.emailId));
 check('native modifier selection operates on visible table rows',TicketTable.getSelectedTicketIds().length===3);
 rows[4].dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:350,clientY:350}));
 check('native context menu targets a row outside the selection',TicketTable.getSelectedTicketIds().length===1&&TicketTable.getSelectedTicketIds()[0]===Number(rows[4].dataset.ticketId));
 const transfer=new DataTransfer();rows[4].dispatchEvent(new DragEvent('dragstart',{bubbles:true,dataTransfer:transfer}));
 check('native drag payload identifies the selected ticket',Number(transfer.getData('text/plain'))===Number(rows[4].dataset.ticketId));
 rows[4].dispatchEvent(new DragEvent('dragend',{bubbles:true,dataTransfer:transfer}));
 document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
 document.querySelectorAll('.dt-pagination button')[1].click();await wait(()=>document.querySelectorAll('#dtBody .dt-row').length===25);
 check('changing pages clears hidden selection',TicketTable.getSelectedTicketIds().length===0&&document.querySelectorAll('#dtBody .dt-row').length===25);
 await TicketTable.requestMode('messages');
 check('returning to messages excludes hidden table rows from selection',visibleEmailIds().length===75);
 await TicketTable.requestMode('table');await wait(()=>ticketPendingWrites===0);await loadTicketById($ticket);
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
        $zoom=['method'=>'Emulation.setDeviceMetricsOverride','params'=>['width'=>720,'height'=>500,'deviceScaleFactor'=>2,'mobile'=>false]];
        $checks[]=json_decode(cdpEvaluate($ws,"(async()=>{document.documentElement.dataset.theme='dark';document.body.dataset.mobilePane='reading';await new Promise(r=>setTimeout(r,400));return JSON.stringify({label:'dark theme and 200 percent equivalent CSS viewport keep actions reachable',pass:!!document.querySelector('.tw-header .link-add-wrap')&&[...document.querySelectorAll('.tw-tab')].every(e=>e.clientWidth>0)});})()",5,null,[$zoom]),true);
        $checks[]=json_decode(cdpEvaluate($ws,"(async()=>{const idle=async()=>{for(let i=0;i<100&&ticketPendingWrites;i++)await new Promise(r=>setTimeout(r,50));};await idle();await TicketWorkspace.requestMode('inbox');const legacy=!document.querySelector('.ticket-workspace')&&Number(currentEmail.ticket_id)===$ticket;await idle();await TicketWorkspace.requestMode('workspace');return JSON.stringify({label:'switching layouts retains the same native ticket',pass:legacy&&!!document.querySelector('.ticket-workspace')&&Number(currentEmail.ticket_id)===$ticket});})()",15),true);
        return $checks;
    }]);
} finally {if($fixture)($fixture['cleanup'])();}
exit($exit);
