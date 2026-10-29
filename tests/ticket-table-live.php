<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Native ticket table, independently of the optional workspace. Local synthetic fixtures only.
 * Control: the old layout fails the full-width, detached-pager and separator
 * checks. The remaining checks keep native queue and draft handling intact.
 */
require __DIR__.'/support/ticket-workspace-fixture.php';
require __DIR__.'/support/ticket-workspace-browser.php';
$fixture=null;$restore=null;$exit=1;
try {
    $fixture=ticketWorkspaceFixture();extract($fixture);
    $restore=ticketWorkspaceSetting($conn,'ticket_list_export','ticket_users');
    $insert('emails',['ticket_id'=>$ticket,'from_address'=>'requester@example.invalid','from_name'=>'ZZ Requester',
        'to_recipients'=>'desk@example.invalid','subject'=>'ZZ Workspace ticket','body_content'=>'ZZ Check the service.',
        'body_type'=>'text','received_datetime'=>gmdate('Y-m-d H:i:s'),'direction'=>'Inbound','is_initial'=>1]);
    for($i=1;$i<75;$i++)$insert('tickets',['ticket_number'=>'ZZUI-'.bin2hex(random_bytes(5)),'subject'=>'ZZ Queue selection '.$i,'tenant_id'=>$tenant]);
    $exit=ticketWorkspaceBrowser([],['url'=>$url.'/auth/login.php','verify'=>function(string $ws)use($fixture):array {
        extract($fixture);
        $credentials=json_encode(['username'=>$analysts['full']['username'],'password'=>$analysts['full']['password']]);
        cdpEvaluate($ws,"fetch('login.php',{method:'POST',body:new URLSearchParams($credentials)}).then(r=>r.ok?'ok':'failed')",20);
        cdpEvaluate($ws,'',10,['method'=>'Page.navigate','params'=>['url'=>$url.'/tickets/']]);
        usleep(500000);
        $checks=json_decode(cdpEvaluate($ws,<<<JS
(async()=>{
 window.__tableErrors=[];window.addEventListener('error',e=>__tableErrors.push(e.message));window.addEventListener('unhandledrejection',e=>__tableErrors.push(String(e.reason)));
 const checks=[],check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});window.__tableChecks=checks;
 const wait=async(fn)=>{for(let i=0;i<200;i++){if(await fn())return true;await new Promise(r=>setTimeout(r,100));}return false;};
 await wait(()=>typeof emails!=='undefined');
 if(typeof emails==='undefined')return JSON.stringify([{label:'native ticket page initialises',pass:false,detail:{path:location.pathname,title:document.title,heading:document.querySelector('h1')?.textContent,scripts:[...document.scripts].map(s=>s.getAttribute('src')).filter(Boolean)}}]);
 await wait(()=>emails.length===75&&(typeof ticketPendingWrites==='undefined'||ticketPendingWrites===0));
 check('message list remains the default independently of the detail layout',window.TICKETS_LIST_LAYOUT==='messages');
 const pane=document.getElementById('readingPane').firstElementChild;
 let initialQuery=null,exportQuery=null;const nativeFetch=window.fetch;
 window.fetch=(input,init)=>{const value=String(input);if(value.includes('tickets/table.php'))initialQuery??=new URL(value,location.href);if(value.includes('table_export.php'))exportQuery=new URL(value,location.href);return nativeFetch(input,init);};
 currentFilter={type:'unassigned'};folderGrouping='analyst';
 await TicketTable.requestMode('table');
 await wait(()=>document.querySelectorAll('#dtBody .dt-row').length===50);
 check('first table request retains the chosen folder',JSON.parse(initialQuery?.searchParams.get('filters')||'{}').assignee_id?.[0]==='unassigned');
 check('switching list style preserves the open reading pane',document.getElementById('readingPane').firstElementChild===pane);
 const rows=[...document.querySelectorAll('#dtBody .dt-row')];
 check('native queue paints only its first 50 tickets',rows.length===50);
 const queue=document.querySelector('.email-list-container'), main=document.querySelector('.main-container');
 const available=main.clientWidth-document.querySelector('.folder-container').getBoundingClientRect().width;
 check('an unopened table uses the available queue width',queue.getBoundingClientRect().width>=available-12,{queue:queue.clientWidth,available});
 const pager=document.querySelector('.dt-pagination');
 check('pagination is outside the horizontally scrolling table',pager&&!pager.closest('.dt-wrap'));
 check('pagination typography matches compact table controls',parseFloat(getComputedStyle(pager).fontSize)<=14);

 multiSelectPaneMode='keep';
 await handleEmailRowClick({ctrlKey:false,metaKey:false,shiftKey:false},Number(rows[0].dataset.emailId));
 await wait(()=>Number(currentEmail?.ticket_id)===Number(rows[0].dataset.ticketId));
 const separator=document.getElementById('ticketTableSplitter');
 check('opening a ticket provides a keyboard-operable split',separator?.getAttribute('role')==='separator'&&separator.tabIndex===0&&!separator.hidden);
 if(separator){
   const before=queue.getBoundingClientRect().width;
   separator.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
   check('keyboard resizing changes the split within useful bounds',queue.getBoundingClientRect().width>before&&queue.clientWidth>=440);
   separator.dispatchEvent(new KeyboardEvent('keydown',{key:'End',bubbles:true}));
   check('resizing preserves a usable detail width',document.getElementById('readingPane').clientWidth>=599);
   separator.dispatchEvent(new KeyboardEvent('keydown',{key:'Home',bubbles:true}));
   check('resizing preserves a usable list width',Math.abs(queue.getBoundingClientRect().width-440)<=1);

 }
 const retained=document.getElementById('readingPane').firstElementChild;
 const scroller=document.querySelector('#ticketTableContainer .dt-wrap');scroller.scrollTop=200;
 const listScroll=scroller.scrollTop;
 if(TicketTable.backToList){
   ticketPendingWrites++;
   try { await TicketTable.backToList(); check('Back waits for native pending writes',getComputedStyle(document.getElementById('readingPane')).display!=='none'); }
   finally { ticketPendingWrites--; }
   await wait(()=>ticketPendingWrites===0);
   const modal=document.getElementById('noteModal'), confirm=window.showConfirm;
   let prompted=false;
   modal.classList.add('active');
   window.showConfirm=async()=>{prompted=true;return false;};
   try { await TicketTable.backToList(); check('cancelling draft discard keeps the detail open',prompted&&document.body.classList.contains('ticket-table-detail')&&modal.classList.contains('active')); }
   finally { modal.classList.remove('active');window.showConfirm=confirm; }
   await TicketTable.backToList();
   check('Back focus preserves the list scroll position',scroller.scrollTop===listScroll);
 }
 check('Back restores full queue width without rebuilding the ticket',queue.getBoundingClientRect().width>=available-12&&document.getElementById('readingPane').firstElementChild===retained,{width:queue.clientWidth,available,pending:ticketPendingWrites,retained:document.getElementById('readingPane').firstElementChild===retained,open:document.body.classList.contains('ticket-table-detail')});
 await handleEmailRowClick({ctrlKey:false,metaKey:false,shiftKey:false},Number(rows[0].dataset.emailId));
 await wait(()=>getComputedStyle(document.getElementById('readingPane')).display!=='none');

 await handleEmailRowClick({ctrlKey:true,metaKey:false,shiftKey:false},Number(rows[1].dataset.emailId));
 await handleEmailRowClick({ctrlKey:false,metaKey:false,shiftKey:true},Number(rows[3].dataset.emailId));
 check('native modifier selection uses visible rows',TicketTable.getSelectedTicketIds().length===3);
 rows[4].dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,clientX:350,clientY:350}));
 check('context menu targets a row outside the selection',TicketTable.getSelectedTicketIds().length===1&&TicketTable.getSelectedTicketIds()[0]===Number(rows[4].dataset.ticketId));
 const transfer=new DataTransfer();rows[4].dispatchEvent(new DragEvent('dragstart',{bubbles:true,dataTransfer:transfer}));
 check('native drag payload identifies the selected ticket',Number(transfer.getData('text/plain'))===Number(rows[4].dataset.ticketId));
 rows[4].dispatchEvent(new DragEvent('dragend',{bubbles:true,dataTransfer:transfer}));
 document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
 document.querySelectorAll('.dt-pagination button')[1].click();await wait(()=>document.querySelectorAll('#dtBody .dt-row').length===25);
 check('changing pages clears hidden selection',TicketTable.getSelectedTicketIds().length===0&&document.querySelectorAll('#dtBody .dt-row').length===25);
 const search=document.getElementById('dtSearch');search.value='ZZ Workspace ticket';search.dispatchEvent(new Event('input',{bubbles:true}));
 await wait(()=>document.querySelectorAll('#dtBody .dt-row').length===1);
 check('native search applies across all pages',document.querySelectorAll('#dtBody .dt-row').length===1&&Number(document.querySelector('#dtBody .dt-row')?.dataset.ticketId)===$ticket);
 const anchorClick=HTMLAnchorElement.prototype.click;HTMLAnchorElement.prototype.click=function(){};
 document.getElementById('dtCsvBtn').click();await wait(()=>exportQuery);await new Promise(r=>setTimeout(r,400));HTMLAnchorElement.prototype.click=anchorClick;
 check('CSV receives the current complete filter and visible columns',exportQuery?.searchParams.get('q')==='zz workspace ticket'&&exportQuery.searchParams.get('columns').split(',').includes('subject'));
 await TicketTable.requestMode('messages');
 check('message mode excludes hidden table rows from selection',visibleEmailIds().length===75);
 await TicketTable.requestMode('table');
 search.value='';search.dispatchEvent(new Event('input',{bubbles:true}));await wait(()=>document.querySelectorAll('#dtBody .dt-row').length===50);
 check('table workflow has no uncaught JavaScript errors',!window.__tableErrors.length,window.__tableErrors);
 return JSON.stringify(checks);
})().catch(error=>JSON.stringify([...window.__tableChecks,{label:'native table workflow completes',pass:false,detail:String(error)}]))
JS,100),true);
        if(array_filter($checks,fn($check)=>!$check['pass']))return $checks;
        // Real pointer input: synthetic PointerEvents do not create pointer capture.
        $split=json_decode(cdpEvaluate($ws,<<<'JS'
(async()=>{
 const row=document.querySelector('#dtBody .dt-row');
 await handleEmailRowClick({ctrlKey:false,shiftKey:false},Number(row.dataset.emailId));
 await new Promise(r=>setTimeout(r,400));
 const box=document.getElementById('ticketTableSplitter').getBoundingClientRect();
 return JSON.stringify({x:box.x+box.width/2,y:box.y+100,width:document.querySelector('.email-list-container').getBoundingClientRect().width});
})()
JS,10),true);
        $pointer=[];
        foreach(['mouseMoved','mousePressed','mouseMoved','mouseReleased']as$i=>$type){
            $pointer[]=['method'=>'Input.dispatchMouseEvent','params'=>['type'=>$type,'x'=>$split['x']+($i>1?32:0),'y'=>$split['y'],
                'button'=>$i===0?'none':'left','buttons'=>in_array($i,[1,2])?1:0,'clickCount'=>$type==='mousePressed'||$type==='mouseReleased'?1:0]];
        }
        $checks[]=json_decode(cdpEvaluate($ws,"JSON.stringify({label:'pointer resizing changes width and releases capture',pass:document.querySelector('.email-list-container').getBoundingClientRect().width>".$split['width']."&&!document.body.classList.contains('ticket-table-resizing')})",10,null,$pointer),true);
        foreach([1024,768,390]as$width){
            $emulate=['method'=>'Emulation.setDeviceMetricsOverride','params'=>['width'=>$width,'height'=>1000,'deviceScaleFactor'=>1,'mobile'=>false]];
            $responsive=json_decode(cdpEvaluate($ws,<<<JS
(async()=>{
 const checks=[],check=(label,pass)=>checks.push({label,pass:!!pass});
 const pause=()=>new Promise(r=>setTimeout(r,300));
 const pane=document.getElementById('readingPane'),queue=document.querySelector('.email-list-container');
 await TicketTable.backToList();await pause();
 const first=document.querySelector('#dtBody .dt-row');
 await handleEmailRowClick({ctrlKey:false,shiftKey:false},Number(first.dataset.emailId));await pause();
 if($width>768){
   check('narrow desktop uses the full detail width',getComputedStyle(queue).display==='none'&&pane.getBoundingClientRect().width>=document.querySelector('.main-container').clientWidth-2);
   await TicketTable.backToList();await pause();
   check('narrow desktop Back restores the queue',getComputedStyle(queue).display!=='none'&&getComputedStyle(pane).display==='none');
 }else{
   check('phone keeps native sliding panes at '+$width,getComputedStyle(queue).display!=='none'&&getComputedStyle(pane).display!=='none'&&getComputedStyle(document.getElementById('ticketTableBack')).display==='none');
 }
 return JSON.stringify(checks);
})()
JS,15,null,[$emulate]),true);
            $checks=array_merge($checks,$responsive);
        }
        $directory=getenv('FREEITSM_SCREENSHOTS');
        if($directory){
            if(!is_dir($directory))mkdir($directory,0700,true);
            foreach([1440,1024,768,390]as$width){
                $emulate=['method'=>'Emulation.setDeviceMetricsOverride','params'=>['width'=>$width,'height'=>1000,'deviceScaleFactor'=>1,'mobile'=>false]];
                $capture=json_decode(cdpEvaluate($ws,'',10,['method'=>'Page.captureScreenshot','params'=>['format'=>'png']],[$emulate,['method'=>'Runtime.evaluate','params'=>['expression'=>"(async()=>{await TicketTable.backToList();document.body.dataset.mobilePane='list';await new Promise(r=>setTimeout(r,400));return 'ready';})()",'awaitPromise'=>true]]]),true);
                if(isset($capture['data']))file_put_contents($directory.'/table-'.$width.'.png',base64_decode($capture['data']));
                if($width>768){
                    foreach(['default','dark']as$theme){
                        $prepare="(async()=>{document.documentElement.dataset.theme='$theme';const row=document.querySelector('#dtBody .dt-row');await handleEmailRowClick({ctrlKey:false,shiftKey:false},Number(row.dataset.emailId));await new Promise(r=>setTimeout(r,500));return 'ready';})()";
                        $detail=json_decode(cdpEvaluate($ws,'',10,['method'=>'Page.captureScreenshot','params'=>['format'=>'png']],[$emulate,['method'=>'Runtime.evaluate','params'=>['expression'=>$prepare,'awaitPromise'=>true]]]),true);
                        if(isset($detail['data']))file_put_contents($directory.'/table-detail-'.$width.'-'.$theme.'.png',base64_decode($detail['data']));
                    }
                    cdpEvaluate($ws,"document.documentElement.dataset.theme='default';'ready'",5);
                }

            }
        }
        return $checks;
    }]);
}finally{
    if($restore)$restore();
    if($fixture)($fixture['cleanup'])();
}
exit($exit);
