<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * SMTP authentication: real save/list endpoints and, with --browser, the mailbox modal.
 * Use an isolated installation and shared PHP session directory:
 * FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://127.0.0.1:<port>
 * php tests/mailbox-smtp-auth.php [--browser]
 * Only inactive synthetic mailboxes are created; no email is sent. Cleanup is in finally.
 * Protocol coverage lives in mailbox-sent-copy.php; credential resolution in mailbox-send-test.php.
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "Set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n"; exit(1); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/encryption.php';
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { throw new RuntimeException('Wrong test database.'); }

$pass = 0; $fail = 0; $ids = []; $adminId = 0; $sessFile = '';
$tag = 'zzsmtpauth' . bin2hex(random_bytes(4));
$check = function (string $label, bool $ok) use (&$pass, &$fail) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
try {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants)
        VALUES (?, '!', 'SMTP test administrator', 'smtp-admin@example.invalid', 1, 1, 1, 1)")->execute([$tag]);
    $adminId = (int)$conn->lastInsertId();
    $sid = $tag;
    $sessFile = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\') . '/sess_' . $sid;
    file_put_contents($sessFile, 'analyst_id|i:' . $adminId . ';csrf_token|s:1:"x";');
    $request = function (string $path, ?array $body = null) use ($base, $sid): array {
        $ch = curl_init($base . '/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x']]);
        if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
        $result = json_decode((string)curl_exec($ch), true);
        if (!is_array($result)) throw new RuntimeException('Endpoint did not return JSON: ' . $path);
        return $result;
    };
    $fields = ['provider' => 'imap', 'name' => $tag, 'target_mailbox' => 'help@example.invalid',
        'azure_client_id' => '', 'imap_server' => 'imap.example.invalid', 'imap_username' => 'reader@example.invalid',
        'imap_password' => 'synthetic-imap-password', 'smtp_server' => 'smtp.example.invalid', 'smtp_port' => 25,
        'smtp_encryption' => 'none', 'smtp_same_as_imap' => false, 'smtp_username' => 'sender@example.invalid',
        'smtp_password' => 'synthetic-smtp-password', 'is_active' => false];
    $save = fn(array $body) => $request('api/tickets/save_mailbox.php', $body);
    $list = fn() => $request('api/tickets/get_mailboxes.php')['mailboxes'];
    $find = function (int $id) use ($list) { foreach ($list() as $m) if ($m['id'] === $id) return $m; return null; };
    $r = $save($fields);
    $id = (int)($r['id'] ?? 0);
    if (!$id) throw new RuntimeException('Could not create the test mailbox.');
    $ids[] = $id;
    $check('new mailbox defaults to SMTP authentication', $find($id)['smtp_auth_enabled'] === true);
    $update = ['id' => $id, 'imap_password' => '', 'smtp_password' => ''] + $fields;
    $r = $save(['smtp_auth_enabled' => false, 'smtp_same_as_imap' => true] + $update);
    $m = $find($id);
    $check('explicit opt-out is saved and returned', !empty($r['success']) && $m['smtp_auth_enabled'] === false);
    $check('switching off preserves both logins without exposing passwords', $m['smtp_username'] === $fields['smtp_username']
        && $m['smtp_password_set'] && $m['imap_password_set'] && !isset($m['smtp_password']) && !isset($m['imap_password']));
    $r = $save($update);
    $check('older clients omitting the flag preserve relay mode', !empty($r['success']) && $find($id)['smtp_auth_enabled'] === false);
    foreach ([null, '', 'false', [], 2] as $bad) {
        $r = $save(['smtp_auth_enabled' => $bad] + $update);
        $check('malformed authentication choice is rejected', ($r['success'] ?? true) === false);
    }
    $r = $save(['smtp_auth_enabled' => true] + $update);
    $m = $find($id);
    $check('re-enabling restores the separate SMTP login', !empty($r['success']) && $m['smtp_auth_enabled'] === true && $m['smtp_password_set']);
    $r = $save(['smtp_auth_enabled' => false, 'imap_password' => ''] + $fields);
    if (!empty($r['id'])) $ids[] = (int)$r['id'];
    $check('new relay mailbox still requires its IMAP password', ($r['success'] ?? true) === false);
    $r = $save(['smtp_auth_enabled' => false] + $fields);
    if (!empty($r['id'])) $ids[] = (int)$r['id'];
    $check('new mailbox can explicitly use an unauthenticated relay', !empty($r['success']) && $find((int)$r['id'])['smtp_auth_enabled'] === false);

    if (in_array('--browser', $argv, true)) {
        require_once 'tests/support/ticket-workspace-browser.php';
        $browserExit = ticketWorkspaceBrowser([], ['url' => $base . '/login.php', 'verify' => function (string $ws) use ($base, $sid, $m) {
            cdpEvaluate($ws, '', 15, ['method' => 'Network.setCookie', 'params' => ['name' => 'PHPSESSID', 'value' => $sid, 'url' => $base]]);
            cdpEvaluate($ws, '', 15, ['method' => 'Page.navigate', 'params' => ['url' => $base . '/tickets/settings/']]);
            cdpEvaluate($ws, "new Promise(resolve => { const t = setInterval(() => { if (typeof openMailboxModal === 'function') { clearInterval(t); resolve('ready'); } }, 100); })", 20);
            $mailbox = json_encode($m, JSON_HEX_TAG | JSON_HEX_AMP);
            return json_decode(cdpEvaluate($ws, '(async () => { const mailbox = ' . $mailbox . ';' . <<<'JS'
                const checks = [], check = (label, pass) => checks.push({label, pass});
                const el = id => document.getElementById(id), visible = selector => !!document.querySelector(selector).getClientRects().length;
                await openMailboxModal(mailbox);
                check('saved authentication and separate login load in the real modal', el('mailboxSmtpAuthEnabled').checked && visible('.smtp-creds'));
                el('mailboxSmtpAuthLabel').click();
                check('clicking the caption does not change the switch', el('mailboxSmtpAuthEnabled').checked);
                el('mailboxSmtpAuthEnabled').click();
                check('relay mode hides SMTP login controls, keeping IMAP fields visible', !visible('.smtp-creds') && !visible('.smtp-auth-creds') && visible('#mailboxImapUsername'));
                const realFetch = window.fetch;
                let tested = null, saveResponse = null;
                window.fetch = async (url, options) => {
                    if (String(url).endsWith('test_mailbox_send.php')) {
                        tested = JSON.parse(options.body);
                        return {json: async () => ({success: true, to: 'smtp-admin@example.invalid', used_saved: false})};
                    }
                    const response = await realFetch(url, options);
                    if (String(url).endsWith('save_mailbox.php')) saveResponse = await response.clone().json();
                    return response;
                };
                await testMailboxSend();
                check('Test sends the unsaved authentication choice', tested?.smtp_auth_enabled === false);
                el('mailboxForm').dispatchEvent(new Event('submit', {cancelable: true}));
                for (let i = 0; i < 100 && !saveResponse; i++) await new Promise(r => setTimeout(r, 50));
                const saved = (await (await realFetch(API_BASE + 'get_mailboxes.php')).json()).mailboxes.find(m => m.id === mailbox.id);
                check('Save persists the form choice through the real endpoint', saveResponse?.success === true && saved.smtp_auth_enabled === false);
                await openMailboxModal(saved);
                check('reopening restores relay mode', !el('mailboxSmtpAuthEnabled').checked && !visible('.smtp-creds'));
                el('mailboxSmtpAuthEnabled').click();
                check('re-enabling reveals the preserved separate login', visible('.smtp-creds') && el('mailboxSmtpUsername').value === mailbox.smtp_username);
                for (const provider of ['microsoft', 'google']) {
                    el('mailboxProvider').value = provider; toggleProviderFields();
                    check(provider + ' hides all SMTP authentication controls', !visible('#mailboxSmtpAuthEnabled') && !visible('.smtp-creds') && !visible('.smtp-auth-creds'));
                }
                await openMailboxModal(null);
                el('mailboxProvider').value = 'imap'; toggleProviderFields();
                check('new mailbox defaults to authentication and IMAP login', el('mailboxSmtpAuthEnabled').checked && el('mailboxSmtpSameAsImap').checked && visible('.smtp-auth-creds') && !visible('.smtp-creds'));
                window.fetch = realFetch;
                return JSON.stringify(checks);
            })()
JS, 30), true);
        }]);
        $check('browser settings regression', $browserExit === 0);
    }
} finally {
    foreach ($ids as $id) $conn->prepare('DELETE FROM target_mailboxes WHERE id = ?')->execute([$id]);
    if ($adminId) {
        $conn->prepare('DELETE FROM user_preferences WHERE analyst_id = ?')->execute([$adminId]);
        $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$adminId]);
    }
    if ($sessFile) @unlink($sessFile);
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
