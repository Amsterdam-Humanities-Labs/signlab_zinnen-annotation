<?php
/**
 * resync_video_count.php
 * Recomputes the cached sentences.video_count column from matched_transcriptions
 * (active sentence videos = zOg='Zin' AND added='1').
 *
 * Run manually:   php /web/zin/resync_video_count.php
 * Run via cron:   see crontab entry (daily).
 *
 * Safe to run repeatedly; it only updates rows whose cached count is wrong.
 */

include '/web/mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");
if ($conn->connect_error) {
    fwrite(STDERR, "[".date('Y-m-d H:i:s')."] DB connect failed: ".$conn->connect_error."\n");
    exit(1);
}

$before = $conn->query("SELECT COALESCE(SUM(video_count),0) c FROM sentences")->fetch_assoc()['c'];

$sql = "UPDATE sentences s SET s.video_count = (
            SELECT COUNT(*) FROM matched_transcriptions mt
            WHERE mt.m_transcription = s.ID AND mt.zOg = 'Zin' AND mt.added = '1'
        )";

$t0 = microtime(true);
if (!$conn->query($sql)) {
    fwrite(STDERR, "[".date('Y-m-d H:i:s')."] Recount FAILED: ".$conn->error."\n");
    exit(1);
}
$dt   = round(microtime(true) - $t0, 2);
$rows = $conn->affected_rows;
$after = $conn->query("SELECT COALESCE(SUM(video_count),0) c FROM sentences")->fetch_assoc()['c'];

fwrite(STDOUT, "[".date('Y-m-d H:i:s')."] video_count resync ok in {$dt}s; rows_changed={$rows}; SUM before={$before} after={$after}\n");
$conn->close();
