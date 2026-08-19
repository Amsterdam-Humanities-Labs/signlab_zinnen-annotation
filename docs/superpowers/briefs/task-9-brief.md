# Task 9 Brief: Keep start/end (left/right) side canvases visible while a gloss is SELECTED

You are implementing ONE task in `/web/zin/subBeta8.html`. Do not touch other files.

## Goal
The left and right side canvases show the selected gloss's START frame (left) and END frame (right). Today they only appear while the mouse HOVERS a subtitle box and hide on mouse-leave. When the user adjusts a gloss's timing with the keyboard (mouse not hovering), they vanish. Make them stay visible — and update — while a gloss is SELECTED (for keyboard timing adjustment), so the user sees the exact start/end frames as they nudge.

## Existing code facts (verified; line numbers approximate — READ around each before editing)
- Selection: global `selectedSubId`, `getSelectedSub()`, and `selectSub(id, opts)` (top-level, defined near the `subtitles` declaration ~line 530). `selectSub` already sets selection, highlights, optionally snaps the playhead, and scrolls the box into view.
- `function updateThreeCanvases(sub)` (~line 1727): if `!sub` or `!sideCanvasesVisible` returns; else sets `canvasLeft.style.display='block'` + `drawFrameOnCanvas(ctxLeft, sub.start)` and `canvasRight.style.display='block'` + `drawFrameOnCanvas(ctxRight, sub.end)`. (It already respects the global "Show Side Videos" toggle `sideCanvasesVisible`, default true — keep respecting it.)
- `function onEndThreeCanvases()` (~line 1743): `if (suppressSideCanvasClear) return; if (!dragActive && !leftResizeActive && !rightResizeActive) { hide canvasLeft/canvasRight + clearRect both + busyMoving = 0; }`. This is what hides them on mouse-leave.
- Box `mouseleave` (~line 1238) calls `onEndThreeCanvases()`.
- The three keyboard nudge functions `nudgeLeftBoundary`, `nudgeRightBoundary`, `nudgeWholeBlock` (top-level) already mutate `sub.start`/`sub.end`, call push + reposition + `syncTwin` + `updateTwinBox` + `setCanvasTime(...,'keyboard')` + `uploadSubtitles()`.
- `deleteSubtitle(id)` already clears `selectedSubId` when the deleted block was selected.
- `updateThreeCanvases`, `onEndThreeCanvases`, `getSelectedSub`, and the nudge functions are all in the same top-level script scope (function declarations are hoisted), so they can call each other regardless of textual order.

## Edits to make

### Edit 1 — show side canvases when a gloss is selected (inside `selectSub`)
In `selectSub(id, opts)`, after the existing highlight/scroll/snap logic for the resolved `sub`, add a call to show the side canvases for the selected gloss. Add it where `sub` is known to be non-null (the function already does `const sub = getSelectedSub();` and guards `if (sub) {...}`):
```js
updateThreeCanvases(sub);
```
Place this INSIDE the existing `if (sub) { ... }` block so it only runs when a real block resolved. (`updateThreeCanvases` self-guards on `sideCanvasesVisible`, so this respects the Show-Side-Videos toggle.)

### Edit 2 — keep them updated as the user nudges (in all three nudge functions)
In each of `nudgeLeftBoundary`, `nudgeRightBoundary`, `nudgeWholeBlock`, after the existing `setCanvasTime(...)` line (and before or after `uploadSubtitles()` — either is fine), add:
```js
updateThreeCanvases(sub);
```
A single call redraws BOTH canvases from `sub.start`/`sub.end`, which correctly covers left-edge, right-edge, and whole-block moves.

### Edit 3 — don't hide them on mouse-leave while a gloss is selected (in `onEndThreeCanvases`)
Modify `onEndThreeCanvases` so that, when not dragging/resizing, if a gloss is currently selected it KEEPS the canvases showing the selected gloss's frames instead of hiding them:
```js
function onEndThreeCanvases() {
    if (suppressSideCanvasClear) return;
    if (!dragActive && !leftResizeActive && !rightResizeActive) {
        const sel = getSelectedSub();
        if (sel) { updateThreeCanvases(sel); return; }   // keep showing the selected gloss
        canvasLeft.style.display = 'none';
        canvasRight.style.display = 'none';
        ctxLeft.clearRect(0, 0, canvasLeft.width, canvasLeft.height);
        ctxRight.clearRect(0, 0, canvasRight.width, canvasRight.height);
        busyMoving = 0;
    }
}
```
(Read the existing body first and preserve its exact hide statements — only add the `const sel = getSelectedSub(); if (sel) { updateThreeCanvases(sel); return; }` guard at the top of the inner `if`.)

### Edit 4 — hide them when selection is cleared on delete (in `deleteSubtitle`)
After the existing `if (selectedSubId === id) { selectedSubId = null; }` line in `deleteSubtitle`, add a call to refresh/hide the side canvases now that nothing is selected:
```js
onEndThreeCanvases();
```
(With `selectedSubId` now null and no drag active, `onEndThreeCanvases` will hide them. If a drag/resize were active it safely does nothing.)

## Behavior after this task
- Single-click or Tab to a gloss → left/right canvases show that gloss's start/end frames and STAY visible (until you select another gloss or deselect), respecting the Show-Side-Videos toggle.
- Hovering another box still previews that box's frames; leaving it returns the canvases to the SELECTED gloss's frames (not blank).
- Nudging the selected gloss updates the previews live (left edge → left canvas, right edge → right canvas, whole block → both).
- While editing text the selection persists, so the canvases remain — acceptable.
- Deleting the selected gloss clears them.

## Verification you CAN do
- Confirm `updateThreeCanvases`, `onEndThreeCanvases`, `getSelectedSub`, `canvasLeft`, `canvasRight`, `ctxLeft`, `ctxRight`, `drawFrameOnCanvas`, `sideCanvasesVisible` all exist (grep).
- Confirm you added exactly: one `updateThreeCanvases(sub)` in `selectSub`, one in EACH of the three nudge functions (3 total), the guard in `onEndThreeCanvases`, and one `onEndThreeCanvases()` in `deleteSubtitle`.
- Extract the inline `<script>` and run `node --check`; report result.

Browser verification by human; provide a checklist: select via click → canvases appear and persist when mouse moves away; nudge left/right/whole → correct canvas updates; hover another box then leave → returns to selected gloss; toggle "Show Side Videos" off → selecting does NOT force them on; delete selected → canvases clear.

## Backup (final step)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task9
```

## Report
Write details to `/web/zin/docs/superpowers/briefs/task-9-report.md`: each edit's final anchor/line, grep confirmations, node --check result, deviations, human checklist. Return only: STATUS, backup file, one-line summary, concerns.
