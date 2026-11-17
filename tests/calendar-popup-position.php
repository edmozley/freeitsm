<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The quick-view popup of the Calendar and of the Change calendar stays inside the
 * window (GitHub #374).
 *
 *   php tests/calendar-popup-position.php
 *
 * Both popups used to open 10px below the clicked item with no check against the
 * window height, so an event in the last row of Month view put its popup - and its
 * Edit / Open change button - off the bottom of the screen, out of reach.
 *
 * The REAL itsm_calendar.js / change-calendar.js and their CSS run here in a
 * 1440x1000 headless window. For each popup: an item near the top opens it below
 * (unchanged), an item near the bottom opens it above, and a popup taller than the
 * window fits inside it and scrolls. Chrome, no database.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
$root = dirname(__DIR__);
$inline = fn(string $f) => str_replace('</script', '<\/script', (string)file_get_contents("$root/$f"));
// The change calendar's popup styles live in the page itself.
preg_match_all('#<style>(.*?)</style>#s', (string)file_get_contents("$root/change-management/calendar.php"), $m);
$changeCss = implode("\n", $m[1]);

$common = <<<'JS'
window.API_BASE = 'zz/'; window.t = k => k; window.cal = (k, f) => f || k;
// The pages' own loading is out of scope: their requests simply never answer.
window.fetch = () => new Promise(() => {});
// Nor their start-up, which draws the whole real page; only the popups are tested.
const originalAdd = document.addEventListener.bind(document);
document.addEventListener = (type, fn, o) => { if (type !== 'DOMContentLoaded') originalAdd(type, fn, o); };
const checks = [];
const check = (label, pass, detail) => checks.push({label, pass: !!pass, detail});
/** An item at the given distance from the top of the window; returns the popup's box after clicking it. */
function openAt(top, show, popupId) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;left:200px;width:120px;height:20px;top:' + top + 'px';
    document.body.appendChild(el);
    show(1, {stopPropagation() {}, target: el});
    const box = document.getElementById(popupId).getBoundingClientRect();
    const item = el.getBoundingClientRect();
    el.remove();
    return {box, item};
}
function run(name, show, popupId, makeTall) {
    const h = window.innerHeight;
    let r = openAt(100, show, popupId);
    check(name + ': an item near the top opens the popup below it, as before', r.box.top >= r.item.bottom, 'popup top ' + r.box.top + ', item bottom ' + r.item.bottom);
    r = openAt(h - 40, show, popupId);
    check(name + ': an item at the bottom of the window keeps the whole popup on screen', r.box.top >= 0 && r.box.bottom <= h, 'popup ' + Math.round(r.box.top) + '-' + Math.round(r.box.bottom) + ', window ' + h);
    check(name + ': ...opening it above the item, not over it', r.box.bottom <= r.item.top, 'popup bottom ' + Math.round(r.box.bottom) + ', item top ' + r.item.top);
    makeTall();
    r = openAt(h / 2, show, popupId);
    const p = document.getElementById(popupId);
    check(name + ': a popup taller than the window fits inside it and scrolls', r.box.top >= 0 && r.box.bottom <= h && p.scrollHeight > p.clientHeight, 'popup ' + Math.round(r.box.top) + '-' + Math.round(r.box.bottom) + ', window ' + h);
}
JS;

$calendar = '<!doctype html><html><head><style>' . file_get_contents("$root/assets/css/itsm_calendar.css") . '</style></head><body>
<div class="event-popup" id="eventPopup">
  <div class="event-popup-header"><span class="event-popup-category" id="popupCategory"></span><button class="event-popup-close">&times;</button></div>
  <h4 class="event-popup-title" id="popupTitle"></h4>
  <div class="event-popup-time" id="popupTime"></div><div class="event-popup-location" id="popupLocation"></div>
  <div class="event-popup-description" id="popupDescription"></div>
  <div class="event-popup-actions"><button class="btn btn-secondary btn-sm">Edit</button></div>
</div><pre id="result"></pre>
<script>' . $common . '</script><script>' . $inline('assets/js/tz.js') . '</script><script>' . $inline('assets/js/itsm_calendar.js') . '</script>
<script>
(() => {
    try {
        events = [{id: 1, title: "ZZ event", start_datetime: "2026-11-28 09:00:00", end_datetime: "2026-11-28 10:00:00", location: "ZZ room", description: "ZZ description"}];
        run("Calendar", showEventPopup, "eventPopup", () => { events[0].title = "ZZ long title ".repeat(400); });
    } catch (e) { check("calendar test runs", false, String(e)); }
    document.getElementById("result").textContent = JSON.stringify(checks);
})();
</script></body></html>';

$change = '<!doctype html><html><head><style>' . file_get_contents("$root/assets/css/itsm_calendar.css") . $changeCss . '</style></head><body>
<div class="change-popup" id="changePopup"><button class="change-popup-close">&times;</button><div id="changePopupContent"></div></div><pre id="result"></pre>
<script>' . $common . '</script><script>' . $inline('assets/js/tz.js') . '</script><script>' . $inline('assets/js/change-calendar.js') . '</script>
<script>
(() => {
    try {
        events = [{id: 1, title: "ZZ change", change_type: "Normal", status: "Scheduled", priority: "Low", impact: "Low",
            start_datetime: "2026-11-28 09:00:00", end_datetime: "2026-11-28 10:00:00", assigned_to_name: "ZZ analyst"}];
        run("Change calendar", showChangePopup, "changePopup", () => { events[0].title = "ZZ long title ".repeat(400); });
    } catch (e) { check("change calendar test runs", false, String(e)); }
    document.getElementById("result").textContent = JSON.stringify(checks);
})();
</script></body></html>';

$exit = ticketWorkspaceBrowser(['index.html' => $calendar]);
$exit = ticketWorkspaceBrowser(['index.html' => $change]) || $exit;
exit($exit ? 1 : 0);
