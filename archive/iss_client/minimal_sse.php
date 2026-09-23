<?php
// Minimal SSE test
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

echo ": test\n\n";
flush();

echo "event: hello\n";
echo "data: {\"message\":\"world\"}\n\n";
flush();

exit;
