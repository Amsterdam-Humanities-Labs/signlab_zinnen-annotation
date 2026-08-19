# subBeta8 — Keyboard Boundary Editing for Annotations

**Date:** 2026-06-19
**Author:** Gomer (with Claude Code)
**Target file:** `/web/zin/subBeta8.html` (cloned from `subBeta4.html`)
**Live tool:** https://signcollect.nl/zin/subBeta4.html → new https://signcollect.nl/zin/subBeta8.html

## Background / Motivation

Feedback from an annotator: the red playhead line does not always align with gloss
boundaries, making it hard to start and end a gloss exactly on the intended frame.
Mouse drag-resize is too imprecise. They requested keyboard-driven, frame-accurate
adjustment of gloss boundaries and positions, plus an automatic "push neighbor aside"
behavior when a gloss is extended into the next one.

This is delivered as a **new file** `subBeta8.html` (a clone of `subBeta4.html`) so the
production tool is untouched. The feature is **purely additive** — all existing behavior
of subBeta4 is preserved.

## Existing system facts (from subBeta4.html)

- Annotations live in a global `subtitles = []`. Each block:
  `{ id, text, start, end, tier, committed, masterId?, cloneId? }`.
- **Times are in seconds (float).** `fps = 60` is hardcoded → **1 frame = 1/60 s ≈ 0.01667 s**.
- 3 tiers: `0` = Nederlands, `1` = Gebaar-voor-Gebaar, `2` = Signbank ID glossen.
- Red line = playhead at global `currentTime`; moved via `setCanvasTime(time, who)`.
- Zoom is global `pixelsPerSecond`; `timeToPx()` / `pxToTime()` convert.
- Existing global keys (keydown listener ~line 3066): `P` play/pause, `1`–`4` speed,
  `+`/`-` zoom, `Escape` cancel-create. Arrow keys are **NOT** globally bound (only used
  inside the gloss-suggestion dropdown and inside contentEditable `.content`).
- The keydown handler already early-returns when `e.target` is contentEditable / INPUT /
  TEXTAREA / SELECT — typing is safe.
- Block reposition helpers: `updateSubtitleBoxDimensions(sub)` (left+width+top),
  `updateSubtitleBoxWidth(sub)` (width only).
- Linked tier pairs synced via `syncTwin(updatedSub)`.
- Autosave: `uploadSubtitles()` sets dirty + debounced (1 s) `performUpload()`.
- Bootstrap 4.5.2; existing modals follow the structure at lines 412–507; toolbar
  buttons at lines 365–407.

## Goals

1. Frame-accurate keyboard adjustment of a selected gloss's left boundary, right
   boundary, or whole position.
2. Selecting/navigating glosses by keyboard (Tab) and mouse (click).
3. Cascading "push neighbor, keep its length" when a move would cause overlap.
4. A discoverable help button + modal listing all keyboard controls.

## Non-Goals (YAGNI)

- No change to mouse drag/resize behavior (kept as-is).
- No configurable step size or configurable key bindings.
- No cross-tier Tab navigation, no multi-select.
- No new server/save path — reuse existing autosave.

## Design

### 1. Selection state

- New global `let selectedSubId = null;`.
- **Click** on a subtitle block sets `selectedSubId` to that block's id and re-applies
  highlight. (Hook into the existing block element creation in `renderAll`; a click
  handler on the box, careful not to break the existing contentEditable / drag-handle /
  dropdown interactions — selection click is on the box body, not while editing text.)
- **Visual highlight:** the selected block gets a distinct style (e.g. a bright/contrasting
  outline + box-shadow glow, and/or a highlighted border) applied via a CSS class
  `selected-sub` toggled in `renderAll` / a small `applySelectionHighlight()` helper.
  Only one block is highlighted at a time.
- A helper `getSelectedSub()` returns the subtitle object for `selectedSubId` (or null).
- If a selected block is deleted, `selectedSubId` is cleared.

### 2. Tab / Shift+Tab navigation (same tier)

- Active in the global keydown handler (only when not typing in a field).
- Build the list of blocks in the **same tier** as the currently selected block, sorted by
  `start`. Tab → next, Shift+Tab → previous (no wrap, or clamp at ends — clamp at ends).
- If nothing is selected, Tab selects the block **nearest the playhead** (smallest
  `|start - currentTime|`); tier preference = the tier of that nearest block.
- On selection change, scroll the timeline so the block is visible and snap the playhead to
  the block's left edge (so the user sees the relevant frame), then highlight.
- Note: the existing in-`.content` Tab handler (lines ~3395) stays for text editing; the
  new global Tab handler only runs when focus is NOT in an editable field.

### 3. Keyboard nudging

In the global keydown handler, when a gloss is selected and focus is not in a field, handle
`ArrowLeft` / `ArrowRight` (call `e.preventDefault()` to stop page scroll):

| Keys | Action | Playhead snaps to |
|------|--------|-------------------|
| `←` / `→` | move **left** boundary by ∓1 / ±1 frame | new left edge |
| `Shift`+`←/→` | move **right** boundary | new right edge |
| `Ctrl`+`←/→` (also `Meta` for Mac) | move **whole block** | new left edge |

