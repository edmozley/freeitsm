<?php
/* NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Clicking the caption or empty row must not silently save a preference.
 * Render every real Preferences switch with its real styles in a temporary page;
 * no login, database, notification permission or settings writes are involved.
 * Run: php tests/preferences-switch-hit-area.php (Chrome/Chromium or CHROME_BIN).
 * The old wrapping labels fail the caption and empty-row checks.
 */
require_once __DIR__ . '/../includes/php_version_guard.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/ticket_workspace.php';
require_once __DIR__ . '/support/ticket-workspace-browser.php';

I18n::setLocale('en');
$root = dirname(__DIR__);
$source = file_get_contents($root . '/system/preferences/index.php');
preg_match_all('~<(?:label|div) class="toggle-group">.*?<span class="toggle-label"[^>]*>.*?</span>\s*</(?:label|div)>~s', $source, $matches);
if (count($matches[0]) !== 10) throw new RuntimeException('Expected all ten Preferences switch templates (5.9.0 added the two war room ones).');
preg_match('~<style>(.*?)</style>~s', $source, $style);
$prefs = array_fill_keys(['search_panel_close_outside', 'remember_module_section', 'remember_company', 'desktop_notifications', 'task_assigned_email'], '');
$prefs['ticket_workspace'] = '0';
$prefs['warroom_desktop_alerts'] = '0';
$prefs['warroom_translate'] = '0';
$sbPrefs = ['on' => false];
$on = false;
$markup = '';
foreach ($matches[0] as $block) {
    // Exercise distinct ids for repeated notification captions, too.
    $keys = strpos($block, 'notif-type') !== false ? ['ticket.assigned', 'task.assigned'] : ['unused'];
    foreach ($keys as $key) {
        ob_start();
        eval('?>' . $block);
        $markup .= '<section class="pref-section">' . ob_get_clean() . '</section>';
    }
}
$css = '';
foreach (['theme', 'inbox', 'system-page'] as $name) $css .= file_get_contents($root . '/assets/css/' . $name . '.css');
$css .= $style[1] . file_get_contents($root . '/assets/css/mobile.css');
$html = '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' . $css
    . '</style></head><body data-mobile-module="system"><main class="sys-page">' . $markup . '</main><script>'
    . 'window.changes=0; document.addEventListener("change",()=>window.changes++);'
    . '</script></body></html>';

exit(ticketWorkspaceBrowser(['index.html' => $html], ['verify' => function (string $ws): array {
    $device = [];
    $read = function (string $js) use ($ws, &$device) {
        return json_decode(cdpEvaluate($ws, 'JSON.stringify(' . $js . ')', 15, null, $device), true);
    };
    $send = function (string $method, array $params) use ($ws, &$device): void {
        cdpEvaluate($ws, '', 15, ['method' => $method, 'params' => $params], $device);
    };
    $click = function (array $point) use ($send): void {
        $send('Input.dispatchMouseEvent', ['type' => 'mousePressed', 'button' => 'left', 'clickCount' => 1] + $point);
        $send('Input.dispatchMouseEvent', ['type' => 'mouseReleased', 'button' => 'left', 'clickCount' => 1] + $point);
    };
    $checks = [];
    foreach (['light', 'dark'] as $theme) {
        foreach ([1280, 390] as $width) {
            $device = [['method' => 'Emulation.setDeviceMetricsOverride', 'params' => ['width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]]];
            $read('(()=>{document.documentElement.setAttribute("data-theme", ' . json_encode($theme) . ');return true;})()');
            $actualWidth = $read('innerWidth');
            $checks[] = ['label' => "$theme viewport width", 'pass' => $actualWidth === $width, 'detail' => $actualWidth];
            echo "Checking $theme at $actualWidth px...\n";
            $count = $read('document.querySelectorAll(".toggle-group").length');
            if ($count !== 11) throw new RuntimeException('Not all switch fixtures rendered.');   // 10 templates, the notification one drawn twice
            for ($i = 0; $i < $count; $i++) {
                $prefix = "$theme/$width switch $i";
                $read('(()=>{window.group=document.querySelectorAll(".toggle-group")[' . $i . '];window.input=group.querySelector("input");input.checked=false;window.changes=0;group.scrollIntoView({block:"center"});return true;})()');
                foreach (['caption', 'blank', 'switch'] as $target) {
                    $point = $read('(()=>{const r=group.getBoundingClientRect(),s=group.querySelector(".toggle-switch").getBoundingClientRect(),c=group.querySelector(".toggle-label").getBoundingClientRect();return '
                        . ($target === 'caption' ? '{x:c.left+5,y:c.top+c.height/2}' : ($target === 'blank' ? '{x:r.right-5,y:s.top+s.height/2}' : '{x:s.left+s.width/2,y:s.top+s.height/2}')) . ';})()');
                    $click($point);
                    $state = $read('({checked:input.checked,changes:window.changes})');
                    $active = $target === 'switch';
                    $checks[] = ['label' => "$prefix $target click", 'pass' => $state === ['checked' => $active, 'changes' => $active ? 1 : 0], 'detail' => $state];
                    // Start each assertion from the same state, including on old code.
                    if (!$active) $read('(()=>{input.checked=false;window.changes=0;return true;})()');
                }
                $read('(()=>{input.focus();return true;})()');
                $send('Input.dispatchKeyEvent', ['type' => 'keyDown', 'key' => ' ', 'code' => 'Space', 'windowsVirtualKeyCode' => 32]);
                $send('Input.dispatchKeyEvent', ['type' => 'keyUp', 'key' => ' ', 'code' => 'Space', 'windowsVirtualKeyCode' => 32]);
                $state = $read('({checked:input.checked,changes:window.changes,focus:document.activeElement===input,outline:getComputedStyle(group.querySelector(".toggle-slider")).outlineStyle})');
                $checks[] = ['label' => "$prefix keyboard Space and visible focus", 'pass' => !$state['checked'] && $state['changes'] === 2 && $state['focus'] && $state['outline'] !== 'none', 'detail' => $state];
                $nodes = json_decode(cdpEvaluate($ws, '', 15, ['method' => 'Accessibility.getFullAXTree'], $device), true)['nodes'];
                $caption = $read('group.querySelector(".toggle-label").textContent.trim()');
                $named = array_filter($nodes, function ($node) use ($caption) {
                    return ($node['role']['value'] ?? '') === 'checkbox' && ($node['name']['value'] ?? '') === $caption;
                });
                $checks[] = ['label' => "$prefix accessible name", 'pass' => count($named) === 1];
            }
        }
    }
    return $checks;
}]));
