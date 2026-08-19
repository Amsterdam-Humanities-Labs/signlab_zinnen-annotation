# subBeta8 Keyboard Boundary Editing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add frame-accurate, keyboard-driven adjustment of gloss boundaries/positions (with selection, Tab navigation, cascading overlap-push, and a help modal) to a clone of subBeta4.

**Architecture:** Clone `subBeta4.html` → `subBeta8.html`. Add a selection state, a set of arrow/Tab key handlers in the existing global keydown listener, a cascading push helper, and a Bootstrap help modal. Everything reuses the existing mutation + autosave plumbing (`updateSubtitleBoxDimensions`, `syncTwin`, `setCanvasTime`, `uploadSubtitles`).

**Tech Stack:** HTML5, vanilla JS, Bootstrap 4.5.2, jQuery. Single-file app. No build step, no test framework.

## Global Constraints

- **Times are in seconds (float).** `fps = 60`, so 1 frame = `1/fps` s. Boundaries must snap to the frame grid (`Math.round(t*fps)/fps`).
- **3 tiers:** 0 Nederlands, 1 Gebaar-voor-Gebaar, 2 Signbank ID glossen. Push and Tab operate **within a single tier**.
- **No two same-tier blocks may overlap** after any operation.
- **Minimum block width = 1 frame.** `start >= 0`, `end <= videoDuration`.
- **Modifier keys:** `Ctrl` OR `Meta` (Mac Cmd) = whole-block move.
- All edits go through the existing debounced `uploadSubtitles()` autosave. Do **not** add a new save path.
- Keyboard handlers must NOT fire when focus is in a contentEditable / INPUT / TEXTAREA / SELECT (the existing keydown handler already early-returns for these — keep that guard).
- Preserve all existing subBeta4 behavior. Feature is purely additive.
- No git in `/web/zin`: "commit" = save a timestamped backup copy before each task and keep the working file consistent. Verification = manual browser check.

**Test URL:** `http://<host>/zin/subBeta8.html?filename=M20260126_1590.wav` (or any real `.wav` with annotations). For local serving when Apache 403s, `php -S 0.0.0.0:8000` from `/web/zin` is the known workaround.

---

### Task 1: Clone subBeta4 → subBeta8

**Files:**
- Create: `/web/zin/subBeta8.html` (copy of `/web/zin/subBeta4.html`)

**Interfaces:**
- Produces: a byte-identical working copy at the new path. All later tasks edit `subBeta8.html` only.

- [ ] **Step 1: Copy the file**

```bash
cp /web/zin/subBeta4.html /web/zin/subBeta8.html
```

- [ ] **Step 2: Verify it loads unchanged**

Open `subBeta8.html?filename=<real .wav>` in a browser. Expected: identical to subBeta4 — video loads, timeline renders, play/zoom/drag all work. No console errors beyond those subBeta4 already shows.

