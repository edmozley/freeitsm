<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function ticketWorkspacePage(string $beforeScripts = ''): string
{
$root = dirname(__DIR__, 2);
$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
foreach (['theme', 'inbox', 'reading-pane', 'ticket-workspace'] as $css) {
    $path = "$root/assets/css/$css.css";
    if (is_file($path)) $html .= '<style>' . file_get_contents($path) . '</style>';
}
$html .= '</head><body><main id="readingPane" class="reading-pane"></main><div id="emailList"></div><div id="selectionBar"></div><pre id="result">RUNNING</pre><script>
window.API_BASE="../api/tickets/"; window.TICKETS_DETAIL_LAYOUT="workspace";
window.TICKETS_SHOW_DOMAINS=true; window.TICKETS_SHOW_PROJECTS=true;
window.translations={}; window.TICKET_AI={};
const originalAdd=document.addEventListener.bind(document);
document.addEventListener=(name,fn,...args)=>{if(name!=="DOMContentLoaded")originalAdd(name,fn,...args)};
</script>';
$html .= '<script>'.$beforeScripts.'</script>';
foreach (['i18n', 'safe-html', 'tz', 'record-preview', 'ticket-workspace', 'inbox'] as $js) {
    $path = "$root/assets/js/$js.js";
    if (is_file($path)) $html .= '<script>' . str_replace('</script', '<\/script', file_get_contents($path)) . '</script>';
}
return $html;
}
