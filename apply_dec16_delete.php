<?php
/**
 * Dec-16 video reset.
 * - Sets the 67 R-file videos listed in r20250527.html (all zOg='zin', added=1) to added=0.
 * - Resets all four statuses of their 60 sentences to 'Niet Klaar'.
 * - Refreshes the cached video_count for those sentences.
 *
 * RUN backup_before_dec16.php FIRST.
 * Run: php /web/zin/apply_dec16_delete.php
 *
 * Safe to re-run: idempotent, and aborts unless scope is exactly 67 rows / 60 sentences.
 */
include '/web/mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset('utf8');
if ($conn->connect_error) { die("DB connect failed: " . $conn->connect_error . "\n"); }

$html = @file_get_contents(__DIR__ . '/r20250527.html');
if ($html === false) { die("Cannot read r20250527.html\n"); }
preg_match_all('/R20\d{6}_\d{3,5}/', $html, $m);
$rfiles = array_values(array_unique($m[0]));
echo "R-files in page: " . count($rfiles) . "\n";

$mtids = []; $sids = [];
foreach ($rfiles as $rf) {
    $stmt = $conn->prepare("SELECT id, m_transcription FROM matched_transcriptions WHERE r_file=? OR r_file=?");
    $b = $rf . '.wav';
    $stmt->bind_param('ss', $rf, $b);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($x = $res->fetch_assoc()) { $mtids[$x['id']] = true; $sids[$x['m_transcription']] = true; }
    $stmt->close();
}
$mtids = array_keys($mtids);
$sids  = array_keys($sids);
echo "matched video rows: " . count($mtids) . " | sentences: " . count($sids) . "\n";

// Safety guard against unexpected scope changes
if (count($mtids) !== 67 || count($sids) !== 60) {
    die("ABORT: expected 67 rows / 60 sentences, got " . count($mtids) . " / " . count($sids) . ". No changes made.\n");
}

$in  = implode(',', array_map('intval', $mtids));
$sin = implode(',', array_map('intval', $sids));

$conn->begin_transaction();
try {
    $conn->query("UPDATE matched_transcriptions SET added='0' WHERE id IN ($in)");
    $v1 = $conn->affected_rows;

    $conn->query("UPDATE sentences
                  SET status_video='Niet Klaar', status_annotatie='Niet Klaar',
                      status_glos='Niet Klaar', status_gvg='Niet Klaar'
                  WHERE ID IN ($sin)");
    $v2 = $conn->affected_rows;

    // keep the cached counter consistent
    $conn->query("UPDATE sentences s SET s.video_count = (
                     SELECT COUNT(*) FROM matched_transcriptions mt
                     WHERE mt.m_transcription = s.ID AND mt.zOg='Zin' AND mt.added='1')
                  WHERE s.ID IN ($sin)");
    $v3 = $conn->affected_rows;

    $conn->commit();
    echo "matched_transcriptions set added=0 : $v1 rows\n";
    echo "sentences reset to 'Niet Klaar'    : $v2 rows\n";
    echo "video_count refreshed              : $v3 rows\n";
    echo "Done.\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR — rolled back: " . $e->getMessage() . "\n";
    exit(1);
}
