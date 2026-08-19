# Task 5 Brief: Tab / Shift+Tab gloss navigation (same tier)

You are implementing ONE task in `/web/zin/subBeta8.html`. Do not touch other files or implement other tasks.

## Where this fits
Earlier tasks added selection (`selectedSubId`, `getSelectedSub()`, `selectSub(id, opts)`) and arrow-key boundary nudging inside the global keydown handler. THIS task adds Tab/Shift+Tab navigation between glosses within the selected block's tier. Purely additive.

## Global Constraints
- Tab navigation walks glosses within the SAME tier as the currently selected block, in time order (`start` ascending). Clamp at the ends (NO wraparound).
- If nothing is selected, Tab selects the block nearest the playhead (smallest `|start - currentTime|`).
- On selection change, snap the playhead to the selected block's left edge (use `selectSub(id, {snapPlayhead:true})`).
- Must not fire while typing in a field — add AFTER the existing editable-field early-return guard in the keydown handler. The existing in-text Tab handler for `.content` editing must remain untouched (it is a separate behavior behind the typing guard).
- `e.preventDefault()` on Tab so the browser doesn't move focus.

## Existing code facts (verified)
- `getSelectedSub()` returns selected subtitle or null.
- `selectSub(id, opts)` sets selection, applies highlight, and if `opts.snapPlayhead` calls `setCanvasTime(sub.start,'select')` and scrolls the block into view.
- Global array `subtitles`; each block `{ id, start, end, tier, ... }`.
- `currentTime` global holds the playhead time in seconds.
- The global keydown handler `document.addEventListener('keydown', (e) => {...})` early-returns when `e.target` is contentEditable/INPUT/TEXTAREA/SELECT. The arrow-key branch (from a prior task) lives in this handler after that guard. Add the Tab branch in the same region (after the guard, near the arrow branch).

## What to implement

Add this branch inside the global keydown handler, after the editable-field guard (placing it just before or after the existing ArrowLeft/ArrowRight branch is fine):
```js
if (e.key === 'Tab') {
  e.preventDefault();
  const cur = getSelectedSub();
  if (!cur) {
    // nothing selected: select the block nearest the playhead
    if (!subtitles.length) return;
    const nearest = subtitles.slice().sort((a, b) =>
      Math.abs(a.start - currentTime) - Math.abs(b.start - currentTime))[0];
    selectSub(nearest.id, {snapPlayhead: true});
    return;
  }
  const tierBlocks = subtitles.filter(s => s.tier === cur.tier).sort((a, b) => a.start - b.start);
  const idx = tierBlocks.findIndex(s => s.id === cur.id);
  const nextIdx = e.shiftKey ? idx - 1 : idx + 1;
  if (nextIdx < 0 || nextIdx >= tierBlocks.length) return; // clamp at ends
  selectSub(tierBlocks[nextIdx].id, {snapPlayhead: true});
  return;
}
```

IMPORTANT: confirm there is no OTHER `e.key === 'Tab'` handling already inside this same global keydown callback that would conflict. (The `.content` editing Tab behavior is a separate listener / behind the typing guard — that is fine and must stay.) If you find a conflicting Tab branch already in THIS global handler, stop and report it as a concern rather than creating a duplicate.

## Verification you CAN do
- Extract inline `<script>` → `node --check`; report result.
- Confirm `currentTime`, `selectSub`, `getSelectedSub`, `subtitles` are in scope where you add the branch.
- Confirm the branch is after the typing guard and inside the keydown callback.
- Confirm you did not modify the separate `.content` Tab-editing logic.

Browser behavior verified by a human later; provide a precise checklist (nothing-selected Tab → nearest; Tab/Shift+Tab walk same tier in time order; clamp at ends; click a gloss in another tier then Tab walks that tier; in-text Tab still works).

## Backup (final step)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task5
```

## Report
Write details to `/web/zin/docs/superpowers/briefs/task-5-report.md`: exact placement (quote 2-3 surrounding lines), confirmation no conflicting global Tab branch existed, that the `.content` Tab logic is untouched, syntax-check result, deviations, human checklist. Return only: STATUS, backup file, one-line summary, concerns.
