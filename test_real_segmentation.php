<?php
/**
 * Test the real segmentation streaming with actual WebSocket connection
 */

error_log("=== Test Segmentation Streaming Started ===");

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);
if (ob_get_level() > 0) {
    ob_end_flush();
}

function sendSSE($data) {
    $json = json_encode($data);
    echo "data: " . $json . "\n\n";
    error_log("SSE sent: " . $json);
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// Test with a real filename
$testFilename = 'M20241204_0100';
$rawDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
$hamerPath = $rawDir . $testFilename . '.hamer';

error_log("Testing with file: " . $hamerPath);

if (!file_exists($hamerPath)) {
    sendSSE(['status' => 'error', 'message' => 'Test file not found: ' . $hamerPath]);
    error_log("ERROR: File not found");
    exit();
}

sendSSE(['status' => 'starting', 'message' => 'Test: File found, connecting to service...']);
error_log("File exists, attempting WebSocket connection");

try {
    require_once __DIR__ . '/SignSegmentationClient.php';
    error_log("SignSegmentationClient loaded");

    $client = new SignSegmentationClient('ws://localhost:8765');
    error_log("Client created");

    // Define progress callback
    $progressCallback = function($progressData) {
        error_log("Progress callback received: " . json_encode($progressData));
        sendSSE([
            'status' => 'progress',
            'stage' => $progressData['stage'] ?? 'processing',
            'percentage' => $progressData['percentage'] ?? 0,
            'message' => $progressData['message'] ?? 'Processing...'
        ]);
    };

    error_log("Calling processHamerFile...");
    $result = $client->processHamerFile($hamerPath, 60, $progressCallback);
    error_log("processHamerFile returned: " . json_encode($result));

    if ($result['success']) {
        sendSSE([
            'status' => 'completed',
            'vtt_content' => substr($result['vtt_content'], 0, 100) . '...', // Truncate for test
            'metadata' => $result['metadata']
        ]);
        error_log("SUCCESS: Segmentation completed");
    } else {
        sendSSE([
            'status' => 'error',
            'message' => $result['error_message'] ?? 'Processing failed'
        ]);
        error_log("ERROR: " . ($result['error_message'] ?? 'Unknown error'));
    }

} catch (Exception $e) {
    $errorMsg = 'Server error: ' . $e->getMessage();
    sendSSE(['status' => 'error', 'message' => $errorMsg]);
    error_log("EXCEPTION: " . $errorMsg);
    error_log("Stack trace: " . $e->getTraceAsString());
}

error_log("=== Test Segmentation Streaming Ended ===");
?>
