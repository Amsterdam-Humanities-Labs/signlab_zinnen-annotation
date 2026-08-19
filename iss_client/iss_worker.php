#!/usr/bin/env php
<?php
/**
 * Background worker for ISS WebSocket communication
 * Run via CLI: php iss_worker.php <request_id> <hamer_path> <fps>
 */

require_once __DIR__ . '/vendor/autoload.php';

use WebSocket\Client;

// Get CLI arguments
$request_id = $argv[1] ?? null;
$hamer_path = $argv[2] ?? null;
$fps = isset($argv[3]) ? (int)$argv[3] : 60;

if (!$request_id || !$hamer_path) {
    error_log("ISS Worker: Missing arguments");
    exit(1);
}

// Progress file
$progress_dir = sys_get_temp_dir() . '/iss_progress';
$progress_file = $progress_dir . '/' . $request_id . '.json';

function updateProgress($file, $data) {
    $data['updated_at'] = time();
    file_put_contents($file, json_encode($data));
}

try {
    // Update: Connecting
    updateProgress($progress_file, [
        'status' => 'connecting',
        'percentage' => 5,
        'message' => 'Connecting to ISS Server...',
        'request_id' => $request_id
    ]);

    // Connect to ISS Server
    $websocket_url = 'wss://signcollect.nl/ISS_Server/ws';
    $client = new Client($websocket_url, [
        'timeout' => 300,
        'headers' => ['User-Agent' => 'ISS-PHP-Worker/1.0']
    ]);

    // Update: Connected
    updateProgress($progress_file, [
        'status' => 'connected',
        'percentage' => 10,
        'message' => 'Connected to ISS Server',
        'request_id' => $request_id
    ]);

    // Send request
    $request = [
        'type' => 'inference_request',
        'request_id' => $request_id,
        'hamer_path' => $hamer_path,
        'fps' => $fps
    ];

    $client->send(json_encode($request));

    // Update: Request sent
    updateProgress($progress_file, [
        'status' => 'request_sent',
        'percentage' => 15,
        'message' => 'Request sent to ISS Server',
        'request_id' => $request_id
    ]);

    // Receive messages with timeout
    $start_time = time();
    $max_runtime = 300; // 5 minutes max
    $last_update = time();

    while (true) {
        // Check timeout
        if (time() - $start_time > $max_runtime) {
            updateProgress($progress_file, [
                'status' => 'error',
                'percentage' => 0,
                'message' => 'Processing timeout after 5 minutes',
                'request_id' => $request_id
            ]);
            $client->close();
            exit(1);
        }

        try {
            $message = $client->receive();
        } catch (Exception $e) {
            error_log("Worker receive error: " . $e->getMessage());
            sleep(1);
            continue;
        }

        if (empty($message)) {
            // Send keepalive update every 10 seconds
            if (time() - $last_update > 10) {
                $current = json_decode(file_get_contents($progress_file), true);
                $current['updated_at'] = time();
                file_put_contents($progress_file, json_encode($current));
                $last_update = time();
            }
            usleep(100000); // 100ms
            continue;
        }

        $data = json_decode($message, true);

        if (!$data) {
            error_log("Worker: Failed to parse JSON: " . substr($message, 0, 200));
            continue;
        }

        $type = $data['type'] ?? '';
        error_log("Worker: Received type=$type for request=$request_id");

        switch ($type) {
            case 'ack':
                updateProgress($progress_file, [
                    'status' => 'acknowledged',
                    'percentage' => 20,
                    'message' => $data['message'] ?? 'Request acknowledged',
                    'request_id' => $request_id
                ]);
                $last_update = time();
                break;

            case 'progress':
                updateProgress($progress_file, [
                    'status' => 'progress',
                    'stage' => $data['stage'] ?? 'processing',
                    'percentage' => min(95, max(20, $data['percentage'] ?? 50)),
                    'message' => $data['message'] ?? 'Processing...',
                    'request_id' => $request_id
                ]);
                $last_update = time();
                break;

            case 'result':
                error_log("Worker: Received result for request=$request_id");
                updateProgress($progress_file, [
                    'status' => 'completed',
                    'percentage' => 100,
                    'message' => 'Segmentation complete!',
                    'request_id' => $request_id,
                    'result' => $data
                ]);
                $client->close();
                error_log("Worker: Completed successfully for request=$request_id");
                exit(0);

            case 'error':
                error_log("Worker: Received error for request=$request_id: " . ($data['message'] ?? 'unknown'));
                updateProgress($progress_file, [
                    'status' => 'error',
                    'percentage' => 0,
                    'message' => $data['message'] ?? 'Segmentation failed',
                    'request_id' => $request_id,
                    'error' => $data
                ]);
                $client->close();
                exit(1);
        }
    }

} catch (Exception $e) {
    updateProgress($progress_file, [
        'status' => 'error',
        'percentage' => 0,
        'message' => 'Error: ' . $e->getMessage(),
        'request_id' => $request_id
    ]);
    exit(1);
}
