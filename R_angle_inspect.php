<?php
/**
 * R-angle (right camera) inspection + manual-delete tool for the M20241216 session.
 *
 * Lists matched_transcriptions rows from 16 Dec 2024 showing the R-angle video,
 * so wrong-angle takes can be spotted. Each card has a "Mark DELETE" button that
 * sets that row's `added='DELETE'` (recoverable via Undo).
 *
 * NOTE: `added` is per-row, so deleting marks the whole recording (M/L/R/A/B),
 * not only the R file. The write action is hard-scoped to M20241216 rows.
 *
 * See docs/superpowers/specs/2026-06-29-videoTop-dec16-design.md
 */

include __DIR__ . '/../mysql_config.php';
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) { http_response_code(500); die("DB connection failed."); }

/* ---- AJAX write handler (POST) ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $id  = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'];
    $newVal = $act === 'del' ? 'DELETE' : ($act === 'undo' ? '1' : null);
    if ($newVal === null || $id <= 0) { echo json_encode(['ok'=>false,'error'=>'bad request']); exit; }
    // Hard scope: only ever touch M20241216 rows.
    $stmt = $conn->prepare("UPDATE matched_transcriptions SET added=? WHERE id=? AND m_file LIKE 'M20241216%'");
    $stmt->bind_param('si', $newVal, $id);
    $stmt->execute();
    echo json_encode(['ok'=>true, 'id'=>$id, 'added'=>$newVal, 'affected'=>$stmt->affected_rows]);
    exit;
}

/* ---- Page render ---- */
$POST_URL  = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/';
$POST_DISK = '/web/gebarenoverleg_media/studioFilesMini/post/';

$filter = $_GET['filter'] ?? 'active';
$cond = match ($filter) {
    'deleted' => "mt.added = 'DELETE'",
    'all'     => "mt.added IN ('1','DELETE')",
    default   => "mt.added = '1'",
};

$sql = "
  SELECT mt.id, mt.r_file, mt.m_file, mt.added, mt.time,
         s.ID AS sid, s.zinString
  FROM matched_transcriptions mt
  LEFT JOIN sentences s ON s.ID = mt.m_transcription
  WHERE mt.m_file LIKE 'M20241216%' AND mt.r_file <> '' AND ($cond)
  ORDER BY mt.id";
$rows = [];
$res = $conn->query($sql);
while ($x = $res->fetch_assoc()) {
    $mp4 = preg_replace('/\.wav$/i', '.mp4', $x['r_file']);
    $x['mp4'] = $mp4;
    $x['fileMissing'] = !file_exists($POST_DISK . $mp4);
    $rows[] = $x;
}

