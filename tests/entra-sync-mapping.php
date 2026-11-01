<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Entra ID directory sync — the parts that decide who someone is.
 *
 *   php tests/entra-sync-mapping.php
 *
 * Pure logic, no database and no tenant. Three rules earn most of these cases:
 *
 *  1. The person handed to the policy layer has guid = ''. The object id goes in
 *     'entra_id'; if it went in 'guid', dsyncLinkIdentity() would store it as
 *     the SUBJECT, and Entra's pairwise `sub` would then collide with it on the
 *     unique (provider_id, user_id) key and break that person's next sign-in.
 *  2. Only an explicit accountEnabled = false marks somebody as left.
 *  3. A multi-tenant issuer names no tenant, and must say so rather than guess.
 */
require_once __DIR__ . '/../includes/entra_sync.php';

$pass = 0; $fail = 0;

function chk(string $label, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { printf("  ok    %s\n", $label); $pass++; return; }
    printf("  FAIL  %s\n          got  %s\n          want %s\n", $label, json_encode($got), json_encode($want));
    $fail++;
}

echo "\nEntra ID sync — mapping and scope\n" . str_repeat('=', 80) . "\n";

echo "\n-- issuer and tenant --\n";
chk('Entra v2 issuer is Entra', entraIsEntraIssuer('https://login.microsoftonline.com/abc/v2.0'), true);
chk('v1 sts issuer is Entra',   entraIsEntraIssuer('https://sts.windows.net/abc/'), true);
chk('Keycloak is not',          entraIsEntraIssuer('https://kc.example.com/realms/x'), false);
chk('a look-alike host is not', entraIsEntraIssuer('https://login.microsoftonline.com.evil.io/x'), false);
$tid = '72f988bf-86f1-41af-91ab-2d7cd011db47';
chk('tenant from the issuer',   entraTenantId(['issuer_url' => "https://login.microsoftonline.com/$tid/v2.0"]), $tid);
chk('override wins',            entraTenantId(['issuer_url' => "https://login.microsoftonline.com/$tid/v2.0", 'graph_tenant_id' => 'contoso.onmicrosoft.com']), 'contoso.onmicrosoft.com');
chk('common names no tenant',   entraTenantId(['issuer_url' => 'https://login.microsoftonline.com/common/v2.0']), '');
chk('organizations neither',    entraTenantId(['issuer_url' => 'https://login.microsoftonline.com/organizations/v2.0']), '');
chk('US Government cloud',      entraHosts(['issuer_url' => 'https://login.microsoftonline.us/x/v2.0'])['graph'], 'graph.microsoft.us');
chk('global cloud by default',  entraHosts(['issuer_url' => "https://login.microsoftonline.com/$tid/v2.0"])['graph'], 'graph.microsoft.com');

echo "\n-- id lists --\n";
$a = 'AAAAAAAA-1111-2222-3333-444444444444';
chk('lower-cased and de-duplicated', entraIdList("$a\n" . strtolower($a)), [strtolower($a)]);
chk('anything not a GUID is dropped', entraIdList("Finance\n\n$a"), [strtolower($a)]);
chk('commas are separators too', count(entraIdList("$a, bbbbbbbb-1111-2222-3333-444444444444")), 2);

echo "\n-- a user --\n";
$u = [
    'id' => strtoupper('11111111-2222-3333-4444-555555555555'), 'userPrincipalName' => 'Ana.Lopez@contoso.com',
    'mail' => 'Ana.Lopez@Contoso.com', 'displayName' => 'Ana López', 'jobTitle' => 'Engineer',
    'department' => 'IT', 'officeLocation' => 'Madrid', 'businessPhones' => ['+34 91 000 00 00'],
    'mobilePhone' => '', 'employeeId' => 'E42', 'accountEnabled' => true, 'userType' => 'Member',
    'manager' => ['id' => 'AAAAAAAA-0000-0000-0000-000000000000'],
];
$p = entraMapUser($u);
chk('guid is EMPTY (rule 1)',       $p['guid'], '');
chk('object id kept, lower-cased',  $p['entra_id'], '11111111-2222-3333-4444-555555555555');
chk('dn is the object id too',      $p['dn'], $p['entra_id']);
chk('username is the UPN',          $p['username'], 'Ana.Lopez@contoso.com');
chk('email lower-cased',            $p['email'], 'ana.lopez@contoso.com');
chk('first business phone',         $p['phone'], '+34 91 000 00 00');
chk('empty mobile is NULL',         $p['mobile'], null);
chk('office from officeLocation',   $p['office'], 'Madrid');
chk('manager object id, lower',     $p['manager_dn'], 'aaaaaaaa-0000-0000-0000-000000000000');
chk('enabled',                      $p['disabled'], false);
chk('member, not guest',            $p['is_guest'], false);

