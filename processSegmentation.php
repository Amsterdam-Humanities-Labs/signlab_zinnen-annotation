<?php
/**
 * Auto-Segmentation API Endpoint
 *
 * Checks for .hamer file existence and processes it through WebSocket server
 * for sign language segmentation.
 *
 * Request: POST with JSON body {filename: "M20251209_7732"}
 * Response: JSON with {success, vtt_content, metadata} or {success, error}
 *
 * @version 1.0
 * @date 2026-01-03
 */

header('Content-Type: application/json');

// Include WebSocket client class
require_once 'SignSegmentationClient.php';

// Read JSON input
$inputData = json_decode(file_get_contents('php://input'), true);

if (!$inputData || !isset($inputData['filename'])) {
    echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
    exit();
}

// Sanitize filename to prevent path traversal
$baseFilename = basename($inputData['filename']);
$baseFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $baseFilename);

// Construct .hamer file path
$rawDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
$hamerPath = $rawDir . $baseFilename . '.hamer';

// Verify the path is within the allowed directory
$realHamerPath = realpath($hamerPath);
$realRawDir = realpath($rawDir);

if ($realHamerPath === false || strpos($realHamerPath, $realRawDir) !== 0) {
    // Path doesn't exist or is outside allowed directory
    if (!file_exists($hamerPath)) {
        echo json_encode([
            'success' => false,
            'error' => 'Hamer file not available for this video.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid file path.'
        ]);
    }
    exit();
}

// Check if .hamer file exists
if (!file_exists($hamerPath)) {
    echo json_encode([
        'success' => false,
        'error' => 'Hamer file not available for this video.'
    ]);
    exit();
}

try {
    // Initialize WebSocket client
    $client = new SignSegmentationClient('ws://localhost:8765');

    // Process the hamer file (fps = 60 as per test results)
    // Optional: Add progress callback for debugging
    $result = $client->processHamerFile($hamerPath, 60, function($progress) {
        // Progress updates can be logged here if needed
        error_log("[Auto-Segmentation] {$progress['stage']}: {$progress['percentage']}%");
    });

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'vtt_content' => $result['vtt_content'],
            'metadata' => $result['metadata']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error_message'] ?? 'Segmentation processing failed.'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
