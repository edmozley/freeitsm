<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Synthetic local-only integration fixtures. No install settings are changed. */
function ticketWorkspaceFixture(): array
{
    $url = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Set FREEITSM_TEST_URL to the local development installation.');
    }
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__, 2) . '/includes/functions.php';
    $conn = connectToDatabase();
    $expected = getenv('FREEITSM_TEST_DATABASE');
    if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
        throw new RuntimeException('Set FREEITSM_TEST_DATABASE to the dedicated development database.');
    }
    $tag = bin2hex(random_bytes(5));
    $made = ['tenants'=>[], 'analysts'=>[], 'tickets'=>[], 'tasks'=>[]];
    $password = bin2hex(random_bytes(20));
    $insert = function (string $table, array $values) use ($conn, &$made): int {
        $columns = implode(',', array_keys($values));
        $marks = implode(',', array_fill(0, count($values), '?'));
        $conn->prepare("INSERT INTO $table ($columns) VALUES ($marks)")->execute(array_values($values));
        $id = (int)$conn->lastInsertId();
        $made[$table][] = $id;
        return $id;
    };
    $cleanup = function () use ($conn, &$made): void {
        // Evidence can deliberately outlive a deleted real task. These are only
        // the synthetic IDs owned by this test, so remove their evidence too.
        foreach ($made['tasks'] as $id) {
            $conn->prepare('DELETE FROM task_events WHERE task_id=?')->execute([$id]);
        }
        foreach ($made['tickets'] as $id) {
            $conn->prepare('DELETE FROM ticket_notes WHERE ticket_id=?')->execute([$id]);
            $conn->prepare('DELETE FROM ticket_time_entries WHERE ticket_id=?')->execute([$id]);
        }
        foreach (array_reverse(array_keys($made)) as $table) {
            foreach (array_reverse($made[$table]) as $id) {
                $conn->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
            }
        }
    };
    try {
        $tenant = $insert('tenants', ['name'=>"ZZ workspace $tag", 'is_active'=>1]);
        $other = $insert('tenants', ['name'=>"ZZ other $tag", 'is_active'=>1]);
        $analysts = [];
        foreach (['full', 'read', 'none'] as $access) {
            $username = "zz-workspace-$tag-$access";
            $id = $insert('analysts', ['username'=>$username, 'email'=>"$username@example.invalid", 'full_name'=>'ZZ Workspace analyst',
                'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'is_active'=>1, 'is_admin'=>0,
                'can_access_all_tenants'=>0, 'can_access_all_modules'=>0, 'password_changed_datetime'=>gmdate('Y-m-d H:i:s')]);
            $conn->prepare('INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)')->execute([$id,$tenant]);
            foreach (['tickets','tasks'] as $module) {
                if ($access === 'none' && $module === 'tasks') continue;
                $conn->prepare('INSERT INTO analyst_modules (analyst_id,module_key,access_level) VALUES (?,?,?)')->execute([$id,$module,$access==='read'?'read':'full']);
            }
            $analysts[$access] = ['id'=>$id, 'username'=>$username, 'password'=>$password];
        }
        $ticket = $insert('tickets', ['ticket_number'=>"ZZ-$tag",'subject'=>'ZZ Workspace ticket','tenant_id'=>$tenant]);
        $otherTicket = $insert('tickets', ['ticket_number'=>"ZZ-$tag-2",'subject'=>'ZZ Other ticket','tenant_id'=>$other]);
        $task = $insert('tasks', ['title'=>'ZZ Workspace task','description'=>'ZZ Work instructions','ticket_id'=>$ticket,'tenant_id'=>$tenant,'created_by_id'=>$analysts['full']['id']]);
        $otherTask = $insert('tasks', ['title'=>'ZZ Other task','ticket_id'=>$otherTicket,'tenant_id'=>$other,'created_by_id'=>$analysts['full']['id']]);
        return compact('url','conn','analysts','tenant','other','ticket','otherTicket','task','otherTask','cleanup','insert');
    } catch (Throwable $error) {
        $cleanup();
        throw $error;
    }
}

function ticketWorkspaceHttp($curl, string $url, ?array $body = null, ?string $csrf = null): array
{
    curl_setopt_array($curl, [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_TIMEOUT=>20, CURLOPT_COOKIEFILE=>'', CURLOPT_POST=>$body !== null,
        CURLOPT_HTTPHEADER=>$csrf ? ['Content-Type: application/json', 'X-CSRF-Token: '.$csrf] : []]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $csrf ? json_encode($body) : http_build_query($body));
    $content = curl_exec($curl);
    if ($content === false) throw new RuntimeException('Local HTTP request failed.');
    return ['status'=>(int)curl_getinfo($curl, CURLINFO_HTTP_CODE),'body'=>$content,'json'=>json_decode($content,true)];
}

function ticketWorkspaceLogin(string $url, array $analyst): array
{
    $curl = curl_init();
    $login = ticketWorkspaceHttp($curl, $url.'/auth/login.php', ['username'=>$analyst['username'],'password'=>$analyst['password']]);
    if ($login['status'] !== 302) throw new RuntimeException('Synthetic local login failed.');
    $page = ticketWorkspaceHttp($curl, $url.'/tickets/');
    if ($page['status'] !== 200 || !preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $match)) {
        throw new RuntimeException('Authenticated local ticket page or CSRF token unavailable.');
    }
    return [$curl, html_entity_decode($match[1])];
}
