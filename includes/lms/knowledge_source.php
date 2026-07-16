<?php
/**
 * Which Knowledge articles the LMS may build a lesson from.
 *
 * SHARED ONLY — articles with no owning company (knowledge_articles.tenant_id
 * IS NULL).
 *
 * Why not "the author's own companies", which is how the rest of the app scopes
 * things: because a COURSE has no company. Nothing in the LMS does —
 * lms_courses, lms_lessons, lms_course_assignments, lms_learning_groups and
 * lms_progress all lack the column. So a lesson built from one client's article
 * is shown to every learner in every company regardless of who authored it.
 * Scoping the picker to the author's companies would close the article leak and
 * open a lesson leak — it looks safe without being safe.
 *
 * Shared-only is the only rule coherent with an install-wide course: if everyone
 * can see the course, only content meant for everyone can feed it.
 *
 * Costs nothing on installs where every article is shared (the migration
 * default) — it only bites once someone marks an article as one company's.
 *
 * ⚠️ If the LMS ever gains a company of its own (courses, lessons, assignments,
 * groups, progress), revisit this: the right rule becomes "the course's company
 * + shared", and this file should go.
 *
 * Written once and shared by BOTH the picker (api/lms/knowledge_articles.php)
 * and the body read (api/lms/ai_author.php). They must agree — a title the
 * picker hides must not be readable by posting its id to the author endpoint.
 */

require_once __DIR__ . '/../tenancy.php';   // tenancyColumnExists()

/**
 * SQL fragment restricting a knowledge_articles query to shared articles.
 *
 * Returns '' on an install that has not run Database Verify yet: the column does
 * not exist, so every article is shared by definition and referencing it would
 * throw — which for the picker means a silently EMPTY article list rather than
 * an error anyone would notice.
 */
function lmsSharedOnlySql(PDO $conn): string
{
    return tenancyColumnExists($conn, 'knowledge_articles', 'tenant_id')
        ? ' AND tenant_id IS NULL'
        : '';
}

/**
 * True when the LMS may read $articleId's body.
 * Fails CLOSED: no row, wrong company, or an unexpected error => false.
 */
function lmsCanUseArticle(PDO $conn, int $articleId): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM knowledge_articles
              WHERE id = ? AND is_published = 1 AND (is_archived = 0 OR is_archived IS NULL)"
            . lmsSharedOnlySql($conn)
        );
        $stmt->execute([$articleId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}
