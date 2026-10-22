<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Native async loaders and navigation against controlled HTTP responses.
 * Tests the real delivered handlers; no mail, AI or external tracker is contacted.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
require __DIR__ . '/support/ticket-workspace-page.php';
$html = ticketWorkspacePage('window.fetch=()=>new Promise(resolve=>{window.__writeDeliver=resolve});') . <<<'HTML'
<script>
document.addEventListener=originalAdd;
const checks=[];
const check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
const realNotes=loadNotes, realTasks=loadTicketTasks, realSla=loadSlaState;
const realCustom=loadTicketCustomFields;
for(const name of ['loadAiSummary','loadTicketChecklists','loadCorrespondenceThread','loadNotes',
    'loadTicketAttachments','loadCmdbObjects','loadTicketDomains','loadTicketProjects','loadTicketAssets',
    'loadTicketTasks','loadTimeEntries','loadSlaState','loadTicketCustomFields','startPresence']) window[name]=()=>{};
const ticket={id:10,ticket_id:101,ticket_number:'ZZ-101',subject:'ZZ asynchronous ticket',
    status:'Open',received_datetime:'2026-01-01 09:00:00',body_content:'ZZ message',body_type:'text',
    sensitivity:'normal',linked_tickets:{},problems:[],changes:[]};
