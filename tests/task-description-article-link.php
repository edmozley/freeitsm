<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A task's description gets Knowledge's article browser in its Link dialog, for an
 * analyst who may read Knowledge. Runs the shipped editor setup from tasks.js and the
 * shared picker in Chrome. The link must stay root-relative: the same description is
 * shown from tasks/table/ and the other views, where ../knowledge/ would be wrong.
 */
require __DIR__.'/support/ticket-workspace-browser.php';

$root = dirname(__DIR__);
$source = file_get_contents("$root/assets/js/tasks.js");
$from = strpos($source, '    // Init TinyMCE for description');
$to = strpos($source, "\n}\n\n// ── Who else", (int)$from);
if ($from === false || $to === false) throw new RuntimeException('Task description editor setup not found.');
$editorCode = 'function initTaskDescription() {' . substr($source, $from, $to - $from) . "\n}";

$html = <<<'HTML'
<!doctype html><html data-theme="light" data-theme-mode="light"><head>
<script src="../assets/js/tinymce/tinymce.min.js"></script>
<script src="../assets/js/kb-article-link-picker.js"></script>
</head><body>
<div id="descriptionEditor"><p>Selected text</p></div>
<pre id="result">RUNNING</pre>
<script>
window.APP_BASE='/helpdesk/';
window.TASK_CAN_KNOWLEDGE=true;
let tinyEditor=null,descSaveTimer=null;
function saveField(){}
window.t=(key,values)=>key.replace('{count}',values?.count??'');
const requests=[];
window.fetch=async input=>{
  const url=new URL(input,location.href);requests.push(url);
  const articles=[{id:22,title:'ZZ First article',folder_id:7},{id:23,title:'ZZ Second article',folder_id:null}];
  const q=(url.searchParams.get('search')||'').toLowerCase();
  return {ok:true,json:async()=>({success:true,articles:articles.filter(a=>a.title.toLowerCase().includes(q))})};
};
</script><script>/*EDITOR_CODE*/</script><script>
const checks=[],check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
const waitFor=async predicate=>{for(let i=0;i<80;i++){if(predicate())return true;await new Promise(r=>setTimeout(r,50));}return false;};
const browseButton=dialog=>Array.from(dialog.querySelectorAll('button')).find(b=>(b.getAttribute('aria-label')||'').toLowerCase().includes('browse'));
(async()=>{
  initTaskDescription();
  const ready=await waitFor(()=>tinymce.activeEditor?.initialized);
  check('the task description editor starts',ready);
  if(!ready)return;
  tinymce.activeEditor.execCommand('mceLink');
  const opened=await waitFor(()=>!!document.querySelector('.tox-dialog'));
  const dialog=document.querySelector('.tox-dialog');
  const browse=opened&&browseButton(dialog);
  check('its Link dialog has the article browse button',!!browse);
  if(!browse)return;
  browse.click();
  const found=await waitFor(()=>document.querySelectorAll('.tox-dialog').length===2
    &&document.querySelectorAll('.tox-dialog')[1].textContent.includes('ZZ Second article'));
  const picker=document.querySelectorAll('.tox-dialog')[1];
  check('the article browser opens',found,picker?.textContent.slice(0,200));
  check('it searches Knowledge from the site root',requests[0]?.pathname==='/helpdesk/api/knowledge/knowledge_articles.php'
    &&requests[0]?.searchParams.get('limit')==='40',requests.map(u=>u.pathname+u.search));
  if(!found)return;
  const choice=Array.from(picker.querySelectorAll('[role="treeitem"]')).find(n=>n.textContent==='ZZ Second article');
  check('articles are listed by title',!!choice,Array.from(picker.querySelectorAll('[role="treeitem"]')).map(n=>n.textContent));
  if(!choice)return;
  choice.click();
  check('choosing an article fills the Link URL',await waitFor(()=>Array.from(dialog.querySelectorAll('input'))
    .some(i=>i.value==='/helpdesk/knowledge/?article=23')),Array.from(dialog.querySelectorAll('input')).map(i=>i.value));
  Array.from(dialog.querySelectorAll('button')).find(b=>b.textContent==='Save').click();
  check('the saved link stays root-relative',await waitFor(()=>tinymce.activeEditor.getContent().includes('href="/helpdesk/knowledge/?article=23"')),
    tinymce.activeEditor.getContent());

  // An analyst without Knowledge: TinyMCE's plain Link dialog, no browse button.
  window.TASK_CAN_KNOWLEDGE=false;
  const first=tinymce.activeEditor;
  initTaskDescription();
  const again=await waitFor(()=>tinymce.activeEditor&&tinymce.activeEditor!==first&&tinymce.activeEditor.initialized);
  if(!again){check('the editor restarts',false);return;}
  tinymce.activeEditor.execCommand('mceLink');
  const plain=await waitFor(()=>!!document.querySelector('.tox-dialog'));
  check('without Knowledge there is no browse button',plain&&!browseButton(document.querySelector('.tox-dialog')));
})().catch(error=>check('editor browser execution: '+error.message,false))
  .finally(()=>{const result=JSON.stringify(checks);document.getElementById('result').textContent=result;parent.postMessage(result,location.origin);});
</script></body></html>
HTML;
$pages = [
    'tasks/index.html' => str_replace('/*EDITOR_CODE*/', $editorCode, $html),
    'index.html' => '<!doctype html><html><body><pre id="result">RUNNING</pre><iframe src="tasks/index.html"></iframe>'
        .'<script>addEventListener("message",event=>{if(event.origin===location.origin&&String(event.data).startsWith("["))document.getElementById("result").textContent=event.data;});</script></body></html>',
    'assets/js/kb-article-link-picker.js' => file_get_contents("$root/assets/js/kb-article-link-picker.js"),
];
$vendor = "$root/assets/js/tinymce";
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($vendor) + 1));
        $pages['assets/js/tinymce/'.$relative] = file_get_contents($file->getPathname());
    }
}
exit(ticketWorkspaceBrowser($pages));
