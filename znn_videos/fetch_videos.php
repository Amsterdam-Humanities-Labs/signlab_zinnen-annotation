<?php
/**
 * Fetch all video URLs from the database.
 * Outputs JSON array of sentences with their video file URLs.
 * Used by check_dimensions.py to get the list of videos to check.
 */

require_once '../../mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    fwrite(STDERR, "Connection failed: " . $conn->connect_error . "\n");
    exit(1);
}

$sql = "SELECT s.ID AS sentence_id, s.zinString,
               REPLACE(mt.l_file, '.wav', '.mp4') AS l_file,
               REPLACE(mt.m_file, '.wav', '.mp4') AS m_file,
               REPLACE(mt.r_file, '.wav', '.mp4') AS r_file
        FROM sentences s
        INNER JOIN matched_transcriptions mt
          ON mt.m_transcription = s.ID
          AND mt.zOg = 'zin'
          AND mt.added = '1'
        WHERE mt.l_file != '' AND mt.m_file != '' AND mt.r_file != ''
        ORDER BY s.ID ASC";

$result = $conn->query($sql);

if (!$result) {
    fwrite(STDERR, "Query failed: " . $conn->error . "\n");
    exit(1);
}

$localBase = '/web/gebarenoverleg_media/studioFilesMini/post/';
$webBase = 'https://media.signcollect.nl/';
$sentences = [];

while ($row = $result->fetch_assoc()) {
    $sentences[] = [
        'sentence_id' => (int)$row['sentence_id'],
        'zinString'   => $row['zinString'],
        'videos'      => [
            'left'   => $localBase . $row['l_file'],
            'center' => $localBase . $row['m_file'],
            'right'  => $localBase . $row['r_file'],
        ],
        'web_urls'    => [
            'left'   => $webBase . $row['l_file'],
            'center' => $webBase . $row['m_file'],
            'right'  => $webBase . $row['r_file'],
        ],
        'thumbnails'  => [
            'left'   => $webBase . str_replace('.mp4', '.jpg', $row['l_file']),
            'center' => $webBase . str_replace('.mp4', '.jpg', $row['m_file']),
            'right'  => $webBase . str_replace('.mp4', '.jpg', $row['r_file']),
        ],
    ];
}

$result->free();
$conn->close();

header('Content-Type: application/json');
echo json_encode($sentences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
