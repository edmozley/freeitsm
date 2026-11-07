<?php
/**
 * Domains — subdomains and recorded certificates: the reads and rules the
 * service, the checks, the alerts and the screens share.
 *
 * SUBDOMAINS. A subdomain (students.example.org) is a domain row of its own,
 * with parent_domain_id pointing at the domain it belongs to (example.org) in
 * the same company. Its registration is the parent's - it is never looked up at
 * a registry, and its renewal is the parent's renewal - but its DNS, email and
 * certificates are its own, and are checked as such. One level only: a parent
 * is never a subdomain itself, so "which registration is this?" always has one
 * answer one step away.
 *
 * 🔑 THE PARENT IS FOUND BY NAME, BOTH WAYS. Adding students.example.org when
 * example.org is in the register links it; adding example.org adopts the
 * subdomains already there. No public-suffix list is needed: only names already
 * in the register can be parents, and a name only ever ends with ".<parent>"
 * when it really is under it.
 *
 * RECORDED CERTIFICATES (domain_manual_certificates): certificates the checks
 * cannot reach, entered by hand, whose expiry joins the alerts, calendar and
 * Watchtower beside the checked ones.
 */

require_once __DIR__ . '/scope.php';   // domainScopeReady() - split DNS prefers a same-scope parent (#249)

/** Has Database Verification added domains.parent_domain_id yet? */
function domainSubdomainsReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT parent_domain_id FROM domains LIMIT 0"); return $ok = true; }
    catch (Throwable) { return $ok = false; }
}

/** Are the recorded-certificate tables there yet? */
function domainManualCertsReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $conn->query("SELECT 1 FROM domain_manual_certificates LIMIT 0");
        $conn->query("SELECT 1 FROM domain_certificate_types LIMIT 0");
        return $ok = true;
    } catch (Throwable) { return $ok = false; }
}

/** Is $name under $parent (students.example.org under example.org)? Never itself. */
function domainIsUnder(string $name, string $parent): bool
{
    $name = strtolower($name); $parent = strtolower($parent);
    return $parent !== '' && $name !== $parent && str_ends_with($name, '.' . $parent);
}

/**
 * The domains in the same company that $name could be a subdomain of - the
 * top-level ones it ends with - longest (closest) first.
 *
 * Split DNS (#249): a name can be in the register twice, Public and Internal. With
 * $preferScope, the parent of THAT scope comes before its twin, so a new internal
 * subdomain links to the internal parent when there is one. Closest name still
 * wins over scope: scope only breaks a tie between two records of the same name.
 *
 * @param ?int $storeTenant  the company as stored (NULL = Default)
 * @param int  $selfId       the domain itself, never its own parent (0 on create)
 * @param ?string $preferScope  'public' or 'internal' - which of two same-named parents comes first
 * @return list<array{id:int,name:string,scope:string}>
 */
