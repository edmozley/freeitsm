<?php
/**
 * API Endpoint: Get knowledge articles for review
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get filter parameter (all, overdue, upcoming, no_date)
    $filter = $_GET['filter'] ?? 'all';

    $sql = "SELECT
                ka.id,
                ka.title,
                ka.next_review_date,
                ka.owner_id,
                ka.created_datetime,
                ka.modified_datetime,
                owner.full_name as owner_name,
                author.full_name as author_name
            FROM knowledge_articles ka
            LEFT JOIN analysts owner ON ka.owner_id = owner.id
            LEFT JOIN analysts author ON ka.author_id = author.id
            WHERE ka.is_published = 1
              AND (ka.is_archived = 0 OR ka.is_archived IS NULL)";

    switch ($filter) {
        case 'overdue':
            $sql .= " AND ka.next_review_date < DATE(UTC_TIMESTAMP())";
            break;
        case 'upcoming':
            $sql .= " AND ka.next_review_date >= DATE(UTC_TIMESTAMP()) AND ka.next_review_date <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)";
            break;
        case 'no_date':
            $sql .= " AND ka.next_review_date IS NULL";
            break;
        // 'all' shows everything
    }

    $sql .= " ORDER BY
                CASE WHEN ka.next_review_date IS NULL THEN 1 ELSE 0 END,
                ka.next_review_date ASC,
                ka.title ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format dates for display
    foreach ($articles as &$article) {
        if ($article['next_review_date']) {
            $reviewDate = new DateTime($article['next_review_date']);
            $today = new DateTime('today');
            $article['next_review_date_formatted'] = $reviewDate->format('d M Y');
            $article['is_overdue'] = $reviewDate < $today;
            $article['days_until_review'] = $today->diff($reviewDate)->days * ($reviewDate < $today ? -1 : 1);
        } else {
            $article['next_review_date_formatted'] = null;
            $article['is_overdue'] = false;
            $article['days_until_review'] = null;
        }
    }

    // Get counts for filter badges
    $countsSql = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN next_review_date < DATE(UTC_TIMESTAMP()) THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN next_review_date >= DATE(UTC_TIMESTAMP()) AND next_review_date <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN next_review_date IS NULL THEN 1 ELSE 0 END) as no_date
    FROM knowledge_articles
    WHERE is_published = 1
    AND (is_archived = 0 OR is_archived IS NULL)";
    $countsStmt = $conn->prepare($countsSql);
    $countsStmt->execute();
    $counts = $countsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'articles' => $articles,
        'counts' => $counts
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
