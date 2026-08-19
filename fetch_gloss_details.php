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
    // Get parameters
    $label = $_GET['label'] ?? '';
    $count = $_GET['count'] ?? '';
    
    if (empty($label)) {
        throw new Exception("Label parameter is required");
    }
    
    // Validate count parameter
    $validCounts = ['0', '1', '2', '3', '4', '5', '5+'];
    if (!in_array($count, $validCounts)) {
        throw new Exception("Invalid count parameter");
    }
    
    // 1. Get glosses for this label from form_data
    $sql = "SELECT id, glos FROM form_data WHERE labels LIKE ? AND glos IS NOT NULL AND glos != ''"; // This is correct - 'labels' in form_data
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed for glosses query: " . $conn->error);
    }
    
    $searchPattern = '%' . $label . '%';
    $stmt->bind_param("s", $searchPattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Failed to execute glosses query: " . $stmt->error);
    }
    
    $glosses = [];
    while ($row = $result->fetch_assoc()) {
        $glosses[] = $row;
    }
    $stmt->close();
    
    // 2. Filter glosses based on occurrence count
    $filteredGlosses = [];
    
    foreach ($glosses as $glossItem) {
        $glos = $glossItem['glos'];
        
        // Count occurrences in sentences
        $sqlCount = "SELECT COUNT(*) as count FROM sentences WHERE glosses LIKE ?";
        $stmtCount = $conn->prepare($sqlCount);
        
        if (!$stmtCount) {
            throw new Exception("Prepare failed for count query: " . $conn->error);
        }
        
        $searchGlosPattern = '%' . $glos . '%';
        $stmtCount->bind_param("s", $searchGlosPattern);
        $stmtCount->execute();
        $resultCount = $stmtCount->get_result();
        
        if (!$resultCount) {
            throw new Exception("Failed to execute count query: " . $stmtCount->error);
        }
        
        $countRow = $resultCount->fetch_assoc();
        $occurrences = (int)$countRow['count'];
        $stmtCount->close();
        
        // Check if this gloss matches the requested count
        $matches = false;
        if ($count === '5+') {
            $matches = ($occurrences > 5);
        } else {
            $matches = ($occurrences == (int)$count);
        }
        
        if ($matches) {
            $filteredGlosses[] = [
                'id' => $glossItem['id'],
                'glos' => $glos,
                'count' => $occurrences
            ];
        }
    }
    
    // 3. Sort glosses by occurrence count (descending) and then alphabetically
    usort($filteredGlosses, function($a, $b) {
        if ($a['count'] === $b['count']) {
            return strcasecmp($a['glos'], $b['glos']);
        }
        return $b['count'] - $a['count'];
    });
    
    // 4. Return success response
    echo json_encode([
        'success' => true,
        'label' => $label,
        'count' => $count,
        'glosses' => $filteredGlosses
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
