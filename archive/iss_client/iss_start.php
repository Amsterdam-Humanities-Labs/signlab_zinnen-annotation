<?php
/**
 * Start ISS inference request (non-blocking)
 * Returns immediately with a job ID
 */

header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';

use WebSocket\Client;

// Get parameters
$hamer_path = $_POST['hamer_path'] ?? $_GET['hamer_path'] ?? null;
$fps = isset($_POST['fps']) ? (int)$_POST['fps'] : (isset($_GET['fps']) ? (int)$_GET['fps'] : 60);
$request_id = $_POST['request_id'] ?? $_GET['request_id'] ?? 'req-' . time() . '-' . rand(1000, 9999);

if (empty($hamer_path)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: hamer_path']);
    exit;
}

// Create progress file path - use /var/tmp for better permissions
$progress_dir = '/var/tmp/iss_progress';
if (!is_dir($progress_dir)) {
    mkdir($progress_dir, 0777, true);
    chmod($progress_dir, 0777); // Ensure writable by all
}
$progress_file = $progress_dir . '/' . $request_id . '.json';

// Initialize progress
$progress = [
    'status' => 'starting',
    'percentage' => 0,
    'message' => 'Initializing...',
    'request_id' => $request_id,
    'started_at' => time()
];
file_put_contents($progress_file, json_encode($progress));
chmod($progress_file, 0666); // Make file writable by worker

// Fork the WebSocket request in background using Python via wrapper script
$log_file = '/var/tmp/iss_worker_' . $request_id . '.log';
$cmd = sprintf(
    '%s %s %s %s > %s 2>&1 &',
    escapeshellarg(__DIR__ . '/run_worker.sh'),
    escapeshellarg($request_id),
    escapeshellarg($hamer_path),
    escapeshellarg($fps),
    escapeshellarg($log_file)
);

exec($cmd);

// Give worker a moment to start
usleep(100000); // 100ms

// Return job ID immediately
echo json_encode([
    'success' => true,
    'request_id' => $request_id,
    'progress_url' => 'iss_client/iss_poll.php?request_id=' . urlencode($request_id)
]);
