<?php
header('Content-Type: application/json');

// Include database configuration
include '../mysql_config.php';

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'success' => false, 
        'error' => 'Connection failed: ' . $conn->connect_error
    ]));
}

// Set charset
$conn->set_charset("utf8");

try {
    // Get gloss parameter
    $glos = $_GET['glos'] ?? '';
    
    if (empty($glos)) {
        throw new Exception("Gloss parameter is required");
    }
    
    // Get limit parameter (default: 100)
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($limit <= 0) {
        $limit = 100; // Fallback to default if invalid
    }
    
    // Fetch sentences containing the gloss
    $sql = "SELECT ID, glosses, zinArray, thema FROM sentences WHERE glosses LIKE ? LIMIT ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed for sentences query: " . $conn->error);
    }
    
    $searchPattern = '%' . $glos . '%';
    $stmt->bind_param("si", $searchPattern, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Failed to execute sentences query: " . $stmt->error);
    }
    
    $sentences = [];
    while ($row = $result->fetch_assoc()) {
        $sentences[] = $row;
    }
    $stmt->close();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'glos' => $glos,
        'count' => count($sentences),
        'sentences' => $sentences
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Close the connection
$conn->close();
?>
