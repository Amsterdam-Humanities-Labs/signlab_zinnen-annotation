<?php
// Resolve a single isolated-gloss studio clip for the annotation-tool spot panel.
//
//   GET ?gloss=GA-MAAR-A  ->  {"success":true,"url":"https://.../studioFilesMini/post/M....mp4"}
//   no clip / no gloss    ->  {"success":false}
//
// Chain: form_data.glos -> form_data.id -> matched_transcriptions.m_transcription
//        (excluding zOg 'Zin' and 'hh'; added='1') -> m_file -> studioFilesMini mp4.
// This is the fallback the browser uses when a gloss has no Signbank video.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');   // allow localhost dev to reach the live host
error_reporting(E_ERROR | E_PARSE);

include '../mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'db']);
    exit();
}
$conn->set_charset('utf8');

$gloss = trim($_GET['gloss'] ?? '');
if ($gloss === '') {
    echo json_encode(['success' => false, 'error' => 'no_gloss']);
    exit();
}

$POST_BASE = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/';
$RAW_BASE  = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/';

// Prefer a post-processed clip (more likely rendered), then the most recent.
$sql = "SELECT mt.m_file, mt.post_processed
        FROM form_data fd
        JOIN matched_transcriptions mt ON mt.m_transcription = fd.id
        WHERE fd.glos = ?
          AND mt.zOg NOT IN ('Zin','hh')
          AND mt.added = '1'
          AND mt.m_file <> ''
        ORDER BY (mt.post_processed = '1') DESC, mt.id DESC
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'prepare']);
    exit();
}
$stmt->bind_param('s', $gloss);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo json_encode(['success' => false]);
    exit();
}

$base = ($row['post_processed'] == '1') ? $POST_BASE : $RAW_BASE;
$url  = $base . preg_replace('/\.(wav)$/i', '.mp4', $row['m_file']);
echo json_encode(['success' => true, 'url' => $url], JSON_UNESCAPED_SLASHES);
