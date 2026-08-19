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

try {
    // Get total sentences count
    $totalQuery = "SELECT COUNT(*) as count FROM sentences WHERE zinString IS NOT NULL AND zinString != ''";
    $totalResult = $conn->query($totalQuery);
    $total = $totalResult->fetch_assoc()['count'];
    
    // Get processed count
    $processedQuery = "SELECT COUNT(*) as count FROM sentences 
                       WHERE zinString IS NOT NULL AND zinString != '' 
                       AND lemma_processed = TRUE";
    $processedResult = $conn->query($processedQuery);
    $processed = $processedResult->fetch_assoc()['count'];
    
    // Get error count
    $errorQuery = "SELECT COUNT(*) as count FROM sentences 
                   WHERE zinString IS NOT NULL AND zinString != '' 
                   AND lemma_error IS NOT NULL";
    $errorResult = $conn->query($errorQuery);
    $errors = $errorResult->fetch_assoc()['count'];
    
    // Calculate pending
    $pending = $total - $processed;
    
    // Get recent errors if any
    $recentErrors = [];
    $errorDetailsQuery = "SELECT id, LEFT(zinString, 50) as sentence, lemma_error 
                          FROM sentences 
                          WHERE lemma_error IS NOT NULL 
                          ORDER BY lemma_processed_at DESC 
                          LIMIT 5";
    $errorDetailsResult = $conn->query($errorDetailsQuery);
    
    while ($row = $errorDetailsResult->fetch_assoc()) {
        $recentErrors[] = [
            'id' => $row['id'],
            'sentence' => $row['sentence'],
            'error' => $row['lemma_error']
        ];
    }
    
    // Get processing rate (last hour)
    $rateQuery = "SELECT COUNT(*) as count FROM sentences 
                  WHERE lemma_processed = TRUE 
                  AND lemma_processed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $rateResult = $conn->query($rateQuery);
    $lastHourCount = $rateResult->fetch_assoc()['count'];
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => (int)$total,
            'processed' => (int)$processed,
            'pending' => (int)$pending,
            'errors' => (int)$errors,
            'percentage' => $total > 0 ? round(($processed / $total) * 100, 2) : 0,
            'last_hour_processed' => (int)$lastHourCount
        ],
        'recent_errors' => $recentErrors
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>