const response=data=>({ok:true,json:async()=>data});
(async()=>{
    displayEmail(ticket,[]);
    const writing=fetch('/api/tickets/save.php',{method:'POST'});
    check('pending native writes prevent workspace navigation',!await TicketWorkspace.canLeave());
    __writeDeliver(new Response('{"success":true}',{headers:{'Content-Type':'application/json'}}));
    await writing;
    for(let i=0;i<100&&ticketPendingWrites;i++)await new Promise(resolve=>setTimeout(resolve,20));
    check('finished native writes release navigation',await TicketWorkspace.canLeave(),ticketPendingWrites);
    document.querySelector('[onclick="toggleLinkAddMenu(event)"]').click();
    check('native Link to menu opens and keeps all nine destinations',document.getElementById('linkAddMenu').classList.contains('open')&&document.querySelectorAll('#linkAddMenu button').length===9);
    let deliver;
    window.fetch=()=>new Promise(resolve=>{deliver=resolve});
    const pending=realNotes(101);
    displayEmail({...ticket,ticket_id:102},[]);
    deliver(response({success:true,notes:[{id:5,note_text:'ZZ stale private note',created_datetime:'2026-01-01 09:00:00',analyst_name:'ZZ Analyst'}]}));
    await pending;
    check('old notes cannot enter a new ticket',!document.getElementById('notesContainer').textContent.includes('ZZ stale private note'));
    const pendingSame=realNotes(102);
    displayEmail({...ticket,ticket_id:102},[]);
    deliver(response({success:true,notes:[{id:6,note_text:'ZZ earlier generation',created_datetime:'2026-01-01 09:00:00',analyst_name:'ZZ Analyst'}]}));
    await pendingSame;
    check('same-ticket rerender also invalidates old responses',!document.getElementById('notesContainer').textContent.includes('ZZ earlier generation'));
    let auditDeliver,auditCalls=0;
    window.fetch=()=>{auditCalls++;return new Promise(resolve=>{auditDeliver=resolve})};
    const historyTab=document.getElementById('tw-tab-history');
    historyTab.click();
    check('History tab loads the native audit without another button',auditCalls===1&&!document.querySelector('#tw-panel-history button'));
    if(auditDeliver){
        historyTab.click();
        check('repeated History clicks share the pending request',auditCalls===1);
        auditDeliver(response({success:true,audit:[{field_name:'ZZ audit field',old_value:'Open',new_value:'Closed',created_datetime:'2026-01-01 09:00:00',analyst_name:'ZZ Analyst'}]}));
        for(let i=0;i<100&&document.getElementById('twAuditBody').getAttribute('aria-busy')==='true';i++)await new Promise(resolve=>setTimeout(resolve,20));
        check('automatically loaded audit entries render inline',document.getElementById('twAuditBody').textContent.includes('ZZ audit field')&&!document.querySelector('#tw-panel-history .tw-state'));
        TicketWorkspace.activate('conversation');historyTab.click();
        auditDeliver(response({success:false,error:'ZZ audit unavailable'}));
        for(let i=0;i<100&&document.getElementById('twAuditBody').getAttribute('aria-busy')==='true';i++)await new Promise(resolve=>setTimeout(resolve,20));
        check('audit failures remain visible',!!document.querySelector('#tw-panel-history [role=alert]'));
        historyTab.click();
        auditDeliver(response({success:true,audit:[]}));
        for(let i=0;i<100&&document.getElementById('twAuditBody').getAttribute('aria-busy')==='true';i++)await new Promise(resolve=>setTimeout(resolve,20));
        check('History tab retries failures and renders an empty history',auditCalls===3&&document.getElementById('twAuditBody').textContent.includes('No audit history')&&!document.querySelector('#tw-panel-history .tw-state'));
    }
    TicketWorkspace.activate('conversation');
    window.fetch=async()=>response({success:false,error:'ZZ failed lookup'});
    await realTasks(102);
    check('task lookup failure is visible, not an empty task list',!!document.querySelector('#tw-panel-work [role=alert]'));
    await realSla(102);
    check('SLA lookup failure is visible, not disabled SLA',!!document.querySelector('#tw-panel-time [role=alert]'));
    check('SLA lookup failure is also visible in the header',!document.getElementById('twSlaSummary').hidden&&!!document.getElementById('twSlaSummary').textContent);
    TicketWorkspace.updateSla(102,{enabled:true,response:{achieved_at:null,breached:false,percent:50,remaining_minutes:45}});
    check('header SLA reuses the native remaining-time indicator',document.getElementById('twSlaSummary').textContent.includes('45m'));
    const composer=document.createElement('textarea');composer.id='channelComposerText';document.getElementById('tw-panel-conversation').append(composer);
    currentTicketChannel='webchat';TicketWorkspace.activate('work');openReplyModal();
    check('channel reply reveals its native composer from another tab',!document.getElementById('tw-panel-conversation').hidden&&document.activeElement===composer);
    currentTicketChannel=null;composer.remove();
    window.TicketFields={render:()=>'<input id="zzCustom">'};
    await realCustom(102);
    check('custom field lookup failure remains visible',!!document.querySelector('#ticketCustomFields [role=alert]'));
    window.fetch=async()=>response({success:true,tickets:[{ticket_id:102,mode:'block',missing:[{key:'priority',label:'Priority'}]}]});
    window.showConfirm=async()=>true;
    check('native mandatory-field block reveals and focuses the existing field',!await confirmCloseWithMandatoryFields([102])&&document.activeElement?.id==='prioritySelect');
    const custom=document.createElement('input');custom.dataset.tfKey='evidence';document.getElementById('ticketCustomFields').append(custom);
    TicketWorkspace.revealMissing(102,[{key:'cf:evidence'}]);
    check('mandatory custom fields are revealed without replacing their editor',document.activeElement===custom);
    delete window.showConfirm;
    selectedEmailId=10;selectedEmailIds=new Set([10]);selectionAnchorId=10;multiSelectPaneMode='keep';
    document.getElementById('emailList').innerHTML=[10,11,12].map(id=>'<div class="email-item" data-email-id="'+id+'" data-ticket-id="'+(id+92)+'"></div>').join('');
    ticketPendingWrites++;
    await handleEmailRowClick({ctrlKey:true,shiftKey:false},11);
    await handleEmailRowClick({ctrlKey:false,shiftKey:true},12);
    check('keep-mode modifier selection remains usable during a pending write',selectedEmailIds.size===2&&selectedEmailIds.has(11)&&selectedEmailIds.has(12));
    multiSelectPaneMode='summary';await onSelectionChanged();
    check('keyboard summary cannot replace an editor with a pending save',selectedEmailIds.size===1&&selectedEmailIds.has(10)&&!!document.querySelector('.tw-grid'));
    await clearSelectionToNone();
    check('Escape keeps the current editor and selection during a pending save',selectedEmailId===10&&selectedEmailIds.has(10)&&!!document.querySelector('.tw-grid'));
    ticketPendingWrites--;
    window.fetch=async()=>response({success:false});
    const failed=await TicketWorkspace.requestMode('inbox');
    check('failed preference save retains current view',failed===false&&window.TICKETS_DETAIL_LAYOUT==='workspace'&&document.getElementById('readingPane').classList.contains('ticket-workspace'));
    const modal=document.createElement('div');modal.id='noteModal';modal.className='modal active';document.body.append(modal);
    window.confirm=()=>false;
    let writes=0;window.fetch=async()=>{writes++;return response({success:true})};
    const stayed=await TicketWorkspace.requestMode('inbox');
    check('declining discard retains draft and performs no preference write',!stayed&&modal.classList.contains('active')&&writes===0);
    modal.remove();
    const changed=await TicketWorkspace.requestMode('inbox');
    check('explicit successful choice changes only presentation',changed&&window.TICKETS_DETAIL_LAYOUT==='inbox'&&currentEmail.ticket_id===102);
})().catch(error=>check('native actions complete',false,String(error))).finally(()=>{
    document.getElementById('result').textContent=JSON.stringify(checks);
});
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html' => $html]));
