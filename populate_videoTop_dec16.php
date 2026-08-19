<?php
/**
 * One-off migration + backfill for sentences.videoTop (M20241216 scope).
 *
 *  - Adds the `videoTop VARCHAR(255) NULL` column to `sentences` if missing.
 *  - For every sentence with at least one M20241216 recording, sets `videoTop`
 *    to the mp4 filename of that sentence's LAST M20241216 take (highest id),
 *    regardless of `added` status.
 *
 * Idempotent: safe to re-run. Run from CLI:  php populate_videoTop_dec16.php
 *
 * See docs/superpowers/specs/2026-06-29-videoTop-dec16-design.md
 */

include __DIR__ . '/../mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { fwrite(STDERR, "DB connection failed: " . $conn->connect_error . "\n"); exit(1); }

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
function out($s){ echo $s . "\n"; }

// 1. Add column if it does not exist.
$res = $conn->query("SHOW COLUMNS FROM sentences LIKE 'videoTop'");
if ($res && $res->num_rows > 0) {
    out("Column `videoTop` already exists — skipping ALTER.");
} else {
    if ($conn->query("ALTER TABLE sentences ADD COLUMN videoTop VARCHAR(255) NULL DEFAULT NULL")) {
        out("Added column `videoTop` to `sentences`.");
    } else {
        fwrite(STDERR, "ALTER failed: " . $conn->error . "\n"); exit(1);
    }
}

// 2. Backfill: last M20241216 take per sentence -> mp4 filename.
$sql = "
UPDATE sentences s
JOIN (
  SELECT t.m_transcription AS sid,
         REPLACE(t.m_file, '.wav', '.mp4') AS mp4
  FROM matched_transcriptions t
  JOIN (
    SELECT m_transcription, MAX(id) AS mid
    FROM matched_transcriptions
    WHERE m_file LIKE 'M20241216%' AND m_transcription REGEXP '^[0-9]+$'
    GROUP BY m_transcription
  ) last ON last.mid = t.id
) v ON v.sid = s.ID
SET s.videoTop = v.mp4";

if (!$conn->query($sql)) { fwrite(STDERR, "Backfill UPDATE failed: " . $conn->error . "\n"); exit(1); }
out("Backfill UPDATE affected rows (changed): " . $conn->affected_rows);

// 3. Report.
$r = $conn->query("SELECT COUNT(*) c FROM sentences WHERE videoTop IS NOT NULL");
out("Sentences with videoTop set: " . $r->fetch_assoc()['c'] . " (expected 399)");

out("Sample:");
$r = $conn->query("SELECT ID, videoTop, SUBSTRING(zinString,1,45) z FROM sentences WHERE videoTop IS NOT NULL ORDER BY ID LIMIT 5");
while ($x = $r->fetch_assoc()) out(sprintf("  sent#%-5s %-22s \"%s\"", $x['ID'], $x['videoTop'], $x['z']));

$conn->close();
out("Done.");
