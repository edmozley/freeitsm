<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/support/ticket-workspace-browser.php';
require __DIR__.'/support/ticket-workspace-page.php';
$root = dirname(__DIR__);
$html = ticketWorkspacePage().<<<'HTML'
<script>
document.addEventListener=originalAdd;
const checks=[];
const check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
for(const name of ['loadAiSummary','loadTicketChecklists','loadCorrespondenceThread','loadNotes',
    'loadTicketAttachments','loadCmdbObjects','loadTicketDomains','loadTicketProjects','loadTicketAssets',
    'loadTicketTasks','loadTimeEntries','loadSlaState','loadTicketCustomFields','startPresence']) window[name]=()=>{};
(async()=>{
    displayEmail({ticket_id:101,ticket_number:'ZZ-101',subject:'ZZ Task embedding',body_content:'ZZ',body_type:'text',received_datetime:'2026-01-01 09:00:00'},[]);
    if(typeof TicketWorkspace.openTask!=='function'){check('native task embedding is available',false);return;}
    await TicketWorkspace.openTask(101,201);await pause(300);
    const frame=document.querySelector('.tw-task-frame');
    check('one active task document',!!frame&&document.querySelectorAll('.tw-task-frame').length===1);
    if(!frame)return;
    const doc=frame.contentDocument;
    doc.getElementById('grow').style.height='1800px';await pause(300);
    check('delayed task content grows without clipping',frame.clientHeight>=1800,frame.clientHeight);
    const tall=frame.clientHeight;
    doc.getElementById('grow').style.height='50px';await pause(300);
    check('task content can shrink again',frame.clientHeight<tall-500,frame.clientHeight);
    const height=frame.style.height;
    window.dispatchEvent(new MessageEvent('message',{origin:location.origin,source:window,data:{type:'freeitsm:task-embed',event:'resize',ticketId:101,taskId:201,height:9999}}));
    window.dispatchEvent(new MessageEvent('message',{origin:'https://example.invalid',source:frame.contentWindow,data:{type:'freeitsm:task-embed',event:'resize',ticketId:101,taskId:201,height:9999}}));
    window.dispatchEvent(new MessageEvent('message',{origin:location.origin,source:frame.contentWindow,data:{type:'freeitsm:task-embed',event:'resize',ticketId:101,taskId:999,height:9999}}));
    check('foreign origin, sender and task messages are ignored',frame.style.height===height);
    TicketWorkspace.activate('conversation');TicketWorkspace.activate('work');
    check('tab switch retains the same task document',document.querySelector('.tw-task-frame')===frame);
    const draft=doc.getElementById('newCommentInput');draft.value='ZZ unsaved comment';draft.dispatchEvent(new Event('input',{bubbles:true}));
    frame.contentWindow.confirm=()=>false;
    const canLeave=await TicketWorkspace.canLeave();
    check('unsaved task draft blocks leaving when discard is declined',!canLeave&&draft.value==='ZZ unsaved comment');
    frame.contentWindow.confirm=()=>true;
    check('explicit discard permits leaving',await TicketWorkspace.canLeave());
    draft.value='';draft.dispatchEvent(new Event('input',{bubbles:true}));
    const form=doc.createElement('iframe');form.className='tf-frame';form.srcdoc='<input id="answer"><button type="submit">Submit</button>';
    doc.getElementById('detailPanel').append(form);await pause(1000);
    const answer=form.contentDocument.getElementById('answer');answer.value='ZZ answer';answer.dispatchEvent(new Event('input',{bubbles:true}));
    frame.contentWindow.confirm=()=>false;
    check('an unsent nested form answer also protects navigation',!await TicketWorkspace.canLeave());
    form.contentDocument.querySelector('button').disabled=true;frame.contentWindow.confirm=()=>true;
    check('a nested form submission cannot be interrupted by discarding',!await TicketWorkspace.canLeave());
    form.srcdoc='<p>ZZ submitted</p>';await pause(1000);
    frame.contentWindow.confirm=()=>false;
    check('a submitted form document releases its old draft',await TicketWorkspace.canLeave());
})().catch(error=>check('embed bridge runs',false,String(error))).finally(()=>document.getElementById('result').textContent=JSON.stringify(checks));
</script></body></html>
HTML;
$bridge = is_file($root.'/assets/js/task-embed-bridge.js') ? file_get_contents($root.'/assets/js/task-embed-bridge.js') : '';
$frame = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0}#detailPanel{height:auto}</style></head><body>'
    .'<div id="detailPanel"><div id="grow" style="height:50px">ZZ task content</div><textarea id="newCommentInput"></textarea></div>'
    .'<script>window.TASK_HOST="ticket";window.TASK_EMBED_CONTEXT={ticketId:101,taskId:201};window.t=k=>k;window.API_BASE="/api/tasks/";</script>'
    .'<script>'.$bridge.'</script><script>window.TaskEmbedBridge?.ready();</script></body></html>';
exit(ticketWorkspaceBrowser(['index.html'=>$html,'tasks/embedded.php'=>$frame]));
