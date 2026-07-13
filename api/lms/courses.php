<?php
/**
 * LMS API: List courses (GET) or Upload new course (POST)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/lms_package.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// The whole-catalogue list AND the upload are management functions — a learner
// sees only their assigned courses, via api/lms/my_courses.php. Gate both branches.
requireCapabilityJson(Cap::LMS_MANAGE);

$conn = connectToDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->query("SELECT c.*, a.full_name as created_by_name FROM lms_courses c LEFT JOIN analysts a ON c.created_by_id = a.id WHERE c.is_active = 1 ORDER BY c.created_datetime DESC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// POST: upload SCORM package
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'zip') {
    echo json_encode(['success' => false, 'error' => 'Only ZIP files are accepted']);
    exit;
}

try {
    // Open and VET the archive before anything is written to disk or to the
    // database. A package that fails the checks in includes/lms_package.php is
    // refused whole — so a rejected upload leaves no files and no stray course
    // row behind, and nothing dangerous ever reaches the web-served directory.
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        throw new Exception('That file could not be opened as a ZIP archive.');
    }

    try {
        $safeEntries = lmsValidatePackage($zip);
    } catch (Exception $e) {
        $zip->close();
        throw $e;
    }

    // Only now is the package known to be safe — create the course record.
    $stmt = $conn->prepare("INSERT INTO lms_courses (title, description, original_filename, created_by_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $description, $file['name'], $_SESSION['analyst_id']]);
    $courseId = (int)$conn->lastInsertId();

    $contentRoot = dirname(dirname(__DIR__)) . '/lms/content';
    lmsHardenContentDir($contentRoot);          // no-op once the .htaccess exists

    $contentDir = $contentRoot . '/' . $courseId;
    if (!is_dir($contentDir)) {
        mkdir($contentDir, 0755, true);
    }

    // Extract ONLY the vetted entries — never the whole archive.
    lmsExtractPackage($zip, $contentDir, $safeEntries);
    $zip->close();

    // Parse imsmanifest.xml
    $manifestPath = $contentDir . '/imsmanifest.xml';
    if (!file_exists($manifestPath)) {
        // Some packages have the manifest inside a subfolder
        $found = glob($contentDir . '/*/imsmanifest.xml');
        if (!empty($found)) {
            $manifestPath = $found[0];
        }
    }

    $scormVersion = null;
    $launchUrl = null;
    $manifestId = null;

    if (file_exists($manifestPath)) {
        $xml = @simplexml_load_file($manifestPath);
        if ($xml) {
            // Get manifest identifier
            $manifestId = (string)($xml['identifier'] ?? '');

            // Detect SCORM version from metadata
            $namespaces = $xml->getNamespaces(true);
            $schemaVersion = '';

            // Try metadata/schemaversion
            if (isset($xml->metadata->schemaversion)) {
                $schemaVersion = (string)$xml->metadata->schemaversion;
            }
            // Try metadata/schema
            if (empty($schemaVersion) && isset($xml->metadata->schema)) {
                $schemaVersion = (string)$xml->metadata->schema;
            }

            if (stripos($schemaVersion, '2004') !== false) {
                $scormVersion = '2004';
            } elseif (stripos($schemaVersion, '1.2') !== false) {
                $scormVersion = '1.2';
            } elseif (stripos($schemaVersion, '1.1') !== false) {
                $scormVersion = '1.1';
            } elseif (isset($namespaces['adlcp'])) {
                // Check namespace URL for version hint
                $ns = $namespaces['adlcp'];
                if (stripos($ns, '2004') !== false || stripos($ns, 'v1p3') !== false) {
                    $scormVersion = '2004';
                } else {
                    $scormVersion = '1.2';
                }
            } else {
                $scormVersion = '1.2'; // Default fallback
            }

            // Find launch URL: first resource with scormType="sco"
            $resources = $xml->resources->resource ?? [];
            foreach ($resources as $resource) {
                $href = (string)($resource['href'] ?? '');
                if (!empty($href)) {
                    // Check for scormType attribute (may be in adlcp namespace)
                    $scormType = '';
                    foreach ($namespaces as $prefix => $uri) {
                        if (stripos($prefix, 'adlcp') !== false) {
                            $attrs = $resource->attributes($uri);
                            if (isset($attrs['scormType']) || isset($attrs['scormtype'])) {
                                $scormType = strtolower((string)($attrs['scormType'] ?? $attrs['scormtype'] ?? ''));
                            }
                        }
                    }

                    if ($scormType === 'sco' || empty($launchUrl)) {
                        // Adjust for subfolder manifests
                        $manifestDir = dirname($manifestPath);
                        $relDir = str_replace($contentDir, '', $manifestDir);
                        $relDir = ltrim(str_replace('\\', '/', $relDir), '/');
                        $launchUrl = $relDir ? ($relDir . '/' . $href) : $href;
                    }

                    if ($scormType === 'sco') break;
                }
            }
        }
    }

    // Update course with parsed data
    $stmt = $conn->prepare("UPDATE lms_courses SET scorm_version = ?, manifest_identifier = ?, launch_url = ? WHERE id = ?");
    $stmt->execute([$scormVersion, $manifestId, $launchUrl, $courseId]);

    echo json_encode([
        'success' => true,
        'id' => $courseId,
        'scorm_version' => $scormVersion,
        'launch_url' => $launchUrl
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