- [ ] **Step 3: Backup baseline**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_baseline
```

---

### Task 2: Selection state + click-to-select + highlight

**Files:**
- Modify: `/web/zin/subBeta8.html` (globals near `let subtitles = [];` ~line 525; block creation in `renderAll` ~lines 1040–1160; CSS in the `<style>` block)

**Interfaces:**
- Produces:
  - global `let selectedSubId = null;`
  - `function getSelectedSub()` → returns the subtitle object whose `id === selectedSubId`, or `null`.
  - `function selectSub(id, {snapPlayhead=false}={})` → sets `selectedSubId`, refreshes highlight, optionally `setCanvasTime(sub.start,'select')`, scrolls block into view.
  - `function applySelectionHighlight()` → toggles CSS class `selected-sub` on the box whose `data-id` matches `selectedSubId`, removes it from all others.
  - CSS class `.subtitle-box.selected-sub`.

- [ ] **Step 1: Add globals and helpers**

Add near the `subtitles` declaration:

```js
let selectedSubId = null;
function getSelectedSub() {
  return subtitles.find(s => s.id === selectedSubId) || null;
}
function applySelectionHighlight() {
  document.querySelectorAll('.subtitle-box').forEach(b => {
    b.classList.toggle('selected-sub', String(b.dataset.id) === String(selectedSubId));
  });
}
function selectSub(id, opts = {}) {
  selectedSubId = id;
  applySelectionHighlight();
  const sub = getSelectedSub();
  if (sub) {
    if (opts.snapPlayhead) setCanvasTime(sub.start, 'select');
    const box = document.querySelector('.subtitle-box[data-id="' + id + '"]');
    if (box && box.scrollIntoView) box.scrollIntoView({block:'nearest', inline:'nearest'});
  }
}
```

- [ ] **Step 2: Ensure each block carries `data-id` and a click-to-select handler**

In `renderAll`, where the `.subtitle-box` element (`box`) is created, ensure it has `box.dataset.id = sub.id;` (add if missing). Then add a selection click that does not interfere with text editing, the drag handle, or the dropdown buttons:

```js
box.addEventListener('mousedown', (e) => {
  // Don't steal clicks meant for editing text, resize handles, drag handle, or dropdown buttons.
  if (e.target.closest('.content, .resize-handle, .drag-handle, .dropdown, button')) return;
  selectSub(sub.id);
});
```

Then call `applySelectionHighlight()` once at the end of `renderAll` so highlight survives re-renders.

- [ ] **Step 3: Add the highlight CSS**

In the `<style>` block, add:

```css
.subtitle-box.selected-sub {
  outline: 3px solid #00e0ff;
  box-shadow: 0 0 8px 2px rgba(0, 224, 255, 0.8);
  z-index: 1006 !important;
}
```

- [ ] **Step 4: Clear selection on delete**

In `deleteSubtitle(id)` (~line 778), after removing the block add:

```js
if (selectedSubId === id) { selectedSubId = null; }
```

- [ ] **Step 5: Manual verify**

Reload. Click a gloss body → cyan outline + glow appears; click another → highlight moves; only one highlighted at a time. Clicking the text still lets you edit (no selection steal). Dropdown buttons (x / Loop / Zoeken) still work. Delete a selected block → no stale highlight, no console error.

- [ ] **Step 6: Backup**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task2
```

---

### Task 3: Frame helpers + clamps + left/right/whole nudge (no push yet)

**Files:**
- Modify: `/web/zin/subBeta8.html` (frame helpers near `fps` ~line 547; global keydown handler ~lines 3066–3113)

**Interfaces:**
- Consumes: `getSelectedSub()`, `selectSub()` (Task 2); existing `fps`, `videoDuration`, `setCanvasTime`, `updateSubtitleBoxDimensions`, `updateSubtitleBoxWidth`, `syncTwin`, `uploadSubtitles`.
- Produces:
  - `const FRAME = 1 / fps;` and `function snapToFrame(t)`.
  - `function nudgeLeftBoundary(sub, frames)`, `nudgeRightBoundary(sub, frames)`, `nudgeWholeBlock(sub, frames)` — each mutates, snaps, clamps (NO push yet), repositions, syncs twin, snaps playhead, autosaves.
  - Arrow-key branch in the global keydown handler.

- [ ] **Step 1: Add frame helpers**

Near the `fps` declaration:

```js
const FRAME = 1 / fps;
function snapToFrame(t) { return Math.round(t * fps) / fps; }
```

- [ ] **Step 2: Add the three nudge functions**

Add (push integration comes in Task 4 — leave the `// PUSH HOOK` comments):

```js
function nudgeLeftBoundary(sub, frames) {
  let ns = snapToFrame(sub.start + frames * FRAME);
  ns = Math.max(0, Math.min(ns, sub.end - FRAME));
  sub.start = ns;
  // PUSH HOOK (leftward)
  updateSubtitleBoxDimensions(sub); syncTwin(sub);
  setCanvasTime(sub.start, 'keyboard'); uploadSubtitles();
}
function nudgeRightBoundary(sub, frames) {
  let ne = snapToFrame(sub.end + frames * FRAME);
  ne = Math.min(videoDuration, Math.max(ne, sub.start + FRAME));
  sub.end = ne;
  // PUSH HOOK (rightward)
  updateSubtitleBoxWidth(sub); syncTwin(sub);
  setCanvasTime(sub.end, 'keyboard'); uploadSubtitles();
}
function nudgeWholeBlock(sub, frames) {
  const len = sub.end - sub.start;
  let ns = snapToFrame(sub.start + frames * FRAME);
  ns = Math.max(0, Math.min(ns, videoDuration - len));
  sub.start = ns; sub.end = ns + len;
  // PUSH HOOK (direction = sign of frames)
  updateSubtitleBoxDimensions(sub); syncTwin(sub);
  setCanvasTime(sub.start, 'keyboard'); uploadSubtitles();
}
```

