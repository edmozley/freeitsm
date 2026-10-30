<?php
/**
 * Importing people, groups and group membership from Microsoft Entra ID.
 *
 * Third transport for directory sync, after LDAP (directory_sync.php) and
 * CardDAV (carddav_sync.php). Same shape as the CardDAV one, for the same
 * reason: the policy layer — `dsyncFindExisting()`, `dsyncApplyToExisting()`,
 * `dsyncCreate()`, `dsyncHandleMissing()`, `dsyncResolveManagers()` and the
 * sanity brake — is transport-agnostic, so this file only has to
 *
 *   1. read users from Microsoft Graph and turn each into the person array, and
 *   2. decide which users are in scope.
 *
 * Groups are the one genuinely new thing. They land in `directory_groups` and
 * `directory_group_members`, and their names are copied into
 * `sso_groups_seen.label`, because Entra sends group OBJECT IDS in the sign-in
 * `groups` claim and an analyst mapping "4f1c…" to a team has no idea what it is.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  WHICH PROVIDER, WHICH CREDENTIALS
 *
 *  An Entra source is not a new protocol. It is an existing OIDC provider whose
 *  issuer is login.microsoftonline.com, with `sync_enabled` switched on. The app
 *  registration that signs people in is, by default, the one that reads the
 *  directory: same client id, same secret. `graph_client_id` /
 *  `graph_client_secret` override that for organisations that keep application
 *  permissions on a separate registration (least privilege).
 *
 *  Graph APPLICATION permissions required, with admin consent:
 *    User.Read.All         — users, and their manager
 *    GroupMember.Read.All  — groups and (transitive) membership
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  🔴 THE IDENTITY TRAP. Entra's OIDC `sub` claim is PAIRWISE — unique per
 *  application, and NOT the user's object id. Sign-in stores `sub` as
 *  user_sso_identities.subject; Graph only knows the object id. Storing the
 *  object id as `subject` would give the same person two subjects for one
 *  provider, and the unique key (provider_id, user_id) would then make their
 *  next sign-in fail outright. So:
 *
 *    - the object id lives in `source_ref` (where the record lives in the
 *      source — exactly what that column is for);
 *    - a person sync creates gets a placeholder subject `entra:<oid>`, which no
 *      real `sub` can equal (a `sub` is base64url and has no colon);
 *    - oidc_callback.php recognises the `oid` claim against `source_ref` and
 *      swaps the placeholder for the real `sub` on first sign-in.
 *
 *  That is why the person handed to the policy layer has `guid` = '': it keeps
 *  dsyncLinkIdentity() — which writes `subject` — out of it entirely.
 */

require_once __DIR__ . '/directory_sync.php';   // the whole policy layer
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/ssl.php';

/** Hard ceiling on one run, same as the other transports. */
const ESYNC_MAX_PEOPLE = 20000;

/** Hard ceiling on groups imported in one run when the scope is "all groups". */
const ESYNC_MAX_GROUPS = 5000;

/** What Graph is asked for, per user. */
const ESYNC_USER_SELECT = 'id,userPrincipalName,mail,displayName,givenName,surname,jobTitle,department,'
                        . 'officeLocation,businessPhones,mobilePhone,employeeId,accountEnabled,userType';

const ESYNC_GROUP_SELECT = 'id,displayName,description,mail,groupTypes,securityEnabled,mailEnabled';

/**
 * Is this issuer Microsoft Entra ID?
 *
 * @param string $issuer  auth_providers.issuer_url
 */
function entraIsEntraIssuer(string $issuer): bool
{
    return (bool)preg_match('#^https://(login\.microsoftonline\.(com|us)|login\.chinacloudapi\.cn|sts\.windows\.net)/#i', trim($issuer));
}

/**
 * The tenant to read: the explicit override, else the one in the issuer URL.
 *
 * ⚠️ A multi-tenant issuer (`common`, `organizations`) names no directory, and
 * client-credentials against it fails with a message about the tenant that does
 * not say "you need to type your tenant id". Returned as '' so the caller can.
 *
 * @param array $provider  the auth_providers row - graph_tenant_id and issuer_url are read
 */
function entraTenantId(array $provider): string
{
    $set = trim((string)($provider['graph_tenant_id'] ?? ''));
    if ($set !== '') return $set;
    if (preg_match('#^https://[^/]+/([^/]+)#', trim((string)($provider['issuer_url'] ?? '')), $m)) {
        $t = strtolower($m[1]);
        if (!in_array($t, ['common', 'organizations', 'consumers'], true)) return $m[1];
    }
    return '';
}

/**
 * Login and Graph hosts for the provider's cloud. Global unless the issuer says
 * US Government or China, which have their own of both.
 *
 * @param array $provider  the auth_providers row - issuer_url is read
 * @return array ['login' => host, 'graph' => host]
 */
