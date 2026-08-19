<?php
/**
 * Estimate total file size of sentence videos and thumbnails.
 * Queries matched_transcriptions where added='1' AND zOg='Zin',
 * checks MP4 and JPG files across all 5 cameras.
 *
 * Usage: php /web/zin/estimate_video_size.php
 */

include '../mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

$sql = "SELECT m_file, l_file, r_file, post_processed
        FROM matched_transcriptions
        WHERE zOg = 'Zin' AND added = '1'";

$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error . "\n");
}

$basePaths = [
    1 => '/web/gebarenoverleg_media/studioFilesMini/post/',
    0 => '/web/gebarenoverleg_media/studioFilesMini/raw/',
];

$cameras = ['m_file' => 'M', 'l_file' => 'L', 'r_file' => 'R'];

$totalMp4Size = 0;
$totalJpgSize = 0;
$mp4Count = 0;
$jpgCount = 0;
$missingCount = 0;
$rowCount = 0;
$expectedCount = 0;

$cameraStats = [];
foreach ($cameras as $col => $label) {
    $cameraStats[$label] = ['count' => 0, 'size' => 0];
}

while ($row = $result->fetch_assoc()) {
    $rowCount++;
    $basePath = $basePaths[(int)$row['post_processed']];

    foreach ($cameras as $col => $label) {
        $wavFile = $row[$col];
        if (empty($wavFile)) {
            continue;
        }

        $base = preg_replace('/\.wav$/i', '', $wavFile);
        $mp4Path = $basePath . $base . '.mp4';
        $jpgPath = $basePath . $base . '.jpg';

        // MP4
        $expectedCount++;
        if (file_exists($mp4Path)) {
            $size = filesize($mp4Path);
            $totalMp4Size += $size;
            $mp4Count++;
            $cameraStats[$label]['count']++;
            $cameraStats[$label]['size'] += $size;
        } else {
            $missingCount++;
        }

        // JPG
        $expectedCount++;
        if (file_exists($jpgPath)) {
            $size = filesize($jpgPath);
            $totalJpgSize += $size;
            $jpgCount++;
            $cameraStats[$label]['count']++;
            $cameraStats[$label]['size'] += $size;
        } else {
            $missingCount++;
        }
    }
}

$result->free();

$zinTotalSize = $totalMp4Size + $totalJpgSize;
$zinFoundCount = $mp4Count + $jpgCount;

// --- Extern (form_data) videos: LMR only ---
$sqlExtern = "SELECT mt.m_file, mt.l_file, mt.r_file, mt.post_processed
              FROM matched_transcriptions mt
              JOIN form_data fd ON mt.m_transcription = fd.id
              WHERE fd.extern = '1' AND mt.added = '1'
              AND mt.zOg IN ('glos', 'extern', 'labels')";

$resultExtern = $conn->query($sqlExtern);
if (!$resultExtern) {
    die("Extern query failed: " . $conn->error . "\n");
}

$extCameras = ['m_file' => 'M', 'l_file' => 'L', 'r_file' => 'R'];

$extMp4Size = 0;
$extJpgSize = 0;
$extMp4Count = 0;
$extJpgCount = 0;
$extMissingCount = 0;
$extRowCount = 0;
$extExpectedCount = 0;

$extCameraStats = [];
foreach ($extCameras as $col => $label) {
    $extCameraStats[$label] = ['count' => 0, 'size' => 0];
}

while ($row = $resultExtern->fetch_assoc()) {
    $extRowCount++;
    $basePath = $basePaths[(int)$row['post_processed']];

    foreach ($extCameras as $col => $label) {
        $wavFile = $row[$col];
        if (empty($wavFile)) {
            continue;
        }

        $base = preg_replace('/\.wav$/i', '', $wavFile);
        $mp4Path = $basePath . $base . '.mp4';
        $jpgPath = $basePath . $base . '.jpg';

        $extExpectedCount++;
        if (file_exists($mp4Path)) {
            $size = filesize($mp4Path);
            $extMp4Size += $size;
            $extMp4Count++;
            $extCameraStats[$label]['count']++;
            $extCameraStats[$label]['size'] += $size;
        } else {
            $extMissingCount++;
        }

        $extExpectedCount++;
        if (file_exists($jpgPath)) {
            $size = filesize($jpgPath);
            $extJpgSize += $size;
            $extJpgCount++;
            $extCameraStats[$label]['count']++;
            $extCameraStats[$label]['size'] += $size;
        } else {
            $extMissingCount++;
        }
    }
}

$resultExtern->free();
$conn->close();

$extTotalSize = $extMp4Size + $extJpgSize;
$extFoundCount = $extMp4Count + $extJpgCount;

function humanSize($bytes) {
    if ($bytes >= 1073741824) {
        return sprintf('%.2f GB', $bytes / 1073741824);
    } elseif ($bytes >= 1048576) {
        return sprintf('%.2f MB', $bytes / 1048576);
    } elseif ($bytes >= 1024) {
        return sprintf('%.2f KB', $bytes / 1024);
    }
    return $bytes . ' B';
}

echo "=== Sentence Videos (zOg='Zin') ===\n";
echo "Rows queried: $rowCount\n";
echo "Files found: $zinFoundCount / $expectedCount expected\n";
echo "  MP4 videos: $mp4Count (" . humanSize($totalMp4Size) . ")\n";
echo "  JPG thumbs: $jpgCount (" . humanSize($totalJpgSize) . ")\n";
echo "Total size: " . humanSize($zinTotalSize) . "\n";
echo "Missing files: $missingCount\n";
echo "\nBreakdown by camera:\n";
foreach ($cameraStats as $label => $stats) {
    echo "  $label: {$stats['count']} files, " . humanSize($stats['size']) . "\n";
}

echo "\n=== Extern/Form Data Videos (LMR only) ===\n";
echo "Rows queried: $extRowCount\n";
echo "Files found: $extFoundCount / $extExpectedCount expected\n";
echo "  MP4 videos: $extMp4Count (" . humanSize($extMp4Size) . ")\n";
echo "  JPG thumbs: $extJpgCount (" . humanSize($extJpgSize) . ")\n";
echo "Total size: " . humanSize($extTotalSize) . "\n";
echo "Missing files: $extMissingCount\n";
echo "\nBreakdown by camera:\n";
foreach ($extCameraStats as $label => $stats) {
    echo "  $label: {$stats['count']} files, " . humanSize($stats['size']) . "\n";
}

$grandTotal = $zinTotalSize + $extTotalSize;
$grandFound = $zinFoundCount + $extFoundCount;
$grandExpected = $expectedCount + $extExpectedCount;
$grandMissing = $missingCount + $extMissingCount;
echo "\n=== GRAND TOTAL ===\n";
echo "Files found: $grandFound / $grandExpected expected\n";
echo "Total size: " . humanSize($grandTotal) . "\n";
echo "Missing files: $grandMissing\n";
