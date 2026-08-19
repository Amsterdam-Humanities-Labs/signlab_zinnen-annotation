<?php
// Temporary script to add the database fields

require_once dirname(__DIR__) . '/mysql_config.php';
$dbname = $database;

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Add fields one by one to handle potential issues
$queries = [
    "ALTER TABLE sentences ADD COLUMN lemma_processed BOOLEAN DEFAULT FALSE COMMENT 'Whether AI lemma processing has been completed'",
    "ALTER TABLE sentences ADD COLUMN lemma_processed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When the lemma was last processed'",
    "ALTER TABLE sentences ADD COLUMN lemma_error TEXT DEFAULT NULL COMMENT 'Any error message from lemma processing'",
    "ALTER TABLE sentences ADD INDEX idx_lemma_processed (lemma_processed)",
    "ALTER TABLE sentences ADD INDEX idx_lemma_processed_at (lemma_processed_at)"
];

foreach ($queries as $query) {
    echo "Running: " . substr($query, 0, 50) . "...\n";
    if ($conn->query($query)) {
        echo "Success\n";
    } else {
        echo "Error: " . $conn->error . "\n";
        // Continue even if field already exists
    }
}

// Update existing sentences
echo "\nUpdating existing sentences...\n";
$updateQuery = "UPDATE sentences 
SET lemma_processed = TRUE, 
    lemma_processed_at = NOW() 
WHERE lemmaList IS NOT NULL 
  AND lemmaList != '[]' 
  AND lemmaList != ''";

if ($conn->query($updateQuery)) {
    echo "Updated " . $conn->affected_rows . " sentences\n";
} else {
    echo "Update error: " . $conn->error . "\n";
}

$conn->close();
echo "\nDone!\n";
?>