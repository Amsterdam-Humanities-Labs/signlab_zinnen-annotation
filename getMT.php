<?php
// Include the MySQL configuration file
include '../mysql_config.php';
//disable warnings
error_reporting(E_ERROR | E_PARSE);
// Set content type to JSON for all responses
header('Content-Type: application/json');

// Retrieve 'id' and 'videoIndex' from GET parameters
$id = $_GET['id'] ?? '';
$index = $_GET['videoIndex'] ?? '';

// Validate and sanitize input
if (empty($id) || !is_numeric($index)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

// Convert 'videoIndex' to integer
$index = intval($index);

// Create a new MySQLi connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Sanitize the ID to prevent SQL injection
$id = $conn->real_escape_string($id);

// SQL query to fetch the m_file
$sql = "SELECT * FROM matched_transcriptions WHERE m_transcription = '$id' AND zOg = 'Zin' AND added='1' ORDER BY ID ASC";

$result = $conn->query($sql);

// Check if any rows are returned
if ($result && $result->num_rows > 0) {
    // Check if the requested index is within bounds
    if ($index < 0 || $index >= $result->num_rows) {
        echo json_encode(['success' => false, 'error' => 'Index out of bounds']);
        exit();
    }

    // Move the result pointer to the desired index
    $result->data_seek($index);
    $row = $result->fetch_assoc();

    // Get the m_file
    $m_file = $row['m_file'];
    $a_file = $row['a_file'];
    $b_file = $row['b_file'];
    $l_file = $row['l_file'];
    $r_file = $row['r_file'];
    $post_processed = $row['post_processed'];


    $base_srt = str_replace('.wav', '', $m_file);

    $srt_nederlands = __DIR__ . '/eaf/zin/' . $base_srt . '_Nederlands.srt';
    $srt_signbank_id_glossen = __DIR__ . '/eaf/zin/' . $base_srt . '_Signbank_ID_glossen.srt';
    $srt_gebaar_voor_gebaar = __DIR__ . '/eaf/zin/' . $base_srt . '_Gebaar-voor-gebaar.srt';
    if(file_exists($srt_nederlands))
    {
        $srt_nederlands = str_replace('/var/www/html/zin/eaf/zin/', 'https://leffe.science.uva.nl:8043/zin/eaf/zin/', $srt_nederlands);
    }
    else
    {
        $srt_nederlands = null;
    }
    if(file_exists($srt_signbank_id_glossen))
    {
        $srt_signbank_id_glossen  = str_replace('/var/www/html/zin/eaf/zin/', 'https://leffe.science.uva.nl:8043/zin/eaf/zin/', $srt_signbank_id_glossen);
    }
    else
    {
        $srt_signbank_id_glossen = null;
    }
    if(file_exists($srt_gebaar_voor_gebaar))
    {
        $srt_gebaar_voor_gebaar  = str_replace('/var/www/html/zin/eaf/zin/', 'https://leffe.science.uva.nl:8043/zin/eaf/zin/', $srt_gebaar_voor_gebaar);
    }
    else
    {
        $srt_gebaar_voor_gebaar = null;
    }


    // Generate the thumbnail path by replacing the video extension with .jpg
    $path_info = pathinfo($m_file);
    // Ensure that the original file has an extension
    if (!isset($path_info['extension'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid file format']);
        exit();
    }

    // Replace the video extension with .jpg for the thumbnail
    $thumbnail = $path_info['dirname'] . '/' . $path_info['filename'] . '.jpg';

    // Optional: Verify that the thumbnail exists (uncomment if needed)
    /*
    if (!file_exists($thumbnail)) {
        echo json_encode(['success' => false, 'error' => 'Thumbnail not found']);
        exit();
    }
    */

    // Return the modified m_file and thumbnail in JSON format
    echo json_encode([
        'success' => true,
        'm_file' => $m_file,
        'l_file' => $l_file,
        'r_file' => $r_file,
        'a_file' => $a_file,
        'b_file' => $b_file,
        'thumbnail' => $thumbnail,
        'post_processed' => $post_processed,
        'srt_nederlands' => $srt_nederlands,
        'srt_signbank_id_glossen' => $srt_signbank_id_glossen,
        'srt_gebaar_voor_gebaar' => $srt_gebaar_voor_gebaar
    ]);
} else {
    // If no result is found, return an error message
    echo json_encode(['success' => false, 'error' => 'No match found']);
}

// Close the connection
$conn->close();
?>