echo "\n-- edge cases --\n";
chk('no mail falls back to the UPN', entraMapUser(['id' => 'x', 'userPrincipalName' => 'bob@contoso.com'])['email'], 'bob@contoso.com');
chk('...but never a guest #EXT# UPN', entraMapUser(['id' => 'x', 'userPrincipalName' => 'bob_gmail.com#EXT#@contoso.onmicrosoft.com'])['email'], null);
chk('disabled only when Graph says false (rule 2)', entraMapUser(['id' => 'x', 'accountEnabled' => false])['disabled'], true);
chk('absent accountEnabled is NOT disabled',       entraMapUser(['id' => 'x'])['disabled'], false);
chk('guest detected',               entraMapUser(['id' => 'x', 'userType' => 'Guest'])['is_guest'], true);
chk('name from given + surname',    entraMapUser(['id' => 'x', 'givenName' => 'Eva', 'surname' => 'Ruiz'])['name'], 'Eva Ruiz');
chk('no id, no person',             entraMapUser(['displayName' => 'Ghost']), null);

echo "\n-- group kinds --\n";
chk('Microsoft 365',    entraGroupKind(['groupTypes' => ['Unified'], 'securityEnabled' => false, 'mailEnabled' => true]), 'microsoft365');
chk('security',         entraGroupKind(['groupTypes' => [], 'securityEnabled' => true, 'mailEnabled' => false]), 'security');
chk('mail security',    entraGroupKind(['groupTypes' => [], 'securityEnabled' => true, 'mailEnabled' => true]), 'mail-security');
chk('distribution',     entraGroupKind(['groupTypes' => [], 'securityEnabled' => false, 'mailEnabled' => true]), 'distribution');

echo "\n-- token errors say what to fix --\n";
chk('wrong secret', str_starts_with(entraExplainTokenError('AADSTS7000215: Invalid client secret provided.'), 'The client secret is wrong'), true);
chk('expired secret', str_starts_with(entraExplainTokenError('AADSTS7000222: The provided client secret keys are expired.'), 'The client secret has expired'), true);

