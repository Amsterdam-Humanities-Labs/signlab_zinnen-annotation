# Task 9 Report: Keep start/end side canvases visible while a gloss is SELECTED

## STATUS: COMPLETE

## Backup
`/web/zin/subBeta8.html.backup_task9`

## One-line summary
Added `updateThreeCanvases(sub)` to `selectSub` and all 3 nudge functions, added `getSelectedSub()` guard to `onEndThreeCanvases`, and added `onEndThreeCanvases()` call in `deleteSubtitle` after clearing selection.

## Edit Locations (final line numbers)

| Edit | Description | Final line(s) |
|------|-------------|---------------|
| Edit 1 | `updateThreeCanvases(sub)` inside `selectSub` if (sub) block | 589 |
| Edit 2a | `updateThreeCanvases(sub)` in `nudgeLeftBoundary` | 1148 |
| Edit 2b | `updateThreeCanvases(sub)` in `nudgeRightBoundary` | 1162 |
| Edit 2c | `updateThreeCanvases(sub)` in `nudgeWholeBlock` | 1186 |
| Edit 3 | `getSelectedSub()` guard in `onEndThreeCanvases` | 1748-1749 |
| Edit 4 | `onEndThreeCanvases()` in `deleteSubtitle` after `selectedSubId = null` | 860 |

## Grep Confirmations

All symbols verified to exist in `subBeta8.html`:
- `updateThreeCanvases` — function defined at ~line 1728
- `onEndThreeCanvases` — function defined at ~line 1744
- `getSelectedSub` — function defined at line 573
- `canvasLeft`, `canvasRight`, `ctxLeft`, `ctxRight` — defined at lines 608-611
- `drawFrameOnCanvas` — present in file
- `sideCanvasesVisible` — defined at line 617
- `suppressSideCanvasClear`, `dragActive`, `leftResizeActive`, `rightResizeActive`, `busyMoving` — all present

Edit count confirmed:
- 1x `updateThreeCanvases(sub)` in `selectSub` (line 589)
- 3x `updateThreeCanvases(sub)` in nudge functions (lines 1148, 1162, 1186)
- Guard added in `onEndThreeCanvases` (lines 1748-1749)
- 1x `onEndThreeCanvases()` in `deleteSubtitle` (line 860)

## Node --check Result

```
node --check /tmp/subBeta8_script.js
(no output — syntax OK)
Exit code: 0
```

## Deviations from Brief

None. All 4 edits implemented exactly as specified. The `onEndThreeCanvases` edit preserved all existing hide statements and only added the `const sel = getSelectedSub(); if (sel) { updateThreeCanvases(sel); return; }` guard at the top of the inner `if` block.

## Human Checklist

- [ ] Single-click a gloss → left/right canvases appear showing start/end frames and STAY visible when mouse moves away
- [ ] Tab to a gloss → same behavior, canvases appear and persist
- [ ] Nudge left boundary (keyboard) → left canvas updates to new start frame, right stays on end frame
- [ ] Nudge right boundary (keyboard) → right canvas updates to new end frame, left stays on start frame
- [ ] Nudge whole block (keyboard) → both canvases update to new start/end frames
- [ ] Hover another box while a gloss is selected → that box's frames preview; leaving it returns canvases to the SELECTED gloss's frames (not blank)
- [ ] Toggle "Show Side Videos" OFF → selecting a gloss does NOT force canvases on (toggle is respected)
- [ ] Delete the selected gloss → canvases clear/hide correctly
