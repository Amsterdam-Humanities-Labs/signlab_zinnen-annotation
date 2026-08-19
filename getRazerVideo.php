<?php
// Resolve the razer 2D studio recording for an animation basename.
// The mkv filenames carry a capture timestamp that is not derivable from the
// basename, so the browser asks us to glob it:
//
//   GET ?base=M20260224_5418_260317_1 [&view=MIDDLE]
//     -> {"success":true,"url":"/gebarenoverleg_media/razerFiles/M..._MIDDLE_2026-03-17_14-37-00.mkv"}
//   no match -> {"success":false,"error":"not_found"}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');   // allow localhost dev to reach the live host

$base = $_GET['base'] ?? '';
$view = strtoupper($_GET['view'] ?? 'MIDDLE');

if (!preg_match('/^[A-Za-z0-9_-]+$/', $base)) {
    echo json_encode(['success' => false, 'error' => 'bad_base']);
    exit();
}
if (!in_array($view, ['LEFT', 'MIDDLE', 'RIGHT'], true)) {
    echo json_encode(['success' => false, 'error' => 'bad_view']);
    exit();
}

$dir = '/web/gebarenoverleg_media/razerFiles/';
$matches = glob($dir . $base . '_' . $view . '_*.mkv');
if (!$matches) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit();
}

sort($matches);                       // newest capture if there are several
$file = basename(end($matches));
echo json_encode([
    'success' => true,
    'url' => '/gebarenoverleg_media/razerFiles/' . rawurlencode($file),
]);