- [ ] **Step 3: Wire arrow keys into the global keydown handler**

Inside the existing `document.addEventListener('keydown', ...)` (after the editable-field early-return guard, alongside the P/1-4/+- handling), add:

```js
if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
  const sub = getSelectedSub();
  if (!sub) return;
  const dir = (e.key === 'ArrowRight') ? 1 : -1;
  e.preventDefault();
  if (e.ctrlKey || e.metaKey) nudgeWholeBlock(sub, dir);
  else if (e.shiftKey)        nudgeRightBoundary(sub, dir);
  else                        nudgeLeftBoundary(sub, dir);
  return;
}
```

- [ ] **Step 4: Manual verify**

Reload, select a gloss. `←`/`→`: left edge moves exactly 1 frame/press, red line lands on the left edge, width changes, can't cross the right edge or go below 0. `Shift`+`←/→`: right edge moves, red line on right edge, can't cross left edge or exceed video end. `Ctrl`+`←/→` (and Cmd on Mac): whole block moves, length unchanged, stops at 0 and video end. Page does not scroll on arrow press. Twin tiers (if any) stay aligned. Edits autosave (Network tab → `saveSubtitlesAndEAFFiles`).

- [ ] **Step 5: Backup**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task3
```

---

### Task 4: Cascading overlap-push (same tier, keep lengths)

**Files:**
- Modify: `/web/zin/subBeta8.html` (add `pushNeighbors`; wire into the three nudge functions at the `// PUSH HOOK` comments)

**Interfaces:**
- Consumes: `subtitles`, `FRAME`, `videoDuration`, `snapToFrame`, `updateSubtitleBoxDimensions`, `syncTwin` (Task 3).
- Produces: `function pushNeighbors(movedSub, direction)` — cascades same-tier blocks out of the way of `movedSub`, preserving each pushed block's length, clamping at `[0, videoDuration]`. `direction` = `+1` rightward, `-1` leftward.

- [ ] **Step 1: Implement `pushNeighbors`**

```js
function pushNeighbors(movedSub, direction) {
  const tierBlocks = subtitles
    .filter(s => s.tier === movedSub.tier && s.id !== movedSub.id)
    .sort((a, b) => a.start - b.start);
  if (direction > 0) {
    // rightward: blocks starting after movedSub, pushed so start >= prev.end
    let boundary = movedSub.end;
    for (const s of tierBlocks) {
      if (s.end <= movedSub.start) continue;        // entirely left of moved block
      if (s.start >= boundary) { boundary = s.end; continue; } // no overlap; advance
      const len = s.end - s.start;
      s.start = snapToFrame(boundary);
      s.end = s.start + len;
      boundary = s.end;
      updateSubtitleBoxDimensions(s); syncTwin(s);
    }
  } else {
    // leftward: blocks before movedSub, pushed so end <= next.start
    let boundary = movedSub.start;
    for (const s of [...tierBlocks].reverse()) {
      if (s.start >= movedSub.end) continue;        // entirely right of moved block
      if (s.end <= boundary) { boundary = s.start; continue; }
      const len = s.end - s.start;
      s.end = snapToFrame(boundary);
      s.start = s.end - len;
      boundary = s.start;
      updateSubtitleBoxDimensions(s); syncTwin(s);
    }
  }
}
```

- [ ] **Step 2: Wire push into the nudge functions**

Replace each `// PUSH HOOK ...` comment:
- In `nudgeLeftBoundary`: after `sub.start = ns;` → `pushNeighbors(sub, -1);`
- In `nudgeRightBoundary`: after `sub.end = ne;` → `pushNeighbors(sub, 1);`
- In `nudgeWholeBlock`: after `sub.end = ns + len;` → `pushNeighbors(sub, frames >= 0 ? 1 : -1);`

- [ ] **Step 3: Clamp the originator against the video bound**

To guarantee no overlap is persisted when the chain hits the video end/0, add a guard at the start of `nudgeRightBoundary` (rightward) — block the extend if the last same-tier block is already pinned at `videoDuration` and `movedSub.end` would push into it past the end:

