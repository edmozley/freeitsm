<?php
/**
 * Network Mapper <-> Knowledge (GitHub #433).
 *
 * Two kinds of link:
 *   - a node or a shape links to ONE article (network_diagram_nodes/shapes.article_id) -
 *     clicked in Present, or opened from its book marker or right-click menu in the editor;
 *   - a diagram lists RELATED articles (network_diagram_articles), and the article lists
 *     the diagrams back ("Network diagrams" when reading it).
 *
 * THE RULE: a diagram never shows an article's title to someone Knowledge would not show
 * the article to. Every title goes through knowledgeVisibilitySql() for the person looking,
 * and anyone without the Knowledge module sees none. An article they cannot read is still
 * there - as "an article you don't have access to", with its id and no title - so they can
 * see something is linked, and a save of theirs keeps a colleague's link rather than
 * quietly dropping it.
 *
 * Drafts and archived articles: an analyst reads both in Knowledge (a draft carries a Draft
 * badge, an archived one sits in the archive), so a link to one shows its title with its state
 * - never "no access", which would be untrue. The picker offers drafts but not archived ones.
 *
 * Saving: a link is kept if the person saving can read that article, OR the diagram already
 * linked it before this save. Nothing else gets in - so an id typed into a request cannot
 * pin an article you cannot read onto a diagram.
 */
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../knowledge/visibility.php';

class NetworkArticleService
{
    /**
     * The ids in $ids that $analystId may read - none at all without the Knowledge module.
     *
     * @param int[] $ids  article ids (any order, duplicates fine)
     * @return array<int,string>  id => title, readable ones only
     */
    public static function readable(PDO $conn, int $analystId, array $ids): array
    {
        return array_map(fn($r) => $r['title'], self::readableRows($conn, $analystId, $ids));
    }

