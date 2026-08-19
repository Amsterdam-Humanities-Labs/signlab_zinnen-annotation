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

// Get search parameters
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

if (empty($query)) {
    die(json_encode([
        'success' => false,
        'error' => 'Search query is required'
    ]));
}

try {
    $sentences = [];
    $totalRows = 0;
    
    // Check if query is numeric (search by ID)
    if (is_numeric($query)) {
        $sentenceId = intval($query);
        
        // Count query
        $countSql = "SELECT COUNT(*) as total FROM sentences WHERE id = ?";
        $countStmt = $conn->prepare($countSql);
        $countStmt->bind_param("i", $sentenceId);
        $countStmt->execute();
        $totalRows = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();
        
        // Fetch query
        $sql = "SELECT id, zinString, lemmaList, lemma_processed, lemma_processed_at, lemma_error 
                FROM sentences WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $sentenceId);
        
    } else {
        // Search by lemma keyword
        $searchPattern = "%$query%";
        
        // First, find lemma IDs that match the search
        $lemmaQuery = "SELECT id FROM lemmaTable WHERE lemma LIKE ?";
        $lemmaStmt = $conn->prepare($lemmaQuery);
        $lemmaStmt->bind_param("s", $searchPattern);
        $lemmaStmt->execute();
        $lemmaResult = $lemmaStmt->get_result();
        
        $lemmaIds = [];
        while ($row = $lemmaResult->fetch_assoc()) {
            $lemmaIds[] = $row['id'];
        }
        $lemmaStmt->close();
        
        if (empty($lemmaIds)) {
            // No matching lemmas found
            echo json_encode([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => 0,
                    'per_page' => $limit,
                    'total_sentences' => 0,
                    'displayed_range' => '0-0',
                    'has_next' => false,
                    'has_previous' => false
                ],
                'search_query' => $query
            ]);
            exit;
        }
        
        // Search for sentences containing these lemma IDs
        $lemmaIdsList = implode(',', $lemmaIds);
        
        // Count query - sentences that contain any of the matching lemma IDs
        $countSql = "SELECT COUNT(DISTINCT s.id) as total 
                     FROM sentences s 
                     WHERE s.lemmaList IS NOT NULL 
                     AND (";
        
        $conditions = [];
        foreach ($lemmaIds as $lemmaId) {
            $conditions[] = "JSON_CONTAINS(s.lemmaList, '$lemmaId', '$')";
        }
        $countSql .= implode(" OR ", $conditions) . ")";
        
        $countResult = $conn->query($countSql);
        $totalRows = $countResult->fetch_assoc()['total'];
        
        // Fetch query
        $sql = "SELECT DISTINCT s.id, s.zinString, s.lemmaList, s.lemma_processed, 
                s.lemma_processed_at, s.lemma_error 
                FROM sentences s 
                WHERE s.lemmaList IS NOT NULL 
                AND (";
        $sql .= implode(" OR ", $conditions) . ")";
        $sql .= " ORDER BY s.id LIMIT $limit OFFSET $offset";
        
        $result = $conn->query($sql);
        $stmt = null;
    }
    
    // Execute the query
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    // Process results
    while ($row = $result->fetch_assoc()) {
        $sentence = [
            'id' => $row['id'],
            'zinString' => $row['zinString'],
            'lemmaList' => [],
            'lemma_processed' => (bool)$row['lemma_processed'],
            'lemma_processed_at' => $row['lemma_processed_at'],
            'lemma_error' => $row['lemma_error']
        ];
        
        // Parse lemmaList
        if (!empty($row['lemmaList'])) {
            $lemmaData = json_decode($row['lemmaList'], true);
            
            if (is_array($lemmaData) && !empty($lemmaData)) {
                // Check if first element is numeric (new ID format)
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
    
    if ($stmt) {
        $stmt->close();
    }
    
    $totalPages = ceil($totalRows / $limit);
    
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
        ],
        'search_query' => $query
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>