function entraHosts(array $provider): array
{
    $issuer = strtolower((string)($provider['issuer_url'] ?? ''));
    if (str_contains($issuer, 'login.microsoftonline.us')) {
        return ['login' => 'login.microsoftonline.us', 'graph' => 'graph.microsoft.us'];
    }
    if (str_contains($issuer, 'chinacloudapi.cn')) {
        return ['login' => 'login.chinacloudapi.cn', 'graph' => 'microsoftgraph.chinacloudapi.cn'];
    }
    return ['login' => 'login.microsoftonline.com', 'graph' => 'graph.microsoft.com'];
}

/**
 * Everything needed to talk to Graph for this provider, secrets decrypted.
 *
 * Throws with a sentence an administrator can act on when something is missing.
 *
 * @param array $provider  the auth_providers row AS STORED (secrets still encrypted)
 */
function entraConfig(array $provider): array
{
    $tenant = entraTenantId($provider);
    if ($tenant === '') {
        throw new RuntimeException('No Entra tenant id. The issuer URL uses a multi-tenant endpoint, so type the tenant id (Directory ID) on the Connection tab.');
    }
    $clientId = trim((string)($provider['graph_client_id'] ?? '')) ?: trim((string)($provider['client_id'] ?? ''));
    $secretEnc = (string)($provider['graph_client_secret'] ?? '');
    if ($secretEnc === '') $secretEnc = (string)($provider['client_secret'] ?? '');
    $secret = $secretEnc !== '' ? (string)decryptValue($secretEnc) : '';
    if ($clientId === '' || $secret === '') {
        throw new RuntimeException('No application (client) id or client secret. Reading the directory needs the app to authenticate as itself, so a secret is required even when sign-in works without one.');
    }
    $hosts = entraHosts($provider);
    return [
        'tenant'    => $tenant,
        'client_id' => $clientId,
        'secret'    => $secret,
        'login'     => $hosts['login'],
        'graph'     => 'https://' . $hosts['graph'] . '/v1.0',
    ];
}

/**
 * The stored provider row with the connection values ON THE FORM laid over it,
 * so Test connection and the group picker check what is about to be saved
 * rather than only ever confirming the last thing that was.
 *
 * A blank or masked secret means "the stored one", as everywhere else.
 *
 * @param array $row  the auth_providers row as stored
 * @param array $data  the request body - graph_tenant_id, graph_client_id, graph_client_secret, graph_use_own_app
 */
function entraApplyFormOverrides(array $row, array $data): array
{
    if (array_key_exists('graph_tenant_id', $data)) $row['graph_tenant_id'] = trim((string)$data['graph_tenant_id']);
    if (array_key_exists('graph_use_own_app', $data) && empty($data['graph_use_own_app'])) {
        // "Use the sign-in app registration": the overrides do not apply.
        $row['graph_client_id'] = '';
        $row['graph_client_secret'] = '';
        return $row;
    }
    if (array_key_exists('graph_client_id', $data)) $row['graph_client_id'] = trim((string)$data['graph_client_id']);
    $secret = (string)($data['graph_client_secret'] ?? '');
    if (!isMaskedNoChangeValue($secret)) $row['graph_client_secret'] = encryptValue($secret);
    return $row;
}

/**
 * An app-only access token for Graph (client credentials), cached per app per request.
 *
 * @param array $cfg  from entraConfig()
 */
function entraToken(array $cfg): string
{
    static $cache = [];
    $key = $cfg['tenant'] . '|' . $cfg['client_id'];
    if (isset($cache[$key]) && time() < $cache[$key]['exp'] - 60) return $cache[$key]['token'];

    $ch = curl_init('https://' . $cfg['login'] . '/' . rawurlencode($cfg['tenant']) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['secret'],
            'scope'         => 'https://' . parse_url($cfg['graph'], PHP_URL_HOST) . '/.default',
            'grant_type'    => 'client_credentials',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    sslApplyCurl($ch);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $err !== '') throw new RuntimeException('Could not reach Microsoft sign-in: ' . $err);
    $data = json_decode((string)$resp, true) ?: [];
    if ($code !== 200 || empty($data['access_token'])) {
        throw new RuntimeException(entraExplainTokenError((string)($data['error_description'] ?? $resp)));
    }
    $cache[$key] = ['token' => $data['access_token'], 'exp' => time() + (int)($data['expires_in'] ?? 3600)];
    return $data['access_token'];
}

/**
 * Turn the AADSTS codes people actually hit into what to go and fix.
 *
 * The raw description is kept on the end: the code is what Microsoft's own
 * documentation is searchable by.
 *
 * @param string $raw  error_description from the token endpoint
 */
