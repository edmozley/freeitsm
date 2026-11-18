<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Knowledge editor: pictures from your computer (GH #391).
 *
 *   php tests/knowledge-image-upload.php
 *
 * The image dialog had no Upload tab: TinyMCE only shows one when an upload handler
 * is set, and none was. kbEmbedImage() (assets/js/knowledge.js) is that handler: it
 * embeds the picture in the article as a pasted one always was, scaling a large
 * photo down first. The REAL knowledge.js runs here (its page start-up stubbed out)
 * against pictures drawn on a canvas. Chrome, no database.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
$root = dirname(__DIR__);
$js = str_replace('</script', '<\/script', (string)file_get_contents("$root/assets/js/knowledge.js"));
$html = <<<HTML
<!doctype html><html><body><pre id="result"></pre>
<script>
window.t = k => k; window.API_BASE = 'zz/';
window.fetch = () => new Promise(() => {});
const originalAdd = document.addEventListener.bind(document);
document.addEventListener = (type, fn, o) => { if (type !== 'DOMContentLoaded') originalAdd(type, fn, o); };
</script>
<script>{$js}</script>
<script>
const checks = [], check = (label, pass, detail) => checks.push({label, pass: !!pass, detail});
/** A w x h picture of the given type, drawn on a canvas. */
const picture = (w, h, type) => new Promise(ok => { const c = document.createElement('canvas'); c.width = w; c.height = h;
  const g = c.getContext('2d'); g.fillStyle = '#3366cc'; g.fillRect(0, 0, w, h); g.fillStyle = '#ffcc00'; g.fillRect(w / 4, h / 4, w / 2, h / 2); c.toBlob(ok, type, 0.9); });
const size = url => new Promise(ok => { const i = new Image(); i.onload = () => ok([i.naturalWidth, i.naturalHeight]); i.src = url; });
(async () => {
  try {
    let url = await kbEmbedImage(await picture(3200, 2000, 'image/jpeg'));
    let wh = await size(url);
    check('a large photo is embedded, scaled to 1600px on its longest side', url.startsWith('data:image/jpeg') && wh[0] === 1600 && wh[1] === 1000, wh.join('x'));
    url = await kbEmbedImage(await picture(2000, 3000, 'image/png'));
    wh = await size(url);
    check('a large PNG stays PNG (it may be transparent) and is scaled', url.startsWith('data:image/png') && wh[1] === 1600, wh.join('x'));
    const small = await picture(400, 300, 'image/png');
    url = await kbEmbedImage(small);
    wh = await size(url);
    check('a small picture is embedded as it is', url.startsWith('data:image/png') && wh[0] === 400 && wh[1] === 300, wh.join('x'));
    const gif = new Blob([Uint8Array.from(atob('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), c => c.charCodeAt(0))], {type: 'image/gif'});
    url = await kbEmbedImage(gif);
    check('a GIF is embedded untouched, so an animation keeps moving', url.startsWith('data:image/gif;base64,R0lGOD'), url.slice(0, 40));
    let msg = '';
    try { await kbEmbedImage(new Blob(['not a picture'], {type: 'text/plain'})); } catch (e) { msg = String(e); }
    check('something that is not a picture is refused with a message', msg !== '', msg);
    msg = '';
    try { await kbEmbedImage(new Blob(['<svg xmlns="http://www.w3.org/2000/svg"/>'], {type: 'image/svg+xml'})); } catch (e) { msg = String(e); }
    check('an SVG is refused', msg !== '', msg);
  } catch (e) { check('test runs', false, String(e)); }
  document.getElementById('result').textContent = JSON.stringify(checks);
})();
</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['index.html' => $html]));