```js
// inside nudgeRightBoundary, after computing ne, before assigning:
const rightNeighbors = subtitles.filter(s => s.tier === sub.tier && s.id !== sub.id && s.start >= sub.start).sort((a,b)=>a.start-b.start);
let needed = ne;
for (const s of rightNeighbors) { needed += (s.end - s.start); }
if (needed > videoDuration) return; // no room to push; ignore this nudge
```

Apply the symmetric guard in `nudgeLeftBoundary` (leftward, against 0):

```js
// inside nudgeLeftBoundary, after computing ns, before assigning:
const leftNeighbors = subtitles.filter(s => s.tier === sub.tier && s.id !== sub.id && s.end <= sub.end).sort((a,b)=>b.start-a.start);
let floor = ns;
for (const s of leftNeighbors) { floor -= (s.end - s.start); }
if (floor < 0) return; // no room to push; ignore this nudge
```

For `nudgeWholeBlock`, the existing `[0, videoDuration - len]` clamp keeps the moved block in-bounds; apply the same total-room guard before assigning when `frames`'s direction has neighbors (reuse the rightNeighbors/leftNeighbors check against `ns + len` / `ns`). Keep it simple: if the room guard fails, `return` without moving.

- [ ] **Step 4: Manual verify**

Place 3 adjacent glosses in one tier (A,B,C). Select A, hold `Shift+→` to extend A's right edge into B: B shifts right keeping its length; keep going until B meets C → C also shifts (cascade). No overlap ever visible. Symmetric: select C, hold `←` (left boundary) into B → B (then A) pushed left, lengths preserved, stops at 0. Whole-block `Ctrl+→`/`Ctrl+←` pushes the same way. At the video end, further extension is ignored (no overlap, no error). Other tiers are unaffected.

- [ ] **Step 5: Backup**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task4
```

---

### Task 5: Tab / Shift+Tab gloss navigation (same tier)

**Files:**
- Modify: `/web/zin/subBeta8.html` (global keydown handler)

**Interfaces:**
- Consumes: `getSelectedSub`, `selectSub`, `subtitles`, `currentTime`.
- Produces: a `Tab`/`Shift+Tab` branch in the global keydown handler that moves selection within the selected block's tier (clamped at ends); if nothing selected, selects the block nearest the playhead.

- [ ] **Step 1: Add the Tab branch**

In the global keydown handler (still inside the editable-field guard so in-text Tab is untouched), add:

```js
if (e.key === 'Tab') {
  e.preventDefault();
  const cur = getSelectedSub();
  if (!cur) {
    // select nearest block to the playhead
    if (!subtitles.length) return;
    const nearest = subtitles.slice().sort((a,b) =>
      Math.abs(a.start - currentTime) - Math.abs(b.start - currentTime))[0];
    selectSub(nearest.id, {snapPlayhead:true});
    return;
  }
  const tierBlocks = subtitles.filter(s => s.tier === cur.tier).sort((a,b)=>a.start-b.start);
  const idx = tierBlocks.findIndex(s => s.id === cur.id);
  const nextIdx = e.shiftKey ? idx - 1 : idx + 1;
  if (nextIdx < 0 || nextIdx >= tierBlocks.length) return; // clamp at ends
  selectSub(tierBlocks[nextIdx].id, {snapPlayhead:true});
  return;
}
```

- [ ] **Step 2: Manual verify**

With nothing selected, press Tab → the gloss nearest the red line gets selected and the playhead snaps to its left edge. Tab walks forward through that tier in time order; Shift+Tab walks back; stops (no wrap) at first/last. Switching tiers still done by clicking a gloss in another tier, after which Tab walks that tier. In-text Tab (while editing gloss text) still navigates/works as before — not hijacked.

- [ ] **Step 3: Backup**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task5
```

---

### Task 6: "⌨ Keyboard Controls" button + help modal

**Files:**
- Modify: `/web/zin/subBeta8.html` (toolbar buttons ~lines 365–407; add a Bootstrap modal near the other modals ~lines 412–507)

**Interfaces:**
- Consumes: Bootstrap 4.5.2 modal markup conventions already in the file.
- Produces: button `#keyboardControlsBtn` (opens modal via data attributes) and modal `#keyboardControlsModal`.