function entraExplainTokenError(string $raw): string
{
    $first = trim(strtok($raw, "\r\n") ?: $raw);
    $hints = [
        'AADSTS7000215' => 'The client secret is wrong. Note that Entra shows the secret VALUE only once; the "Secret ID" is not the secret.',
        'AADSTS7000222' => 'The client secret has expired. Create a new one in the app registration.',
        'AADSTS700016'  => 'No application with this client id exists in this tenant. Check the client id and the tenant id.',
        'AADSTS90002'   => 'The tenant id was not found. Use the Directory (tenant) ID from the Overview page of the app registration.',
        'AADSTS900023'  => 'The tenant id is not valid.',
    ];
    foreach ($hints as $code => $hint) {
        if (str_contains($raw, $code)) return $hint . ' (' . $first . ')';
    }
    return 'Microsoft refused the app credentials: ' . $first;
}

/**
 * GET one Graph URL. Retries on 429/5xx honouring Retry-After.
 *
 * Throws EntraGraphError carrying the HTTP status, so a caller can tell "this
 * group no longer exists" (404) from "the app may not read groups" (403).
 *
 * @param array $cfg  from entraConfig()
 * @param string $url  a path under /v1.0 (leading slash), or an absolute @odata.nextLink
 */
function entraGet(array $cfg, string $url, int $maxRetries = 5): array
{
    if ($url !== '' && $url[0] === '/') $url = $cfg['graph'] . $url;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . entraToken($cfg),
                'Accept: application/json',
                // Required for $search and $count; harmless everywhere else.
                'ConsistencyLevel: eventual',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        sslApplyCurl($ch);
        $raw    = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            if ($attempt === $maxRetries) throw new EntraGraphError('Could not reach Microsoft Graph: ' . $err, 0);
            sleep(min(30, 2 ** $attempt));
            continue;
        }
        $headers = substr($raw, 0, $hdrLen);
        $body    = substr($raw, $hdrLen);

        if ($code === 200) {
            $json = json_decode($body, true);
            if (!is_array($json)) throw new EntraGraphError('Microsoft Graph returned something that is not JSON.', 200);
            return $json;
        }
        if (($code === 429 || $code >= 500) && $attempt < $maxRetries) {
            $wait = preg_match('/^Retry-After:\s*(\d+)/im', $headers, $m) ? (int)$m[1] : 2 ** $attempt;
            sleep(max(1, min(60, $wait)));
            continue;
        }

        $msg = json_decode($body, true)['error']['message'] ?? trim($body);
        if ($code === 403) {
            throw new EntraGraphError('The app is not allowed to read this (' . $msg . '). Give the app registration the Microsoft Graph '
                . 'APPLICATION permissions User.Read.All and GroupMember.Read.All, then press "Grant admin consent".', 403);
        }
        throw new EntraGraphError('Microsoft Graph said HTTP ' . $code . ': ' . $msg, $code);
    }
    throw new EntraGraphError('Microsoft Graph could not be reached.', 0);
}

/** A Graph failure that remembers its HTTP status. */
class EntraGraphError extends RuntimeException
{
    public int $status;
    public function __construct(string $message, int $status)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/**
 * Every page of a Graph collection, following @odata.nextLink.
 *
 * ⚠️ PAGED, for the same reason the LDAP search is: Graph returns one page and
 * stops, and an unpaged read of a real tenant imports the first hundred people
 * and reports complete success.
 *
 * @param array $cfg  from entraConfig()
 * @param string $path  the first page's path under /v1.0
 * @param int $max  stop (with an exception) beyond this many items
 */
function entraGetAll(array $cfg, string $path, int $max): array
{
    $out = [];
    $url = $path;
    while ($url !== '') {
        $page = entraGet($cfg, $url);
        foreach ($page['value'] ?? [] as $item) {
            $out[] = $item;
            if (count($out) > $max) {
                throw new RuntimeException('More than ' . $max . ' items came back from ' . strtok($path, '?')
                    . '. Narrow the scope to the groups you actually want.');
            }
        }
        $url = (string)($page['@odata.nextLink'] ?? '');
    }
    return $out;
}

/**
 * A stored list of group object ids (one per line) as an array: lower-cased,
 * de-duplicated, and anything that is not a GUID dropped.
 *
 * @param ?string $raw  graph_user_groups or graph_groups as stored
 */
function entraIdList(?string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$raw) as $id) {
        $id = strtolower(trim($id));
        if (entraIsGuid($id)) $out[$id] = true;
    }
    return array_keys($out);
}

/** @param string $s  anything */
function entraIsGuid(string $s): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

/**
 * One Graph user as the person array the policy layer expects.
 * Null when the user has no object id (which Graph never does, but a bad page
 * must not become a person with no identity).
 *
 * Choices made in the open:
 *   email    — `mail`; failing that the UPN, IF it is a real address and not a
 *              guest's `#EXT#` one. Tenants without Exchange licences leave
 *              `mail` empty for everybody, and a service desk that cannot email
 *              anyone is a worse outcome than using the sign-in address.
 *   username — the UPN. What the person types to sign in to Microsoft, and what
 *              an analyst recognises; it is stored per provider, not globally.
 *   phone    — the first of `businessPhones`.
 *   office   — `officeLocation`.
 *
 * @param array $u  one item of /users
 */
