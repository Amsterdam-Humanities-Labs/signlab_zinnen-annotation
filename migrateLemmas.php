<?php
// Migration script to convert existing lemma strings to IDs

require_once dirname(__DIR__) . '/mysql_config.php';
$dbname = $database;

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

echo "Starting lemma migration...\n";

try {
    // First, create lemmaTable if it doesn't exist
    $createTableSQL = file_get_contents('lemmaTable.sql');
    if ($conn->multi_query($createTableSQL)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
    
    // Clear any pending results
    while ($conn->more_results()) {
        $conn->next_result();
    }
    
    // Fetch all sentences with lemmaList
    $sql = "SELECT id, lemmaList FROM sentences WHERE lemmaList IS NOT NULL AND lemmaList != '[]'";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $totalRows = $result->num_rows;
    $processed = 0;
    $errors = 0;
    
    echo "Found $totalRows sentences to migrate.\n";
    
    while ($row = $result->fetch_assoc()) {
        $sentenceId = $row['id'];
        $lemmaList = json_decode($row['lemmaList'], true);
        
        if (!is_array($lemmaList)) {
            echo "Warning: Invalid lemmaList for sentence $sentenceId\n";
            $errors++;
            continue;
        }
        
        // Check if already migrated (first element is numeric)
        if (!empty($lemmaList) && is_numeric($lemmaList[0])) {
            echo "Sentence $sentenceId already migrated, skipping...\n";
            $processed++;
            continue;
        }
        
        $conn->begin_transaction();
        
        try {
            $lemmaIds = [];
            
            foreach ($lemmaList as $lemma) {
                $lemma = trim($lemma);
                
                if (empty($lemma)) {
                    continue;
                }
                
                // Check if lemma exists
                $checkStmt = $conn->prepare("SELECT id FROM lemmaTable WHERE lemma = ?");
                $checkStmt->bind_param("s", $lemma);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkRow = $checkResult->fetch_assoc()) {
                    $lemmaIds[] = $checkRow['id'];
                } else {
                    // Insert new lemma
                    $insertStmt = $conn->prepare("INSERT INTO lemmaTable (lemma) VALUES (?)");
                    $insertStmt->bind_param("s", $lemma);
                    $insertStmt->execute();
                    $lemmaIds[] = $conn->insert_id;
                    $insertStmt->close();
                }
                
                $checkStmt->close();
            }
            
            // Update sentence with ID array
            $lemmaIdsJson = json_encode($lemmaIds);
            $updateStmt = $conn->prepare("UPDATE sentences SET lemmaList = ? WHERE id = ?");
            $updateStmt->bind_param("si", $lemmaIdsJson, $sentenceId);
            $updateStmt->execute();
            $updateStmt->close();
            
            $conn->commit();
            $processed++;
            
            if ($processed % 100 == 0) {
                echo "Processed $processed/$totalRows sentences...\n";
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            echo "Error processing sentence $sentenceId: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\nMigration completed!\n";
    echo "Total processed: $processed\n";
    echo "Errors: $errors\n";
    
    // Show statistics
    $statsResult = $conn->query("SELECT COUNT(*) as count FROM lemmaTable");
    $stats = $statsResult->fetch_assoc();
    echo "Total unique lemmas in database: " . $stats['count'] . "\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}

$conn->close();
?>