Implementation detail — a single frame step constant:
```js
const FRAME = 1 / fps;            // 1/60 s
function snapToFrame(t) { return Math.round(t * fps) / fps; }
```

- `←` = −1 frame, `→` = +1 frame.
- Compute the new value, `snapToFrame()` it, apply clamps, mutate `sub.start` / `sub.end`,
  run overlap-push (section 4), then:
  - `updateSubtitleBoxDimensions(sub)` (or width helper),
  - `syncTwin(sub)` if it has a twin,
  - `setCanvasTime(<edited edge>, 'keyboard')` to move the red line + show the frame,
  - `uploadSubtitles()` for autosave.

**Clamps:**
- Minimum block width = 1 frame: left boundary can't reach/exceed `end - FRAME`; right
  boundary can't reach/precede `start + FRAME`.
- `start >= 0`; `end <= videoDuration`.
- Whole-block move clamps so neither edge leaves `[0, videoDuration]` (move stops at the
  bound; block keeps its length).

### 4. Overlap push (cascade, same tier, keep lengths)

A reusable function applied after a boundary/whole-block move:

```
pushNeighbors(movedSub, direction)   // direction = +1 (rightward) or -1 (leftward)
```

- Consider only **same-tier** blocks, sorted by `start`.
- **Rightward** (right boundary extended right, or whole block moved right): for each
  subsequent block whose `start < previous.end`, shift it right so
  `start = previous.end` (keep its duration: `end = start + len`). Continue cascading to the
  next block. Stop if a block would pass `videoDuration` — clamp the chain at the end
  (the move that caused the overflow is itself clamped so the invariant holds).
- **Leftward** (left boundary extended left, or whole block moved left): symmetric — push
  preceding blocks earlier so they don't overlap, keeping lengths, clamped at time 0.
- Every pushed block also gets `updateSubtitleBoxDimensions()` + `syncTwin()`.
- All blocks touched are part of the same `uploadSubtitles()` autosave.

Edge handling: if clamping at the video bound (or 0) means the originating move can't fully
apply without overlap, the originating move is reduced/blocked so no overlap is ever
persisted (no two same-tier blocks may overlap after the operation).

### 5. Help button + modal

- New toolbar button near the existing toolbar buttons (lines 365–407), Bootstrap style
  e.g. `class="btn btn-outline-secondary"`, label **"⌨ Keyboard Controls"**, opens the
  modal via `data-toggle="modal" data-target="#keyboardControlsModal"`.
- New Bootstrap modal `#keyboardControlsModal` (following lines 412–507 structure) listing:
  - **Selection:** Click a gloss / Tab / Shift+Tab.
  - **Boundaries:** ← → left edge; Shift+← → right edge; Ctrl+← → whole block.
  - **Behavior notes:** 1 press = 1 frame; playhead snaps to the edited edge; extending into
    a neighbor pushes it aside (keeps its length).
  - **Existing keys:** P play/pause; 1–4 speed; + / − zoom.

## Data flow (a single nudge)

```
keydown(ArrowRight, shift) →
  getSelectedSub() → sub
  newEnd = snapToFrame(sub.end + FRAME); clamp
  sub.end = newEnd
  pushNeighbors(sub, +1)            // cascade same-tier
  updateSubtitleBoxWidth(sub); syncTwin(sub)
  setCanvasTime(sub.end, 'keyboard')   // red line to right edge
  uploadSubtitles()                 // debounced autosave
```

## Testing / verification

Manual (browser), since this is a UI feature with no test harness in the repo. Load
`subBeta8.html?filename=<a real .wav>` and verify:

1. Click selects a gloss (highlight visible); Tab/Shift+Tab walk same-tier glosses in time
   order; playhead snaps to selected gloss's left edge.
2. `←`/`→` move the left edge by exactly 1 frame each; red line sits on that edge; width
   changes; can't cross the right edge (1-frame min) or go below 0.
3. `Shift`+`←/→` move the right edge; red line on right edge; can't cross left edge or
   exceed video end.
4. `Ctrl`+`←/→` move the whole block, length preserved; clamps at 0 and video end.
5. Extending the right edge into the next gloss pushes it (and cascades), each keeping its
   length; no overlap remains. Symmetric for left.
6. Linked master/clone tiers stay in sync after each operation.
7. Edits autosave (Network tab shows `saveSubtitlesAndEAFFiles`); reload preserves them.
8. Typing in a gloss text field still works — arrows/Tab there behave as before, not as
   nudges.
9. Help button opens the modal with the full list.
10. All original subBeta4 features (play, speed, zoom, drag-resize, add/delete, auto-segment,
    download) still work.

## Risks

- Click-to-select must not interfere with existing click handlers (contentEditable edit,
  drag handle, dropdown buttons). Mitigation: attach selection on the box body and guard
  against clicks that originate on interactive children.
- Frame snapping every block could surface pre-existing non-frame-aligned boundaries; that's
  acceptable (it only snaps blocks the user actually moves).
- `Ctrl` is used as the whole-block modifier; ensure it doesn't collide with browser
  shortcuts for the arrow keys (it doesn't for plain arrows). Also accept `Meta` for Mac.
