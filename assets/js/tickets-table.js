/** Ticket-specific adapter. All row actions still enter inbox.js's native handlers. */
window.TicketTable = (() => {
    'use strict';
    let table=null, folderKey='', contextKey='', switching=false;
    const text=key=>t('tickets.queue.'+key);
    const isActive=()=>window.TICKETS_LIST_LAYOUT==='table';
    const context=()=>({view:['trash','snoozed'].includes(currentFilter.type)?currentFilter.type:'active',
        mine:viewFilterMineActive(),includeClosed:!viewFilter.hide_closed,searchContent:!!document.getElementById('ticketTableContent')?.checked});

    /** Map the native folder selection to queue facets. */
    function folderFilters() {
        const f=currentFilter, result={};
        if(f.type.startsWith('unassigned'))result[folderGrouping==='analyst'?'assignee_id':folderGrouping==='team'?'team_id':'department_id']=['unassigned'];
        if(f.type==='department'||f.type==='dept_status')result.department_id=[String(f.id??f.dept_id)];
        if(f.type==='team'||f.type==='team_status')result.team_id=[String(f.id??f.team_id)];
        if(f.type==='analyst'||f.type==='analyst_status')result.assignee_id=[String(f.id??f.analyst_id)];
        if(f.status){const status=ticketStatuses.find(s=>s.name===f.status);if(status)result.status_id=[String(status.id)];}
        return result;
    }

    /** Serialize only the queue contract; the server derives company access. */
    function parameters(q) {
        const sort={priority_id:'priority',status_id:'status',assignee_id:'assigned_analyst_id'}[q.sort.key] || q.sort.key;
        return new URLSearchParams({page:q.page,page_size:q.pageSize,sort,dir:q.sort.dir,q:q.search,filters:JSON.stringify(q.filters),
            view:q.context.view,mine:q.context.mine?'1':'0',include_closed:q.context.includeClosed?'1':'0',search_content:q.context.searchContent?'1':'0'});
    }

    /** A page change must never keep hidden tickets selected for a bulk write. */
    function clearSelection() {
        if(!isActive())return;
        selectedEmailIds.clear();selectionAnchorId=null;selectionFocusId=null;
        hideSelectionBar();hideKeepModeWarning();renderSelectionUi();
    }

    /** Adapt shared table presentation to the existing inbox action handlers. */
    function setup() {
        const columns=[
            ['ticket_number',null,true,true],['subject',null,true,false],['status_id','status',true,true],['priority_id','priority',true,true],
            ['assignee_id','assignee_name',true,true],['created_datetime',null,true,true],
            ['department_id','department_name',false,false],['team_id','team_name',false,false],['ticket_type_id','ticket_type_name',false,false],
            ['work_start_datetime',null,false,true],['work_end_datetime',null,false,true],['snoozed_until',null,false,true],['company_name',null,false,false]
        ].map(([key,field,visible,sortable],index)=>({key,label:text('columns.'+key),defaultVisible:visible,defaultOrder:index,sortable,
            filterable:['status_id','priority_id','assignee_id','department_id','team_id','ticket_type_id'].includes(key),
            display:row=>{
                const value=row[field||key];
                if(!value)return '';
                if(['created_datetime','work_start_datetime','work_end_datetime','snoozed_until'].includes(key))return fmtDateTime(parseUTCDate(value));
                return value;
            }}));
        table=createDataTable({manual:true,columns,initialFilters:folderFilters(),prefKey:'tickets_table',viewsKey:'tickets',prefApi:sharedApiBase()+'system/',
            defaultSort:{key:'created_datetime',dir:'desc'},rowId:r=>r.inbox_id,noun:text('ticket'),getContext:context,
            applyContext:ctx=>{
                currentFilter={type:['trash','snoozed'].includes(ctx.view)?ctx.view:'all'};
                viewFilter={mine:!!ctx.mine,hide_closed:ctx.includeClosed===false};
                document.getElementById('ticketTableContent').checked=!!ctx.searchContent;
                folderKey=JSON.stringify([currentFilter,folderGrouping]);contextKey=JSON.stringify(context());
                document.getElementById('emailListTitle').textContent=t('tickets.list.'+(currentFilter.type==='all'?'all_tickets':currentFilter.type));
                updateActiveFolderClasses();
                updateViewFilterUI();loadFolderCounts();
            },
            onLoading:clearSelection,onLoadError:clearSelection,
            onRowClick:async(row,event)=>{
                handleEmailRowClick(event,Number(row.inbox_id));
            },
            onRowContextMenu:(row,event)=>openTicketContextMenu(event,Number(row.ticket_id),row.ticket_number),
            onRowsRendered:rows=>{
                if(!isActive())return;
                emails=rows;
                document.querySelectorAll('#dtBody .dt-row').forEach(node=>{
                    const row=rows.find(r=>String(r.inbox_id)===node.dataset.id);
                    node.classList.add('email-item','ticket-queue-row');node.dataset.emailId=row.inbox_id;node.dataset.ticketId=row.ticket_id;
                    node.dataset.ticketNumber=row.ticket_number;node.draggable=true;node.tabIndex=0;
                    node.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();node.click();}});
                    const slot=document.createElement('span');slot.dataset.slaSlot=row.ticket_id;node.firstElementChild?.append(slot);
                });
                attachEmailDragHandlers();renderSelectionUi();loadInboxSlaIndicators();
            },
            server:{
                load:async q=>{
                    // Changing a column filter can leave a folder's original
                    // filter. Reflect that in the tree instead of mislabelling it.
                    const narrowed=folderFilters();
                    if(Object.entries(narrowed).some(([key,value])=>JSON.stringify(q.filters[key])!==JSON.stringify(value))){
                        currentFilter={type:'all'};folderKey=JSON.stringify([currentFilter,folderGrouping]);
                        document.getElementById('emailListTitle').textContent=t('tickets.list.all_tickets');updateActiveFolderClasses();
                    }
                    const response=await fetch(API_BASE+'table.php?'+parameters(q));const data=await response.json();
                    if(!response.ok||!data.success)throw Error('Ticket list unavailable');return data;
                },
                exportCsv:async(q,signal)=>{
                    const params=parameters(q);params.set('columns',q.columns.join(','));
                    const response=await fetch(API_BASE+'table_export.php?'+params,{signal});
                    if(!response.ok||!response.headers.get('Content-Type')?.includes('text/csv'))throw Error('Export unavailable');
                    const blob=await response.blob(),url=URL.createObjectURL(blob),anchor=document.createElement('a');
                    anchor.href=url;anchor.download='tickets.csv';anchor.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
                }
            }});
        document.getElementById('ticketTableContent').addEventListener('change',()=>table.setQuery({page:1}));
    }

    /** Reuse the native refresh loop and retain filters unless the folder changes. */
    async function reload() {
        if(!isActive())return;
        const key=JSON.stringify([currentFilter,folderGrouping]), nextContext=JSON.stringify(context());
        if(!table){setup();folderKey=key;contextKey=nextContext;await table.start();return;}
        if(folderKey!==key){folderKey=key;contextKey=nextContext;await table.setQuery({filters:folderFilters()});}
        else if(contextKey!==nextContext){contextKey=nextContext;await table.setQuery({page:1});}
        else await table.reload();
    }

    /** Persist the list preference only after existing drafts allow navigation. */
    async function requestMode(mode) {
        const previous=window.TICKETS_LIST_LAYOUT||'messages',select=document.getElementById('ticketListSelect');
        if(switching||!['messages','table'].includes(mode))return;
        switching=true;select.disabled=true;
        try {
            if(!await TicketWorkspace.canLeave())return;
            const response=await fetch(sharedApiBase()+'system/set_user_preference.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({key:'tickets_list_layout',value:mode})});
            const result=await response.json();if(!response.ok||!result.success)throw Error('Preference failed');
            loadEmailsToken++;clearSelection();window.TICKETS_LIST_LAYOUT=mode;paint();await loadEmails();
        }catch(error){showToast(text('save_failed'),'error');}
        finally{switching=false;select.disabled=false;select.value=window.TICKETS_LIST_LAYOUT||previous;}
    }

    /** Keep exactly one visible row source for native selection and drag handlers. */
    function paint(){document.body.classList.toggle('ticket-table-mode',isActive());document.getElementById('emailList').hidden=isActive();document.getElementById('ticketTableContainer').hidden=!isActive();}
    document.addEventListener('DOMContentLoaded',paint);
    return {reload,requestMode,isActive,getSelectedTicketIds:()=>selectedTicketIds()};
})();