// Header counts (Dec16 R rows).
$cnt = $conn->query("SELECT
    SUM(added='1') AS active, SUM(added='DELETE') AS deleted, COUNT(*) AS tot
    FROM matched_transcriptions WHERE m_file LIKE 'M20241216%' AND r_file <> ''")->fetch_assoc();
$conn->close();

$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>R-angle inspection — M20241216</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin:0; background:#f4f5f7; color:#1d2129; }
  header { position:sticky; top:0; background:#fff; border-bottom:1px solid #dcdfe4; padding:14px 20px; box-shadow:0 1px 3px rgba(0,0,0,.06); z-index:5; }
  header h1 { font-size:18px; margin:0 0 4px; }
  header .meta { font-size:13px; color:#5c6b7a; }
  header .filters { margin-top:8px; font-size:13px; }
  header a { text-decoration:none; padding:4px 10px; border-radius:6px; border:1px solid #c3c9d0; color:#1d2129; margin-right:6px; }
  header a.active { background:#1d2129; color:#fff; border-color:#1d2129; }
  .note { font-size:12px; color:#8a6d3b; background:#fff7e6; border:1px solid #f0d9a8; border-radius:6px; padding:6px 10px; margin-top:8px; }
  .grid { display:grid; gap:16px; padding:20px; grid-template-columns:repeat(auto-fill, minmax(320px,1fr)); }
  .card { background:#fff; border:1px solid #e1e4e8; border-radius:10px; overflow:hidden; display:flex; flex-direction:column; transition:opacity .15s; }
  .card.is-deleted { opacity:.55; }
  .card .text { padding:10px 12px; font-size:15px; line-height:1.35; }
  .card .ids { padding:0 12px 8px; font-size:12px; color:#6b7682; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .badge { font-size:11px; font-weight:600; padding:2px 7px; border-radius:999px; }
  .badge.active { background:#e3f6e8; color:#1a7f37; }
  .badge.deleted { background:#fde2e1; color:#c0362c; }
  .fname { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; }
  video { width:100%; display:block; background:#000; aspect-ratio:16/9; }
  .missing { padding:28px 12px; text-align:center; background:#fff7e6; color:#8a6d3b; font-size:13px; }
  .actions { padding:0 12px 12px; }
  button.act { font-size:13px; font-weight:600; padding:7px 12px; border-radius:7px; cursor:pointer; border:1px solid transparent; width:100%; }
  button.del { background:#c0362c; color:#fff; }
  button.del:hover { background:#a82c23; }
  button.undo { background:#fff; color:#1d2129; border-color:#c3c9d0; }
  button.undo:hover { background:#f0f1f3; }
  button:disabled { opacity:.5; cursor:default; }
  .confirm { display:flex; flex-direction:column; gap:6px; }
  .confirm .q { font-size:13px; color:#c0362c; font-weight:600; text-align:center; }
  .confirm .row { display:flex; gap:8px; }
  .confirm .row button.act { width:auto; flex:1; }
  .err { font-size:12px; color:#c0362c; margin-top:6px; text-align:center; }
</style>
</head>
<body>
<header>
  <h1>R-angle inspection — M20241216 (16 Dec 2024)</h1>
  <div class="meta">
    Showing <?= count($rows) ?> · session R rows: <?= $h($cnt['active']) ?> active,
    <?= $h($cnt['deleted']) ?> deleted, <?= $h($cnt['tot']) ?> total ·
    videos = <span class="fname">r_file</span> from <span class="fname">studioFilesMini/post/</span>
  </div>
  <div class="filters">
    <a href="?filter=active"  class="<?= $filter==='active'?'active':'' ?>">Active (to review)</a>
    <a href="?filter=deleted" class="<?= $filter==='deleted'?'active':'' ?>">Already deleted</a>
    <a href="?filter=all"     class="<?= $filter==='all'?'active':'' ?>">All</a>
  </div>
  <div class="note">⚠ Deleting sets <span class="fname">added='DELETE'</span> on the whole recording (M/L/R/A/B together), not just the R file. Use Undo to revert.</div>
</header>

<div class="grid">
<?php foreach ($rows as $r):
    $isDel = ($r['added'] === 'DELETE');
    $url = $POST_URL . rawurlencode($r['mp4']);
?>
  <div class="card <?= $isDel?'is-deleted':'' ?>" data-id="<?= $h($r['id']) ?>">
    <?php if ($r['fileMissing']): ?>
      <div class="missing">⚠ R mp4 not found on disk<br><span class="fname"><?= $h($r['mp4']) ?></span></div>
    <?php else: ?>
      <video controls preload="none" poster="<?= $h($POST_URL . rawurlencode(preg_replace('/\.mp4$/','.jpg',$r['mp4']))) ?>">
        <source src="<?= $h($url) ?>" type="video/mp4">
      </video>
    <?php endif; ?>
    <div class="text"><?= $h($r['sid'] ? $r['zinString'] : '(no linked sentence)') ?></div>
    <div class="ids">
      <span>row <?= $h($r['id']) ?></span>
      <?php if ($r['sid']): ?><span>· sent#<?= $h($r['sid']) ?></span><?php endif; ?>
      <span class="fname"><?= $h($r['mp4']) ?></span>
      <span class="badge status <?= $isDel?'deleted':'active' ?>"><?= $isDel?'DELETED':'active' ?></span>
    </div>
    <div class="actions">
      <?php if ($isDel): ?>
        <button class="act undo" onclick="doToggle(this,'undo')">Undo (restore to added=1)</button>
      <?php else: ?>
        <button class="act del" onclick="askDelete(this)">Mark DELETE</button>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
// Inline (no native dialog) confirm: first click swaps the button for a
// "Delete take? [Confirm] [Cancel]" row inside the card.
function actions(card) { return card.querySelector('.actions'); }

function renderDefault(card) {
  const del = card.classList.contains('is-deleted');
  actions(card).innerHTML = del
    ? '<button class="act undo" onclick="doToggle(this,\'undo\')">Undo (restore to added=1)</button>'
    : '<button class="act del" onclick="askDelete(this)">Mark DELETE</button>';
}

function askDelete(btn) {
  actions(btn.closest('.card')).innerHTML =
    '<div class="confirm">' +
      '<span class="q">Delete this take? (whole M/L/R/A/B row)</span>' +
      '<div class="row">' +
        '<button class="act del" onclick="doToggle(this,\'del\')">Confirm</button>' +
        '<button class="act undo" onclick="renderDefault(this.closest(\'.card\'))">Cancel</button>' +
      '</div>' +
    '</div>';
}

function showError(card, msg) {
  const a = actions(card);
  if (!a.querySelector('.err')) a.insertAdjacentHTML('beforeend', '<div class="err"></div>');
  a.querySelector('.err').textContent = '⚠ ' + msg;
}

async function doToggle(btn, action) {
  const card = btn.closest('.card');
  const id = card.dataset.id;
  actions(card).querySelectorAll('button').forEach(b => b.disabled = true);
  try {
    const res = await fetch(location.pathname, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=' + action + '&id=' + encodeURIComponent(id)
    });
    const data = await res.json();
    if (!data.ok) { showError(card, data.error || 'failed'); return; }
    const nowDeleted = (data.added === 'DELETE');
    card.classList.toggle('is-deleted', nowDeleted);
    const badge = card.querySelector('.badge.status');
    badge.classList.toggle('deleted', nowDeleted);
    badge.classList.toggle('active', !nowDeleted);
    badge.textContent = nowDeleted ? 'DELETED' : 'active';
    renderDefault(card);
  } catch (e) {
    showError(card, e.message);
    actions(card).querySelectorAll('button').forEach(b => b.disabled = false);
  }
}
</script>
</body>
</html>
