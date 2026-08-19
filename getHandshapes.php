<?php
/**
 * getHandshapes.php
 *
 * PHP endpoint for handshape recognition requests.
 * Receives AJAX requests from frontend and communicates with
 * Python WebSocket server on port 9000.
 *
 * @version 1.0
 * @date 2026-01-05
 */

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

// Parse JSON input from POST body
$inputData = json_decode(file_get_contents('php://input'), true);

// Validate required inputs
if (!$inputData || !isset($inputData['videoId'])) {
    echo json_encode(['success' => false, 'error' => 'Video ID not provided']);
    exit();
}

if (!isset($inputData['start_time']) || !isset($inputData['end_time'])) {
    echo json_encode(['success' => false, 'error' => 'Start time or end time not provided']);
    exit();
}

// Sanitize and extract parameters
$videoId = basename($inputData['videoId']); // Prevent directory traversal
$startTime = floatval($inputData['start_time']);
$endTime = floatval($inputData['end_time']);

// Validate time range
if ($endTime <= $startTime) {
    echo json_encode(['success' => false, 'error' => 'Invalid time range: end time must be greater than start time']);
    exit();
}

// Construct .hamer file path
$rawDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
$hamerPath = $rawDir . $videoId . '.hamer';

// Check if .hamer file exists
if (!file_exists($hamerPath)) {
    echo json_encode([
        'success' => false,
        'error' => 'Hamer file not available for this video',
        'path' => $videoId . '.hamer' // Don't expose full path in error
    ]);
    exit();
}

// Load HandshapeClient
require_once __DIR__ . '/HandshapeClient.php';

try {
    // Create WebSocket client connection to port 9000
    $client = new HandshapeClient('ws://localhost:9000');

    // Get handshape predictions
    $result = $client->getHandshapes($hamerPath, $startTime, $endTime);

    if ($result['success']) {
        // Success - return hand predictions
        echo json_encode([
            'success' => true,
            'l_hand' => $result['l_hand'],
            'r_hand' => $result['r_hand']
        ]);
    } else {
        // Server returned error
        echo json_encode([
            'success' => false,
            'error' => $result['error_message']
        ]);
    }

} catch (Exception $e) {
    // PHP exception (connection failure, etc.)
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
