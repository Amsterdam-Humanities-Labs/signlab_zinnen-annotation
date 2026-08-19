# Task 3 Brief: Frame helpers + clamps + left/right/whole nudge (NO push yet)

You are implementing ONE task in `/web/zin/subBeta8.html`. Do not touch other files or implement other tasks.

## Where this fits
subBeta8 adds keyboard gloss-boundary editing. Task 2 (already merged) added selection: globals `selectedSubId`, and functions `getSelectedSub()`, `selectSub(id, opts)`, `applySelectionHighlight()`. THIS task adds frame math + the arrow-key nudging of the selected gloss's left boundary, right boundary, or whole position. Overlap-push comes in Task 4 — leave explicit `// PUSH HOOK` comments where it will go. Purely additive; preserve all existing behavior.

## Global Constraints
- Times are seconds (float). `fps = 60` (declared `let frames = [], fps = 60, currentTime = 0;`). 1 frame = `1/fps` s. Snap boundaries to the frame grid.
- Minimum block width = 1 frame. `start >= 0`, `end <= videoDuration`.
- Modifiers: `Ctrl` OR `Meta` (Mac Cmd) = whole-block move; `Shift` = right boundary; no modifier = left boundary.
- Reuse existing functions. Autosave is `uploadSubtitles()`. No new save path.
- Keyboard handlers must not fire while typing in a field — the existing keydown handler already early-returns for contentEditable/INPUT/TEXTAREA/SELECT. Add your arrow handling AFTER that guard.

## Existing code facts (verified)
- `getSelectedSub()` returns the selected subtitle object or null (added Task 2).
- `videoDuration` is a global (≈ `frames.length / fps`).
- `setCanvasTime(time, who)` moves the red playhead + redraws.
- `updateSubtitleBoxDimensions(sub)` repositions a block (left+width+top). `updateSubtitleBoxWidth(sub)` adjusts width only.
- `syncTwin(updatedSub)` syncs linked master/clone tier pairs.
- `uploadSubtitles()` triggers debounced autosave.
- The global keydown listener is `document.addEventListener('keydown', (e) => { ... })` near line 3066. It early-returns if `e.target.isContentEditable || e.target.tagName === 'INPUT' || 'TEXTAREA' || 'SELECT'`. Existing keys inside it: P (play), 1-4 (speed), +/- (zoom), Escape. Arrow keys are NOT yet handled there.

## What to implement

### 1. Frame helpers — place near the `fps` declaration (after the `let frames...` line)
```js
const FRAME = 1 / fps;
function snapToFrame(t) { return Math.round(t * fps) / fps; }
```

### 2. The three nudge functions (add near the other subtitle-mutation helpers, e.g. after the resize handlers, or near updateSubtitleBoxDimensions). Leave the PUSH HOOK comments exactly as shown — Task 4 fills them in.
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
NOTE: the parameter is named `frames` deliberately (it is a count of frames, ±1). The global array is also named `frames`. Inside these functions the parameter SHADOWS the global array — that is fine and intended because these functions never use the global frames array. Do not rename.

### 3. Wire arrow keys into the existing global keydown handler, AFTER the editable-field early-return guard, alongside the existing P/1-4/+- handling:
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
Make sure this lands INSIDE the keydown callback, after the typing guard, and that it does not conflict with any existing ArrowLeft/ArrowRight handling there (there should be none in the global handler — the arrow handling that exists is inside the gloss-suggestion dropdown and inside contentEditable `.content`, both of which are separate listeners / behind the typing guard).

## Verification you CAN do
- Extract the inline `<script>` and run `node --check` on it (or copy to a temp .js and check). Report the result.
- Confirm `videoDuration`, `updateSubtitleBoxWidth`, `updateSubtitleBoxDimensions`, `syncTwin`, `setCanvasTime`, `uploadSubtitles` all exist in the file (grep) so your calls resolve.
- Confirm you placed the arrow branch after the typing guard and inside the keydown callback.

Browser behavior verification is deferred to a human; in your report, state exactly what to test.

## Backup (final step)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task3
```

## Report
Write full details to `/web/zin/docs/superpowers/briefs/task-3-report.md`: anchors/line numbers used, confirmation each referenced function exists, your syntax-check method+result, the exact placement of the arrow branch (quote 2-3 surrounding lines), any deviation + why, and the human browser checklist. Then return only: STATUS, backup file, one-line summary, concerns.
