<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/ai_settings_panel.php';
require_once __DIR__ . '/../../includes/theme.php';
$current_page = 'settings';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Problem Management Settings</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=10">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=37">
    <style>
        /* Pin the shared accent to the module red so the tabs, Add buttons,
           toggles, modal primary button and focus rings read on-brand. */
        body { --accent: var(--pm-accent, #dc2626); --accent-hover: var(--pm-accent-hover, #b91c1c); }
        /* Same shell pattern as tickets/settings: header pinned, .container scrolls,
           full width (no max-width cap). */
        .settings-shell { display: flex; flex-direction: column; height: 100vh; }
        /* margin:0 is essential: inbox.css gives .container `margin:30px auto`, and auto
           left/right margins on a flex item suppress the default stretch — so the
           container would shrink to content width and centre (only obvious with few tabs). */
        .container { flex: 1 1 auto; min-height: 0; overflow-y: auto; max-width: none; width: 100%; margin: 0; padding: 24px 32px 40px; box-sizing: border-box; }
        .container > h1 { font-size: 1.5rem; margin: 0 0 18px; }
        .tab-content > p { margin-bottom: 14px; }
        .pms-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: middle; margin-right: 6px; }
        .tab-content .table-action-btn svg { width: 15px; height: 15px; }
    </style>
</head>
<body>
    <div class="settings-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="container">
        <h1>Problem Management settings</h1>

        <div class="tabs">
            <button class="tab active" data-tab="statuses" onclick="pmsTab('statuses')">Statuses</button>
            <button class="tab" data-tab="priorities" onclick="pmsTab('priorities')">Priorities</button>
            <button class="tab" data-tab="ai" onclick="pmsTab('ai')">Problem AI</button>
        </div>

        <div class="tab-content active" id="tab-statuses">
            <div class="section-header"><h2>Statuses</h2><button class="add-btn" onclick="pmsOpen('status')">Add</button></div>
            <table><thead><tr><th>Name</th><th>Closed?</th><th>Default</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody id="pmsStatusRows"><tr><td colspan="5">Loading…</td></tr></tbody></table>
        </div>

        <div class="tab-content" id="tab-priorities">
            <div class="section-header"><h2>Priorities</h2><button class="add-btn" onclick="pmsOpen('priority')">Add</button></div>
            <table><thead><tr><th>Name</th><th>Default</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody id="pmsPriorityRows"><tr><td colspan="4">Loading…</td></tr></tbody></table>
        </div>

        <div class="tab-content" id="tab-ai">
            <h2 style="margin-top:0;">Problem AI</h2>
            <p style="color:var(--text-muted, #555);">Used by “Draft root cause” and “Detect problems”. Bring your own provider and key.</p>
            <?php renderAiSettingsPanel('problem_ai'); ?>
        </div>
    </div>
    </div><!-- /.settings-shell -->

    <!-- Add/edit modal (shared .modal primitives) -->
    <div class="modal" id="pmsModal">
        <div class="modal-content" style="max-width: 440px;">
            <div class="modal-header" id="pmsModalTitle">Add</div>
            <div class="modal-body">
                <input type="hidden" id="pmsId"><input type="hidden" id="pmsKind">
                <div class="form-group"><label>Name *</label><input type="text" id="pmsName"></div>
                <div class="form-group"><label>Colour</label><input type="text" id="pmsColour" placeholder="#6a1b9a"></div>
                <div class="form-group" id="pmsClosedRow"><label><input type="checkbox" id="pmsClosed"> Counts as closed</label></div>
                <div class="form-group"><label><input type="checkbox" id="pmsDefault"> Default</label></div>
                <div class="form-group"><label><input type="checkbox" id="pmsActive" checked> Active</label></div>
                <div class="form-group"><label>Display order</label><input type="number" id="pmsOrder" value="0"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="pmsClose()">Cancel</button>
                <button class="btn btn-primary" onclick="pmsSave()">Save</button>
            </div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>assets/js/toast.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/confirm.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/ai-settings.js"></script>
    <script>
    const PMS_API = '../../api/problem-management/';
    function pmsEsc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
    const PMS_EDIT_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    const PMS_DEL_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
    function pmsActions(kind, id){
        return `<button class="table-action-btn" title="Edit" onclick='pmsEdit("${kind}",${id})'>${PMS_EDIT_SVG}</button>
                <button class="table-action-btn delete" title="Delete" onclick="pmsDel('${kind}',${id})">${PMS_DEL_SVG}</button>`;
    }
    function pmsBadge(active){ return active==1 ? '<span class="status-badge status-active">Active</span>' : '<span class="status-badge status-inactive">Inactive</span>'; }
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
            <td>${x.is_closed==1?'Yes':'—'}</td><td>${x.is_default==1?'★':''}</td><td>${pmsBadge(x.is_active)}</td>
            <td>${pmsActions('status',x.id)}</td></tr>`).join('') || '<tr><td colspan="5">None yet.</td></tr>';
        const p=await fetch(PMS_API+'get_problem_priorities.php?manage=1').then(r=>r.json());
        pmsPriorities = p.success? p.priorities:[];
        document.getElementById('pmsPriorityRows').innerHTML = pmsPriorities.map(x=>`<tr>
            <td><span class="pms-swatch" style="background:${pmsEsc(x.colour||'#ccc')}"></span>${pmsEsc(x.name)}</td>
            <td>${x.is_default==1?'★':''}</td><td>${pmsBadge(x.is_active)}</td>
            <td>${pmsActions('priority',x.id)}</td></tr>`).join('') || '<tr><td colspan="4">None yet.</td></tr>';
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
        if(!payload.name){ showToast('Name is required','error'); return; }
        const url=kind==='status'?'save_problem_status.php':'save_problem_priority.php';
        const r=await fetch(PMS_API+url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>r.json());
        if(r.success){ pmsClose(); showToast(kind.charAt(0).toUpperCase()+kind.slice(1)+' saved','success'); pmsLoad(); } else showToast(r.error||'Save failed','error');
    }
    async function pmsDel(kind,id){
        const ok = await showConfirm({ title: 'Delete '+kind+'?', message: 'This cannot be undone.', okLabel: 'Delete', okClass: 'danger' });
        if(!ok) return;
        const url=kind==='status'?'delete_problem_status.php':'delete_problem_priority.php';
        const r=await fetch(PMS_API+url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})}).then(r=>r.json());
        if(r.success){ showToast(kind.charAt(0).toUpperCase()+kind.slice(1)+' deleted','success'); pmsLoad(); } else showToast(r.error||'Delete failed','error');
    }
    pmsLoad();
    </script>
</body>
</html>
