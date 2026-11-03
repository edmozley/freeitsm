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

/**
 * Hard ceiling on users READ when the scope is chosen groups. Graph can only list
 * the directory and filter here, so a small scope in a big tenant still reads it
 * all. Higher than ESYNC_MAX_PEOPLE, which limits who is imported.
 */
const ESYNC_MAX_SCAN = 200000;

/** What Graph is asked for, per user. */
const ESYNC_USER_SELECT = 'id,userPrincipalName,mail,displayName,givenName,surname,jobTitle,department,'
                        . 'officeLocation,businessPhones,mobilePhone,employeeId,accountEnabled,userType';

const ESYNC_GROUP_SELECT = 'id,displayName,description,mail,groupTypes,securityEnabled,mailEnabled';

/**
 * Which Entra attribute fills each person field when the provider does not say.
 *
 * The same model as LDAP's dsyncAttrDefaults() / ldap_attr_*: one attribute per
 * field, set per provider in graph_attr_<field>, and a blank box meaning this
 * default. Two fields are deliberately not here: the unique id is always the
 * object id (`id`), which the identity scheme at the top of this file depends
 * on, and the manager is a Graph relationship (`$expand=manager`), not an
 * attribute that could be pointed elsewhere.
 */
const ESYNC_ATTR_DEFAULTS = [
    'name'        => 'displayName',
    'username'    => 'userPrincipalName',
    'email'       => 'mail',
    'job_title'   => 'jobTitle',
    'department'  => 'department',
    'office'      => 'officeLocation',
    'phone'       => 'businessPhones',
    'mobile'      => 'mobilePhone',
    'employee_id' => 'employeeId',
];

/**
 * Can this be used as an Entra attribute name? A user property, or one level
 * into a complex one - `onPremisesExtensionAttributes/extensionAttribute5`,
 * `employeeOrgData/division` - including a tenant's own directory extensions
 * (`extension_<appId>_<name>`).
 *
 * 🔴 It ends up in a Graph URL ($select). Anything that is not plainly a name is
 * refused, rather than escaped, so a setting can never reshape the query.
 *
 * @param string $name  what the administrator typed
 */
function entraAttrNameValid(string $name): bool
{
    return (bool)preg_match('#^[A-Za-z][A-Za-z0-9_]{0,99}(/[A-Za-z][A-Za-z0-9_]{0,99})?$#', $name);
}

/**
 * The attribute one field is read from: the provider's choice, else the default.
 * A stored value that is not a valid name falls back to the default rather
 * than reaching Graph (it cannot be saved, but a row edited by hand could hold one).
 *
 * @param array $provider  the auth_providers row - graph_attr_<field> is read
 * @param string $field  a key of ESYNC_ATTR_DEFAULTS
 */
function entraAttr(array $provider, string $field): string
{
    $set = trim((string)($provider['graph_attr_' . $field] ?? ''));
    return ($set !== '' && entraAttrNameValid($set)) ? $set : (ESYNC_ATTR_DEFAULTS[$field] ?? '');
}

/**
 * The $select for /users: what the import always needs, plus every attribute
 * the mapping reads (a complex one by its top-level name - Graph returns it whole).
 *
 * @param array $provider  the auth_providers row
 */
function entraUserSelect(array $provider): string
{
    $props = explode(',', ESYNC_USER_SELECT);
    foreach (array_keys(ESYNC_ATTR_DEFAULTS) as $field) {
        $props[] = explode('/', entraAttr($provider, $field))[0];
    }
    return implode(',', array_values(array_unique($props)));
}

/**
 * One attribute's value from a Graph user, as text ('' when absent or empty).
 *
 * A path goes one level into a complex property. A list gives its first
 * non-empty value - `businessPhones`, `otherMails`. Names are matched ignoring
 * case, because an administrator types `OfficeLocation` as easily as
 * `officeLocation`, and $select accepts either while the JSON does not.
 *
 * @param array $u  one item of /users
 * @param string $path  e.g. 'city' or 'onPremisesExtensionAttributes/extensionAttribute5'
 */
