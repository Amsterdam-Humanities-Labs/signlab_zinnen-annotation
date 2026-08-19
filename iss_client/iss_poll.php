<?php
/**
 * Poll for ISS inference progress
 * Returns current progress status
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$request_id = $_GET['request_id'] ?? null;

if (empty($request_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing request_id parameter']);
    exit;
}

// Get progress file - use /var/tmp for better permissions
$progress_dir = '/var/tmp/iss_progress';
$progress_file = $progress_dir . '/' . $request_id . '.json';

if (!file_exists($progress_file)) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Progress not found',
        'request_id' => $request_id
    ]);
    exit;
}

// Read and return progress
$progress = json_decode(file_get_contents($progress_file), true);

// Clean up completed/error jobs older than 5 minutes
if (in_array($progress['status'] ?? '', ['completed', 'error'])) {
    $age = time() - ($progress['updated_at'] ?? 0);
    if ($age > 300) {
        @unlink($progress_file);
    }
}

echo json_encode($progress);
