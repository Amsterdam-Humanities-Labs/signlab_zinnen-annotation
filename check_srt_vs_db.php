<?php
/**
 * Compare SRT file contents against sentences table values.
 * Checks Nederlands, Gebaar-voor-gebaar, and Signbank ID glossen.
 *
 * Usage: php /web/zin/check_srt_vs_db.php
 */

include '../mysql_config.php';

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}
$conn->set_charset("utf8mb4");

// Get all sentences with their matched_transcription m_file
$sql = "SELECT s.ID, s.zinStringEAF, s.gvg, s.glosses, mt.m_file
        FROM sentences s
        JOIN matched_transcriptions mt ON mt.m_transcription = s.ID
        WHERE mt.zOg IN ('Zin','zin') AND mt.added = '1'";

$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error . "\n");
}

$eafDir = '/web/zin/eaf/zin/';

function parseSrt($path) {
    if (!file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    $blocks = preg_split('/\n\n+/', trim($content));
    $texts = [];
    foreach ($blocks as $block) {
        $lines = explode("\n", trim($block));
        if (count($lines) >= 3) {
            // Line 0 = index, line 1 = timecodes, line 2+ = text
            $text = trim(implode("\n", array_slice($lines, 2)));
            if ($text !== '' && $text !== '-') {
                $texts[] = $text;
            }
        }
    }
    return $texts;
}

$total = 0;
$nedMismatch = 0;
$gvgMismatch = 0;
$glossMismatch = 0;
$nedMissing = 0;
$gvgMissing = 0;
$glossMissing = 0;
$mismatches = [];

while ($row = $result->fetch_assoc()) {
    $total++;
    $id = $row['ID'];
    $base = preg_replace('/\.(wav|mp4)$/i', '', $row['m_file']);

    // Nederlands
    $nedPath = $eafDir . $base . '_Nederlands.srt';
    $nedSrt = parseSrt($nedPath);
    if ($nedSrt === null) {
        $nedMissing++;
    } else {
        $srtNed = implode(' ', $nedSrt);
        $dbNed = trim($row['zinStringEAF'] ?? '');
        if ($srtNed !== $dbNed) {
            $nedMismatch++;
            if ($nedMismatch <= 10) {
                $mismatches[] = "NED  ID=$id ($base)\n  SRT: " . substr($srtNed, 0, 80) . "\n  DB:  " . substr($dbNed, 0, 80);
            }
        }
    }

    // Gebaar-voor-gebaar
    $gvgPath = $eafDir . $base . '_Gebaar-voor-gebaar.srt';
    $gvgSrt = parseSrt($gvgPath);
    if ($gvgSrt === null) {
        $gvgMissing++;
    } else {
        $srtGvg = json_encode($gvgSrt, JSON_UNESCAPED_UNICODE);
        $dbGvg = trim($row['gvg'] ?? '[]');
        if ($srtGvg !== $dbGvg) {
            $gvgMismatch++;
            if ($gvgMismatch <= 10) {
                $mismatches[] = "GVG  ID=$id ($base)\n  SRT: " . substr($srtGvg, 0, 80) . "\n  DB:  " . substr($dbGvg, 0, 80);
            }
        }
    }

    // Signbank ID glossen
    $glossPath = $eafDir . $base . '_Signbank_ID_glossen.srt';
    $glossSrt = parseSrt($glossPath);
    if ($glossSrt === null) {
        $glossMissing++;
    } else {
        $srtGloss = json_encode($glossSrt, JSON_UNESCAPED_UNICODE);
        $dbGloss = trim($row['glosses'] ?? '[]');
        if ($srtGloss !== $dbGloss) {
            $glossMismatch++;
            if ($glossMismatch <= 10) {
                $mismatches[] = "GLOS ID=$id ($base)\n  SRT: " . substr($srtGloss, 0, 80) . "\n  DB:  " . substr($dbGloss, 0, 80);
            }
        }
    }
}

$result->free();
$conn->close();

echo "=== SRT vs Database Comparison ===\n";
echo "Total sentences checked: $total\n\n";

echo "Nederlands:\n";
echo "  Mismatches: $nedMismatch\n";
echo "  SRT missing: $nedMissing\n\n";

echo "Gebaar-voor-gebaar:\n";
echo "  Mismatches: $gvgMismatch\n";
echo "  SRT missing: $gvgMissing\n\n";

echo "Signbank ID glossen:\n";
echo "  Mismatches: $glossMismatch\n";
echo "  SRT missing: $glossMissing\n\n";

$totalMismatch = $nedMismatch + $gvgMismatch + $glossMismatch;
echo "Total mismatches: $totalMismatch\n\n";

if (!empty($mismatches)) {
    echo "=== Sample mismatches (first 10 per tier) ===\n";
    foreach ($mismatches as $m) {
        echo "$m\n\n";
    }
}
