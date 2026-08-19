<?php
/**
 * Read-only inspection page for sentences.videoTop (M20241216 scope).
 *
 * Lists every sentence whose `videoTop` is set, showing the Dutch text, the
 * chosen take's `added` status (deleted takes flagged), and the embedded video.
 *
 * See docs/superpowers/specs/2026-06-29-videoTop-dec16-design.md
 */

include __DIR__ . '/../mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { http_response_code(500); die("DB connection failed."); }

$POST_URL  = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/';
$POST_DISK = '/web/gebarenoverleg_media/studioFilesMini/post/';

// Optional filter: ?filter=deleted shows only sentences whose chosen take is deleted.
$filter = $_GET['filter'] ?? 'all';

// Pull each sentence + the `added` status of its chosen take (matched by filename).
$sql = "
  SELECT s.ID, s.zinString, s.videoTop, mt.added, mt.date, mt.time
  FROM sentences s
  LEFT JOIN matched_transcriptions mt
    ON mt.m_transcription = s.ID
   AND mt.m_file = REPLACE(s.videoTop, '.mp4', '.wav')
  WHERE s.videoTop IS NOT NULL
  ORDER BY s.ID";
$rows = [];
$res = $conn->query($sql);
while ($x = $res->fetch_assoc()) {
    $isDeleted = ($x['added'] !== '1');
    if ($filter === 'deleted' && !$isDeleted) continue;
    $x['isDeleted'] = $isDeleted;
    $x['fileMissing'] = !file_exists($POST_DISK . $x['videoTop']);
    $rows[] = $x;
}

// Counts for the header (unfiltered).
$total = $conn->query("SELECT COUNT(*) c FROM sentences WHERE videoTop IS NOT NULL")->fetch_assoc()['c'];
$conn->close();

$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>videoTop inspection — M20241216</title>
<style>
  :root { color-scheme: light; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         margin: 0; background: #f4f5f7; color: #1d2129; }
  header { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #dcdfe4;
           padding: 14px 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); z-index: 5; }
  header h1 { font-size: 18px; margin: 0 0 4px; }
  header .meta { font-size: 13px; color: #5c6b7a; }
  header .filters { margin-top: 8px; font-size: 13px; }
  header a { text-decoration: none; padding: 4px 10px; border-radius: 6px;
             border: 1px solid #c3c9d0; color: #1d2129; margin-right: 6px; }
  header a.active { background: #1d2129; color: #fff; border-color: #1d2129; }
  .grid { display: grid; gap: 16px; padding: 20px;
          grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
  .card { background: #fff; border: 1px solid #e1e4e8; border-radius: 10px;
          overflow: hidden; display: flex; flex-direction: column; }
  .card .text { padding: 10px 12px; font-size: 15px; line-height: 1.35; }
  .card .ids { padding: 0 12px 8px; font-size: 12px; color: #6b7682;
               display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .badge { font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 999px; }
  .badge.active { background: #e3f6e8; color: #1a7f37; }
  .badge.deleted { background: #fde2e1; color: #c0362c; }
  .fname { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  video { width: 100%; display: block; background: #000; aspect-ratio: 16/9; }
  .missing { padding: 28px 12px; text-align: center; background: #fff7e6;
             color: #8a6d3b; font-size: 13px; }
</style>
</head>
<body>
<header>
  <h1>videoTop inspection — M20241216 session</h1>
  <div class="meta"><?= count($rows) ?> shown / <?= $h($total) ?> total populated · videos from <span class="fname">studioFilesMini/post/</span></div>
  <div class="filters">
    <a href="?filter=all" class="<?= $filter==='all'?'active':'' ?>">All</a>
    <a href="?filter=deleted" class="<?= $filter==='deleted'?'active':'' ?>">Deleted take only</a>
  </div>
</header>

<div class="grid">
<?php foreach ($rows as $r):
    $name = $r['videoTop'];
    $url  = $POST_URL . rawurlencode($name);
?>
  <div class="card">
    <?php if ($r['fileMissing']): ?>
      <div class="missing">⚠ mp4 not found on disk<br><span class="fname"><?= $h($name) ?></span></div>
    <?php else: ?>
      <video controls preload="none" poster="<?= $h($POST_URL . rawurlencode(str_replace('.mp4','.jpg',$name))) ?>">
        <source src="<?= $h($url) ?>" type="video/mp4">
      </video>
    <?php endif; ?>
    <div class="text"><?= $h($r['zinString']) ?></div>
    <div class="ids">
      <span>#<?= $h($r['ID']) ?></span>
      <span class="fname"><?= $h($name) ?></span>
      <?php if ($r['isDeleted']): ?>
        <span class="badge deleted">DELETED (added=<?= $h($r['added'] === null ? 'NULL' : $r['added']) ?>)</span>
      <?php else: ?>
        <span class="badge active">active</span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
</body>
</html>
