<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include '../../mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

$page = max(1, (int)($_GET['page'] ?? 1));
$view = $_GET['view'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$itemsPerPage = 100;
$offset = ($page - 1) * $itemsPerPage;

// Read crop fixes for status merge
$cropFixes = readCropFixes();
$fixesMap = [];
foreach ($cropFixes as $fix) {
    $fixesMap[$fix['m_file']] = $fix;
}

// Build the cropfixed m_file list for filtering
$cropFixedFiles = array_keys($fixesMap);

// Base WHERE conditions
$where = "mt.m_file IS NOT NULL AND mt.m_file != '' AND mt.added = '1' AND mt.zOg = 'zin'";
$types = '';
$params = [];

// Search filter
if ($search !== '') {
    $searchPattern = '%' . $search . '%';
    $where .= " AND (mt.m_file LIKE ? OR s.zinArray LIKE ?)";
    $types .= 'ss';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
}

// View filter: cropfixed = only videos with a crop fix entry
if ($view === 'cropfixed' && !empty($cropFixedFiles)) {
    $placeholders = implode(',', array_fill(0, count($cropFixedFiles), '?'));
    $where .= " AND mt.m_file IN ($placeholders)";
    $types .= str_repeat('s', count($cropFixedFiles));
    $params = array_merge($params, $cropFixedFiles);
} elseif ($view === 'cropfixed' && empty($cropFixedFiles)) {
    // No crop fixes exist, return empty
    echo json_encode([
        'success' => true,
        'rows' => [],
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => 0,
            'totalCount' => 0,
            'itemsPerPage' => $itemsPerPage
        ]
    ]);
    $conn->close();
    exit();
}

// Count query
$countSql = "SELECT COUNT(*) AS total
    FROM matched_transcriptions mt
    INNER JOIN sentences s ON s.ID = mt.m_transcription
    WHERE $where";

$countStmt = $conn->prepare($countSql);
if (!empty($types)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalCount / $itemsPerPage);

// Data query
$sql = "SELECT mt.ID AS video_id, mt.m_file, mt.l_file, mt.r_file,
               mt.post_processed, mt.m_transcription AS sentence_id,
               s.zinID, s.zinArray, s.thema
        FROM matched_transcriptions mt
        INNER JOIN sentences s ON s.ID = mt.m_transcription
        WHERE $where
        ORDER BY mt.ID DESC
        LIMIT ?, ?";

$types .= 'ii';
$params[] = $offset;
$params[] = $itemsPerPage;

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    // Merge crop fix status
    $mFile = $row['m_file'];
    if (isset($fixesMap[$mFile])) {
        $fix = $fixesMap[$mFile];
        $row['crop_fix_requested_at'] = $fix['requested_at'] ?? null;

        // Determine overall status from files array
        $allResolved = true;
        if (isset($fix['files']) && is_array($fix['files'])) {
            foreach ($fix['files'] as $fileEntry) {
                if ($fileEntry['status'] !== 'resolved') {
                    $allResolved = false;
                    break;
                }
            }
        } else {
            $allResolved = false;
        }
        $row['crop_fix_status'] = $allResolved ? 'resolved' : 'unresolved';
    } else {
        $row['crop_fix_status'] = null;
        $row['crop_fix_requested_at'] = null;
    }

    $rows[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'rows' => $rows,
    'pagination' => [
        'currentPage' => $page,
        'totalPages' => $totalPages,
        'totalCount' => (int)$totalCount,
        'itemsPerPage' => $itemsPerPage
    ]
]);

$conn->close();

/**
 * Read crop fixes from the videoFix JSON file
 */
function readCropFixes() {
    // videoFix keeps its queue outside its checkout (videofix_data/) since
    // signlab_crop-fix-manager#2; the old in-checkout path is the fallback for a host
    // that has not been migrated yet.
    require_once __DIR__ . '/../sc_paths.php';
    $file = sc_path('videofix_data', 'crop_fixes.json');
    if (!file_exists($file)) {
        $file = __DIR__ . '/../../videoFix/crop_fixes.json';
    }
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if ($data === null || !isset($data['fixes'])) {
        return [];
    }
    return $data['fixes'];
}
?>
