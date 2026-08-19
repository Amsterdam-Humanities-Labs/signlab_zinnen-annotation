<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
require_once dirname(__DIR__) . '/mysql_config.php';
$dbname = $database;

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['sentenceId']) || !isset($input['lemmas'])) {
    die(json_encode([
        'success' => false,
        'error' => 'Missing required fields: sentenceId and lemmas'
    ]));
}

$sentenceId = intval($input['sentenceId']);
$newLemmas = $input['lemmas'];

if (!is_array($newLemmas)) {
    die(json_encode([
        'success' => false,
        'error' => 'Lemmas must be an array'
    ]));
}

try {
    $conn->begin_transaction();
    
    $lemmaIds = [];
    
    // Process each lemma
    foreach ($newLemmas as $lemma) {
        $lemma = trim($lemma);
        
        if (empty($lemma)) {
            continue;
        }
        
        // Check if lemma already exists
        $checkStmt = $conn->prepare("SELECT id FROM lemmaTable WHERE lemma = ?");
        $checkStmt->bind_param("s", $lemma);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Lemma exists, use existing ID
            $lemmaIds[] = $row['id'];
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
    
    // Update sentence with new lemmaList (array of IDs)
    $lemmaListJson = json_encode($lemmaIds);
    $updateStmt = $conn->prepare("UPDATE sentences SET lemmaList = ? WHERE id = ?");
    $updateStmt->bind_param("si", $lemmaListJson, $sentenceId);
    $updateStmt->execute();
    
    if ($updateStmt->affected_rows === 0) {
        throw new Exception("No sentence found with ID: $sentenceId");
    }
    
    $updateStmt->close();
    
    // Commit transaction
    $conn->commit();
    
    // Return success with updated data
    echo json_encode([
        'success' => true,
        'sentenceId' => $sentenceId,
        'lemmaIds' => $lemmaIds,
        'lemmas' => $newLemmas,
        'message' => 'Lemmas updated successfully'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>