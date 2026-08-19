# Task 2 Brief: Selection state + click-to-select + highlight

You are implementing ONE task in the file `/web/zin/subBeta8.html` (a single-file HTML/JS sign-language annotation editor). Do not touch any other file. Do not implement other tasks.

## Where this fits
subBeta8 is a clone of subBeta4. We are adding keyboard-driven gloss boundary editing. This task adds the foundational "selected gloss" concept that later tasks (arrow-key nudging, Tab navigation) build on. Everything is purely additive — preserve all existing behavior.

## Global Constraints (apply to every task)
- Times are in seconds (float). `fps = 60`. (Not directly used in this task, but context.)
- 3 tiers: 0 Nederlands, 1 Gebaar-voor-Gebaar, 2 Signbank ID glossen.
- Reuse existing functions; do NOT add new save paths. Autosave is `uploadSubtitles()`.
- Bootstrap 4.5.2; jQuery present.
- Preserve all existing subBeta4 behavior. Feature is purely additive.

## Existing code facts (verified)
- Global `let subtitles = [];` near line 525. Each block: `{ id, text, start, end, tier, committed, masterId?, cloneId? }`.
- `function renderAll()` (~lines 1019–1517) rebuilds the timeline; it creates a `.subtitle-box` element (variable `box`) per subtitle. There is also a `.content` (contentEditable text), `.drag-handle`, `.resize-handle` (left/right), and a per-box `.dropdown` with buttons (x/Loop/Zoeken/etc.).
- `setCanvasTime(time, who)` moves the red playhead + redraws.
- `function deleteSubtitle(id)` (~line 778) removes a block.
- There is a `<style>` block in the `<head>` for CSS.
- The existing global keydown handler early-returns when focus is in contentEditable/INPUT/TEXTAREA/SELECT. (Not modified in this task.)

## What to implement

### 1. Globals + helpers (place near the `subtitles` declaration)
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

### 2. In `renderAll`, on the per-box element:
- Ensure the box has `box.dataset.id = sub.id;` (add if not already present).
- Add a selection handler that does NOT steal clicks meant for editing/resizing/dragging/dropdown:
```js
box.addEventListener('mousedown', (e) => {
  if (e.target.closest('.content, .resize-handle, .drag-handle, .dropdown, button')) return;
  selectSub(sub.id);
});
```
- At the END of `renderAll`, call `applySelectionHighlight();` so the highlight survives re-renders.
  IMPORTANT: make sure `sub` is the correct per-iteration subtitle variable in scope where you add the mousedown handler — match the existing loop variable name used in renderAll (it may be `sub`, `s`, or similar). Read the surrounding code and use the actual variable.

### 3. Add CSS to the `<style>` block:
```css
.subtitle-box.selected-sub {
  outline: 3px solid #00e0ff;
  box-shadow: 0 0 8px 2px rgba(0, 224, 255, 0.8);
  z-index: 1006 !important;
}
```

### 4. In `deleteSubtitle(id)`, after the block is removed, add:
```js
if (selectedSubId === id) { selectedSubId = null; }
```

## Verification you CAN do (no browser available to you)
- After editing, extract the inline `<script>` content and run a JS syntax check if feasible, OR at minimum carefully re-read your edits for balanced braces and that you inserted code at valid points (not inside a string/comment, correct function scope).
- Confirm you did not alter unrelated logic.
- Confirm the loop variable you referenced in the mousedown handler actually exists at that point in `renderAll`.

Browser verification (highlight appearance, click-to-select not stealing text edits, dropdown still works) will be done later by a human — note in your report exactly what to check.

## Backup (do this as your final step, stands in for a commit)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task2
```

## Report
Write your full report to `/web/zin/docs/superpowers/briefs/task-2-report.md` containing: what you changed (with the actual line numbers/anchors you used), the exact renderAll loop variable name you matched, any deviation from the brief and why, your syntax-check method + result, and the precise browser checks a human should run. Then return only: STATUS (DONE / DONE_WITH_CONCERNS / NEEDS_CONTEXT / BLOCKED), the backup file created, a one-line summary, and any concerns.
