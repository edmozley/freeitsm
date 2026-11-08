<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Article links must open and select their folder in all four Knowledge layouts.
 * The top-level article list is deliberately empty, as on an install that files
 * every article. On the old code, list/cards/details never open the deep link;
 * tree opens it but keeps the wrong folder selected.
 */
require __DIR__.'/support/ticket-workspace-browser.php';

$source = file_get_contents(dirname(__DIR__).'/assets/js/knowledge.js');
if ($source === false) throw new RuntimeException('Cannot read Knowledge navigation script.');
function knowledgeLinkSource(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from);
    if ($from === false || $to === false) throw new RuntimeException('Knowledge function not found: '.$start);
    return substr($source, $from, $to - $from);
}

$navigation = knowledgeLinkSource($source, 'async function loadFolders() {', '/** Children of')
    . knowledgeLinkSource($source, 'function folderChildren(parent) {', '/**')
    . knowledgeLinkSource($source, 'function renderFolderTree(rootCount) {', '/** Fill the editor')
    . knowledgeLinkSource($source, "async function loadArticles(search = '', tagIds = []) {", '// Render tag filters')
    . knowledgeLinkSource($source, 'async function viewArticle(articleId) {', '// Render article detail')
    . knowledgeLinkSource($source, 'function syncArticleUrl(view) {', '// Show/hide views')
    . knowledgeLinkSource($source, 'function showView(view) {', '// Tag input functions');
$deepLink = knowledgeLinkSource($source, '// Check for article ID in URL on page load', '// Server-stamped UTC timestamps');

$page = <<<'HTML'
<!doctype html><html><body>
<div class="knowledge-container">
  <div id="kbFolderTree"></div>
  <div id="articleListView"><input id="articleSearch"><div id="articleList"></div></div>
  <div id="articleDetailView" style="display:none"><div id="articleContent"></div></div>
  <div id="articleEditorView" style="display:none"></div>
</div>
<script>/*LAYOUT*/
const API_BASE='api/';
let articles=[],kbArticlesLoaded=false,kbFolders=[],activeFolder='',kbRootArticleCount=0,currentArticle=null;
let kbCanManagePerms=false,articleEditor=null,isRecycleBinView=false,activeTagFilters=[];
let kbLayout=window.KB_TEST_LAYOUT;
window.t=key=>key;
function escapeHtml(value){return String(value).replaceAll('&','&amp;').replaceAll('<','&lt;');}
function jsAttr(value){return JSON.stringify(String(value)).replaceAll('"','&quot;');}
function kbEditorIsFullScreen(){return false;}
function renderFolderPicker(){}
function renderArticleList(){
  const box=document.getElementById('articleList');
  box.innerHTML=articles.length
    ? articles.map(a=>'<div data-article="'+a.id+'">'+escapeHtml(a.title)+'</div>').join('')
    : '<div>Keine Artikel</div>';
}
function renderArticleDetail(){
  document.getElementById('articleContent').innerHTML='<h1>'+escapeHtml(currentArticle.title)+'</h1><div class="article-content-body">'+currentArticle.body+'</div>';
}
function showToast(){}
window.fetch=async input=>{
  const url=new URL(input,location.href);
  const articles=[
    {id:22,title:'ZZ source',folder_id:3,body:'<a href="?article=23">ZZ target</a>'},
    {id:23,title:'ZZ target',folder_id:5,body:'<p>ZZ linked article</p>'}
  ];
  let data;
  if(url.pathname.endsWith('/knowledge_articles.php')){
    const folder=url.searchParams.get('folder');
    data={success:true,articles:folder==='root'?[]:folder?articles.filter(a=>String(a.folder_id)===folder):articles};
  }else if(url.pathname.endsWith('/knowledge_article.php')){
    const article=articles.find(a=>String(a.id)===url.searchParams.get('id'));
    data=article?{success:true,article}:{success:false,error:'Not found'};
  }else if(url.pathname.endsWith('/folders.php')){
    data={success:true,root_count:0,can_manage:false,folders:[
      {id:3,parent_id:null,name:'ZZ parent',article_count:1,is_restricted:false},
      {id:5,parent_id:3,name:'ZZ child',article_count:1,is_restricted:false}
    ]};
  }else throw new Error('Unexpected fixture request: '+url.pathname);
  return {json:async()=>data};
};
</script><script>/*PRODUCTION_FUNCTIONS*/</script>
<script>
loadFolders();
loadArticles();
</script><script>/*DEEP_LINK*/</script>
<script>
const mode=window.KB_TEST_LAYOUT;
const articleId=new URLSearchParams(location.search).get('article');
const started=Date.now();
const poll=setInterval(()=>{
  const detail=document.getElementById('articleDetailView').style.display==='block';
  const title=document.querySelector('#articleContent h1')?.textContent;
  const folder=document.querySelector('#kbFolderTree .kb-folder.active')?.dataset.folder;
  const listed=[...document.querySelectorAll('#articleList [data-article]')].map(row=>Number(row.dataset.article));
  const listMatches=mode==='tree'?listed.includes(Number(articleId)):listed.length===1 && listed[0]===Number(articleId);
  if(articleId==='22' && detail && title==='ZZ source' && listMatches){
    clearInterval(poll);
    parent.postMessage({mode,stage:'source',pass:folder==='3',folder,listed},location.origin);
    document.querySelector('#articleContent a[href="?article=23"]').click();
  }else if(articleId==='23' && detail && title==='ZZ target' && folder==='5' && listMatches){
    clearInterval(poll);
    parent.postMessage({mode,stage:'target',pass:true,folder,listed},location.origin);
  }else if(Date.now()-started>6500){
    clearInterval(poll);
    parent.postMessage({mode,stage:articleId==='22'?'source':'target',pass:false,
      detail,title,folder,listed,articleId,url:location.search},location.origin);
  }
},50);
</script></body></html>
HTML;
$page = str_replace(['/*PRODUCTION_FUNCTIONS*/', '/*DEEP_LINK*/'], [$navigation, $deepLink], $page);

$pages = [];
foreach (['list', 'cards', 'tree', 'details'] as $mode) {
    $pages['knowledge/'.$mode.'.html'] = str_replace('/*LAYOUT*/', 'window.KB_TEST_LAYOUT='.json_encode($mode).';', $page);
}
$pages['index.html'] = <<<'HTML'
<!doctype html><html><body><pre id="result">RUNNING</pre>
<iframe src="knowledge/list.html?article=22"></iframe><iframe src="knowledge/cards.html?article=22"></iframe>
<iframe src="knowledge/tree.html?article=22"></iframe><iframe src="knowledge/details.html?article=22"></iframe>
<script>
const received={};
function finish(){
  const checks=[];
  for(const mode of ['list','cards','tree','details']){
    for(const stage of ['source','target']){
      const r=received[mode]?.[stage];
      checks.push({label:mode+' '+stage+' article opens with its folder selected',pass:!!r?.pass,detail:r||'no result'});
    }
  }
  document.getElementById('result').textContent=JSON.stringify(checks);
}
window.addEventListener('message',event=>{
  if(event.origin!==location.origin || !['list','cards','tree','details'].includes(event.data?.mode))return;
  const {mode,stage}=event.data;
  (received[mode]??={})[stage]=event.data;
  if(Object.keys(received).length===4 && Object.values(received).every(r=>r.target || r.source?.pass===false))finish();
});
setTimeout(finish,15000);
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser($pages));
