<?php
/**
 * Test script for SSE segmentation streaming
 */

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
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// Test 1: Send initial message
sendSSE(['status' => 'test', 'message' => 'Test script started']);
sleep(1);

// Test 2: Send progress messages
for ($i = 0; $i <= 100; $i += 10) {
    sendSSE([
        'status' => 'progress',
        'stage' => 'testing',
        'percentage' => $i,
        'message' => "Test progress at {$i}%"
    ]);
    usleep(500000); // 0.5 seconds
}

// Test 3: Send completion
sendSSE([
    'status' => 'completed',
    'message' => 'Test completed successfully'
]);
?>