    /**
     * Like readable(), with each article's state: 'live', 'draft' or 'archived'.
     *
     * TRAP: knowledgeVisibilitySql() defaults to lifecycle 'live' (published, not archived) - the
     * right default for a list of articles to READ, and the wrong one here: a diagram already
     * linked to a draft or an archived article, and the analyst can open both in Knowledge, so
     * 'live' made them look like "an article you don't have access to". Permission (company,
     * audience, access list) still applies in full; only the lifecycle filter is lifted.
     *
     * @param int[] $ids  article ids (any order, duplicates fine)
     * @return array<int,array{title:string,state:string}>
     */
    public static function readableRows(PDO $conn, int $analystId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids || $analystId <= 0 || !analystCanAccessModule($conn, $analystId, 'knowledge')) return [];
        [$vis, $args] = knowledgeVisibilitySql($conn, KnowledgeViewer::forAnalyst($conn, $analystId), 'a', ['lifecycle' => 'any']);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT a.id, a.title, a.is_published, a.is_archived FROM knowledge_articles a WHERE a.id IN ($ph)" . $vis);
        $st->execute(array_merge($ids, $args));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = ['title' => (string)$r['title'], 'state' => self::stateOf($r)];
        return $out;
    }

    /** 'archived', 'draft' or 'live' for an article row with is_published / is_archived. */
    private static function stateOf(array $r): string
    {
        if ((int)($r['is_archived'] ?? 0) === 1) return 'archived';
        return (int)($r['is_published'] ?? 1) === 1 ? 'live' : 'draft';
    }

    /**
     * Every article id this diagram version links to now - on nodes, on shapes and related.
     * Empty on an install that has not run DB Verify since these arrived.
     *
     * @param int $diagramId  the diagram version
     * @return int[]
     */
    public static function idsOnDiagram(PDO $conn, int $diagramId): array
    {
        $ids = [];
        foreach (["SELECT article_id FROM network_diagram_nodes WHERE diagram_id = ? AND article_id IS NOT NULL",
                  "SELECT article_id FROM network_diagram_shapes WHERE diagram_id = ? AND article_id IS NOT NULL",
                  "SELECT article_id FROM network_diagram_articles WHERE diagram_id = ?"] as $sql) {
            try {
                $st = $conn->prepare($sql);
                $st->execute([$diagramId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
            } catch (PDOException $e) {
                // the column or table is not there yet
            }
        }
        return array_keys($ids);
    }

    /**
     * The article ids a save may keep: what the saver can read, plus what was already linked.
     * Call BEFORE the save replaces anything.
     *
     * @param int[] $wanted  the ids the save carries
     * @return array<int,true>  allowed id => true
     */
    public static function allowedForSave(PDO $conn, int $analystId, int $diagramId, array $wanted): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $wanted), fn($i) => $i > 0)));
        if (!$wanted) return [];
        $allowed = array_fill_keys(self::idsOnDiagram($conn, $diagramId), true);
        $new = array_values(array_filter($wanted, fn($i) => !isset($allowed[$i])));
        foreach (array_keys(self::readable($conn, $analystId, $new)) as $id) $allowed[$id] = true;
        return $allowed;
    }

    /**
     * What the editor needs: the related list, in the order added, a title for every
     * article the diagram links to (null = not readable by this analyst), and the state of
     * every readable one that is not live (draft / archived).
     *
     * @param int $diagramId  the diagram version
     * @return array{related: int[], titles: array<string,?string>, states: array<string,string>}
     */
    public static function forDiagram(PDO $conn, int $analystId, int $diagramId): array
    {
        $related = [];
        try {
            $st = $conn->prepare("SELECT article_id FROM network_diagram_articles WHERE diagram_id = ? ORDER BY id");
            $st->execute([$diagramId]);
            $related = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $related = [];
        }
        $all = self::idsOnDiagram($conn, $diagramId);
        $can = self::readableRows($conn, $analystId, $all);
        $titles = [];
        $states = [];
        foreach ($all as $id) {
            $titles[(string)$id] = isset($can[$id]) ? $can[$id]['title'] : null;
            if (isset($can[$id]) && $can[$id]['state'] !== 'live') $states[(string)$id] = $can[$id]['state'];
        }
        return ['related' => $related, 'titles' => $titles, 'states' => (object)$states];
    }

    /**
     * Adds an article to a diagram's related list. Current version only; the article must be
     * one the analyst can read. Adding one that is already there does nothing.
     *
     * @param int $diagramId  the diagram version (must be the current one)
     * @param int $articleId  the article
     */
    public static function addRelated(PDO $conn, ActorContext $ctx, int $diagramId, int $articleId): void
    {
        self::requireCurrent($conn, $diagramId);
        if (!self::readable($conn, (int)$ctx->actorId, [$articleId])) {
            throw new ServiceError('not_found', 'not_found', 'Article not found.');
        }
        $conn->prepare("INSERT IGNORE INTO network_diagram_articles (diagram_id, article_id, created_by_analyst_id, created_datetime)
                        VALUES (?, ?, ?, UTC_TIMESTAMP())")->execute([$diagramId, $articleId, $ctx->actorId ?: null]);
    }

    /**
     * Takes an article off a diagram's related list (any article - you may remove a link to
     * one you cannot read). Current version only.
     *
     * @param int $diagramId  the diagram version (must be the current one)
     * @param int $articleId  the article
     */
    public static function removeRelated(PDO $conn, int $diagramId, int $articleId): void
    {
        self::requireCurrent($conn, $diagramId);
        $conn->prepare("DELETE FROM network_diagram_articles WHERE diagram_id = ? AND article_id = ?")->execute([$diagramId, $articleId]);
    }

    /** Copies the related list onto a new version (createVersion calls this). */
    public static function copyRelated(PDO $conn, int $fromId, int $toId): void
    {
        try {
            $conn->prepare("INSERT INTO network_diagram_articles (diagram_id, article_id, created_by_analyst_id, created_datetime)
                            SELECT ?, article_id, created_by_analyst_id, created_datetime FROM network_diagram_articles WHERE diagram_id = ?")
                 ->execute([$toId, $fromId]);
        } catch (PDOException $e) {
            // table not there yet
        }
    }

    /**
     * The picker: articles this analyst can read whose title matches - newest first when
     * nothing is typed. Drafts are offered (a runbook is often linked while it is being
     * written), archived ones are not. Empty without the Knowledge module.
     *
     * @param string $q  what was typed
     * @return array<int,array{id:int,title:string,state:string}>
     */
    public static function search(PDO $conn, int $analystId, string $q): array
    {
        if ($analystId <= 0 || !analystCanAccessModule($conn, $analystId, 'knowledge')) return [];
        [$vis, $args] = knowledgeVisibilitySql($conn, KnowledgeViewer::forAnalyst($conn, $analystId), 'a', ['lifecycle' => 'unarchived']);
        $q = trim($q);
        $where = '';
        if ($q !== '') {
            $where = ' AND a.title LIKE ?';
            $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        $st = $conn->prepare("SELECT a.id, a.title, a.is_published, a.is_archived FROM knowledge_articles a WHERE 1 = 1" . $vis . $where . "
                               ORDER BY " . ($q !== '' ? 'a.title' : 'COALESCE(a.modified_datetime, a.created_datetime) DESC') . " LIMIT 40");
        $st->execute($args);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'state' => self::stateOf($r)], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The other way: the CURRENT diagram versions that use an article - related to it, or
     * with a node or a shape linked to it. Empty unless the analyst has the Network Mapper
     * module and may read the article.
     *
     * @param int $articleId  the article being read
     * @return array<int,array{id:int,title:string,how:string}>  how = related | item
     */
    public static function diagramsForArticle(PDO $conn, int $analystId, int $articleId): array
    {
        if ($articleId <= 0 || !analystCanAccessModule($conn, $analystId, 'network-mapper')) return [];
        if (!self::readable($conn, $analystId, [$articleId])) return [];
        $how = [];
        foreach (['related' => "SELECT diagram_id FROM network_diagram_articles WHERE article_id = ?",
                  'item'    => "SELECT diagram_id FROM network_diagram_nodes WHERE article_id = ?
                                UNION SELECT diagram_id FROM network_diagram_shapes WHERE article_id = ?"] as $kind => $sql) {
            try {
                $st = $conn->prepare($sql);
                $st->execute($kind === 'item' ? [$articleId, $articleId] : [$articleId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $how[(int)$d] = $how[(int)$d] ?? $kind;
            } catch (PDOException $e) {
                // not there yet
            }
        }
        if (!$how) return [];
        $ph = implode(',', array_fill(0, count($how), '?'));
        $st = $conn->prepare("SELECT d.id, d.title, d.version_label FROM network_diagrams d
                               WHERE d.id IN ($ph) AND NOT EXISTS (SELECT 1 FROM network_diagrams c WHERE c.parent_diagram_id = d.id)
                               ORDER BY d.title");
        $st->execute(array_keys($how));
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'version_label' => $r['version_label'],
                                    'how' => $how[(int)$r['id']]], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Throws unless the diagram exists and is its chain's current version. */
    private static function requireCurrent(PDO $conn, int $diagramId): void
    {
        $st = $conn->prepare("SELECT (SELECT COUNT(*) FROM network_diagrams c WHERE c.parent_diagram_id = d.id) FROM network_diagrams d WHERE d.id = ?");
        $st->execute([$diagramId]);
        $children = $st->fetchColumn();
        if ($children === false) throw new ServiceError('not_found', 'not_found', 'Diagram not found.');
        if ((int)$children > 0) throw new ServiceError('conflict', 'conflict', 'This is a historical version (read-only).');
    }
}
