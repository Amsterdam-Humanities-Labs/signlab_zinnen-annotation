<?php
/**
 * ISS Server WebSocket Client
 *
 * Sends sign segmentation inference requests to the ISS Server.
 *
 * Usage:
 *   Call from JavaScript using AJAX/fetch
 *
 * POST Parameters:
 *   - hamer_path: Path or filename of .hamer file (required)
 *   - fps: Frames per second (optional, default: 60)
 *   - request_id: Custom request ID (optional, auto-generated if not provided)
 *
 * Response:
 *   JSON with real-time progress updates and final result
 */

// Allow CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Try form data
    $input = $_POST;
}

// Validate required parameters
if (empty($input['hamer_path'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: hamer_path']);
    exit;
}

$hamer_path = $input['hamer_path'];
$fps = isset($input['fps']) ? (int)$input['fps'] : 60;
$request_id = isset($input['request_id']) ? $input['request_id'] : 'req-' . time() . '-' . rand(1000, 9999);

// Configuration
$websocket_url = 'wss://signcollect.nl/ISS_Server/ws';
$timeout = 300; // 5 minutes

// Check if textalk/websocket is available
if (!class_exists('WebSocket\Client')) {
    http_response_code(500);
    echo json_encode([
        'error' => 'WebSocket client not available',
        'details' => 'Please install: composer require textalk/websocket'
    ]);
    exit;
}

use WebSocket\Client;

try {
    // Connect to ISS Server
    $client = new Client($websocket_url, [
        'timeout' => $timeout,
        'headers' => [
            'User-Agent' => 'ISS-PHP-Client/1.0'
        ]
    ]);

    // Prepare request
    $request = [
        'type' => 'inference_request',
        'request_id' => $request_id,
        'hamer_path' => $hamer_path,
        'fps' => $fps
    ];

    // Send request
    $client->send(json_encode($request));

    // Collect all messages
    $messages = [];
    $result = null;
    $error = null;

    // Receive messages
    while (true) {
        $message = $client->receive();

        if (empty($message)) {
            continue;
        }

        $data = json_decode($message, true);

        if (!$data) {
            continue;
        }

        // Store message
        $messages[] = $data;

        // Check message type
        $type = $data['type'] ?? '';

        if ($type === 'result') {
            $result = $data;
            break;
        } elseif ($type === 'error') {
            $error = $data;
            break;
        }
    }

    // Close connection
    $client->close();

    // Return response
    if ($result) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'request_id' => $request_id,
            'result' => $result,
            'messages' => $messages
        ]);
    } elseif ($error) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'request_id' => $request_id,
            'error' => $error,
            'messages' => $messages
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'request_id' => $request_id,
            'error' => ['message' => 'No result received'],
            'messages' => $messages
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'request_id' => $request_id,
        'error' => [
            'message' => $e->getMessage(),
            'type' => get_class($e)
        ]
    ]);
}
