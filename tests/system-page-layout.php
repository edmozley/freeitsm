<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every System page uses the one page layout (assets/css/system-page.css).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/system-page-layout.php
 *
 * Opens every System area (from getSystemAreas(), so a new area is checked the day
 * it is added) in headless Chrome at 1600px and MEASURES it - reading the CSS has
 * said "full width" three times when the screen was not:
 *   1. the page has a .sys-page container spanning the width it is given;
 *   2. its .sys-title sits on the standard gutter (32px) at the standard size;
 *   3. the page ends inside the window: no second scrollbar, the bottom reachable;
 *   6. (below) every dialog on the page, opened, is the standard System modal: .sys-modal
 *      with head/body(/foot), 520/820/1000 wide, 8px corners, an 18px/600 title and
 *      content 24px in from the edges;
 *   4. nothing inside is capped: no visible block 400px+ wide that a max-width
 *      holds narrower than the page. Controls are allowed caps (inputs, selects,
 *      search boxes, table cells, images); anything else that genuinely needs one
 *      says so with data-sys-cap="why".
 * Needs Chrome; skipped without. Writes nothing to the database.
 *
 * TRAP: a System page with its own layout (a full-screen app such as the
 * Engineering Wiki) is listed in $APP_PAGES with the reason - never silently.
 *
 * @wiki System-Page-Layout
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../system/includes/areas.php';
require_once __DIR__ . '/../includes/integrations/integrations.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || $base === '') { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
foreach (['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $alt) if (!is_file($chrome) && is_file($alt)) $chrome = $alt;
if (!is_file($chrome)) { echo "(skipped: no Chrome)\n"; exit(0); }

// Pages that are an application rather than a settings screen, with why.
$APP_PAGES = [
    'system/engineering-wiki/' => 'a three-pane document reader with its own layout',
    'system/api/docs.php'      => 'the API reference: a document reader with its own navigation, like the help pages',
];

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$root = dirname(__DIR__);
$sessDir = rtrim((string)ini_get('session.save_path'), '/\\') ?: sys_get_temp_dir();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$sid = 'zzspl' . bin2hex(random_bytes(6));
$frame = $root . '/zz_spl_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . bin2hex(random_bytes(32)) . '";');

$probe = <<<'JS'
(()=>{const d=f.contentDocument,w=f.contentWindow,W=w.innerWidth;const o={W};
const page=d.querySelector('.sys-page');if(!page)return {W,noPage:1};
const pr=page.getBoundingClientRect(),pcs=w.getComputedStyle(page),pe=page.parentElement,pecs=w.getComputedStyle(pe);
o.pageW=Math.round(pr.width);o.parentW=Math.round(pe.clientWidth-parseFloat(pecs.paddingLeft)-parseFloat(pecs.paddingRight));o.padL=pcs.paddingLeft;
const t=page.querySelector('.sys-title');if(t){const r=t.getBoundingClientRect(),cs=w.getComputedStyle(t);o.titleX=Math.round(r.left-pr.left);o.titleFs=cs.fontSize;o.titleFw=cs.fontWeight;}
const inner=pr.width-parseFloat(pcs.paddingLeft)-parseFloat(pcs.paddingRight);const caps=[];
for(const e of page.querySelectorAll('*')){if(e.closest('[data-sys-cap],.modal,[class*=overlay],[class*=modal],[hidden],dialog,table'))continue;
 if(/^(INPUT|SELECT|TEXTAREA|IMG|BUTTON|CANVAS|svg|LABEL|TD|TH|VIDEO|IFRAME)$/i.test(e.tagName))continue;
 const cs=w.getComputedStyle(e);if(cs.maxWidth==='none'||cs.maxWidth==='100%'||cs.position==='fixed'||cs.position==='absolute')continue;
 if(/search|input|field|select|chip|badge|pill|toolbar|btn/i.test(String(e.className)))continue;
 const r=e.getBoundingClientRect();if(r.width<400||r.height<1)continue;
 if(r.width<inner-40)caps.push(e.tagName.toLowerCase()+'.'+String(e.className).split(' ').filter(Boolean).join('.')+' max-width:'+cs.maxWidth);}
o.caps=caps.slice(0,5);o.docOver=d.scrollingElement.scrollHeight-w.innerHeight;let sc=page;while(sc&&sc!==d.body){const ov=w.getComputedStyle(sc).overflowY;if(ov==='auto'||ov==='scroll')break;sc=sc.parentElement;}o.bottom=(sc&&sc!==d.body)?Math.round(sc.getBoundingClientRect().bottom-w.innerHeight):0;const roots=[...d.querySelectorAll('body *')].filter(e=>{if(e.closest('.header,[id^=waffle],[class*=waffle]'))return false;const cs=w.getComputedStyle(e);return cs.position==='fixed'&&/modal|overlay|backdrop|dialog/i.test(String(e.className)+' '+e.id)});o.modals=[];for(const r of roots.filter(r=>!roots.some(x=>x!==r&&x.contains(r)))){const hid=r.hidden;r.hidden=false;r.classList.add('active','open','show');r.style.setProperty('display','flex','important');r.style.setProperty('visibility','visible','important');r.style.setProperty('opacity','1','important');const m={id:r.id||String(r.className).split(' ')[0]};const box=r.querySelector('.sys-modal');if(!box){m.noSys=1;}else{box.style.setProperty('transition','none','important');box.style.setProperty('transform','none','important');const bb=box.getBoundingClientRect(),bcs=w.getComputedStyle(box);m.w=Math.round(bb.width);m.rad=bcs.borderRadius;const h=box.querySelector('.sys-modal-head');if(h){const hcs=w.getComputedStyle(h);const rg=d.createRange();rg.selectNodeContents(h);m.hFs=hcs.fontSize;m.hFw=hcs.fontWeight;m.hL=Math.round(h.getBoundingClientRect().left-bb.left+parseFloat(hcs.paddingLeft));}const b=box.querySelector('.sys-modal-body');if(b){let mn=1e9,mx=1e9;for(const e of b.querySelectorAll('label,input,select,textarea,button,p,h3,h4,table,pre')){const q=e.getBoundingClientRect(),c2=w.getComputedStyle(e);if(q.width<1||q.height<1||c2.display==='none'||e.closest('[hidden]'))continue;mn=Math.min(mn,q.left-bb.left);mx=Math.min(mx,bb.right-q.right);}m.bL=mn===1e9?null:Math.round(mn);m.bR=mx===1e9?null:Math.round(mx);}const ft=box.querySelector('.sys-modal-foot');if(ft){const bt=[...ft.querySelectorAll('button,a.btn')].filter(x=>x.getBoundingClientRect().width>0);const lb=bt[bt.length-1];m.fR=lb?Math.round(bb.right-lb.getBoundingClientRect().right):null;m.btns=[...new Set(bt.map(x=>{const c=w.getComputedStyle(x);return (x.classList.contains('btn')?'':'no .btn ')+c.paddingTop+' '+c.paddingRight+'|'+c.borderTopLeftRadius+'|'+c.fontSize+'|'+c.fontWeight;}))];}m.head=!!h;m.body=!!b;m.foot=!!ft;box.style.removeProperty('transform');box.style.removeProperty('transition');}o.modals.push(m);r.hidden=hid;r.classList.remove('active','open','show');r.style.removeProperty('display');r.style.removeProperty('visibility');r.style.removeProperty('opacity');}return o;})()
JS;
$probe = str_replace("\n", ' ', $probe);

$urls = array_merge(['system/'], array_map(fn($a) => 'system/' . $a['url'], getSystemAreas()));
// The screens BELOW the System cards: every other System page that includes the System
// header, found on disk so a new one is checked without anyone remembering to add it.
// Help pages (system/help/, */help.php) have their own house style and are left out.
// Those that only open for a record get one: three ZZ sign-in providers, made here and
// removed in finally, and the first tracker in the integrations registry.
$made = [];
$sub = [];
foreach (array_merge(glob($root . '/system/*/*.php') ?: []) as $abs) {
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, strlen($root) + 1));
    if (str_starts_with($rel, 'system/includes/') || str_starts_with($rel, 'system/help/') || str_ends_with($rel, '/help.php')) continue;
    if (!preg_match('#include[^;]*includes/header\.php#', (string)file_get_contents($abs))) continue;
    $u = str_ends_with($rel, '/index.php') ? substr($rel, 0, -9) : $rel;
    if (!in_array($u, $urls, true)) $sub[] = $u;
}
$zzProvider = function (string $protocol, string $issuer) use ($conn, &$made): int {
    $conn->prepare("INSERT INTO auth_providers (display_name, protocol, issuer_url, client_id) VALUES (?, ?, ?, 'zz')")->execute(['ZZ layout test', $protocol, $issuer]);
    return $made[] = (int)$conn->lastInsertId();
};
$needs = [
    'system/sso/provider.php'          => fn() => 'id=' . $zzProvider('ldap', 'ldap://zz.invalid'),
    'system/sso/entra.php'             => fn() => 'id=' . $zzProvider('oidc', 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0'),
    'system/sso/carddav.php'           => fn() => 'id=' . $zzProvider('carddav', 'https://zz.invalid/dav'),
    'system/integrations/provider.php' => function () { foreach (integrationsAvailableProviders() as $k => $m) if (($m['kind'] ?? 'tracker') === 'tracker') return 'provider=' . rawurlencode($k); return null; },
];
// FREEITSM_SPL_ONLY=cost-centres,sso,sso/entra.php checks just those (system/ itself is "landing").
if ($only = getenv('FREEITSM_SPL_ONLY')) {
    $want = array_map(fn($x) => $x === 'landing' ? 'system/' : (str_ends_with($x, '.php') ? 'system/' . trim($x, '/ ') : 'system/' . trim($x, '/ ') . '/'), explode(',', $only));
    $urls = array_values(array_intersect($urls, $want));
    $sub = array_values(array_intersect($sub, $want));
}
try {
    foreach ($sub as $u) {
        if (isset($needs[$u])) {
            $q = ($needs[$u])();
            if ($q === null) { echo "$u - skipped: nothing to open it for on this install\n"; continue; }
            $urls[] = "$u?$q";
        } else {
            $urls[] = $u;
        }
    }
    foreach ($urls as $u) {
        $path = strtok($u, '?');
        if (isset($APP_PAGES[$path])) { echo "$u - skipped: {$APP_PAGES[$path]}\n"; continue; }
        echo "$u\n";
        file_put_contents($frame, "<?php setcookie('PHPSESSID', '$sid', 0, '/'); ?><!doctype html><html><body style=\"margin:0\"><pre id=\"out\">waiting</pre>"
            . "<iframe id=\"f\" src=\"$u\" style=\"width:1600px;height:1000px;border:0\"></iframe><script>const f=document.getElementById('f');"
            . "f.addEventListener('load',()=>{setTimeout(()=>{let o;try{o=$probe}catch(e){o={err:String(e)}};document.getElementById('out').textContent=JSON.stringify(o);},2000);});</script></body></html>");
        $out = (string)shell_exec('"' . $chrome . '" --headless=new --disable-gpu --window-size=1700,1100 --user-data-dir="' . sys_get_temp_dir() . '/zz_spl_chrome_' . getmypid() . '" --virtual-time-budget=8000 --dump-dom "' . $base . '/' . basename($frame) . '" 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        $j = preg_match('#<pre id="out">(.*?)</pre>#s', $out, $m) ? (json_decode(html_entity_decode($m[1]), true) ?: []) : [];
        if (!$j || isset($j['err'])) { check('the page could be measured', false, $j['err'] ?? 'no output'); continue; }
        if (!empty($j['noPage'])) { check('has a .sys-page container', false); continue; }
        check('the page fills the width it is given', abs($j['pageW'] - $j['parentW']) <= 1, "{$j['pageW']} of {$j['parentW']}");
        check('the standard gutter', $j['padL'] === '32px', $j['padL']);
        check('a .sys-title on the gutter at the standard size',
            isset($j['titleX']) && $j['titleX'] === 32 && $j['titleFs'] === '22px' && $j['titleFw'] === '700',
            isset($j['titleX']) ? "x={$j['titleX']} {$j['titleFs']}/{$j['titleFw']}" : 'no .sys-title');
        check('nothing capped narrower than the page', !$j['caps'], implode('; ', $j['caps']));
        check('the page ends inside the window (no second scrollbar, bottom reachable)', $j['docOver'] <= 0 && $j['bottom'] <= 0, "page runs {$j['docOver']}px past, scroll box {$j['bottom']}px");
        // 6. Every dialog on the page: the System modal (system-page.css), measured open.
        foreach ($j['modals'] ?? [] as $md) {
            $bad = [];
            if (!empty($md['noSys'])) { $bad[] = 'not a .sys-modal'; }
            else {
                if (!$md['head'] || !$md['body']) $bad[] = 'needs .sys-modal-head and .sys-modal-body';
                if (!in_array($md['w'], [520, 820, 1000], true)) $bad[] = "width {$md['w']} (520, 820 or 1000)";
                if ($md['rad'] !== '8px') $bad[] = "corners {$md['rad']}";
                if ($md['head'] && ($md['hFs'] !== '18px' || $md['hFw'] !== '600' || $md['hL'] !== 24)) $bad[] = "title {$md['hFs']}/{$md['hFw']} at {$md['hL']}px";
                if ($md['body'] && $md['bL'] !== null && ($md['bL'] < 24 || $md['bR'] < 24)) $bad[] = "body content {$md['bL']}px / {$md['bR']}px from the edges (24+)";
                if ($md['foot'] && $md['fR'] !== null && $md['fR'] !== 24) $bad[] = "footer button {$md['fR']}px from the right (24)";
                // The app's standard button (inbox.css .btn): 10px 20px, 4px corners, 14px / 500.
                foreach ($md['btns'] ?? [] as $bs) if ($bs !== '10px 20px|4px|14px|500') $bad[] = "footer button is not the standard button ({$bs})";
            }
            check("dialog #{$md['id']} is the standard System modal", !$bad, implode('; ', $bad));
        }
    }
} finally {
    foreach ($made as $id) { try { $conn->exec("DELETE FROM auth_providers WHERE id = " . (int)$id); } catch (Throwable $e) { echo "  (could not remove ZZ provider $id)\n"; } }
    @unlink($frame);
    @unlink("$sessDir/sess_$sid");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
