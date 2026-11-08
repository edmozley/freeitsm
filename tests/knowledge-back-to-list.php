<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** "Back to list" shows the list already in the page instead of reloading the module.
 * The reload cost the whole page, ~25 scripts and the folder, article and tag fetches on
 * every press, and lost the folder, search and tag filters. Runs the shipped
 * kbBackToList(), showView() and syncArticleUrl() on a bare page in headless Chrome;
 * no database. On the old code (a plain href="./") the first check fails.
 */
require __DIR__.'/support/ticket-workspace-browser.php';

$root = dirname(__DIR__);
$source = file_get_contents($root.'/assets/js/knowledge.js');
$index = file_get_contents($root.'/knowledge/index.php');
if ($source === false || $index === false) throw new RuntimeException('Cannot read the Knowledge page or script.');
function knowledgeBackSource(string $source, string $start, string $end): string
{
    $from = strpos($source, $start);
    $to = $from === false ? false : strpos($source, $end, $from);
    if ($from === false || $to === false) throw new RuntimeException('Knowledge function not found: '.$start);
    return substr($source, $from, $to - $from);
}
$code = knowledgeBackSource($source, 'let kbListNeedsReload = false;', '/**')
    . knowledgeBackSource($source, 'function syncArticleUrl(view) {', '// Show/hide views')
    . knowledgeBackSource($source, 'function showView(view) {', '// Tag input functions');

// The real button, as the page renders it.
if (!preg_match('#<a class="btn btn-secondary" href="\./"[^>]*>(?=<\?php echo htmlspecialchars\(t\(\'knowledge\.detail\.back\'\)\))#', $index, $m)) {
    throw new RuntimeException('The Back to list link was not found in knowledge/index.php.');
}
$link = str_replace('href="./"', 'href="./?zz=reloaded"', $m[0]) . 'Back to list</a>';

$html = '<!doctype html><html><body><div class="knowledge-container">'
    . '<div id="articleListView"><input id="articleSearch" value="printer"><div id="articleList">ZZ list rows</div></div>'
    . '<div id="articleDetailView">' . $link . '</div><div id="articleEditorView"></div>'
    . '<button id="recycleBinToggle"></button><h2 id="articleListHeader"></h2>'
    . '</div><pre id="result">RUNNING</pre><script>'
    . 'let activeFolder="3", currentArticle={id:7}, isRecycleBinView=false, articleEditor=null, activeTagFilters=[1], kbArticlesLoaded=true, loads=[];'
    . 'window.t=k=>k; const kbEditorIsFullScreen=()=>false;'
    . 'function loadArticles(search, tags){ loads.push([search, tags]); }'
    . $code . '</script>' . <<<'HTML'
<script>
const checks=[], check=(label,pass,detail)=>checks.push({label,pass:!!pass,detail});
const back=()=>document.querySelector('#articleDetailView a');
try {
    window.__marker='same page';
    showView('detail');
    back().click();
    check('a plain click stays on the page', window.__marker==='same page' && location.search.indexOf('zz=reloaded')<0, location.search);
    check('it shows the list and hides the article', document.getElementById('articleListView').style.display==='block' && document.getElementById('articleDetailView').style.display==='none');
    check('the list behind is kept - nothing is fetched', loads.length===0 && document.getElementById('articleList').textContent==='ZZ list rows', loads);
    check('the address bar names the folder', location.search==='?folder=3', location.search);
    check('Ctrl-click is left to the browser (new tab)', kbBackToList({ctrlKey:true, preventDefault(){ throw new Error('prevented'); }})===true);

    // An article reached while the recycle bin is open leaves the bin's rows behind it.
    isRecycleBinView=true;
    showView('detail');
    back().click();
    check('from the recycle bin it reloads the list once, with the search and tags', loads.length===1 && loads[0][0]==='printer' && loads[0][1][0]===1, loads);
    showView('detail'); back().click();
    check('...and only that once', loads.length===1, loads);

    kbArticlesLoaded=false; showView('detail'); back().click();
    check('a list that never loaded is fetched', loads.length===2, loads);
} catch (error) {
    check('Back to list executes: '+error.message, false);
}
document.getElementById('result').textContent=JSON.stringify(checks);
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html'=>$html]));
