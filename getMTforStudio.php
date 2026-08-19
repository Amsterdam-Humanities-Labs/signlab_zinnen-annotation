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

// Function to get records from both tables
function getRecords($conn, $id) {
    $records = [];
    
    // Query matched_transcriptions table - using date and time fields for sorting
    $mt_sql = "SELECT *, 'matched_transcriptions' as source FROM matched_transcriptions 
               WHERE m_transcription = '$id' AND zOg = 'Zin' AND added='1' 
               ORDER BY date ASC, time ASC, ID ASC";
    $mt_result = $conn->query($mt_sql);
    
    if ($mt_result && $mt_result->num_rows > 0) {
        while ($row = $mt_result->fetch_assoc()) {
            $records[] = $row;
        }
    }
    
    // Query CameraRecords table - using datetime_ms field if it exists, otherwise use date and time
    $cr_sql = "SELECT *, 'CameraRecords' as source FROM CameraRecords 
               WHERE glosId = '$id' AND stateVideo = 'stopped' AND zOg= 'Zin'
               ORDER BY datetime_ms ASC, ID ASC";
    $cr_result = $conn->query($cr_sql);
    
    if ($cr_result && $cr_result->num_rows > 0) {
        while ($row = $cr_result->fetch_assoc()) {
            $records[] = $row;
        }
    }
    
    return $records;
}

// Get all matching records
$all_records = getRecords($conn, $id);
$total_records = count($all_records);

// Check if any records were found
if ($total_records > 0) {
    // Check if the requested index is within bounds
    if ($index < 0 || $index >= $total_records) {
        echo json_encode(['success' => false, 'error' => 'Index out of bounds']);
        exit();
    }

    // Get the record at the specified index
    $row = $all_records[$index];
    $source = $row['source'];

    // Initialize variables
    $m_file = null;
    $a_file = null;
    $b_file = null;
    $l_file = null;
    $r_file = null;
    $post_processed = null;
    $videoTop = null;
    $videoLabel = null;
    $videoCategory = null;
    $date = $row['date'] ?? null;
    $time = $row['time'] ?? null;

    // Handle fields based on which table the record came from
    if ($source === 'matched_transcriptions') {
        $m_file = $row['m_file'];
        $a_file = $row['a_file'];
        $b_file = $row['b_file'];
        $l_file = $row['l_file'];
        $r_file = $row['r_file'];
        $post_processed = $row['post_processed'];
        $videoLabel = $row['videoLabel'];
        $videoCategory = $row['videoCategory'];
    } else { // CameraRecords
        $videoTop = $row['videoTop'];
        if (!empty($videoTop) && !strstr($videoTop, 'http')) {
            $videoTop = "https://signcollect.nl/uploads/" . $videoTop;
        }
        $videoLabel = $row['videoLabel'];
        $videoCategory = $row['videoCategory'];
    }

    // Generate SRT file paths if m_file exists
    $srt_nederlands = null;
    $srt_signbank_id_glossen = null;
    $srt_gebaar_voor_gebaar = null;
    
    if ($m_file) {
        $base_srt = str_replace('.wav', '', $m_file);

        $srt_nederlands = __DIR__ . '/eaf/zin/' . $base_srt . '_Nederlands.srt';
        $srt_signbank_id_glossen = __DIR__ . '/eaf/zin/' . $base_srt . '_Signbank_ID_glossen.srt';
        $srt_gebaar_voor_gebaar = __DIR__ . '/eaf/zin/' . $base_srt . '_Gebaar-voor-gebaar.srt';
        
        if(file_exists($srt_nederlands)) {
            $srt_nederlands = str_replace('/var/www/html/zin/eaf/zin/', 'https://leffe.science.uva.nl:8043/zin/eaf/zin/', $srt_nederlands);
        } else {
            $srt_nederlands = null;
        }
        
        if(file_exists($srt_signbank_id_glossen)) {
            $srt_signbank_id_glossen = str_replace('/var/www/html/zin/eaf/zin/', 'https://leffe.science.uva.nl:8043/zin/eaf/zin/', $srt_signbank_id_glossen);
        } else {
            $srt_signbank_id_glossen = null;
        }
        
        if(file_exists($srt_gebaar_voor_gebaar)) {
            $srt_gebaar_voor_gebaar = str_replace('/var/www/html/zin/eaf/zin/', 'https://leffe.science.uva.nl:8043/zin/eaf/zin/', $srt_gebaar_voor_gebaar);
        } else {
            $srt_gebaar_voor_gebaar = null;
        }
    }

    // Generate thumbnail path for videoTop if available
    $thumbnail = null;
    if ($videoTop) {
        $path_info = pathinfo($videoTop);
        if (isset($path_info['dirname']) && isset($path_info['filename'])) {
            $thumbnail = $path_info['dirname'] . '/' . $path_info['filename'] . '.jpg';
        }
    }

    // Return the data in JSON format
    echo json_encode([
        'success' => true,
        'source' => $source,
        'row_id' => $row['ID'],
        'date' => $date,
        'time' => $time,
        'm_file' => $m_file,
        'l_file' => $l_file,
        'r_file' => $r_file,
        'a_file' => $a_file,
        'b_file' => $b_file,
        'videoTop' => $videoTop,
        'thumbnail' => $thumbnail,
        'videoLabel' => $videoLabel,
        'videoCategory' => $videoCategory,
        'post_processed' => $post_processed,
        'srt_nederlands' => $srt_nederlands,
        'srt_signbank_id_glossen' => $srt_signbank_id_glossen,
        'srt_gebaar_voor_gebaar' => $srt_gebaar_voor_gebaar,
    ]);
} else {
    // If no result is found, return an error message
    echo json_encode(['success' => false, 'error' => 'No match found']);
}

// Close the connection
$conn->close();
?>
