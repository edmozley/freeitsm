<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Real ticket renderer/layout regression; PHP + Chrome only, no database.
 * The native initial section loaders are isolated here; actions and HTTP
 * permissions are covered separately by ticket-workspace-actions.php.
 * Control: without the adapter the new layout assertion fails.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
require __DIR__ . '/support/ticket-workspace-page.php';
$html = ticketWorkspacePage();
$html .= <<<'HTML'
<script>
document.addEventListener=originalAdd;
const checks=[];
const check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
try {
    // Rendering is real. Only asynchronous reads/presence are replaced; they
    // must not contact any application or install during a layout-only test.
    for(const name of ['loadAiSummary','loadTicketChecklists','loadCorrespondenceThread','loadNotes',
        'loadTicketAttachments','loadCmdbObjects','loadTicketDomains','loadTicketProjects','loadTicketAssets',
        'loadTicketTasks','loadTimeEntries','loadSlaState','loadTicketCustomFields','startPresence']) window[name]=()=>{};
    departments=[{id:1,name:'ZZ Service desk'}];
    analysts=[{id:1,full_name:'ZZ Analyst'}];
    ticketStatuses=[{id:1,name:'Open'}];
    ticketPriorities=[{id:1,name:'Normal'}];
    ticketTeams=[{id:1,name:'ZZ Operations'}];
    ticketClassification={category:true,closure_category:true,resolution_code:true};
    const ticket={id:10,ticket_id:101,ticket_number:'ZZ-101',subject:'ZZ Complete ticket',
        status:'Open',department_id:1,priority_id:1,owner_id:1,assigned_team_id:1,
        from_address:'requester@example.invalid',from_name:'ZZ Requester',to_recipients:'desk@example.invalid',
        received_datetime:'2026-01-01 09:00:00',body_content:'ZZ original message',body_type:'text',
        sensitivity:'confidential',linked_tickets:{},problems:[],changes:[]};
    window.TICKETS_DETAIL_LAYOUT='inbox';
    displayEmail(ticket,[]);
    const pane=document.getElementById('readingPane');
    const ids=[...pane.querySelectorAll('[id]')].map(n=>n.id);
    const actions=[...pane.querySelectorAll('[onclick],[onchange]')].map(n=>n.getAttribute('onclick')||n.getAttribute('onchange'));
    check('legacy layout remains the default structure',!pane.classList.contains('ticket-workspace'));
    window.TICKETS_DETAIL_LAYOUT='workspace';
    displayEmail(ticket,[]);
    check('workspace arranges the real renderer',pane.classList.contains('ticket-workspace')&&pane.querySelector('[role=tablist]'));
    check('every native control survives the layout',ids.every(id=>document.getElementById(id)),ids.length);
    const after=[...pane.querySelectorAll('[onclick],[onchange]')].map(n=>n.getAttribute('onclick')||n.getAttribute('onchange'));
    check('every native action retains its handler',actions.every(a=>after.includes(a)),actions.length);
    const allIds=[...document.querySelectorAll('[id]')].map(n=>n.id);
    check('no duplicate IDs',allIds.length===new Set(allIds).size);
    if(window.TicketWorkspace){
        check('Link to remains outside hidden tab panels',!document.getElementById('linkAddMenu').closest('[role=tabpanel]'));
        const field=document.getElementById('ownerSelect');
        const thread=document.getElementById('threadContainer');
        const draft=document.createElement('textarea');draft.value='ZZ unsaved';thread.append(draft);
        TicketWorkspace.activate('work');TicketWorkspace.activate('conversation');
        check('tabs preserve controls and unsaved input',document.getElementById('ownerSelect')===field&&draft.isConnected&&draft.value==='ZZ unsaved');
        const unknown=document.createElement('section');unknown.id='zzFutureSection';unknown.textContent='ZZ future content';
        pane.append(unknown);
        TicketWorkspace.prepare(pane,{ticketId:101,mode:'workspace'});
        check('unmapped content remains reachable',unknown.isConnected&&getComputedStyle(unknown).display!=='none');
        TicketWorkspace.activate('time');
        check('time and SLA share their own panel',document.getElementById('slaContainer').closest('[role=tabpanel]')===document.getElementById('timeEntriesContainer').closest('[role=tabpanel]'));
        check('classification and confidentiality summary remain in the header',!!document.getElementById('summaryConfidential')?.closest('.tw-header')&&!!document.getElementById('summaryStatus')?.closest('.tw-header'));
        const tabs=[...pane.querySelectorAll('[role=tab]')];
        tabs[0].focus();tabs[0].dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
        check('tabs support arrow-key navigation',document.activeElement===tabs[1]);
        for (const [name,extra] of [['closed',{status:'Closed',status_is_closed:1}],['trash',{deleted_datetime:'2026-01-02 10:00:00'}],['snoozed',{snoozed_until:'2099-01-01 10:00:00',snooze_reason:'ZZ Waiting'}]]) {
            window.TICKET_AI={summary_enabled:true,read_enabled:true};
            window.TICKETS_DETAIL_LAYOUT='inbox';displayEmail({...ticket,...extra},[]);
            const controls=[...pane.querySelectorAll('[onclick],[onchange]')].map(n=>n.getAttribute('onclick')||n.getAttribute('onchange'));
            window.TICKETS_DETAIL_LAYOUT='workspace';displayEmail({...ticket,...extra},[]);
            const moved=[...pane.querySelectorAll('[onclick],[onchange]')].map(n=>n.getAttribute('onclick')||n.getAttribute('onchange'));
            check(name+' keeps all conditional native actions',controls.every(a=>moved.includes(a)),controls.length);
            if(name==='trash')check('trashed workspace retains the native disabled-action wrapper',getComputedStyle(pane.querySelector('.tw-grid')).pointerEvents==='none');
        }

    }
} catch(error) { check('renderer completes without errors',false,String(error)); }
document.getElementById('result').textContent=JSON.stringify(checks);
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html' => $html]));
