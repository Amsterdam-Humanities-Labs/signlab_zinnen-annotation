<?php
/**
 * Simple SSE test to verify output buffering is disabled
 */

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable ALL output buffering
while (ob_get_level()) {
    ob_end_clean();
}

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);

// Send initial SSE comment
echo ": SSE test started\n\n";
if (ob_get_level()) ob_flush();
flush();

// Send 5 test events with 1 second delay
for ($i = 1; $i <= 5; $i++) {
    echo "event: test\n";
    echo "data: " . json_encode(['count' => $i, 'message' => "Test message $i", 'time' => date('H:i:s')]) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
    sleep(1);
}

// Send done event
echo "event: done\n";
echo "data: " . json_encode(['message' => 'Test complete']) . "\n\n";
if (ob_get_level()) ob_flush();
flush();