- [ ] **Step 1: Add the toolbar button**

Next to the existing toolbar buttons (e.g. after `downloadSubtitleBtn`):

```html
<button id="keyboardControlsBtn" class="btn btn-outline-secondary ml-2"
        data-toggle="modal" data-target="#keyboardControlsModal">⌨ Keyboard Controls</button>
```

- [ ] **Step 2: Add the modal**

Near the other modals:

```html
<div class="modal fade" id="keyboardControlsModal" tabindex="-1" role="dialog" aria-labelledby="keyboardControlsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="keyboardControlsModalLabel">⌨ Keyboard Controls</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p><strong>Select a gloss first</strong> — click it, or press <kbd>Tab</kbd>. The selected gloss is outlined in cyan.</p>
        <table class="table table-sm table-bordered">
          <thead><tr><th>Keys</th><th>Action</th></tr></thead>
          <tbody>
            <tr><td><kbd>Tab</kbd> / <kbd>Shift</kbd>+<kbd>Tab</kbd></td><td>Next / previous gloss (same tier)</td></tr>
            <tr><td><kbd>&larr;</kbd> / <kbd>&rarr;</kbd></td><td>Move <strong>left</strong> boundary by 1 frame</td></tr>
            <tr><td><kbd>Shift</kbd>+<kbd>&larr;</kbd>/<kbd>&rarr;</kbd></td><td>Move <strong>right</strong> boundary by 1 frame</td></tr>
            <tr><td><kbd>Ctrl</kbd>+<kbd>&larr;</kbd>/<kbd>&rarr;</kbd> (<kbd>Cmd</kbd> on Mac)</td><td>Move the <strong>whole block</strong> by 1 frame</td></tr>
          </tbody>
        </table>
        <p class="text-muted small mb-2">The red playhead jumps to whichever edge you're editing, so you always see the exact frame. Extending a gloss into its neighbor pushes the neighbor aside (keeping its length); pushes cascade down the tier.</p>
        <hr>
        <p class="mb-1"><strong>Existing shortcuts</strong></p>
        <table class="table table-sm table-bordered mb-0">
          <tbody>
            <tr><td><kbd>P</kbd></td><td>Play / pause</td></tr>
            <tr><td><kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd> <kbd>4</kbd></td><td>Speed 0.25× / 0.5× / 0.75× / 1×</td></tr>
            <tr><td><kbd>+</kbd> / <kbd>&minus;</kbd></td><td>Zoom in / out</td></tr>
          </tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Manual verify**

Reload. The "⌨ Keyboard Controls" button is visible in the toolbar and styled like its neighbors. Clicking it opens the modal; the table lists all new + existing shortcuts; Close and the × dismiss it. Opening/closing the modal does not disturb selection or the timeline.

- [ ] **Step 4: Backup**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task6
```

---

### Task 7: Full regression + acceptance pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full acceptance checklist from the spec**

Against `subBeta8.html?filename=<real .wav>`, confirm spec §"Testing / verification" items 1–10:
1. Click + Tab selection, highlight, playhead snap.
2. `←/→` left edge, 1 frame, clamps.
3. `Shift+←/→` right edge, clamps.
4. `Ctrl+←/→` whole block, length preserved, clamps.
5. Cascading push both directions, lengths kept, no overlap.
6. Twin tiers stay synced.
7. Autosave fires; reload preserves edits.
8. Typing in text fields unaffected by arrows/Tab.
9. Help modal complete.
10. All original subBeta4 features still work (play, speed, zoom, drag-resize, add/delete, auto-segment, download).

- [ ] **Step 2: Console check**

Confirm no new JS errors in the console during the above (compare against subBeta4's pre-existing console output).

- [ ] **Step 3: Final backup**

```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_final
```

---

## Self-Review Notes

- **Spec coverage:** selection (T2), left/right/whole nudge + snap + clamps (T3), cascade push (T4), Tab nav (T5), help button+modal (T6), regression (T7), clone (T1). All spec sections mapped.
- **Type consistency:** `getSelectedSub`, `selectSub`, `nudgeLeftBoundary/RightBoundary/WholeBlock`, `pushNeighbors(sub, direction)`, `snapToFrame`, `FRAME` used consistently across tasks.
- **Adaptation:** no test framework → manual browser verification per task; no git → timestamped file backups per task.
