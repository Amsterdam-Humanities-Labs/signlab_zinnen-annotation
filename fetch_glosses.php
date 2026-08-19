<?php
error_reporting(E_ERROR | E_PARSE);

include('../mysql_config.php');

$glosses = $_GET['glosses'] ?? '';
$glossesArray = explode(" ", $glosses);

// Create a connection to the database
$conn = new mysqli($servername, $username, $password, $database);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// Prepare statement for fetching form_data
$stmt = $conn->prepare("SELECT * FROM form_data WHERE glos = ?");
$stmt->bind_param("s", $gloss);
$formData = [];

foreach ($glossesArray as $gloss) {
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $formData[$gloss] = $row;
    }
}
$stmt->close();

// Preload matched_transcriptions data into memory
$transcriptions = [];
$transcriptionSql = "SELECT * FROM matched_transcriptions WHERE (zOg = 'Glos' OR zOg = '') AND added != 'DELETE'";
$transcriptionResult = $conn->query($transcriptionSql);

if ($transcriptionResult) {
    while ($transcriptionRow = $transcriptionResult->fetch_assoc()) {
        if (isset($formData[$transcriptionRow['m_transcription']])) {
            continue;
        }
        $transcriptions[$transcriptionRow['definitive_outcome']][] = $transcriptionRow;
    }
}

$data = [];

foreach ($formData as $row) {
    $videoLeft = [];
    $videoCenter = [];
    $videoRight = [];
    // Removed videoA and videoB as they are not part of GebaarRaw model

    if (isset($transcriptions[$row['id']])) {
        foreach ($transcriptions[$row['id']] as $transcriptionRow) {
            $videoLeft[] = ["file" => str_replace(".wav", ".mp4", $transcriptionRow["l_file"])];
            $videoCenter[] = [
                "file" => str_replace(".wav", ".mp4", $transcriptionRow["m_file"]),
                "id" => strval($transcriptionRow["id"]),      // Ensure id is string
                "added" => $transcriptionRow["added"]
            ];
            $videoRight[] = ["file" => str_replace(".wav", ".mp4", $transcriptionRow["r_file"])];
            
            $processed = ($transcriptionRow["post_processed"] == "1") ? "2" : "1";
        }
    }

    //for usd file we are going to look in mocap_files and retrieve the latest .glb file from filename and change .glb to .usdz file
    $usd_file = "";
    $mocapSql = "SELECT * FROM mocap_files WHERE glos = '{$row['glos']}' ORDER BY id DESC LIMIT 1";
    $mocapResult = $conn->query($mocapSql);
    if ($mocapResult) {
        $mocapRow = $mocapResult->fetch_assoc();
        if ($mocapRow) {
            $usd_file = str_replace(".fbx", ".usdz", $mocapRow["filename"]);
            $usd_file = str_replace(".glb", ".usdz", $usd_file);
        }
    }

    $data[] = [
        "processed" => $processed ?? "",
        "woord" => htmlspecialchars($row["woord"] ?? "", ENT_QUOTES, 'UTF-8'),
        "signbank" => htmlspecialchars($row["signbank"] ?? "", ENT_QUOTES, 'UTF-8'),
        "glos" => htmlspecialchars($row["glos"], ENT_QUOTES, 'UTF-8'),
        "glos_engels" => htmlspecialchars($row["glos_engels"] ?? "", ENT_QUOTES, 'UTF-8'),
        "id" => strval($row["id"] ?? ""), // Convert id to string
        "videoLeft" => json_encode($videoLeft, JSON_UNESCAPED_UNICODE),
        "videoCenter" => json_encode($videoCenter, JSON_UNESCAPED_UNICODE),
        "videoRight" => json_encode($videoRight, JSON_UNESCAPED_UNICODE),
        "thema" => htmlspecialchars($row["thema"] ?? "", ENT_QUOTES, 'UTF-8'),
        "usd_file" => htmlspecialchars($usd_file ?? "", ENT_QUOTES, 'UTF-8'),
    ];
}

header("Content-Type: application/json; charset=utf-8");
echo json_encode($data, JSON_UNESCAPED_UNICODE);

$conn->close();
?>