echo "\n-- field mapping (graph_attr_*, the ldap_attr_* idea for Graph) --\n";
$mu = [
    'id' => '11111111-2222-3333-4444-555555555555', 'userPrincipalName' => 'ana@contoso.com', 'mail' => 'ana.lopez@contoso.com',
    'displayName' => 'Ana López', 'officeLocation' => 'Building 4', 'city' => 'Madrid', 'businessPhones' => ['', '+34 91 000 0000'],
    'otherMails' => ['not-an-address', 'ana@home.example'], 'onPremisesSamAccountName' => null, 'employeeId' => 'E-1',
    'onPremisesExtensionAttributes' => ['extensionAttribute5' => 'HR-0042', 'extensionAttribute6' => null],
    'employeeOrgData' => ['division' => 'Retail', 'costCenter' => 'CC-7'],
];
$def = entraMapUser($mu);
chk('CONTROL: an empty mapping imports exactly what the defaults do', entraMapUser($mu, ['graph_attr_office' => '', 'graph_attr_email' => null]), $def);
chk('default office is officeLocation',            $def['office'], 'Building 4');
chk('office mapped to city',                         entraMapUser($mu, ['graph_attr_office' => 'city'])['office'], 'Madrid');
chk('one level into a complex property',             entraMapUser($mu, ['graph_attr_employee_id' => 'onPremisesExtensionAttributes/extensionAttribute5'])['employee_id'], 'HR-0042');
chk('...employeeOrgData too',                        entraMapUser($mu, ['graph_attr_department' => 'employeeOrgData/division'])['department'], 'Retail');
chk('names are matched ignoring case',               entraMapUser($mu, ['graph_attr_office' => 'CITY'])['office'], 'Madrid');
chk('a list gives its first non-empty value',        $def['phone'], '+34 91 000 0000');
chk('an empty mapped attribute imports nothing',     entraMapUser($mu, ['graph_attr_office' => 'onPremisesExtensionAttributes/extensionAttribute6'])['office'], null);
chk('a mapped username that is empty falls back to the UPN', entraMapUser($mu, ['graph_attr_username' => 'onPremisesSamAccountName'])['username'], 'ana@contoso.com');
chk('a mapped email that is not an address falls back to the UPN', entraMapUser($mu, ['graph_attr_email' => 'otherMails'])['email'], 'ana@contoso.com');
chk('a mapped email that is an address is used, lowercased', entraMapUser($mu + ['onPremisesUserPrincipalName' => 'Ana.Lopez@CORP.example'], ['graph_attr_email' => 'onPremisesUserPrincipalName'])['email'], 'ana.lopez@corp.example');
chk('the identity is never mapped: still the object id', entraMapUser($mu, ['graph_attr_username' => 'city'])['entra_id'], '11111111-2222-3333-4444-555555555555');

echo "\n-- attribute names that may reach a Graph URL --\n";
foreach (['city', 'onPremisesExtensionAttributes/extensionAttribute5', 'extension_9d1a2b3c4d5e6f708192a3b4c5d6e7f8_costCode'] as $ok) chk("accepted: $ok", entraAttrNameValid($ok), true);
foreach (['city,mail', 'a/b/c', '../city', "city'", 'city mail', '$select', '', '5city'] as $bad) chk('refused: ' . json_encode($bad), entraAttrNameValid($bad), false);
chk('a refused name stored by hand falls back to the default', entraAttr(['graph_attr_office' => 'city,mail'], 'office'), 'officeLocation');
$sel = explode(',', entraUserSelect(['graph_attr_employee_id' => 'onPremisesExtensionAttributes/extensionAttribute5', 'graph_attr_office' => 'city', 'graph_attr_department' => 'department']));
chk('$select asks for a complex property by its top-level name', in_array('onPremisesExtensionAttributes', $sel, true) && !in_array('onPremisesExtensionAttributes/extensionAttribute5', $sel, true), true);
chk('$select asks for a mapped plain attribute', in_array('city', $sel, true), true);
chk('$select names nothing twice', count($sel) === count(array_unique($sel)), true);

echo "\n-- what an endpoint may say about a failure --\n";
// The log line entraErrorForAdmin() writes is expected here, not a failure.
$prevLog = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
chk('CONTROL: a sentence written for the administrator is shown as it is',
    entraErrorForAdmin(new RuntimeException('No Entra tenant id.'), 'test'), 'No Entra tenant id.');
chk('a Graph error is shown as it is', entraErrorForAdmin(new EntraGraphError('Microsoft Graph said HTTP 403: Forbidden', 403), 'test'), 'Microsoft Graph said HTTP 403: Forbidden');
$db = entraErrorForAdmin(new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'graph_x' in 'field list'"), 'test');
chk('a database error is never echoed (PDOException IS a RuntimeException)', str_contains($db, 'SQLSTATE') || str_contains($db, 'graph_x'), false);
chk('...the caller is pointed at the log instead', str_contains($db, 'error log'), true);
chk('an unexpected error type is not echoed either', entraErrorForAdmin(new TypeError('secret internals'), 'test') === 'secret internals', false);
ini_set('error_log', (string)$prevLog);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail ? 1 : 0);
