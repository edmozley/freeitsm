<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The reading pane's button row does not jump when you move from one ticket to the
 * next (Ed, 17 Nov 2026: "it feels kind of jarring").
 *
 *   php tests/ticket-toolbar-steady.php
 *
 * Checklist, Watch and Forms arrive after the pane is drawn, each from its own
 * script once its own request answers. They used to be inserted at the front of the
 * toolbar on arrival, so every switch rebuilt the row without them and the other
 * buttons jumped left and then right two or three times. inbox.js now keeps them in
 * place, pending, while the new ticket loads (keepLateToolbarButtons).
 *
 * The REAL checklists/ticket_view.js, ticket-watchers.js and ticket-forms.js run here
 * against a stubbed fetch whose answers come back out of order, as they do on a real
 * server. Every animation frame of a switch is checked: Add note must never move.
 * Then: each button ends up ready (not pending), Checklist opens the NEW ticket,
 * and a ticket with no forms loses its Forms button. Chrome, no database.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
require __DIR__ . '/support/ticket-workspace-page.php';
$root = dirname(__DIR__);
$extra = '';
foreach (['checklists/ticket_view.js', 'assets/js/ticket-watchers.js', 'assets/js/ticket-forms.js'] as $f) {
    $extra .= '<script>' . str_replace('</script', '<\/script', (string)file_get_contents("$root/$f")) . '</script>';
}
$html = ticketWorkspacePage() . $extra . <<<'HTML'
<script>
document.addEventListener=originalAdd;
const checks=[];
const check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
// Everything else a ticket loads is out of scope here.
for(const name of ['loadAiSummary','loadCorrespondenceThread','loadNotes','loadTicketAttachments','loadCmdbObjects',
    'loadTicketDomains','loadTicketProjects','loadTicketAssets','loadTicketTasks','loadTimeEntries','loadSlaState',
    'loadTicketCustomFields','startPresence','liveWatchOpenTicket']) window[name]=()=>{};
window.TICKETS_MODULE_ON=Object.assign(window.TICKETS_MODULE_ON||{},{checklists:true});
// The three answers, deliberately out of order (Forms first, Checklist last).
const delays={checklists:90,watchers:40,forms:15};
window.fetch=(url)=>{
    const u=String(url), id=Number((u.match(/ticket_id=(\d+)/)||[])[1]||0);
    let kind=null, body=null;
    if(u.includes('ticket_checklists.php')){kind='checklists';body={success:true,checklists:[{total_items:2,completed_items:id===104?2:1}],empty_closure_mode:'off'};}
    else if(u.includes('watchers.php')){kind='watchers';body={success:true,watchers:[{id:1}],watching:id===104,can_add:true,ready:true};}
    else if(u.includes('forms.php')){kind='forms';body={success:true,enabled:id!==103,forms:id===103?[]:[{submission_id:1},{}],available:[],can_write:true};}
    else body={success:true};
    return new Promise(r=>setTimeout(()=>r({ok:true,status:200,json:async()=>body,text:async()=>JSON.stringify(body)}),kind?delays[kind]:5));
};
let opened=null; window.openChecklistModal=id=>{opened=id;};
const email=id=>({ticket_id:id,ticket_number:'ZZ-'+id,subject:'ZZ ticket '+id,body_content:'ZZ',body_type:'text',received_datetime:'2026-01-01 09:00:00'});
const toolbar=()=>document.querySelector('.action-toolbar');
const noteX=()=>{const b=[...(toolbar()?.querySelectorAll('button')||[])].find(x=>x.getAttribute('onclick')==='openNoteModal()');return b?Math.round(b.getBoundingClientRect().left):null;};
const ids=()=>[...toolbar().children].map(e=>e.id).filter(Boolean);
/** Switch to a ticket and watch every frame for 400ms; returns the distinct Add note positions seen. */
async function switchTo(id){
    const seen=new Set([noteX()]);
    displayEmail(email(id),[]);
    const until=performance.now()+400;
    await new Promise(done=>{const tick=()=>{seen.add(noteX());performance.now()<until?requestAnimationFrame(tick):done();};requestAnimationFrame(tick);});
    return [...seen];
}
(async()=>{
    displayEmail(email(101),[]);
    await pause(400);
    check('first ticket: Checklist, Watch and Forms arrive, in that order, before Add note',
        ids().slice(0,3).join(',')==='btnSopChecklists,btnTicketWatch,btnTicketForms',ids().join(','));

    let xs=await switchTo(102);
    check('switching ticket: Add note never moves on any frame (no jump)',xs.length===1,'positions seen: '+xs.join(', '));
    check('...the three buttons are still first, in the same order',ids().slice(0,3).join(',')==='btnSopChecklists,btnTicketWatch,btnTicketForms',ids().join(','));
    check('...and none is left pending',!toolbar().querySelector('.action-btn-pending'));
    document.getElementById('btnSopChecklists').click();
    check('Checklist opens the ticket now showing, not the one it was created for',opened===102,'opened '+opened);

    // While the new ticket loads, the carried buttons cannot be clicked.
    displayEmail(email(101),[]);
    const pending=toolbar().querySelectorAll('.action-btn-pending').length;
    check('while the next ticket loads, the carried buttons are pending and disabled',pending===3 && document.getElementById('btnSopChecklists').disabled,pending+' pending');
    await pause(400);

    // A ticket whose buttons really read differently (2/2, Watching): they may move once, never bounce.
    xs=await switchTo(104);
    check('different labels: Add note moves at most once (no jumping back and forth)',xs.length<=2,'positions seen: '+xs.join(', '));
    check('...and the buttons show the new ticket: Checklist (2/2), watching',/\(2\/2\)/.test(document.getElementById('btnSopChecklists').textContent)
        && document.getElementById('btnTicketWatch').getAttribute('aria-pressed')==='true',document.getElementById('btnSopChecklists').textContent.trim());

    xs=await switchTo(103);
    check('a ticket with no forms: its Forms button goes, the rest stay put',!document.getElementById('btnTicketForms')
        && ids().slice(0,2).join(',')==='btnSopChecklists,btnTicketWatch',ids().join(','));
    check('...and nothing is left pending',!toolbar().querySelector('.action-btn-pending'));
})().catch(error=>check('toolbar test runs',false,String(error))).finally(()=>document.getElementById('result').textContent=JSON.stringify(checks));
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html' => $html]));