function entraUserValue(array $u, string $path): string
{
    $v = $u;
    foreach (explode('/', $path) as $seg) {
        if (!is_array($v)) return '';
        $found = false;
        foreach ($v as $k => $x) {
            if (is_string($k) && strcasecmp($k, $seg) === 0) { $v = $x; $found = true; break; }
        }
        if (!$found) return '';
    }
    if (is_array($v)) {
        foreach ($v as $x) if (is_scalar($x) && trim((string)$x) !== '') return trim((string)$x);
        return '';
    }
    return (is_bool($v) || $v === null) ? '' : trim((string)$v);
}

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
 * What an Entra settings endpoint may tell the administrator about a failure.
 *
 * The engine's own RuntimeExceptions are sentences written for the person at the
 * screen - a missing tenant id, a refused secret with its AADSTS code explained,
 * a Graph permission to grant - and are shown as they are. Anything else, and a
 * database error above all, is never echoed: its text can carry SQL, table names
 * or server paths. It goes to the error log and the caller gets one generic line.
 *
 * ⚠️ PDOException IS a RuntimeException, so it is caught first.
 *
 * @param Throwable $e  what the endpoint caught
 * @param string $where  which endpoint, for the log line
 */
function entraErrorForAdmin(Throwable $e, string $where): string
{
    if (!($e instanceof PDOException) && $e instanceof RuntimeException) return $e->getMessage();
    error_log("[entra] $where: " . get_class($e) . ': ' . $e->getMessage());
    return 'Something went wrong on the server. The details are in the PHP error log.';
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
 * Each field is read from the attribute the provider maps it to (entraAttr();
 * the defaults are ESYNC_ATTR_DEFAULTS). Choices made in the open, whatever the
 * mapping:
 *   email    — must be a real address. Failing that, the UPN, IF it is one and
 *              not a guest's `#EXT#` one. Tenants without Exchange licences
 *              leave `mail` empty for everybody, and a service desk that cannot
 *              email anyone is a worse outcome than using the sign-in address.
 *   username — failing the mapped attribute, the UPN: a mapping to, say,
 *              onPremisesSamAccountName is empty for cloud-only accounts, and a
 *              person with no username cannot be matched on the next run.
 *   name     — failing the mapped attribute, first name and surname, then the UPN.
 *   phone    — a list attribute (`businessPhones`) gives its first number.
 *
 * @param array $u  one item of /users
 * @param array $provider  the auth_providers row - graph_attr_* are read; [] for the defaults
 */
function entraMapUser(array $u, array $provider = []): ?array
{
    $oid = strtolower(trim((string)($u['id'] ?? '')));
    if ($oid === '') return null;
    $val = fn(string $field) => entraUserValue($u, entraAttr($provider, $field));

    $upn   = trim((string)($u['userPrincipalName'] ?? ''));
    $email = strtolower($val('email'));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if ($email === '' && $upn !== '' && !str_contains($upn, '#EXT#') && filter_var($upn, FILTER_VALIDATE_EMAIL)) {
        $email = strtolower($upn);
    }

    $username = $val('username');
    if ($username === '') $username = $upn;

    $name = $val('name');
    if ($name === '') $name = trim(($u['givenName'] ?? '') . ' ' . ($u['surname'] ?? ''));
    if ($name === '') $name = $upn !== '' ? $upn : $oid;

    $mgr = strtolower(trim((string)($u['manager']['id'] ?? '')));

    $nz = fn(string $field) => ($v = $val($field)) !== '' ? $v : null;
    return [
        // Deliberately '' — see the identity note at the top of this file.
        'guid'        => '',
        'entra_id'    => $oid,
        'username'    => $username,
        'email'       => $email !== '' ? $email : null,
        'name'        => $name,
        'job_title'   => $nz('job_title'),
        'department'  => $nz('department'),
        'office'      => $nz('office'),
        'phone'       => $nz('phone'),
        'mobile'      => $nz('mobile'),
        'employee_id' => $nz('employee_id'),
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
 * TRAP: a match in step 2 is a NAME, and names get reused. A leaver's address
 * given to a new starter arrives with a new object id, and matching it would
 * hand them the leaver's record, tickets and portal sign-in. A record already
 * linked to a different object id is therefore never matched: it comes back as
 * 'taken', which the caller reports as a conflict, whatever the conflict setting.
 * Two people cannot share one record, and a directory import is not allowed to
 * decide that they are the same person.
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
    if ($row) {
        $s = $conn->prepare("SELECT source_ref FROM user_sso_identities WHERE provider_id = ? AND user_id = ?");
        $s->execute([(int)$provider['id'], (int)$row['id']]);
        $linked = strtolower(trim((string)$s->fetchColumn()));
        if ($linked !== '' && $linked !== $p['entra_id']) return [$row, 'taken'];
    }
    if ($row && $how === 'email' && (int)($row['auth_provider_id'] ?? 0) === (int)$provider['id']) {
        $how = 'sso';
    }
    return [$row, $how];
}

/**
 * Record the object id against the person, without ever touching a real `sub`.
 *
 * If they already have an identity row for this provider (they have signed in),
 * only source_ref is set on it, and never over a different object id: see
 * entraFindExisting(). Otherwise one is created with the placeholder subject
 * `entra:<oid>`, which the OIDC callback replaces on first sign-in.
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
            $conn->prepare(
                "UPDATE user_sso_identities SET source_ref = ?, email = COALESCE(?, email)
                  WHERE id = ? AND (source_ref IS NULL OR source_ref = '' OR LOWER(source_ref) = ?)"
            )->execute([$p['entra_id'], $p['email'], (int)$rowId, $p['entra_id']]);
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
 * Import one person: find them, then update, create, or leave them alone as a
 * conflict. Writes nothing on a preview except the run's own log.
 *
 * @param array $provider  the auth_providers row
 * @param array $p  the person from entraMapUser()
 * @param bool $preview  true to work it out without changing people
 * @param int $runId  directory_sync_runs.id
 * @param array $counts  the run's counts, added to
 * @param array $seenUserIds  users.id of everybody found, added to
 * @param array $oidToUserId  object id => users.id, added to
 */
function entraImportPerson(PDO $conn, array $provider, array $p, bool $preview, int $runId,
                           array &$counts, array &$seenUserIds, array &$oidToUserId): void
{
    $pid = (int)$provider['id'];
    [$existing, $how] = entraFindExisting($conn, $provider, $p);

    if ($how === 'taken') {
        $counts['conflict']++;
        dsyncLogEntry($conn, $runId, 'conflict', (int)$existing['id'], $p,
            'A different Entra account now uses this sign-in name or email address, so this is a new '
            . 'person, not the one already here. Left untouched and not imported, so that nobody takes over '
            . 'the old record.');
        return;
    }
    // Already managed by another sign-in source - an LDAP directory holding the same
    // address, say. Never moved between sources by an import (dsyncNameMatchRefused()).
    if ($how === 'other_directory') {
        $counts['conflict']++;
        dsyncLogEntry($conn, $runId, 'conflict', (int)$existing['id'], $p, dsyncRefusedMessage($how));
        return;
    }

    if ($existing && $how === 'email'
        && (string)($provider['sync_on_conflict'] ?? 'adopt') !== 'adopt') {
        $counts['conflict']++;
        dsyncLogEntry($conn, $runId, 'conflict', (int)$existing['id'], $p,
            'Already exists here with the same email address. Left untouched, because this '
            . 'directory is set to flag conflicts rather than adopt them.');
        return;
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
        return;
    }

    $newId = dsyncCreate($conn, $provider, $p, $preview);
    if ($newId === null) {
        $counts['error']++;
        dsyncLogEntry($conn, $runId, 'error', null, $p, 'Could not be created — see the server error log.');
        return;
    }
    $counts['created']++;
    if ($newId > 0) {
        $seenUserIds[] = $newId;
        $oidToUserId[$p['entra_id']] = $newId;
        entraLinkIdentity($conn, $pid, $newId, $p);
    }
    dsyncLogEntry($conn, $runId, 'create', $newId ?: null, $p, dsyncNewPersonSummary($p)
        . ($p['disabled'] ? ' Disabled in Entra, so ' . ($preview ? 'they will be' : 'they were') . ' added as having left.' : ''));
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

    // A preview changes nobody, so it can run beside a live import.
    if (!$preview && !dsyncLock($conn, $pid)) {
        dsyncFinishRun($conn, $runId, 'stopped', $counts,
            'Stopped without changing anything: another import of this source is still running.');
        return dsyncGetRun($conn, $runId);
    }

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
        $raw = entraGetAll($cfg, '/users?$select=' . entraUserSelect($provider) . '&$expand=manager($select=id)&$top=100',
            $inScope === null ? ESYNC_MAX_PEOPLE : ESYNC_MAX_SCAN);

        $includeGuests = (int)($provider['graph_include_guests'] ?? 0) === 1;
        $people = [];
        foreach ($raw as $u) {
            $p = entraMapUser($u, $provider);
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
                entraImportPerson($conn, $provider, $p, $preview, $runId, $counts, $seenUserIds, $oidToUserId);
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
                    // A preview has created nobody, so everybody new would read as
                    // "outside the scope" here. It can only say what it cannot count.
                    if ($preview) {
                        $message .= ' (A preview cannot count memberships of people it has not created yet.)';
                    } elseif ($g['outside'] > 0) {
                        $message .= sprintf(' Members not imported as people (outside the people scope, or guests): %d.', $g['outside']);
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
    } finally {
        if (!$preview) dsyncUnlock($conn, $pid);
    }

    dsyncFinishRun($conn, $runId, $status, $counts, $message);
    return dsyncGetRun($conn, $runId);
}
