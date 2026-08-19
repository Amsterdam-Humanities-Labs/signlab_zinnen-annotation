<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Database connection
require_once dirname(__DIR__) . '/mysql_config.php';
$dbname = $database;

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Get pagination parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    // First get total count
    $countSql = "SELECT COUNT(*) as total FROM sentences WHERE zinString IS NOT NULL";
    $countResult = $conn->query($countSql);
    $totalRows = $countResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);
    
    // Fetch sentences with pagination
    $sql = "SELECT id, zinString, lemmaList FROM sentences WHERE zinString IS NOT NULL LIMIT $limit OFFSET $offset";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $sentences = [];
    
    while ($row = $result->fetch_assoc()) {
        $sentence = [
            'id' => $row['id'],
            'zinString' => $row['zinString'],
            'lemmaList' => []
        ];
        
        // Parse lemmaList - handle both old format (string array) and new format (ID array)
        if (!empty($row['lemmaList'])) {
            $lemmaData = json_decode($row['lemmaList'], true);
            
            if (is_array($lemmaData) && !empty($lemmaData)) {
                // Check if first element is numeric (new ID format) or string (old format)
                if (is_numeric($lemmaData[0])) {
                    // New format: array of IDs - fetch lemmas from lemmaTable
                    $ids = implode(',', array_map('intval', $lemmaData));
                    $lemmaQuery = "SELECT id, lemma FROM lemmaTable WHERE id IN ($ids) ORDER BY FIELD(id, $ids)";
                    $lemmaResult = $conn->query($lemmaQuery);
                    
                    if ($lemmaResult) {
                        $lemmaMap = [];
                        while ($lemmaRow = $lemmaResult->fetch_assoc()) {
                            $lemmaMap[$lemmaRow['id']] = $lemmaRow['lemma'];
                        }
                        
                        // Preserve order from original array
                        foreach ($lemmaData as $id) {
                            if (isset($lemmaMap[$id])) {
                                $sentence['lemmaList'][] = $lemmaMap[$id];
                            }
                        }
                    }
                } else {
                    // Old format: array of strings
                    $sentence['lemmaList'] = $lemmaData;
                }
            }
        }
        
        $sentences[] = $sentence;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $sentences,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $limit,
            'total_sentences' => $totalRows,
            'displayed_range' => ($offset + 1) . '-' . min($offset + $limit, $totalRows),
            'has_next' => $page < $totalPages,
            'has_previous' => $page > 1
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>