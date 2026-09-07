<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/sc_paths.php';

/**
 * Full backup of `sentences` and `matched_transcriptions` before the Dec-16 video reset.
 * Run FIRST:  php /web/zin/backup_before_dec16.php
 * Writes timestamped .sql dumps to /web/zin/backups/.
 */
include sc_path('mysql_config.php');

$ts  = date('Ymd_His');
$dir = __DIR__ . '/backups';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }

$ok = true;
foreach (['sentences', 'matched_transcriptions'] as $tbl) {
    $out = "$dir/{$tbl}_backup_{$ts}.sql";
    $err = $out . '.err';
    $cmd = 'mysqldump --single-transaction --skip-lock-tables '
         . '--host='     . escapeshellarg($servername) . ' '
         . '--user='     . escapeshellarg($username)   . ' '
         . '--password=' . escapeshellarg($password)   . ' '
         . escapeshellarg($database) . ' ' . escapeshellarg($tbl)
         . ' > ' . escapeshellarg($out) . ' 2> ' . escapeshellarg($err);
    system($cmd, $rc);
    if ($rc === 0 && file_exists($out) && filesize($out) > 0) {
        echo "OK  $tbl -> $out (" . round(filesize($out) / 1024) . " KB)\n";
        @unlink($err);
    } else {
        echo "FAILED  $tbl (rc=$rc) — see $err\n";
        $ok = false;
    }
}
echo $ok ? "Backup complete.\n" : "BACKUP FAILED — do NOT run the apply script.\n";
