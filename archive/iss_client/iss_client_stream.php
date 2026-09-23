<?php
/**
 * ISS Server WebSocket Client - Streaming Version
 *
 * Sends sign segmentation inference requests and streams progress updates
 * back to the client using Server-Sent Events (SSE).
 *
 * Usage:
 *   Connect from JavaScript using EventSource
 *   Pass parameters via URL query string
 *
 * GET Parameters:
 *   - hamer_path: Path, filename, or URL of .hamer file (required)
 *                 Can be local path or full URL (e.g., https://example.com/file.hamer)
 *   - fps: Frames per second (optional, default: 60)
 *   - request_id: Custom request ID (optional, auto-generated if not provided)
 *
 * Response:
 *   Server-Sent Events stream with progress updates and final result
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Set headers for SSE - SIMPLE VERSION
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

// Send initial SSE comment to establish connection FIRST
echo ": SSE connection established\n\n";
flush();

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Validate required parameters
if (empty($_GET['hamer_path'])) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Missing required parameter: hamer_path']) . "\n\n";
    flush();
    exit;
}

$hamer_path = $_GET['hamer_path'];
$fps = isset($_GET['fps']) ? (int)$_GET['fps'] : 60;
$request_id = isset($_GET['request_id']) ? $_GET['request_id'] : 'req-' . time() . '-' . rand(1000, 9999);

// Configuration
$websocket_url = 'wss://signcollect.nl/ISS_Server/ws';
$timeout = 300; // 5 minutes

// Check if textalk/websocket is available
if (!class_exists('WebSocket\Client')) {
    echo "event: error\n";
    echo "data: " . json_encode([
        'error' => 'WebSocket client not available',
        'details' => 'Please install: composer require textalk/websocket'
    ]) . "\n\n";
    flush();
    exit;
}

use WebSocket\Client;

/**
 * Send SSE message
 */
function sendSSE($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

try {
    // Send initial connection event
    sendSSE('connecting', ['message' => 'Connecting to ISS Server...']);

    // Connect to ISS Server
    $client = new Client($websocket_url, [
        'timeout' => $timeout,
        'headers' => [
            'User-Agent' => 'ISS-PHP-Client-Stream/1.0'
        ]
    ]);

    sendSSE('connected', ['message' => 'Connected successfully', 'websocket_url' => $websocket_url]);

    // Prepare request
    $request = [
        'type' => 'inference_request',
        'request_id' => $request_id,
        'hamer_path' => $hamer_path,
        'fps' => $fps
    ];

    // Send request
    $client->send(json_encode($request));

    sendSSE('request_sent', [
        'request_id' => $request_id,
        'hamer_path' => $hamer_path,
        'fps' => $fps
    ]);

    // Receive messages
    $msgCount = 0;
    while (true) {
        $message = $client->receive();
        $msgCount++;

        if (empty($message)) {
            // Send keepalive every 10 empty messages
            if ($msgCount % 10 == 0) {
                sendSSE('debug', ['message' => 'Waiting for messages...', 'count' => $msgCount]);
            }
            continue;
        }

        $data = json_decode($message, true);

        if (!$data) {
            sendSSE('debug', ['message' => 'Failed to parse JSON', 'raw' => substr($message, 0, 100)]);
            continue;
        }

        $type = $data['type'] ?? '';

        // Send message to client
        sendSSE($type, $data);

        // Check if we're done
        if ($type === 'result' || $type === 'error') {
            // Close connection
            $client->close();

            // Send done event
            sendSSE('done', ['request_id' => $request_id]);
            break;
        }
    }

} catch (Exception $e) {
    sendSSE('error', [
        'message' => $e->getMessage(),
        'type' => get_class($e)
    ]);
    sendSSE('done', ['request_id' => $request_id]);
}
