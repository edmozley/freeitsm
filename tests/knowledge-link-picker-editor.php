<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Exercise the shipped TinyMCE link dialog and its article browser in Chrome. */
require __DIR__.'/support/ticket-workspace-browser.php';

$source = file_get_contents(dirname(__DIR__).'/assets/js/knowledge.js');
$from = strpos($source, 'function initTinyMCE() {');
$to = strpos($source, '/** Just the editor', $from);
if ($from === false || $to === false) throw new RuntimeException('Knowledge editor configuration not found.');
$pickerCode = substr($source, $from, $to - $from);

$html = <<<'HTML'
<!doctype html><html data-theme="light" data-theme-mode="light"><head>
<script src="../assets/js/tinymce/tinymce.min.js"></script>
</head><body>
<textarea id="articleBody">Selected text</textarea>
<pre id="result">RUNNING</pre>
<script>
const API_BASE='api/';
let articleEditor=null,kbFolders=[];
function folderPath(){return [];}
function escapeHtml(text){const div=document.createElement('div');div.textContent=text;return div.innerHTML;}
window.t=(key,values)=>key.replace('{count}',values?.count??'');
const requests=[];
window.fetch=async input=>{
  const url=new URL(input,location.href);requests.push(url);
  const articles=[{id:22,title:'ZZ First article',folder_id:null},
    {id:23,title:'ZZ <img> Internal article',folder_id:null}];
  const q=(url.searchParams.get('search')||'').toLowerCase();
  return {ok:true,json:async()=>({success:true,articles:articles.filter(a=>a.title.toLowerCase().includes(q))})};
};
</script><script>/*PICKER_CODE*/</script><script>
const checks=[],check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
const waitFor=async predicate=>{for(let i=0;i<80;i++){if(predicate())return true;await new Promise(r=>setTimeout(r,50));}return false;};
(async()=>{
  initTinyMCE();
  const ready=await waitFor(()=>tinymce.activeEditor?.initialized);
  check('bundled TinyMCE starts the Knowledge editor',ready);
  if(!ready)return;
  tinymce.activeEditor.execCommand('mceLink');
  const opened=await waitFor(()=>!!document.querySelector('.tox-dialog'));
  check('TinyMCE opens its real Link dialog',opened);
  if(!opened)return;
  const dialog=document.querySelector('.tox-dialog');
  const buttons=Array.from(dialog.querySelectorAll('button'));
  const browse=buttons.find(button=>(button.title+' '+button.getAttribute('aria-label')).toLowerCase().includes('browse'));
  check('Link URL has the article browse button',!!browse,buttons.map(b=>({title:b.title,aria:b.getAttribute('aria-label')})));
  if(!browse)return;
  browse.click();
  const found=await waitFor(()=>document.querySelectorAll('.tox-dialog').length===2
    &&document.querySelectorAll('.tox-dialog')[1].textContent.includes('ZZ First article'));
  const picker=document.querySelectorAll('.tox-dialog')[1];
  check('article browser opens as a searchable nested dialog',found&&picker.contains(document.activeElement),
    {dialogs:document.querySelectorAll('.tox-dialog').length,focus:document.activeElement?.outerHTML.slice(0,180),text:picker?.textContent.slice(0,260)});
  check('search is capped for this installation',requests[0]?.searchParams.get('limit')==='40'
    &&requests[0]?.pathname.endsWith('/knowledge/api/knowledge_articles.php'));
  if(!found)return;
  check('article titles render safely',!picker.querySelector('img')&&picker.textContent.includes('ZZ <img> Internal article'));
  const search=picker.querySelector('input[data-mce-name="search"]');
  search.value='Internal';search.dispatchEvent(new Event('input',{bubbles:true}));
  check('search filters the results',await waitFor(()=>picker.textContent.includes('ZZ <img> Internal article')
    &&!picker.textContent.includes('ZZ First article')),
    {text:picker.textContent.slice(0,260),requests:requests.length});
  check('tree exposes an article choice',!!Array.from(picker.querySelectorAll('[role="treeitem"],button'))
    .find(node=>node.textContent.includes('ZZ <img> Internal article')),
    Array.from(picker.querySelectorAll('[role="treeitem"],button')).map(node=>({role:node.getAttribute('role'),text:node.textContent.slice(0,80)})));
  const choice=Array.from(picker.querySelectorAll('[role="treeitem"],button'))
    .find(node=>node.textContent.includes('ZZ <img> Internal article'));
  if(!choice)return;
  choice.click();
  check('choosing an article fills the real Link URL',await waitFor(()=>Array.from(dialog.querySelectorAll('input')).some(i=>i.value.includes('article=23'))),
    Array.from(dialog.querySelectorAll('input')).map(i=>i.value));
  browse.click();
  const reopened=await waitFor(()=>document.querySelectorAll('.tox-dialog').length===2);
  const again=document.querySelectorAll('.tox-dialog')[1];
  if(!reopened)return;
  Array.from(again.querySelectorAll('button')).find(button=>button.textContent==='common.cancel').click();
  check('cancel leaves the selected URL untouched',document.querySelectorAll('.tox-dialog').length===1
    &&Array.from(dialog.querySelectorAll('input')).some(i=>i.value==='?article=23'));
  Array.from(dialog.querySelectorAll('button')).find(button=>button.textContent==='Save').click();
  check('saving creates a relative article link',await waitFor(()=>tinymce.activeEditor.getContent().includes('article=23')),
    tinymce.activeEditor.getContent());
  const link=tinymce.activeEditor.getBody().querySelector('a');
  tinymce.activeEditor.selection.select(link);
  tinymce.activeEditor.execCommand('mceLink');
  const editing=await waitFor(()=>document.querySelectorAll('.tox-dialog').length===1);
  const editDialog=document.querySelector('.tox-dialog');
  if(!editing||!editDialog)return;
  const textInput=Array.from(editDialog.querySelectorAll('input')).find(input=>input.value.includes('Internal article'));
  textInput.value='Custom label';
  textInput.dispatchEvent(new Event('input',{bubbles:true}));
  textInput.dispatchEvent(new Event('change',{bubbles:true}));
  const editBrowse=Array.from(editDialog.querySelectorAll('button'))
    .find(button=>(button.getAttribute('aria-label')||'').includes('Browse'));
  editBrowse.click();
  const editPickerReady=await waitFor(()=>document.querySelectorAll('.tox-dialog').length===2
    &&document.querySelectorAll('.tox-dialog')[1].textContent.includes('ZZ First article'));
  if(!editPickerReady)return;
  const editPicker=document.querySelectorAll('.tox-dialog')[1];
  const pickerState={active:!!kbArticleLinkPicker,meta:kbArticleLinkPicker?.meta,
    callback:typeof kbArticleLinkPicker?.callback,articles:kbArticleLinkPicker?.articles.length};
  Array.from(editPicker.querySelectorAll('[role="treeitem"]'))
    .find(node=>node.textContent.includes('ZZ First article')).click();
  check('choosing another article keeps edited display text',await waitFor(()=>Array.from(editDialog.querySelectorAll('input'))
    .some(input=>input.value==='Custom label')&&Array.from(editDialog.querySelectorAll('input'))
    .some(input=>input.value==='?article=22')),
    {pickerState,after:Array.from(editDialog.querySelectorAll('input')).map(input=>input.value)});
})().catch(error=>check('editor browser execution: '+error.message,false))
  .finally(()=>{const result=JSON.stringify(checks);document.getElementById('result').textContent=result;parent.postMessage(result,location.origin);});
</script></body></html>
HTML;
$html = str_replace('/*PICKER_CODE*/', $pickerCode, $html);
$pages = [
    'knowledge/index.html' => $html,
    'index.html' => '<!doctype html><html><body><pre id="result">RUNNING</pre><iframe src="knowledge/index.html"></iframe>'
        .'<script>addEventListener("message",event=>{if(event.origin===location.origin&&String(event.data).startsWith("["))document.getElementById("result").textContent=event.data;});</script></body></html>',
];
$vendor = dirname(__DIR__).'/assets/js/tinymce';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($vendor) + 1));
        $pages['assets/js/tinymce/'.$relative] = file_get_contents($file->getPathname());
    }
}
exit(ticketWorkspaceBrowser($pages));
