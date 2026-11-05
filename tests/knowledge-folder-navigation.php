<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Clicking a Knowledge folder leaves an open article detail for that folder's list.
 * The browser runs the shipped navigation functions; no database or Node.js is needed.
 * On the old code, the first two list-view checks fail even though the URL changes.
 */
require __DIR__.'/support/ticket-workspace-browser.php';

$source = file_get_contents(dirname(__DIR__).'/assets/js/knowledge.js');
if ($source === false) throw new RuntimeException('Cannot read Knowledge navigation script.');
function knowledgeNavigationFunction(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from);
    if ($from === false || $to === false) throw new RuntimeException('Knowledge navigation function not found: '.$start);
    return substr($source, $from, $to - $from);
}
$navigation = knowledgeNavigationFunction($source, 'function selectFolder(value) {', '/** Asks for a name')
    . knowledgeNavigationFunction($source, 'function syncArticleUrl(view) {', '// Show/hide views')
    . knowledgeNavigationFunction($source, 'function showView(view) {', '// Tag input functions');

$html = '<!doctype html><html><body>'
    . '<div class="knowledge-container">'
    . '<button id="folderNine" onclick="selectFolder(9)">ZZ Folder Nine</button>'
    . '<button id="allArticles" onclick="selectFolder(\'\')">All articles</button>'
    . '<div id="articleListView"><input id="articleSearch"><div id="articleList"></div></div>'
    . '<div id="articleDetailView"></div>'
    . '<div id="articleEditorView"><input id="draft" value="ZZ unsaved draft"></div>'
    . '</div><pre id="result">RUNNING</pre><script>'
    . 'let activeFolder="", currentArticle={id:7}, isRecycleBinView=false, articleEditor=null, activeTagFilters=[];'
    . 'const kbEditorIsFullScreen=()=>false, renderFolderTree=()=>{}, loadFolders=()=>{};'
    . 'function loadArticles(){ document.getElementById("articleList").textContent="Folder "+activeFolder; }'
    . $navigation . '</script>' . <<<'HTML'
<script>
const checks=[], check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
try {
    showView('detail');
    document.getElementById('folderNine').click();
    const list=document.getElementById('articleListView');
    const detail=document.getElementById('articleDetailView');
    check('folder click opens its article list',list.style.display==='block'&&detail.style.display==='none',list.style.display+'/'+detail.style.display);
    check('folder click loads the selected folder',document.getElementById('articleList').textContent==='Folder 9');
    check('folder URL replaces article URL',location.search==='?folder=9',location.search);

    showView('detail');
    document.getElementById('allArticles').click();
    check('All articles click also leaves detail',list.style.display==='block'&&detail.style.display==='none');
    check('All articles URL clears folder and article',location.search==='');

    showView('editor');
    document.getElementById('folderNine').click();
    const editor=document.getElementById('articleEditorView');
    check('folder click keeps an open editor and its draft',editor.style.display==='flex'&&document.getElementById('draft').value==='ZZ unsaved draft');
} catch (error) {
    check('Knowledge navigation executes: '+error.message,false);
}
document.getElementById('result').textContent=JSON.stringify(checks);
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html'=>$html]));
