<?php
/**
 * Projects, joined to the rest of FreeITSM (3.2.0) - the ONE place that decides
 * who may see a link and who may make one. Modelled on includes/domains/links.php.
 *
 * Seven kinds of link, each a plain join table:
 *
 *   asset    project_assets              the equipment the project works on
 *   change   project_changes             changes the project raises
 *   ticket   project_tickets             tickets about it - including the ones it caused
 *   contract project_contracts           supplier contracts it relies on
 *   cmdb     project_cmdb_objects        configuration items it touches
 *   article  project_knowledge_articles  runbooks and write-ups
 *   problem  project_problems            problems it exists to fix (3.3.0)
 *
 * 🔑 THE RULE, for every kind: a link is only shown, made or removed between two
 * records the analyst can already open - Projects plus the other module, and the
 * record itself (its company, or for an article its knowledge permissions). A
 * link the reader cannot see both ends of does not exist for them: it is left
 * out, never shown as "hidden".
 *
 * 🔑 And a link never crosses companies. An asset, change, ticket or CI must be
 * in the project's own company (and so must a problem). Contracts and knowledge articles are not
 * company records (contracts are install-wide; articles have their own audience
 * model), so they are judged by their own permissions only.
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../entity_links.php';
require_once __DIR__ . '/../service_context.php';

if (!defined('PROJECT_LINKS_LOADED')) {
    define('PROJECT_LINKS_LOADED', true);

    /** kind => table, the column naming the other record, the module it belongs to, and its company table (null = not a company record). */
    function projectLinkKinds(): array
    {
        return [
            'asset'    => ['table' => 'project_assets',             'col' => 'asset_id',       'module' => 'assets',    'scoped' => 'assets'],
            'change'   => ['table' => 'project_changes',            'col' => 'change_id',      'module' => 'changes',   'scoped' => 'changes'],
            'ticket'   => ['table' => 'project_tickets',            'col' => 'ticket_id',      'module' => 'tickets',   'scoped' => 'tickets'],
            'contract' => ['table' => 'project_contracts',          'col' => 'contract_id',    'module' => 'contracts', 'scoped' => null],
            'cmdb'     => ['table' => 'project_cmdb_objects',       'col' => 'cmdb_object_id', 'module' => 'cmdb',      'scoped' => 'cmdb_objects'],
            'article'  => ['table' => 'project_knowledge_articles', 'col' => 'article_id',     'module' => 'knowledge', 'scoped' => null],
            'problem'  => ['table' => 'project_problems',           'col' => 'problem_id',     'module' => 'problems',  'scoped' => 'problems'],
        ];
    }

    /**
     * Before Database Verification the tables are not there: no links, never an error.
     * With $kind, that one kind's table (3.3.0) - so an upgrade that adds a kind
     * (problem) hides only that kind until Verification, not every link.
     */
    function projectLinksReady(PDO $conn, ?string $kind = null): bool
    {
        static $ready = [];
        $kinds = $kind !== null ? [$kind => projectLinkKinds()[$kind] ?? null] : projectLinkKinds();
        foreach ($kinds as $name => $k) {
            if ($k === null) return false;
            if (!isset($ready[$name])) {
                try { $conn->query("SELECT 1 FROM {$k['table']} LIMIT 0"); $ready[$name] = true; }
                catch (Throwable $e) { $ready[$name] = false; }
            }
            if ($kind !== null) return $ready[$name];
        }
        // No kind named: is ANY kind usable (the Connections tab's "run Verification" note)?
        return in_array(true, $ready, true);
    }

    function projectLinkKind(string $kind): array
    {
        $kinds = projectLinkKinds();
        if (!isset($kinds[$kind])) throw new ServiceError('validation', 'invalid_field', 'Unknown link kind.');
        return $kinds[$kind];
    }

    /** May this analyst use links of this kind at all? (Projects AND the other module.) */
    function projectLinkKindAllowed(PDO $conn, int $analystId, string $kind): bool
    {
        $k = projectLinkKinds()[$kind] ?? null;
        return $k !== null
            && analystCanAccessModule($conn, $analystId, 'projects')
            && analystCanAccessModule($conn, $analystId, $k['module']);
    }

    /** A record's company with NULL read as the Default company. */
    function projectLinkTenantOf(PDO $conn, string $table, int $id): ?int
    {
        $st = $conn->prepare("SELECT tenant_id FROM $table WHERE id = ?");
        $st->execute([$id]);
        $t = $st->fetchColumn();
        if ($t === false) return null;
        return $t === null ? (int)getDefaultTenantId($conn) : (int)$t;
    }

    /** The project's company (Default for NULL), or not-found for a project out of reach. */
    function projectLinkProjectTenant(PDO $conn, ActorContext $ctx, int $projectId): int
    {
        require_once __DIR__ . '/../services/projects.php';
        $row = ProjectsService::loadForActor($conn, $ctx, $projectId);
        return $row['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$row['tenant_id'];
    }

    /**
     * Is the OTHER end of a link one this analyst may see, and (for company
     * records) in the project's company?
     */
    function projectLinkTargetOk(PDO $conn, int $analystId, string $kind, int $targetId, ?int $projectTenant): bool
    {
        if ($targetId <= 0) return false;
        $k = projectLinkKinds()[$kind] ?? null;
        if (!$k) return false;
        $multi = isMultiTenant($conn);
        switch ($kind) {
            case 'asset':
                if (!analystCanAccessAsset($conn, $analystId, $targetId)) return false;
                break;
            case 'change':
                if (!analystCanAccessChange($conn, $analystId, $targetId)) return false;
                break;
            case 'ticket':
                if (!analystCanAccessTicket($conn, $analystId, $targetId)) return false;
                $st = $conn->prepare("SELECT 1 FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
                $st->execute([$targetId]);
                if (!$st->fetchColumn()) return false;
                break;
            case 'cmdb':
                if (!analystCanAccessCmdbObject($conn, $analystId, $targetId)) return false;
                break;
            case 'problem':
                if (!analystCanAccessProblem($conn, $analystId, $targetId)) return false;
                break;
            case 'contract':
                $st = $conn->prepare("SELECT 1 FROM contracts WHERE id = ?");
                $st->execute([$targetId]);
                return (bool)$st->fetchColumn();
            case 'article':
                require_once __DIR__ . '/../knowledge/visibility.php';
                // 'unarchived', as the analyst article list: a DRAFT can be linked.
                // A lesson turned into an article (3.2.0) is saved as a draft and
                // linked at once; the default 'live' refused it. Permissions are
                // unchanged - only the publish state is no longer a bar.
                return knowledgeCanRead($conn, KnowledgeViewer::forAnalyst($conn, $analystId), $targetId, ['lifecycle' => 'unarchived']);
        }
        // A company record: must exist and sit in the project's company.
        if (!$multi || $projectTenant === null) {
            $st = $conn->prepare("SELECT 1 FROM {$k['scoped']} WHERE id = ?");
            $st->execute([$targetId]);
            return (bool)$st->fetchColumn();
        }
        return projectLinkTenantOf($conn, $k['scoped'], $targetId) === $projectTenant;
    }

    /** Linked records, shaped for display: id, label, a sub-line, a status and a link. */
    function projectLinkDescribe(PDO $conn, string $kind, array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        switch ($kind) {
            case 'asset':
                $st = $conn->prepare(
                    "SELECT a.id, a.hostname, a.asset_tag, a.manufacturer, a.model, s.name AS status
                       FROM assets a LEFT JOIN asset_status_types s ON s.id = a.asset_status_id
                      WHERE a.id IN ($in) ORDER BY a.hostname");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['hostname'] ?: ($r['asset_tag'] ?: '#' . $r['id']),
                    'sub' => trim(($r['asset_tag'] ? $r['asset_tag'] . ' - ' : '') . trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? ''))),
                    'status' => $r['status'], 'url' => entityLink('asset', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'change':
                $st = $conn->prepare(
                    "SELECT c.id, c.title, s.name AS status, s.colour AS status_colour, COALESCE(s.is_closed, 0) AS is_closed, c.work_start_datetime
                       FROM changes c LEFT JOIN change_statuses s ON s.id = c.status_id
                      WHERE c.id IN ($in) ORDER BY COALESCE(s.is_closed, 0), c.id DESC");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => 'CHG-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT), 'sub' => $r['title'],
                    'status' => $r['status'], 'status_colour' => $r['status_colour'], 'closed' => (int)$r['is_closed'] === 1,
                    'url' => entityLink('change', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'ticket':
                $st = $conn->prepare(
                    "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, ts.colour AS status_colour, COALESCE(ts.is_closed, 0) AS is_closed
                       FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
                      WHERE t.id IN ($in) ORDER BY COALESCE(ts.is_closed, 0), t.created_datetime DESC");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => ($r['ticket_number'] ?: '#' . $r['id']), 'sub' => $r['subject'],
                    'status' => $r['status'], 'status_colour' => $r['status_colour'], 'closed' => (int)$r['is_closed'] === 1,
                    'url' => entityLink('ticket', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'contract':
                $st = $conn->prepare(
                    "SELECT c.id, c.contract_number, c.title, c.contract_end, s.name AS status,
                            COALESCE(NULLIF(sup.trading_name, ''), sup.legal_name) AS supplier
                       FROM contracts c
                  LEFT JOIN contract_statuses s ON s.id = c.contract_status_id
                  LEFT JOIN suppliers sup ON sup.id = c.supplier_id
                      WHERE c.id IN ($in) ORDER BY c.title");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['title'] ?: ($r['contract_number'] ?: '#' . $r['id']),
                    'sub' => trim(($r['contract_number'] ? $r['contract_number'] . ' - ' : '') . ($r['supplier'] ?? '')),
                    'status' => $r['status'], 'end' => $r['contract_end'], 'url' => entityLink('contract', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'cmdb':
                $st = $conn->prepare("SELECT o.id, o.name, c.name AS class_name FROM cmdb_objects o LEFT JOIN cmdb_classes c ON c.id = o.class_id WHERE o.id IN ($in) ORDER BY o.name");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['name'], 'sub' => $r['class_name'],
                    'url' => entityLink('cmdb_object', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'problem':
                $st = $conn->prepare(
                    "SELECT p.id, p.problem_number, p.title, p.is_known_error, s.name AS status, s.colour AS status_colour, COALESCE(s.is_closed, 0) AS is_closed
                       FROM problems p LEFT JOIN problem_statuses s ON s.id = p.status_id
                      WHERE p.id IN ($in) ORDER BY COALESCE(s.is_closed, 0), p.id DESC");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['problem_number'] ?: 'PRB-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT), 'sub' => $r['title'],
                    'status' => $r['status'], 'status_colour' => $r['status_colour'], 'closed' => (int)$r['is_closed'] === 1, 'known_error' => (int)$r['is_known_error'] === 1,
                    'url' => entityLink('problem', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'article':
                $st = $conn->prepare("SELECT id, title, modified_datetime FROM knowledge_articles WHERE id IN ($in) ORDER BY title");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['title'], 'sub' => null,
                    'url' => entityLink('knowledge_article', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
        }
        return [];
    }

    /**
     * Everything linked to one project, per kind, for this analyst: a kind they
     * cannot use is left out entirely; within a kind, rows they cannot see are dropped.
     */
    function projectLinks(PDO $conn, ActorContext $ctx, int $projectId): array
    {
        $tenant = projectLinkProjectTenant($conn, $ctx, $projectId);
        $out = [];
        if (!projectLinksReady($conn)) return $out;
        foreach (projectLinkKinds() as $kind => $k) {
            if (!projectLinksReady($conn, $kind) || !projectLinkKindAllowed($conn, $ctx->actorId, $kind)) continue;
            $st = $conn->prepare("SELECT {$k['col']} FROM {$k['table']} WHERE project_id = ?");
            $st->execute([$projectId]);
            $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
                fn($id) => projectLinkTargetOk($conn, $ctx->actorId, $kind, $id, $tenant)));
            $out[$kind] = projectLinkDescribe($conn, $kind, $ids);
        }
        return $out;
    }

    function projectLinkAdd(PDO $conn, ActorContext $ctx, int $projectId, string $kind, int $targetId): bool
    {
        $k = projectLinkKind($kind);
        if (!projectLinksReady($conn, $kind)) throw new ServiceError('validation', 'not_ready', 'Run System → Database Verification first.');
        $tenant = projectLinkProjectTenant($conn, $ctx, $projectId);
        ProjectsService::assertCanChange($conn, $ctx, ProjectsService::loadRow($conn, $projectId));
        if (!projectLinkKindAllowed($conn, $ctx->actorId, $kind) || !projectLinkTargetOk($conn, $ctx->actorId, $kind, $targetId, $tenant)) {
            throw new ServiceError('not_found', 'not_found', 'That record cannot be linked to this project.');
        }
        $st = $conn->prepare("INSERT IGNORE INTO {$k['table']} (project_id, {$k['col']}, created_by_analyst_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");
        $st->execute([$projectId, $targetId, $ctx->actorId ?: null]);
        $added = $st->rowCount() > 0;
        if ($added) {
            $d = projectLinkDescribe($conn, $kind, [$targetId]);
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'link_added', null, $kind . ': ' . ($d[0]['label'] ?? $targetId), $ctx->source === 'api' ? 'api' : 'app');
        }
        return $added;
    }

    function projectLinkRemove(PDO $conn, ActorContext $ctx, int $projectId, string $kind, int $targetId): bool
    {
        $k = projectLinkKind($kind);
        if (!projectLinksReady($conn, $kind)) return false;
        $tenant = projectLinkProjectTenant($conn, $ctx, $projectId);
        ProjectsService::assertCanChange($conn, $ctx, ProjectsService::loadRow($conn, $projectId));
        // Removing needs the same right as adding: both ends visible. A link you
        // cannot see is not yours to delete.
        if (!projectLinkKindAllowed($conn, $ctx->actorId, $kind) || !projectLinkTargetOk($conn, $ctx->actorId, $kind, $targetId, $tenant)) {
            throw new ServiceError('not_found', 'not_found', 'Link not found.');
        }
        $d = projectLinkDescribe($conn, $kind, [$targetId]);
        $st = $conn->prepare("DELETE FROM {$k['table']} WHERE project_id = ? AND {$k['col']} = ?");
        $st->execute([$projectId, $targetId]);
        $removed = $st->rowCount() > 0;
        if ($removed) ProjectsService::audit($conn, $projectId, $ctx->actorId, 'link_removed', $kind . ': ' . ($d[0]['label'] ?? $targetId), null, $ctx->source === 'api' ? 'api' : 'app');
        return $removed;
    }

    /**
     * Records of one kind that could be linked: matching $q, in the project's
     * company where that applies, visible to the analyst, not already linked. At most 20.
     */
    function projectLinkSearch(PDO $conn, ActorContext $ctx, int $projectId, string $kind, string $q): array
    {
        $k = projectLinkKind($kind);
        $tenant = projectLinkProjectTenant($conn, $ctx, $projectId);
        if (!projectLinkKindAllowed($conn, $ctx->actorId, $kind) || !projectLinksReady($conn, $kind)) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%';
        $taken = "NOT EXISTS (SELECT 1 FROM {$k['table']} l WHERE l.project_id = ? AND l.{$k['col']} = x.id)";
        $multi = isMultiTenant($conn);
        $default = (int)getDefaultTenantId($conn);
        $company = $multi ? " AND COALESCE(x.tenant_id, $default) = ?" : '';
        $cArgs = $multi ? [$tenant] : [];
        switch ($kind) {
            case 'asset':
                $sql = "SELECT x.id FROM assets x WHERE (x.hostname LIKE ? OR x.asset_tag LIKE ? OR x.service_tag LIKE ? OR x.model LIKE ?) AND $taken$company ORDER BY x.hostname LIMIT 40";
                $args = array_merge([$like, $like, $like, $like, $projectId], $cArgs);
                break;
            case 'change':
                $sql = "SELECT x.id FROM changes x WHERE (x.title LIKE ? OR x.id = ?) AND $taken$company ORDER BY x.id DESC LIMIT 40";
                $args = array_merge([$like, (int)preg_replace('/\D/', '', $q), $projectId], $cArgs);
                break;
            case 'ticket':
                $sql = "SELECT x.id FROM tickets x WHERE x.deleted_datetime IS NULL AND (x.subject LIKE ? OR x.ticket_number LIKE ?) AND $taken$company ORDER BY x.created_datetime DESC LIMIT 40";
                $args = array_merge([$like, $like, $projectId], $cArgs);
                break;
            case 'cmdb':
                $sql = "SELECT x.id FROM cmdb_objects x WHERE x.name LIKE ? AND $taken$company ORDER BY x.name LIMIT 40";
                $args = array_merge([$like, $projectId], $cArgs);
                break;
            case 'problem':
                $sql = "SELECT x.id FROM problems x WHERE (x.title LIKE ? OR x.problem_number LIKE ? OR x.id = ?) AND $taken$company ORDER BY x.id DESC LIMIT 40";
                $args = array_merge([$like, $like, (int)preg_replace('/\D/', '', $q), $projectId], $cArgs);
                break;
            case 'contract':
                $sql = "SELECT x.id FROM contracts x WHERE (x.title LIKE ? OR x.contract_number LIKE ?) AND $taken ORDER BY x.title LIMIT 40";
                $args = [$like, $like, $projectId];
                break;
            case 'article':
                require_once __DIR__ . '/../knowledge/visibility.php';
                [$vis, $vArgs] = knowledgeVisibilitySql($conn, KnowledgeViewer::forAnalyst($conn, $ctx->actorId), 'x');
                $sql = "SELECT x.id FROM knowledge_articles x WHERE x.title LIKE ? AND $taken $vis ORDER BY x.title LIMIT 40";
                $args = array_merge([$like, $projectId], $vArgs);
                break;
            default:
                return [];
        }
        $st = $conn->prepare($sql);
        $st->execute($args);
        // The SQL narrows by company; the per-record check is the same one
        // add() makes, so the list never offers something add() would refuse.
        $ids = array_slice(array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
            fn($id) => projectLinkTargetOk($conn, $ctx->actorId, $kind, $id, $tenant))), 0, 20);
        return projectLinkDescribe($conn, $kind, $ids);
    }

    // ------------------------------------------------------------------
    //  The other direction: the record's own page (3.2.0, "show the project
    //  from the other side"). Same rules as above, read from the other end.
    // ------------------------------------------------------------------

    /** Projects by id, shaped for a list on another module's page. */
    function projectLinkProjectRows(PDO $conn, array $ids): array
    {
        if (!$ids) return [];
        require_once __DIR__ . '/read.php';
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT p.*, a.full_name AS owner_name FROM projects p LEFT JOIN analysts a ON a.id = p.owner_analyst_id
                               WHERE p.id IN ($in) ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed', 'cancelled'), p.name");
        $st->execute(array_map('intval', $ids));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $stats = projectTaskStats($conn, array_column($rows, 'id'));
        $cfg = projectHealthConfig($conn);
        return array_map(function ($r) use ($stats, $cfg) {
            $d = projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg);
            return ['id' => (int)$d['id'], 'code' => $d['code'], 'name' => $d['name'], 'status' => $d['status'],
                    'colour' => projectColourHex($d['colour']), 'icon' => $d['icon'], 'health' => $d['shown_health'],
                    'progress' => $d['progress'], 'owner_name' => $d['owner_name'], 'target_end_date' => $d['target_end_date'],
                    'url' => entityLink('project', (int)$d['id'])];
        }, $rows);
    }

    /**
     * The projects one record (asset, change, ticket, contract, CI, article) is
     * linked to that this analyst may see. Empty - never an error - when they
     * cannot open Projects, the other module, or the record, so a page can call
     * it unconditionally.
     */
    function projectsLinkedTo(PDO $conn, ActorContext $ctx, string $kind, int $targetId): array
    {
        $k = projectLinkKinds()[$kind] ?? null;
        if ($k === null || !projectLinksReady($conn, $kind) || !projectLinkKindAllowed($conn, $ctx->actorId, $kind)) return [];
        if (!projectLinkTargetOk($conn, $ctx->actorId, $kind, $targetId, null)) return [];
        $st = $conn->prepare("SELECT project_id FROM {$k['table']} WHERE {$k['col']} = ?");
        $st->execute([$targetId]);
        require_once __DIR__ . '/../services/projects.php';
        $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), function ($pid) use ($conn, $ctx) {
            try { ProjectsService::loadForActor($conn, $ctx, $pid); return true; } catch (Throwable $e) { return false; }
        }));
        return projectLinkProjectRows($conn, $ids);
    }

    /**
     * The picker on the record's page: live projects this analyst may CHANGE,
     * in the record's company (for company records), not already linked,
     * matching $q. At most 20.
     */
    function projectsPickableFor(PDO $conn, ActorContext $ctx, string $kind, int $targetId, string $q): array
    {
        $k = projectLinkKinds()[$kind] ?? null;
        if ($k === null || !projectLinksReady($conn, $kind) || !projectLinkKindAllowed($conn, $ctx->actorId, $kind)) return [];
        if (!projectLinkTargetOk($conn, $ctx->actorId, $kind, $targetId, null)) return [];
        require_once __DIR__ . '/../services/projects.php';
        require_once __DIR__ . '/settings.php';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%';
        $sql = "SELECT p.* FROM projects p WHERE p.status NOT IN ('closed', 'cancelled') AND (p.name LIKE ? OR p.id = ?)
                  AND NOT EXISTS (SELECT 1 FROM {$k['table']} l WHERE l.project_id = p.id AND l.{$k['col']} = ?)";
        $args = [$like, (int)preg_replace('/\D/', '', $q), $targetId];
        if ($k['scoped'] && isMultiTenant($conn)) {
            $default = (int)getDefaultTenantId($conn);
            $sql .= " AND COALESCE(p.tenant_id, $default) = ?";
            $args[] = projectLinkTenantOf($conn, $k['scoped'], $targetId);
        }
        $st = $conn->prepare($sql . " ORDER BY p.name LIMIT 60");
        $st->execute($args);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            try { ProjectsService::loadForActor($conn, $ctx, (int)$p['id']); } catch (Throwable $e) { continue; }
            if (!projectCanChange($conn, $ctx->actorId, $p)) continue;
            $ids[] = (int)$p['id'];
            if (count($ids) >= 20) break;
        }
        return projectLinkProjectRows($conn, $ids);
    }

    /** Remove every link a project holds - called when the project is deleted. */
    function projectLinksDeleteAll(PDO $conn, int $projectId): void
    {
        foreach (projectLinkKinds() as $k) {
            try { $conn->prepare("DELETE FROM {$k['table']} WHERE project_id = ?")->execute([$projectId]); } catch (Throwable $e) { /* not created yet */ }
        }
    }

    /**
     * The project's linked changes that are still open and NOT approved - what a
     * stage gate warns about (3.2.0, "going live safely").
     *
     * Approved is the recorded fact (changes.approval_datetime), the same test
     * Watchtower's "awaiting approval" uses, so an extra approval stage in the
     * change workflow needs no list here. Unlike Watchtower it KEEPS drafts (a
     * change still in its starting status): at a gate, a change nobody has even
     * submitted is the bigger worry, and the list says which ones are drafts.
     * Closed changes (done, failed, cancelled, rejected) are not waiting on anyone.
     *
     * Changes have no stage, so this is the project's whole list; the gate shows
     * each one's planned start so the board can see what falls in the next stage.
     */
    function projectUnapprovedChanges(PDO $conn, int $projectId): array
    {
        try {
            $st = $conn->prepare(
                "SELECT c.id, c.title, c.work_start_datetime, s.name AS status, s.colour AS status_colour, COALESCE(s.is_default, 0) AS is_draft
                   FROM project_changes pc
                   JOIN changes c ON c.id = pc.change_id
              LEFT JOIN change_statuses s ON s.id = c.status_id
                  WHERE pc.project_id = ? AND c.approval_datetime IS NULL AND COALESCE(s.is_closed, 0) = 0
               ORDER BY c.work_start_datetime IS NULL, c.work_start_datetime, c.id");
            $st->execute([$projectId]);
        } catch (Throwable $e) {
            return [];   // before Database Verification: nothing linked
        }
        return array_map(fn($r) => [
            'id'            => (int)$r['id'],
            'label'         => 'CHG-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT),
            'title'         => $r['title'],
            'status'        => $r['status'],
            'status_colour' => $r['status_colour'],
            'draft'         => (int)$r['is_draft'] === 1,
            'work_start'    => $r['work_start_datetime'],
            'url'           => entityLink('change', (int)$r['id']),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }
}