function entraMapUser(array $u): ?array
{
    $oid = strtolower(trim((string)($u['id'] ?? '')));
    if ($oid === '') return null;

    $upn   = trim((string)($u['userPrincipalName'] ?? ''));
    $email = strtolower(trim((string)($u['mail'] ?? '')));
    if ($email === '' && $upn !== '' && !str_contains($upn, '#EXT#') && filter_var($upn, FILTER_VALIDATE_EMAIL)) {
        $email = strtolower($upn);
    }

    $name = trim((string)($u['displayName'] ?? ''));
    if ($name === '') $name = trim(($u['givenName'] ?? '') . ' ' . ($u['surname'] ?? ''));
    if ($name === '') $name = $upn !== '' ? $upn : $oid;

    $phones = $u['businessPhones'] ?? [];
    $phone  = is_array($phones) && $phones ? trim((string)$phones[0]) : '';
    $mgr    = strtolower(trim((string)($u['manager']['id'] ?? '')));

    $nz = fn($v) => ($v = trim((string)$v)) !== '' ? $v : null;
    return [
        // Deliberately '' — see the identity note at the top of this file.
        'guid'        => '',
        'entra_id'    => $oid,
        'username'    => $upn,
        'email'       => $email !== '' ? $email : null,
        'name'        => $name,
        'job_title'   => $nz($u['jobTitle'] ?? ''),
        'department'  => $nz($u['department'] ?? ''),
        'office'      => $nz($u['officeLocation'] ?? ''),
        'phone'       => $phone !== '' ? $phone : null,
        'mobile'      => $nz($u['mobilePhone'] ?? ''),
        'employee_id' => $nz($u['employeeId'] ?? ''),
        // The manager pass keys on 'dn'. Here both are object ids, which is
        // exactly the job a DN does there: a stable name for one person.
        'manager_dn'  => $mgr !== '' ? $mgr : null,
        'dn'          => $oid,
        // Only an explicit false disables. Absent means Graph did not say, and
        // we never invent a reason to mark somebody as left.
        'disabled'    => ($u['accountEnabled'] ?? true) === false,
        'is_guest'    => strcasecmp((string)($u['userType'] ?? 'Member'), 'Guest') === 0,
    ];
}

/**
 * Object ids of every USER that is a member — directly or through nested
 * groups — of any of these groups.
 *
 * A group that no longer exists throws: a missing include is the dangerous
 * direction (everybody in it starts counting towards being marked as left), so
 * the run refuses rather than going ahead without it.
 *
 * @param array $cfg  from entraConfig()
 * @param array $groupIds  object ids
 */
function entraMemberIds(array $cfg, array $groupIds): array
{
    $ids = [];
    foreach ($groupIds as $gid) {
        try {
            $members = entraGetAll($cfg, '/groups/' . rawurlencode($gid)
                . '/transitiveMembers/microsoft.graph.user?$select=id&$top=999', ESYNC_MAX_PEOPLE);
        } catch (EntraGraphError $e) {
            if ($e->status === 404) {
                throw new RuntimeException('Stopped without changing anything: the group ' . $gid
                    . ' no longer exists in Entra, so nobody in it can be found. Choose the groups again on the Importing tab.');
            }
            throw $e;
        }
        foreach ($members as $m) {
            $id = strtolower((string)($m['id'] ?? ''));
            if ($id !== '') $ids[$id] = true;
        }
    }
    return $ids;
}

/**
 * The FreeITSM record for an Entra user.
 *
 *   1. the object id, in user_sso_identities.source_ref — survives renames
 *   2. dsyncFindExisting(): directory username (UPN), then email
 *
 * An email match on somebody ALREADY assigned to this provider is reported as
 * 'sso' rather than 'email': that is a person who has been signing in through
 * this very app registration, not one being taken over from a local password,
 * and the conflict setting exists for the second case only.
 *
 * @param array $provider  the auth_providers row
 * @param array $p  the person from entraMapUser()
 * @return array [row|null, how]
 */
function entraFindExisting(PDO $conn, array $provider, array $p): array
{
    $s = $conn->prepare(
        "SELECT u.* FROM user_sso_identities i JOIN users u ON u.id = i.user_id
          WHERE i.provider_id = ? AND i.source_ref = ? LIMIT 1"
    );
    $s->execute([(int)$provider['id'], $p['entra_id']]);
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) return [$row, 'guid'];

    [$row, $how] = dsyncFindExisting($conn, $provider, $p);
    if ($row && $how === 'email' && (int)($row['auth_provider_id'] ?? 0) === (int)$provider['id']) {
        $how = 'sso';
    }
    return [$row, $how];
}

