<?php
header('Content-Type: application/json');

// Include the MySQL configuration file
include '../mysql_config.php';

// Disable PHP warnings
error_reporting(E_ERROR | E_PARSE);

// Create a new MySQLi connection
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

// Check for a connection error
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Query to get unique labels
$sql = "SELECT DISTINCT label FROM sentences WHERE label IS NOT NULL AND label != '' ORDER BY label";
$result = $conn->query($sql);

if ($result) {
    $labels = [];
    while ($row = $result->fetch_assoc()) {
        $labels[] = $row['label'];
    }
    echo json_encode(['success' => true, 'labels' => $labels]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to fetch labels: ' . $conn->error]);
}

$conn->close();
?>
