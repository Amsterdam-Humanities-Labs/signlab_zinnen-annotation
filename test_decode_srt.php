<?php
/**
 * Test script to decode the SRT content properly
 */

// Simulate receiving the base64 data (76 bytes as shown in debug)
// Let's test with a real request
require_once __DIR__ . '/SignSegmentationClient.php';

$hamerPath = '/web/gebarenoverleg_media/studioFilesMini/raw/M20241204_0100.hamer';

echo "Testing SignSegmentationClient with: $hamerPath\n\n";

$client = new SignSegmentationClient('ws://localhost:8765');

$progressCallback = function($progressData) {
    echo "Progress: " . ($progressData['stage'] ?? 'unknown') . " - " .
         ($progressData['percentage'] ?? 0) . "%\n";
};

$result = $client->processHamerFile($hamerPath, 60, $progressCallback);

echo "\n=== RESULT ===\n";
echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
echo "VTT content length: " . strlen($result['vtt_content'] ?? '') . "\n";

if (isset($result['vtt_content']) && strlen($result['vtt_content']) > 0) {
    echo "\n=== VTT CONTENT (first 500 bytes) ===\n";
    echo substr($result['vtt_content'], 0, 500) . "\n";

    echo "\n=== HEX DUMP (first 100 bytes) ===\n";
    echo bin2hex(substr($result['vtt_content'], 0, 100)) . "\n";

    // Try to detect encoding
    echo "\n=== ENCODING DETECTION ===\n";
    echo "mb_detect_encoding: " . mb_detect_encoding($result['vtt_content']) . "\n";
    echo "Is valid UTF-8: " . (mb_check_encoding($result['vtt_content'], 'UTF-8') ? 'YES' : 'NO') . "\n";

    // Try converting to UTF-8
    if (!mb_check_encoding($result['vtt_content'], 'UTF-8')) {
        echo "\n=== TRYING TO CONVERT TO UTF-8 ===\n";
        $converted = mb_convert_encoding($result['vtt_content'], 'UTF-8', 'ISO-8859-1');
        echo "Converted content:\n";
        echo $converted . "\n";
    }
}

if (isset($result['metadata'])) {
    echo "\n=== METADATA ===\n";
    print_r($result['metadata']);
}

if (!$result['success']) {
    echo "\n=== ERROR ===\n";
    echo $result['error_message'] ?? 'Unknown error';
    echo "\n";
}
?>
