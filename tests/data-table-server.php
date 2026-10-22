<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/support/ticket-workspace-browser.php';
$script = file_get_contents(dirname(__DIR__).'/assets/js/data-table.js');
$html = '<!doctype html><html><body><input id="dtSearch"><button id="dtCsvBtn">CSV</button><span id="dtCount"></span><table id="dtTable"><thead id="dtHead"></thead><tbody id="dtBody"></tbody></table><pre id="result"></pre><script>window.t=k=>k;window.fetch=async()=>({json:async()=>({success:true,preferences:{}})});</script><script>'.$script.'</script>'.<<<'HTML'
<script>
const checks=[],check=(label,pass)=>checks.push({label,pass:!!pass});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
const rows=Array.from({length:125},(_,i)=>({id:i+1,label:'Row '+(i+1)}));
let fail=false,delayed=null,exported=null,firstQuery=null;
const config={manual:true,columns:[{key:'id',label:'ID',type:'number',defaultVisible:true,defaultOrder:0}],prefKey:'test',
 initialFilters:{id:['1','2']},
 server:{load:async q=>{firstQuery??=q;if(fail)throw Error('offline');if(q.search==='slow')await new Promise(r=>delayed=r);let all=rows.filter(r=>!q.search||q.search==='slow'||String(r.id).includes(q.search));if(q.sort.dir==='desc')all.reverse();return {rows:all.slice((q.page-1)*q.pageSize,q.page*q.pageSize),total:all.length,page:q.page,pageSize:q.pageSize,facets:{}};},exportCsv:async q=>exported=q}};
(async()=>{
 const table=createDataTable(config);
 if(!table.start){check('optional server table contract is available',false);return;}
 await table.start();check('loads one page of a globally counted result',table.getAllRows().length===50&&document.getElementById('dtCount').textContent.includes('125'));
 check('initial folder filters are included in the first request',firstQuery.filters.id.join(',')==='1,2');
 await table.setQuery({page:3});check('last page contains the remaining 25 rows',table.getViewRows().length===25&&table.getViewRows()[0].id===101);
 await table.setQuery({sort:{key:'id',dir:'desc'},page:1});check('sort applies before pagination',table.getViewRows()[0].id===125);
 const old=table.setQuery({search:'slow'});await pause(20);await table.setQuery({search:'12'});delayed();await old;
 check('late old query cannot replace the current page',table.getViewRows().length===8&&table.getViewRows()[0].id===125);
 fail=true;await table.reload();check('load failure retains rows and disables stale actions',table.getAllRows().length===8&&document.getElementById('dtTable').inert&&!!document.querySelector('.dt-server-error'));
 fail=false;await table.reload();check('retry restores a usable page',!document.getElementById('dtTable').inert&&!document.querySelector('.dt-server-error'));
 document.getElementById('dtCsvBtn').click();await pause(20);check('export receives the complete query and visible columns',exported?.search==='12'&&exported.columns.join(',')==='id');
 const legacy=createDataTable({manual:true,columns:config.columns,prefKey:'legacy',load:async()=>rows});await legacy.start();
 check('client-only tables still load their complete data',legacy.getViewRows().length===125);
})().catch(e=>check('table contract executes: '+e.message,false)).finally(()=>document.getElementById('result').textContent=JSON.stringify(checks));
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html'=>$html]));
