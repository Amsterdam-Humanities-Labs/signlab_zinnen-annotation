<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', 0);

// Get datetime_ms from the request
$datetime_ms = isset($_GET['datetime_ms']) ? (int)$_GET['datetime_ms'] : 0;

if ($datetime_ms > 0) {
    // Convert datetime_ms to date in the format YYYYMMDD
    $date = date('Y-m-d', $datetime_ms / 1000);
    $formattedDate = str_replace('-', '', $date); // Converts date to YYYYMMDD format
    // Define directory path
    $dir = "/var/www/html/gebarenoverleg_media/studioFilesMini/raw/";

    // Check if the directory exists and get files
    $files = [];
    if (is_dir($dir)) {
        $allFiles = scandir($dir);
        $files = array_diff($allFiles, array('.', '..')); // Remove . and ..

        // Filter files based on the formatted date and file extension
        $files = array_filter($files, function($file) use ($formattedDate) {
            return strpos($file, $formattedDate) !== false && pathinfo($file, PATHINFO_EXTENSION) === 'mp4';
        });
    }

    // Initialize camera bindings and camera file arrays
    $cameraBindings = ['L' => 'camera1', 'R' => 'camera2', 'A' => 'camera3', 'B' => 'camera4', 'M' => 'camera5'];
    $cameraFiles = ['camera1' => [], 'camera2' => [], 'camera3' => [], 'camera4' => [], 'camera5' => []];

    // Collect files for each camera based on their starting letters
    foreach ($files as $file) {
        $firstChar = strtoupper($file[0]);
        if (isset($cameraBindings[$firstChar])) {
            $cameraKey = $cameraBindings[$firstChar];
            $cameraFiles[$cameraKey][] = $file; // Add the file to the respective camera array
        }
    }

    // Get camera requests from GET parameters
    $cameraIndices = [
        'camera1' => isset($_GET['camera1']) ? (int)$_GET['camera1'] : 1,
        'camera2' => isset($_GET['camera2']) ? (int)$_GET['camera2'] : 1,
        'camera3' => isset($_GET['camera3']) ? (int)$_GET['camera3'] : 1,
        'camera4' => isset($_GET['camera4']) ? (int)$_GET['camera4'] : 1,
        'camera5' => isset($_GET['camera5']) ? (int)$_GET['camera5'] : 1,
    ];

    // Function to get the file by index, accounting for 1-based indexing from GET parameters
    function getFileByIndex($files, $index) {
        $filesArray = array_values($files);
        return isset($filesArray[$index - 1]) ? $filesArray[$index - 1] : null; // Adjust index to 0-based
    }

    // Match camera requests to files based on their indices
    $result = [];
    foreach ($cameraFiles as $camera => $files) {
        $index = $cameraIndices[$camera];
        $result[$camera] = getFileByIndex($files, $index) ?? "No file available for $camera";
    }

    // Return the results
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
} else {
    header('Content-Type: application/json');
    echo json_encode(["error" => "Invalid datetime_ms provided."]);
}
?>
