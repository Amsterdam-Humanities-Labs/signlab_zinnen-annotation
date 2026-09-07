<?php

// signcollect-lib's install-root resolver: sc_path(), sc_dir(), sc_root().
// Vendored shim - it finds /web/lib/paths.php, or falls back to /web.
require_once __DIR__ . '/sc_paths.php';

// One-off: add optional `user` column to sentences_logs (idempotent).
// Run: php /web/zin/add_log_user_column.php
include sc_path('mysql_config.php');
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");
if ($conn->connect_error) { die("DB connect failed: ".$conn->connect_error."\n"); }

$r = $conn->query("SHOW COLUMNS FROM sentences_logs LIKE 'user'");
if ($r && $r->num_rows > 0) { echo "Column `user` already exists.\n"; exit(0); }

if ($conn->query("ALTER TABLE sentences_logs ADD COLUMN user VARCHAR(255) NULL AFTER ip_address")) {
    echo "Added `user` column to sentences_logs.\n";
} else {
    echo "ALTER failed: ".$conn->error."\n";
    exit(1);
}
