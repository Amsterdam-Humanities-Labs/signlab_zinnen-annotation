#!/usr/bin/env php
<?php
/**
 * Command-line test for ISS Client
 *
 * Usage:
 *   php test_client.php <hamer_file> [fps]
 *
 * Example:
 *   php test_client.php M20241111_6433.hamer 60
 */

require_once __DIR__ . '/vendor/autoload.php';

use WebSocket\Client;

// Check arguments
if ($argc < 2) {
    echo "Usage: php test_client.php <hamer_file> [fps]\n";
    echo "Example: php test_client.php M20241111_6433.hamer 60\n";
    exit(1);
}

$hamer_path = $argv[1];
$fps = isset($argv[2]) ? (int)$argv[2] : 60;
$request_id = 'cli-test-' . time();

echo "========================================\n";
echo "ISS Client Command-Line Test\n";
echo "========================================\n";
echo "File: $hamer_path\n";
echo "FPS: $fps\n";
echo "Request ID: $request_id\n";
echo "\n";

// Configuration
$websocket_url = 'wss://signcollect.nl/ISS_Server/ws';

try {
    echo "Connecting to ISS Server...\n";

    $client = new Client($websocket_url, [
        'timeout' => 300,
        'headers' => ['User-Agent' => 'ISS-CLI-Test/1.0']
    ]);

    echo "✓ Connected!\n\n";

    // Send request
    $request = [
        'type' => 'inference_request',
        'request_id' => $request_id,
        'hamer_path' => $hamer_path,
        'fps' => $fps
    ];

    $client->send(json_encode($request));
    echo "Request sent.\n\n";

    // Receive messages
    $start_time = microtime(true);

    while (true) {
        $message = $client->receive();

        if (empty($message)) {
            continue;
        }

        $data = json_decode($message, true);

        if (!$data) {
            continue;
        }

        $type = $data['type'] ?? '';

        switch ($type) {
            case 'ack':
                echo "✓ ACK: " . ($data['message'] ?? '') . "\n";
                break;

            case 'progress':
                $stage = $data['stage'] ?? 'unknown';
                $percentage = $data['percentage'] ?? 0;
                $msg = $data['message'] ?? '';
                $bar = str_repeat('=', (int)($percentage / 5)) . str_repeat(' ', 20 - (int)($percentage / 5));
                echo sprintf("\r[%s] %s (%d%%) - %s", $bar, $stage, $percentage, $msg);
                break;

            case 'result':
                echo "\n\n";
                echo "========================================\n";
                echo "✓ RESULT RECEIVED\n";
                echo "========================================\n";

                $metadata = $data['metadata'] ?? [];

                echo "Segments detected: " . ($metadata['segments_detected'] ?? 'N/A') . "\n";
                echo "Total frames: " . ($metadata['total_frames'] ?? 'N/A') . "\n";
                echo "Duration: " . ($metadata['duration_seconds'] ?? 'N/A') . "s\n";
                echo "Processing time: " . ($metadata['processing_time'] ?? 'N/A') . "s\n";
                echo "FPS: " . ($metadata['fps'] ?? 'N/A') . "\n";
                echo "Confidence: " . (isset($metadata['confidence_score']) ? ($metadata['confidence_score'] * 100) . '%' : 'N/A') . "\n";
                echo "\n";

                if (isset($data['srt_content'])) {
                    echo "SRT Content (first 500 chars):\n";
                    echo "-----------------------------------\n";
                    echo substr($data['srt_content'], 0, 500) . "\n";
                    if (strlen($data['srt_content']) > 500) {
                        echo "... (truncated)\n";
                    }
                    echo "-----------------------------------\n";
                }

                $client->close();
                $elapsed = microtime(true) - $start_time;
                echo "\nTotal time: " . number_format($elapsed, 2) . "s\n";
                exit(0);

            case 'error':
                echo "\n\n";
                echo "✗ ERROR: " . ($data['message'] ?? 'Unknown error') . "\n";
                if (isset($data['details'])) {
                    echo "Details: " . $data['details'] . "\n";
                }
                $client->close();
                exit(1);
        }
    }

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
