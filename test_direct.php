<?php
/**
 * Direct test of the segmentation streaming without web server
 */

// Simulate POST input
$_POST = ['filename' => 'M20241204_0100'];
$_SERVER['REQUEST_METHOD'] = 'POST';

// Capture output
ob_start();

// Mock php://input
$GLOBALS['mock_input'] = json_encode(['filename' => 'M20241204_0100']);

// Override file_get_contents for php://input
function file_get_contents_mock($filename) {
    if ($filename === 'php://input') {
        return $GLOBALS['mock_input'];
    }
    return file_get_contents($filename);
}

// Set SSE headers (these won't show in CLI but that's ok)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

function sendSSE($data) {
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

// Test the logic
$inputData = json_decode($GLOBALS['mock_input'], true);

if (!$inputData || !isset($inputData['filename'])) {
    sendSSE(['status' => 'error', 'message' => 'Filename not provided.']);
    exit();
}

$baseFilename = basename($inputData['filename']);
$baseFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $baseFilename);
$rawDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
$hamerPath = $rawDir . $baseFilename . '.hamer';

echo "Testing with file: $hamerPath\n";

if (!file_exists($hamerPath)) {
    sendSSE(['status' => 'error', 'message' => 'Hamer file not available']);
    exit();
}

sendSSE(['status' => 'starting', 'message' => 'Connecting to segmentation service...']);

try {
    require_once __DIR__ . '/SignSegmentationClient.php';
    $client = new SignSegmentationClient('ws://localhost:8765');

    $progressCallback = function($progressData) {
        sendSSE([
            'status' => 'progress',
            'stage' => $progressData['stage'] ?? 'processing',
            'percentage' => $progressData['percentage'] ?? 0,
            'message' => $progressData['message'] ?? 'Processing...'
        ]);
    };

    $result = $client->processHamerFile($hamerPath, 60, $progressCallback);

    if ($result['success']) {
        sendSSE([
            'status' => 'completed',
            'vtt_content' => substr($result['vtt_content'], 0, 200) . '...',
            'metadata' => $result['metadata']
        ]);
        echo "\n✅ SUCCESS!\n";
    } else {
        sendSSE([
            'status' => 'error',
            'message' => $result['error_message'] ?? 'Processing failed'
        ]);
        echo "\n❌ FAILED!\n";
    }

} catch (Exception $e) {
    sendSSE([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    echo "\n❌ EXCEPTION: " . $e->getMessage() . "\n";
}
?>
