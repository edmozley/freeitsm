<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Article links must open and select their folder in all four Knowledge layouts.
 * The top-level article list is deliberately empty, as on an install that files
 * every article. On the old code, list/cards/details never open the deep link;
 * tree opens it but keeps the wrong folder selected.
 *
 * Two more cases from the review: a link to an article whose folder is not in
 * the analyst's tree leaves the selection alone, and opening an article from the
 * list (a row click, a search result) never re-homes the list behind it.
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
let articles=[],kbArticlesLoaded=false,kbFoldersSettled=false,kbFolders=[],activeFolder='',kbRootArticleCount=0,currentArticle=null;
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
    {id:23,title:'ZZ target',folder_id:5,body:'<p>ZZ linked article</p>'},
    {id:24,title:'ZZ hidden',folder_id:9,body:'<p>ZZ shared, folder not</p>'}
  ];
  let data;
  if(url.pathname.endsWith('/knowledge_articles.php')){
    const folder=url.searchParams.get('folder');
    const listable=articles.filter(a=>a.id!==24);
    data={success:true,articles:folder==='root'?[]:folder?listable.filter(a=>String(a.folder_id)===folder):listable};
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
const kase=window.KB_TEST_CASE||'link';
const articleId=new URLSearchParams(location.search).get('article');
const started=Date.now();
const poll=setInterval(()=>{
  const detail=document.getElementById('articleDetailView').style.display==='block';
  const title=document.querySelector('#articleContent h1')?.textContent;
  const folder=document.querySelector('#kbFolderTree .kb-folder.active')?.dataset.folder;
  const listed=[...document.querySelectorAll('#articleList [data-article]')].map(row=>Number(row.dataset.article));
  const listMatches=mode==='tree'?listed.includes(Number(articleId)):listed.length===1 && listed[0]===Number(articleId);
  if(kase==='click'){
    // A row click from All articles: the list behind must stay where it was.
    if(!window.kbClicked && kbArticlesLoaded && kbFoldersSettled){window.kbClicked=Date.now();viewArticle(22);}
    if(window.kbClicked && Date.now()-window.kbClicked>800){
      clearInterval(poll);
      parent.postMessage({mode:'click',stage:'click',pass:detail && title==='ZZ source' && folder==='' && activeFolder==='',folder,title},location.origin);
    }else if(Date.now()-started>6500){
      clearInterval(poll);
      parent.postMessage({mode:'click',stage:'click',pass:false,detail,title,folder},location.origin);
    }
    return;
  }
  if(kase==='hidden'){
    if(detail && title==='ZZ hidden' && !window.kbSeen) window.kbSeen=Date.now();
    if(window.kbSeen && Date.now()-window.kbSeen>800){
      clearInterval(poll);
      parent.postMessage({mode:'hidden',stage:'hidden',pass:folder==='' && activeFolder==='',folder,title},location.origin);
    }else if(Date.now()-started>6500){
      clearInterval(poll);
      parent.postMessage({mode:'hidden',stage:'hidden',pass:false,detail,title,folder},location.origin);
    }
    return;
  }
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
foreach (['hidden', 'click'] as $case) {
    $pages['knowledge/'.$case.'.html'] = str_replace('/*LAYOUT*/', 'window.KB_TEST_LAYOUT="list";window.KB_TEST_CASE='.json_encode($case).';', $page);
}
$pages['index.html'] = <<<'HTML'
<!doctype html><html><body><pre id="result">RUNNING</pre>
<iframe src="knowledge/list.html?article=22"></iframe><iframe src="knowledge/cards.html?article=22"></iframe>
<iframe src="knowledge/tree.html?article=22"></iframe><iframe src="knowledge/details.html?article=22"></iframe>
<iframe src="knowledge/hidden.html?article=24"></iframe><iframe src="knowledge/click.html"></iframe>
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
  checks.push({label:'a link to an article in a folder the analyst cannot see leaves the selection alone',pass:!!received.hidden?.hidden?.pass,detail:received.hidden?.hidden||'no result'});
  checks.push({label:'opening an article from the list does not re-home the list behind it',pass:!!received.click?.click?.pass,detail:received.click?.click||'no result'});
  document.getElementById('result').textContent=JSON.stringify(checks);
}
window.addEventListener('message',event=>{
  if(event.origin!==location.origin || !['list','cards','tree','details','hidden','click'].includes(event.data?.mode))return;
  const {mode,stage}=event.data;
  (received[mode]??={})[stage]=event.data;
  const layouts=['list','cards','tree','details'].every(m=>received[m]?.target || received[m]?.source?.pass===false);
  if(layouts && received.hidden && received.click)finish();
});
setTimeout(finish,15000);
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser($pages));
