<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/ai_settings_panel.php';
$current_page = 'settings';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Problem Management Settings</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <style>
        .pms-page { max-width: 960px; margin: 0 auto; padding: 24px 24px 60px; }
        .pms-page h1 { font-size: 1.5rem; margin: 0 0 18px; }
        table.pms { width: 100%; border-collapse: collapse; }
        table.pms th, table.pms td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        table.pms th { font-size: 12px; text-transform: uppercase; color: #6b7280; }
        .pms-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: middle; margin-right: 6px; }
        .pms-btn { padding: 7px 14px; border: 1px solid #cfd8dc; border-radius: 6px; background: #fff; cursor: pointer; font-weight: 600; }
        .pms-btn-primary { background: #6a1b9a; color: #fff; border-color: #6a1b9a; }
        .pms-link { color: #6a1b9a; cursor: pointer; }
        .pms-del { color: #c62828; cursor: pointer; }
        .pms-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); align-items: center; justify-content: center; z-index: 1000; }
        .pms-modal.active { display: flex; }
        .pms-modal-content { background: #fff; border-radius: 10px; width: 420px; max-width: 92%; padding: 20px; }
        .pms-modal-content .row { margin-bottom: 12px; }
        .pms-modal-content label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .pms-modal-content input[type=text], .pms-modal-content input[type=number] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #cfd8dc; border-radius: 6px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="pms-page">
        <h1>Problem Management settings</h1>

        <div class="tabs">
            <button class="tab active" data-tab="statuses" onclick="pmsTab('statuses')">Statuses</button>
            <button class="tab" data-tab="priorities" onclick="pmsTab('priorities')">Priorities</button>
            <button class="tab" data-tab="ai" onclick="pmsTab('ai')">Problem AI</button>
        </div>

        <div class="tab-content active" id="tab-statuses">
            <div class="section-header"><h2>Statuses</h2><button class="add-btn pms-btn pms-btn-primary" onclick="pmsOpen('status')">Add status</button></div>
            <table class="pms"><thead><tr><th>Name</th><th>Closed?</th><th>Default</th><th>Active</th><th></th></tr></thead>
            <tbody id="pmsStatusRows"><tr><td colspan="5">Loading…</td></tr></tbody></table>
        </div>

        <div class="tab-content" id="tab-priorities">
            <div class="section-header"><h2>Priorities</h2><button class="add-btn pms-btn pms-btn-primary" onclick="pmsOpen('priority')">Add priority</button></div>
            <table class="pms"><thead><tr><th>Name</th><th>Default</th><th>Active</th><th></th></tr></thead>
            <tbody id="pmsPriorityRows"><tr><td colspan="4">Loading…</td></tr></tbody></table>
        </div>

        <div class="tab-content" id="tab-ai">
            <h2 style="margin-top:0;">Problem AI</h2>
            <p style="color:#555;font-size:14px;">Used by “Draft root cause” and “Detect problems”. Bring your own provider and key.</p>
            <?php renderAiSettingsPanel('problem_ai'); ?>
        </div>
    </div>

    <div class="pms-modal" id="pmsModal">
        <div class="pms-modal-content">
            <h2 id="pmsModalTitle" style="margin-top:0;color:#6a1b9a;">Add</h2>
            <input type="hidden" id="pmsId"><input type="hidden" id="pmsKind">
            <div class="row"><label>Name</label><input type="text" id="pmsName"></div>
            <div class="row"><label>Colour</label><input type="text" id="pmsColour" placeholder="#6a1b9a"></div>
            <div class="row" id="pmsClosedRow"><label><input type="checkbox" id="pmsClosed"> Counts as closed</label></div>
            <div class="row"><label><input type="checkbox" id="pmsDefault"> Default</label></div>
            <div class="row"><label><input type="checkbox" id="pmsActive" checked> Active</label></div>
            <div class="row"><label>Display order</label><input type="number" id="pmsOrder" value="0"></div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button class="pms-btn" onclick="pmsClose()">Cancel</button>
                <button class="pms-btn pms-btn-primary" onclick="pmsSave()">Save</button>
            </div>
        </div>
    </div>

    <script>
    const PMS_API = '../../api/problem-management/';
    function pmsEsc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    function pmsTab(name){
        document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active', t.dataset.tab===name));
        document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
        document.getElementById('tab-'+name).classList.add('active');
    }
    let pmsStatuses=[], pmsPriorities=[];
    async function pmsLoad(){
        const s=await fetch(PMS_API+'get_problem_statuses.php?manage=1').then(r=>r.json());
        pmsStatuses = s.success? s.statuses:[];
        document.getElementById('pmsStatusRows').innerHTML = pmsStatuses.map(x=>`<tr>
            <td><span class="pms-swatch" style="background:${pmsEsc(x.colour||'#ccc')}"></span>${pmsEsc(x.name)}</td>
            <td>${x.is_closed==1?'Yes':'—'}</td><td>${x.is_default==1?'★':''}</td><td>${x.is_active==1?'Yes':'No'}</td>
            <td style="text-align:right;"><span class="pms-link" onclick='pmsEdit("status",${x.id})'>Edit</span> &nbsp; <span class="pms-del" onclick="pmsDel('status',${x.id})">Delete</span></td></tr>`).join('') || '<tr><td colspan="5">None</td></tr>';
        const p=await fetch(PMS_API+'get_problem_priorities.php?manage=1').then(r=>r.json());
        pmsPriorities = p.success? p.priorities:[];
        document.getElementById('pmsPriorityRows').innerHTML = pmsPriorities.map(x=>`<tr>
            <td><span class="pms-swatch" style="background:${pmsEsc(x.colour||'#ccc')}"></span>${pmsEsc(x.name)}</td>
            <td>${x.is_default==1?'★':''}</td><td>${x.is_active==1?'Yes':'No'}</td>
            <td style="text-align:right;"><span class="pms-link" onclick='pmsEdit("priority",${x.id})'>Edit</span> &nbsp; <span class="pms-del" onclick="pmsDel('priority',${x.id})">Delete</span></td></tr>`).join('') || '<tr><td colspan="4">None</td></tr>';
    }
    function pmsOpen(kind, row){
        document.getElementById('pmsKind').value=kind;
        document.getElementById('pmsId').value=row?row.id:'';
        document.getElementById('pmsModalTitle').textContent=(row?'Edit ':'Add ')+kind;
        document.getElementById('pmsName').value=row?row.name:'';
        document.getElementById('pmsColour').value=row?(row.colour||''):'';
        document.getElementById('pmsClosed').checked=row?row.is_closed==1:false;
        document.getElementById('pmsDefault').checked=row?row.is_default==1:false;
        document.getElementById('pmsActive').checked=row?row.is_active==1:true;
        document.getElementById('pmsOrder').value=row?row.display_order:0;
        document.getElementById('pmsClosedRow').style.display = kind==='status'?'':'none';
        document.getElementById('pmsModal').classList.add('active');
    }
    function pmsEdit(kind,id){ const arr=kind==='status'?pmsStatuses:pmsPriorities; pmsOpen(kind, arr.find(x=>x.id==id)); }
    function pmsClose(){ document.getElementById('pmsModal').classList.remove('active'); }
    async function pmsSave(){
        const kind=document.getElementById('pmsKind').value;
        const payload={ id:document.getElementById('pmsId').value||0, name:document.getElementById('pmsName').value.trim(),
            colour:document.getElementById('pmsColour').value.trim(), is_default:document.getElementById('pmsDefault').checked?1:0,
            is_active:document.getElementById('pmsActive').checked?1:0, display_order:parseInt(document.getElementById('pmsOrder').value||'0',10) };
        if(kind==='status') payload.is_closed=document.getElementById('pmsClosed').checked?1:0;
        if(!payload.name){ alert('Name is required'); return; }
        const url=kind==='status'?'save_problem_status.php':'save_problem_priority.php';
        const r=await fetch(PMS_API+url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json());
        if(r.success){ pmsClose(); pmsLoad(); } else alert(r.error||'Save failed');
    }
    async function pmsDel(kind,id){
        if(!confirm('Delete this '+kind+'?')) return;
        const url=kind==='status'?'delete_problem_status.php':'delete_problem_priority.php';
        const r=await fetch(PMS_API+url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})}).then(r=>r.json());
        if(r.success) pmsLoad(); else alert(r.error||'Delete failed');
    }
    pmsLoad();
    </script>
</body>
</html>