/**
 * Record the object id against the person, without ever touching a real `sub`.
 *
 * If they already have an identity row for this provider (they have signed in),
 * only source_ref is set on it. Otherwise one is created with the placeholder
 * subject `entra:<oid>`, which the OIDC callback replaces on first sign-in.
 *
 * @param int $providerId  auth_providers.id
 * @param int $userId  users.id
 * @param array $p  the person from entraMapUser()
 */
function entraLinkIdentity(PDO $conn, int $providerId, int $userId, array $p): void
{
    try {
        $s = $conn->prepare("SELECT id FROM user_sso_identities WHERE provider_id = ? AND user_id = ?");
        $s->execute([$providerId, $userId]);
        $rowId = $s->fetchColumn();
        if ($rowId) {
            $conn->prepare("UPDATE user_sso_identities SET source_ref = ?, email = COALESCE(?, email) WHERE id = ?")
                 ->execute([$p['entra_id'], $p['email'], (int)$rowId]);
            return;
        }
        $conn->prepare(
            "INSERT INTO user_sso_identities (user_id, provider_id, subject, email, source_ref, linked_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$userId, $providerId, 'entra:' . $p['entra_id'], $p['email'], $p['entra_id']]);
    } catch (Throwable $e) {
        // Same rule as dsyncLinkIdentity(): the person is imported either way,
        // and the next run finds them by UPN.
        error_log('[esync] identity link failed: ' . $e->getMessage());
    }
}

/**
 * Do the group tables exist yet? An install that has not run Database
 * Verification since this arrived imports people and says groups are waiting.
 */
function entraGroupTablesReady(PDO $conn): bool
{
    try {
        return (bool)$conn->query("SHOW TABLES LIKE 'directory_group_members'")->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Which kind of group this is, in the words the Entra portal uses.
 *
 * @param array $g  one item of /groups
 */
function entraGroupKind(array $g): string
{
    $types = array_map('strtolower', (array)($g['groupTypes'] ?? []));
    if (in_array('unified', $types, true)) return 'microsoft365';
    if (!empty($g['securityEnabled'])) return !empty($g['mailEnabled']) ? 'mail-security' : 'security';
    return 'distribution';
}

/**
 * The groups in scope, as Graph items.
 *
 * 'selected' reads each chosen group on its own; a group that has gone is
 * returned in $gone rather than failing the run — losing a group is not
 * dangerous the way losing a people-scope group is, it only stops updating.
 *
 * @param array $cfg  from entraConfig()
 * @param array $provider  graph_group_scope and graph_groups are read
 * @return array [groups, gone ids]
 */
function entraFetchGroups(array $cfg, array $provider): array
{
    $scope = (string)($provider['graph_group_scope'] ?? 'none');
    if ($scope === 'all') {
        return [entraGetAll($cfg, '/groups?$select=' . ESYNC_GROUP_SELECT . '&$top=999', ESYNC_MAX_GROUPS), []];
    }
    if ($scope !== 'selected') return [[], []];

    $groups = []; $gone = [];
    foreach (entraIdList($provider['graph_groups'] ?? '') as $gid) {
        try {
            $groups[] = entraGet($cfg, '/groups/' . rawurlencode($gid) . '?$select=' . ESYNC_GROUP_SELECT);
        } catch (EntraGraphError $e) {
            if ($e->status !== 404) throw $e;
            $gone[] = $gid;
        }
    }
    return [$groups, $gone];
}

/**
 * Bring groups and their membership in line with Entra.
 *
 * Membership is TRANSITIVE: somebody in a nested group counts as a member,
 * because "who is in Finance" is a question about people, and the answer an
 * approver means includes the Finance-Payroll subgroup.
 *
 * Only people FreeITSM knows are linked. A member outside the people scope is
 * counted, not created — importing people is the user scope's job, and a group
 * quietly widening it would undo the choice made there.
 *
 * Groups are never deleted, like people: one that leaves the scope is marked
 * inactive and keeps its last known members.
 *
 * @param array $cfg  from entraConfig()
 * @param array $provider  the auth_providers row
 * @param array $oidToUserId  object id => users.id for everybody matched or created this run
 * @param int $runId  directory_sync_runs.id
 * @param bool $preview  true to write nothing
 * @return array summary counts
 */
function entraSyncGroups(PDO $conn, array $cfg, array $provider, array $oidToUserId, int $runId, bool $preview): array
{
    $pid = (int)$provider['id'];
    $sum = ['groups' => 0, 'new' => 0, 'gone' => 0, 'added' => 0, 'removed' => 0, 'outside' => 0];

    [$groups, $goneIds] = entraFetchGroups($cfg, $provider);

    // Everybody this provider has ever linked, not just this run's people: in
    // 'groups' user scope the membership of an unrelated group can still
    // include somebody imported earlier.
    $s = $conn->prepare("SELECT LOWER(source_ref) r, user_id FROM user_sso_identities WHERE provider_id = ? AND source_ref IS NOT NULL");
    $s->execute([$pid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($oidToUserId[$r['r']])) $oidToUserId[$r['r']] = (int)$r['user_id'];
    }

    $seenGroupIds = [];
    foreach ($groups as $g) {
        $gid  = strtolower((string)($g['id'] ?? ''));
        if ($gid === '') continue;
        $name = trim((string)($g['displayName'] ?? '')) ?: $gid;
        $sum['groups']++;

        $s = $conn->prepare("SELECT * FROM directory_groups WHERE provider_id = ? AND external_id = ?");
        $s->execute([$pid, $gid]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;

        $members = entraGetAll($cfg, '/groups/' . rawurlencode($gid)
            . '/transitiveMembers/microsoft.graph.user?$select=id&$top=999', ESYNC_MAX_PEOPLE);
        $want = [];
        foreach ($members as $m) {
            $oid = strtolower((string)($m['id'] ?? ''));
            if ($oid === '') continue;
            if (isset($oidToUserId[$oid]) && $oidToUserId[$oid] > 0) $want[$oidToUserId[$oid]] = true;
            else $sum['outside']++;
        }

        $have = [];
        if ($row) {
            $s = $conn->prepare("SELECT user_id FROM directory_group_members WHERE group_id = ?");
            $s->execute([(int)$row['id']]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) $have[(int)$uid] = true;
        }
        $add = array_keys(array_diff_key($want, $have));
        $del = array_keys(array_diff_key($have, $want));
        $sum['added']   += count($add);
        $sum['removed'] += count($del);

        if (!$preview) {
            $vals = [$name, mb_substr((string)($g['description'] ?? ''), 0, 1000) ?: null,
                     ($g['mail'] ?? '') ?: null, entraGroupKind($g), count($want)];
            if ($row) {
                $conn->prepare(
                    "UPDATE directory_groups SET display_name = ?, description = ?, mail = ?, group_kind = ?, member_count = ?,
                            is_active = 1, last_seen_in_source = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP()
                      WHERE id = ?"
                )->execute(array_merge($vals, [(int)$row['id']]));
                $groupId = (int)$row['id'];
            } else {
                $conn->prepare(
                    "INSERT INTO directory_groups (display_name, description, mail, group_kind, member_count,
                            provider_id, external_id, is_active, last_seen_in_source, created_datetime, updated_datetime)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
                )->execute(array_merge($vals, [$pid, $gid]));
                $groupId = (int)$conn->lastInsertId();
            }
            if ($add) {
                $ins = $conn->prepare("INSERT IGNORE INTO directory_group_members (group_id, user_id, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())");
                foreach ($add as $uid) $ins->execute([$groupId, $uid]);
            }
            if ($del) {
                $conn->prepare("DELETE FROM directory_group_members WHERE group_id = ? AND user_id IN ("
                    . implode(',', array_fill(0, count($del), '?')) . ")")->execute(array_merge([$groupId], $del));
            }
            $seenGroupIds[] = $groupId;
            entraLabelSeenGroup($conn, $pid, $gid, $name);
        } elseif ($row) {
            $seenGroupIds[] = (int)$row['id'];
        }

        if (!$row) $sum['new']++;
        $action = !$row ? 'create' : (($add || $del || $row['display_name'] !== $name) ? 'update' : 'unchanged');
        dsyncLogEntry($conn, $runId, $action, null, ['username' => 'group', 'name' => 'Group: ' . $name],
            sprintf('%d member(s) here; %d added, %d removed.', count($want), count($add), count($del)));
    }

    // Groups this provider imported before that are no longer in scope.
    $sql  = "SELECT id, display_name FROM directory_groups WHERE provider_id = ? AND is_active = 1";
    $args = [$pid];
    if ($seenGroupIds) {
        $sql .= " AND id NOT IN (" . implode(',', array_fill(0, count($seenGroupIds), '?')) . ")";
        $args = array_merge($args, $seenGroupIds);
    }
    // ⚠️ Same instinct as the people brake: "all groups" returning nothing is
    // far more likely a permission than an empty tenant, so nothing is retired.
    $scope = (string)($provider['graph_group_scope'] ?? 'none');
    if (!($scope === 'all' && !$groups)) {
        $s = $conn->prepare($sql);
        $s->execute($args);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $old) {
            $sum['gone']++;
            if (!$preview) {
                $conn->prepare("UPDATE directory_groups SET is_active = 0, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([(int)$old['id']]);
            }
            dsyncLogEntry($conn, $runId, 'deactivate', null, ['username' => 'group', 'name' => 'Group: ' . $old['display_name']],
                ($preview ? 'Would be marked' : 'Marked') . ' as inactive — no longer in the groups being imported. Its members are kept as they were.');
        }
    }
    foreach ($goneIds as $gid) {
        dsyncLogEntry($conn, $runId, 'error', null, ['username' => 'group', 'name' => 'Group: ' . $gid],
            'This group no longer exists in Entra. Remove it from the groups to import.');
    }
    return $sum;
}

/**
 * Give an Entra group its name in the sign-in claim mapping screens.
 *
 * Entra's `groups` claim carries object ids. The mapping dropdowns read
 * `sso_groups_seen`, so the group is added there and named — but an admin's
 * own label is never overwritten, and `last_seen_datetime` is left alone
 * because it means "seen at sign-in", which this is not.
 *
 * @param int $pid  auth_providers.id
 * @param string $gid  the group's object id
 * @param string $name  its display name
 */
function entraLabelSeenGroup(PDO $conn, int $pid, string $gid, string $name): void
{
    try {
        $conn->prepare(
            "INSERT INTO sso_groups_seen (provider_id, group_name, label, first_seen_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE label = COALESCE(label, VALUES(label))"
        )->execute([$pid, $gid, mb_substr($name, 0, 100)]);
    } catch (Throwable $e) {
        // Older install without the 3.5.0 tables: a nicety, never a failure.
    }
}

/**
 * Run an import for one Entra provider.
 *
 * Mirrors cardDavSyncRun(): same run table, same counts, same status words
 * ('ok' | 'stopped' | 'failed'), same return shape — the CLI's exit code and
 * the History tab both depend on that.
 *
 * @param array $provider  the auth_providers row as stored (protocol 'oidc', secrets still encrypted)
 * @param string $mode  'live' | 'preview'
 */
function entraSyncRun(PDO $conn, array $provider, string $mode = 'live', ?int $analystId = null): array
{
    $preview = ($mode === 'preview');
    $pid     = (int)$provider['id'];

    $conn->prepare(
        "INSERT INTO directory_sync_runs (provider_id, mode, status, started_datetime, triggered_by_analyst_id)
         VALUES (?, ?, 'running', UTC_TIMESTAMP(), ?)"
    )->execute([$pid, $preview ? 'preview' : 'live', $analystId]);
    $runId = (int)$conn->lastInsertId();

    $counts = ['seen' => 0, 'created' => 0, 'updated' => 0, 'adopted' => 0,
               'deactivated' => 0, 'conflict' => 0, 'error' => 0];
    $status  = 'ok';
    $message = '';

    try {
        $cfg = entraConfig($provider);

        // --- who is in scope -------------------------------------------------
        $userScope = (string)($provider['graph_user_scope'] ?? 'all');
        $inScope   = null;   // null = everybody
        if ($userScope === 'groups') {
            $gids = entraIdList($provider['graph_user_groups'] ?? '');
            if (!$gids) {
                throw new RuntimeException('People are imported from chosen groups, but no group is chosen. Pick at least one on the Importing tab.');
            }
            $inScope = entraMemberIds($cfg, $gids);
            // Same reasoning as CardDAV's empty-scope stop: an emptied group
            // is far likelier than everybody leaving at once.
            if (!$inScope) {
                throw new RuntimeException('Stopped without changing anything: the chosen groups have no members. '
                    . 'That is far more often a group emptied or replaced than everybody leaving, so nobody has been touched.');
            }
        }

        // Manager is expanded inline: one request per page rather than one per person.
        $raw = entraGetAll($cfg, '/users?$select=' . ESYNC_USER_SELECT . '&$expand=manager($select=id)&$top=100',
            $inScope === null ? ESYNC_MAX_PEOPLE : PHP_INT_MAX);

        $includeGuests = (int)($provider['graph_include_guests'] ?? 0) === 1;
        $people = [];
        foreach ($raw as $u) {
            $p = entraMapUser($u);
            if ($p === null) continue;
            if ($inScope !== null && !isset($inScope[$p['entra_id']])) continue;
            if ($p['is_guest'] && !$includeGuests) continue;
            $people[] = $p;
        }
        if (count($people) > ESYNC_MAX_PEOPLE) {
            throw new RuntimeException('More than ' . ESYNC_MAX_PEOPLE . ' people are in scope. Narrow it to the groups you want.');
        }
        $counts['seen'] = count($people);

        // --- Rule 2: a run that looks wrong changes nothing --------------------
        $brake = syncBrakeTripped($provider, $counts['seen'], [
            'source' => 'Entra directory',
            'causes' => 'a group taken out of the scope, or the app losing its Graph permissions',
        ]);
        if ($brake !== null) {
            dsyncFinishRun($conn, $runId, 'stopped', $counts, $brake);
            return dsyncGetRun($conn, $runId);
        }

        // --- the policy layer, reused verbatim ---------------------------------
        $seenUserIds = [];
        $oidToUserId = [];
        foreach ($people as $p) {
            try {
                [$existing, $how] = entraFindExisting($conn, $provider, $p);

                if ($existing && $how === 'email'
                    && (string)($provider['sync_on_conflict'] ?? 'adopt') !== 'adopt') {
                    $counts['conflict']++;
                    dsyncLogEntry($conn, $runId, 'conflict', (int)$existing['id'], $p,
                        'Already exists here with the same email address. Left untouched, because this '
                        . 'directory is set to flag conflicts rather than adopt them.');
                    continue;
                }

                if ($existing) {
                    $uid = (int)$existing['id'];
                    $seenUserIds[] = $uid;
                    $oidToUserId[$p['entra_id']] = $uid;
                    $changes = dsyncApplyToExisting($conn, $provider, $existing, $p, $preview);
                    if (!$preview) entraLinkIdentity($conn, $pid, $uid, $p);
                    if ($how === 'email') {
                        $counts['adopted']++;
                        dsyncLogEntry($conn, $runId, 'adopt', $uid, $p,
                            'Matched an existing person by email address and linked them to Entra. '
                            . '⚠️ They now sign in with Microsoft, so any portal password they had no longer works.'
                            . ($changes ? ' Also updated: ' . $changes : ''));
                    } elseif ($changes !== '') {
                        $counts['updated']++;
                        dsyncLogEntry($conn, $runId, 'update', $uid, $p, $changes);
                    } else {
                        dsyncLogEntry($conn, $runId, 'unchanged', $uid, $p, '');
                    }
                    continue;
                }

                $newId = dsyncCreate($conn, $provider, $p, $preview);
                if ($newId === null) {
                    $counts['error']++;
                    dsyncLogEntry($conn, $runId, 'error', null, $p, 'Could not be created — see the server error log.');
                } else {
                    $counts['created']++;
                    if ($newId > 0) {
                        $seenUserIds[] = $newId;
                        $oidToUserId[$p['entra_id']] = $newId;
                        entraLinkIdentity($conn, $pid, $newId, $p);
                    }
                    dsyncLogEntry($conn, $runId, 'create', $newId ?: null, $p, dsyncNewPersonSummary($p));
                }
            } catch (Throwable $e) {
                $counts['error']++;
                dsyncLogEntry($conn, $runId, 'error', null, $p, 'Could not be imported: ' . $e->getMessage());
                error_log('[esync] ' . $p['entra_id'] . ': ' . $e->getMessage());
            }
        }

        // Reporting lines: object id => users.id, the map the manager pass reads.
        dsyncResolveManagers($conn, $people, $oidToUserId, $runId, $preview);

        // Rules 1 and 3: nobody is deleted, and missing once is noise.
        $counts['deactivated'] = dsyncHandleMissing($conn, $provider, $seenUserIds, $runId, $preview);

        $message = sprintf(
            'People read: %d. Created: %d. Updated: %d. Adopted: %d. Marked as left: %d.',
            $counts['seen'], $counts['created'], $counts['updated'], $counts['adopted'], $counts['deactivated']
        );
        if ($counts['error'] > 0)    $message .= sprintf(' Could not be imported: %d.', $counts['error']);
        if ($counts['conflict'] > 0) $message .= sprintf(' Left alone as a conflict: %d.', $counts['conflict']);

        // --- groups ------------------------------------------------------------
        // After people, because membership can only point at people who exist.
        if ((string)($provider['graph_group_scope'] ?? 'none') !== 'none') {
            if (!entraGroupTablesReady($conn)) {
                $message .= ' Groups were not imported: run Database Verification (System → Database) first.';
            } else {
                try {
                    $g = entraSyncGroups($conn, $cfg, $provider, $oidToUserId, $runId, $preview);
                    $message .= sprintf(' Groups: %d (new: %d, no longer imported: %d). Memberships added: %d, removed: %d.',
                        $g['groups'], $g['new'], $g['gone'], $g['added'], $g['removed']);
                    if ($g['outside'] > 0) {
                        $message .= sprintf(' Members not imported as people (outside the people scope, or guests): %d.', $g['outside']);
                    }
                    if ($preview && $g['added'] > 0) {
                        $message .= ' (A preview cannot count memberships of people it has not created yet.)';
                    }
                } catch (Throwable $e) {
                    // People are already done and correct; a group failure must
                    // not turn their run into a "failed" one that hides that.
                    $counts['error']++;
                    $message .= ' Groups could not be imported: ' . $e->getMessage();
                    error_log('[esync] groups for provider ' . $pid . ': ' . $e->getMessage());
                }
            }
        }

        if (!$preview) {
            $conn->prepare("UPDATE auth_providers SET sync_last_run_datetime = UTC_TIMESTAMP(), sync_last_count = ? WHERE id = ?")
                 ->execute([$counts['seen'], $pid]);
        }
    } catch (Throwable $e) {
        $status  = 'failed';
        $message = $e->getMessage();
        error_log('[esync] provider ' . $pid . ' failed: ' . $e->getMessage());
    }

    dsyncFinishRun($conn, $runId, $status, $counts, $message);
    return dsyncGetRun($conn, $runId);
}
