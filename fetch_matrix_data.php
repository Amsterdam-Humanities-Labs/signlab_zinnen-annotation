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
    // 1. Fetch labels from uniqueLabels.php
    $labelsJsonUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/uniqueLabels.php';
    $labelsJson = @file_get_contents($labelsJsonUrl);
    
    if ($labelsJson === false) {
        // If external fetch fails, try direct database query
        $sql = "SELECT DISTINCT label, color FROM labels WHERE label IS NOT NULL AND label != '' ORDER BY label ASC";
        $result = $conn->query($sql);
        
        if (!$result) {
            throw new Exception("Failed to fetch labels: " . $conn->error);
        }
        
        $labels = [];
        while ($row = $result->fetch_assoc()) {
            $rowLabels = explode(',', $row['label']); // This is correct - 'label' in the labels table
            foreach ($rowLabels as $label) {
                $trimmedLabel = trim($label);
                if (!empty($trimmedLabel)) {
                    $labelExists = false;
                    foreach ($labels as $existingLabel) {
                        if ($existingLabel['name'] === $trimmedLabel) {
                            $labelExists = true;
                            break;
                        }
                    }
                    
                    if (!$labelExists) {
                        $labels[] = [
                            'name' => $trimmedLabel,
                            'color' => $row['color'] ?? '#e9ecef'
                        ];
                    }
                }
            }
        }
    } else {
        $labels = json_decode($labelsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse labels JSON: " . json_last_error_msg());
        }
    }
    
    // Initialize matrix data
    $matrixData = [];
    $totalGlosses = 0;
    
    // 2. Process each label
    foreach ($labels as $label) {
        $labelName = $label['name'];
        
        // 3. Get glosses for this label from form_data
        $sql = "SELECT id, glos FROM form_data WHERE labels LIKE ? AND glos IS NOT NULL AND glos != ''"; // Fixed: Use 'labels' column in form_data
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed for glosses query: " . $conn->error);
        }
        
        $searchPattern = '%' . $labelName . '%';
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
        
        // Skip labels with no glosses
        if (empty($glosses)) {
            continue;
        }
        
        // 4. Initialize counters for this label
        $occurrenceCounts = [
            0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, '5+' => 0
        ];
        
        // 5. Count occurrences for each gloss
        foreach ($glosses as $glossItem) {
            $glos = $glossItem['glos'];
            
            // 6. Look for this gloss in sentences
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
            
            // 7. Increment the appropriate counter
            if ($occurrences > 5) {
                $occurrenceCounts['5+']++;
            } else {
                $occurrenceCounts[$occurrences]++;
            }
        }
        
        $totalLabelGlosses = count($glosses);
        $totalGlosses += $totalLabelGlosses;
        
        // 8. Add to matrix data
        $matrixData[] = [
            'label' => $labelName,
            'color' => $label['color'],
            'totalGlosses' => $totalLabelGlosses,
            'occurrenceCounts' => $occurrenceCounts
        ];
    }
    
    // 9. Sort matrix data by total number of glosses (descending)
    usort($matrixData, function($a, $b) {
        return $b['totalGlosses'] - $a['totalGlosses'];
    });
    
    // 10. Return success response
    echo json_encode([
        'success' => true,
        'matrix' => $matrixData,
        'labelCount' => count($matrixData),
        'totalGlosses' => $totalGlosses
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