function domainParentCandidates(PDO $conn, string $name, ?int $storeTenant, int $selfId = 0, ?string $preferScope = null): array
{
    if (!domainSubdomainsReady($conn)) return [];
    // Every proper suffix of the name with at least two labels is a candidate.
    $labels = explode('.', strtolower($name));
    $suffixes = [];
    for ($i = 1; $i < count($labels) - 1; $i++) $suffixes[] = implode('.', array_slice($labels, $i));
    if (!$suffixes) return [];
    $ph = implode(',', array_fill(0, count($suffixes), '?'));
    $hasScope = domainScopeReady($conn);
    $st = $conn->prepare("SELECT id, domain_name, " . ($hasScope ? 'scope' : "'public' AS scope") . " FROM domains
                           WHERE domain_name IN ($ph) AND tenant_id <=> ? AND parent_domain_id IS NULL AND id <> ?
                        ORDER BY CHAR_LENGTH(domain_name) DESC" . ($hasScope && $preferScope !== null ? ', (scope = ?) DESC, id' : ', id'));
    $st->execute(array_merge($suffixes, [$storeTenant, $selfId], $hasScope && $preferScope !== null ? [$preferScope] : []));
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['domain_name'], 'scope' => (string)$r['scope']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * The domains already in the register that belong under a domain just added -
 * subdomains added before their parent - in the same company, not yet linked.
 *
 * @return list<int>
 */
function domainOrphanSubdomains(PDO $conn, int $parentId, string $parentName, ?int $storeTenant): array
{
    if (!domainSubdomainsReady($conn)) return [];
    $like = '%.' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($parentName));
    $st = $conn->prepare("SELECT id, domain_name FROM domains
                           WHERE domain_name LIKE ? AND tenant_id <=> ? AND parent_domain_id IS NULL AND id <> ?
                             AND NOT EXISTS (SELECT 1 FROM (SELECT parent_domain_id FROM domains WHERE parent_domain_id IS NOT NULL) k WHERE k.parent_domain_id = domains.id)");
    $st->execute([$like, $storeTenant, $parentId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (domainIsUnder((string)$r['domain_name'], $parentName)) $out[] = (int)$r['id'];
    }
    return $out;
}

/**
 * Links every subdomain already in the register to its parent - the one-off
 * Database Verification runs when the column first arrives. Shortest names
 * first, so in a chain (example.org, a.example.org, x.a.example.org) each is
 * linked to the top-level domain, one level deep; a domain that is already a
 * parent is left alone. Each link is in the domain's history.
 *
 * @return int  how many were linked
 */
function domainLinkExistingSubdomains(PDO $conn): int
{
    if (!domainSubdomainsReady($conn)) return 0;
    $linked = 0;
    $rows = $conn->query("SELECT id, domain_name, tenant_id FROM domains WHERE parent_domain_id IS NULL ORDER BY CHAR_LENGTH(domain_name), id")->fetchAll(PDO::FETCH_ASSOC);
    $kids = $conn->prepare("SELECT COUNT(*) FROM domains WHERE parent_domain_id = ?");
    foreach ($rows as $d) {
        $kids->execute([(int)$d['id']]);
        if ((int)$kids->fetchColumn() > 0) continue;   // a parent already
        $cand = domainParentCandidates($conn, $d['domain_name'], $d['tenant_id'] === null ? null : (int)$d['tenant_id'], (int)$d['id']);
        if (!$cand) continue;
        $conn->prepare("UPDATE domains SET parent_domain_id = ? WHERE id = ?")->execute([$cand[0]['id'], (int)$d['id']]);
        $conn->prepare("INSERT INTO domain_audit (domain_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                        VALUES (?, NULL, 'parent_domain_id', NULL, ?, 'import', UTC_TIMESTAMP())")->execute([(int)$d['id'], $cand[0]['name']]);
        $linked++;
    }
    return $linked;
}

/**
 * A domain's subdomains, for its page.
 *
 * @return list<array{id:int,domain_name:string,display_name:?string,security_grade:?string,ssl_expiry_date:?string}>
 */
function domainSubdomains(PDO $conn, int $parentId): array
{
    if (!domainSubdomainsReady($conn)) return [];
    // scope (#249): split DNS moves a subdomain to the parent of its own scope.
    $scope = domainScopeReady($conn) ? 'scope' : "'public' AS scope";
    $st = $conn->prepare("SELECT id, domain_name, display_name, purpose, security_grade, ssl_expiry_date, $scope
                            FROM domains WHERE parent_domain_id = ? ORDER BY domain_name");
    $st->execute([$parentId]);
    return array_map(function ($r) { $r['id'] = (int)$r['id']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * A domain's recorded certificates, soonest expiry first, with the type and
 * provider names and the days left.
 */
function domainManualCertificates(PDO $conn, int $domainId): array
{
    if (!domainManualCertsReady($conn)) return [];
    $st = $conn->prepare("SELECT m.id, m.purpose, m.cert_type_id, t.name AS cert_type, m.supplier_id,
                                 COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier_name, m.provider_name,
                                 m.expiry_date, m.url, m.notes, m.updated_datetime, m.created_datetime
                            FROM domain_manual_certificates m
                       LEFT JOIN domain_certificate_types t ON t.id = m.cert_type_id
                       LEFT JOIN suppliers s ON s.id = m.supplier_id
                           WHERE m.domain_id = ?
                        ORDER BY m.expiry_date, m.purpose");
    $st->execute([$domainId]);
    $today = new DateTimeImmutable(gmdate('Y-m-d'));
    return array_map(function ($r) use ($today) {
        foreach (['id', 'cert_type_id', 'supplier_id'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        $r['days_left'] = (int)$today->diff(new DateTimeImmutable($r['expiry_date']))->format('%r%a');
        $r['provider'] = $r['supplier_name'] ?: $r['provider_name'];
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** The certificate types, for the form and the settings list. */
function domainCertificateTypes(PDO $conn, bool $withUse = false): array
{
    if (!domainManualCertsReady($conn)) return [];
    $use = $withUse ? ", (SELECT COUNT(*) FROM domain_manual_certificates m WHERE m.cert_type_id = t.id) AS in_use" : '';
    $rows = $conn->query("SELECT t.id, t.name, t.is_active, t.display_order$use FROM domain_certificate_types t ORDER BY t.display_order, t.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id']; $r['display_order'] = (int)$r['display_order']; $r['is_active'] = (bool)(int)$r['is_active'];
        if ($withUse) $r['in_use'] = (int)$r['in_use'];
    }
    return $rows;
}
