# Task 4 Brief: Cascading overlap-push (same tier, keep lengths)

You are implementing ONE task in `/web/zin/subBeta8.html`. Do not touch other files or implement other tasks.

## Where this fits
Task 3 added three nudge functions — `nudgeLeftBoundary(sub, frames)`, `nudgeRightBoundary(sub, frames)`, `nudgeWholeBlock(sub, frames)` — each containing a `// PUSH HOOK ...` comment placeholder. THIS task adds `pushNeighbors(movedSub, direction)` and wires it into those placeholders, plus adds "room guards" so a move that has nowhere to push is ignored (never persists an overlap). Purely additive; preserve everything else.

## Global Constraints
- 3 tiers (0,1,2). Push operates ONLY within `movedSub.tier`.
- No two same-tier blocks may overlap after any operation.
- Pushed blocks keep their original length.
- Clamp the cascade at `[0, videoDuration]`. If there is no room to push, the originating nudge is ignored (return without moving).
- Snap pushed boundaries to the frame grid (`snapToFrame`, already defined).
- Reuse `updateSubtitleBoxDimensions(sub)` and `syncTwin(sub)` for each pushed block. Autosave is already called by the nudge functions after the hook — do not add another.

## Existing code facts (verified, from Task 3)
- `const FRAME = 1 / fps;` and `function snapToFrame(t)` exist.
- `videoDuration` global exists.
- The three nudge functions exist with these PUSH HOOK lines:
  - `nudgeLeftBoundary`: `// PUSH HOOK (leftward)` — appears right after `sub.start = ns;`
  - `nudgeRightBoundary`: `// PUSH HOOK (rightward)` — right after `sub.end = ne;`
  - `nudgeWholeBlock`: `// PUSH HOOK (direction = sign of frames)` — right after `sub.start = ns; sub.end = ns + len;`
- Each block: `{ id, text, start, end, tier, ... }`. Global array `subtitles`.

## What to implement

### 1. Add `pushNeighbors` (place near the nudge functions)
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

### 2. Wire push into the nudge functions — replace each `// PUSH HOOK ...` comment line:
- In `nudgeLeftBoundary`, replace `// PUSH HOOK (leftward)` with: `pushNeighbors(sub, -1);`
- In `nudgeRightBoundary`, replace `// PUSH HOOK (rightward)` with: `pushNeighbors(sub, 1);`
- In `nudgeWholeBlock`, replace `// PUSH HOOK (direction = sign of frames)` with: `pushNeighbors(sub, frames >= 0 ? 1 : -1);`

### 3. Add room guards so a move with no room is ignored (prevents persisting overlap at the video bounds).

In `nudgeRightBoundary`, AFTER computing `ne` (the clamped right edge) but BEFORE `sub.end = ne;`, insert:
```js
const rightNeighbors = subtitles
  .filter(s => s.tier === sub.tier && s.id !== sub.id && s.start >= sub.start)
  .sort((a, b) => a.start - b.start);
let needed = ne;
for (const s of rightNeighbors) { needed += (s.end - s.start); }
if (needed > videoDuration) return; // no room to push; ignore this nudge
```

In `nudgeLeftBoundary`, AFTER computing `ns` (the clamped left edge) but BEFORE `sub.start = ns;`, insert:
```js
const leftNeighbors = subtitles
  .filter(s => s.tier === sub.tier && s.id !== sub.id && s.end <= sub.end)
  .sort((a, b) => b.start - a.start);
let floor = ns;
for (const s of leftNeighbors) { floor -= (s.end - s.start); }
if (floor < 0) return; // no room to push; ignore this nudge
```

In `nudgeWholeBlock`, AFTER computing the clamped `ns` and `len` but BEFORE assigning `sub.start`/`sub.end`, add a directional room guard mirroring the above. Use the same pattern, keyed on the move direction:
```js
if (frames > 0) {
  const rightNeighbors = subtitles
    .filter(s => s.tier === sub.tier && s.id !== sub.id && s.start >= sub.start)
    .sort((a, b) => a.start - b.start);
  let needed = ns + len;                 // proposed new end of the moved block
  for (const s of rightNeighbors) { needed += (s.end - s.start); }
  if (needed > videoDuration) return;
} else if (frames < 0) {
  const leftNeighbors = subtitles
    .filter(s => s.tier === sub.tier && s.id !== sub.id && s.end <= sub.end)
    .sort((a, b) => b.start - a.start);
  let floor = ns;                        // proposed new start of the moved block
  for (const s of leftNeighbors) { floor -= (s.end - s.start); }
  if (floor < 0) return;
}
```
IMPORTANT: in nudgeWholeBlock these guards must use the OLD `sub.start`/`sub.end` for the neighbor filter (i.e. filter BEFORE you assign the new `sub.start`/`sub.end`). Since the brief says insert the guard before assignment, `sub.start`/`sub.end` still hold the old values at that point — correct. Verify this ordering carefully when you place the code.

## Verification you CAN do
- Extract inline `<script>` → `node --check`. Report result.
- Re-read the three nudge functions end-to-end after editing and confirm: (a) the room guard sits before the `sub.start`/`sub.end` assignment and uses old values for neighbor filtering; (b) `pushNeighbors` is called after the assignment; (c) the existing `updateSubtitleBox*`, `syncTwin`, `setCanvasTime`, `uploadSubtitles` calls are still present and after the push.
- Confirm no PUSH HOOK comment remains (`grep "PUSH HOOK"` should return nothing).

Browser behavior (visual cascade, no-overlap, bounds) is verified by a human later. Provide a precise human test checklist (3 adjacent glosses A,B,C in one tier; extend/move into neighbors; cascade; bounds).

## Backup (final step)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task4
```

## Report
Write full details to `/web/zin/docs/superpowers/briefs/task-4-report.md`: where pushNeighbors was placed, the final text of all three nudge functions (paste them), confirmation no PUSH HOOK comment remains, syntax-check result, any deviation + why, and the human test checklist. Return only: STATUS, backup file, one-line summary, concerns.
