# Task 4 Report: Cascading overlap-push (same tier, keep lengths)

## Placement of `pushNeighbors`

`pushNeighbors` was placed immediately before `nudgeLeftBoundary`, at line 1043 of
`/web/zin/subBeta8.html`. All four functions are now contiguous in the file.

## Final text of all three nudge functions and pushNeighbors

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

function nudgeLeftBoundary(sub, frames) {
  let ns = snapToFrame(sub.start + frames * FRAME);
  ns = Math.max(0, Math.min(ns, sub.end - FRAME));
  const leftNeighbors = subtitles
    .filter(s => s.tier === sub.tier && s.id !== sub.id && s.end <= sub.end)
    .sort((a, b) => b.start - a.start);
  let floor = ns;
  for (const s of leftNeighbors) { floor -= (s.end - s.start); }
  if (floor < 0) return; // no room to push; ignore this nudge
  sub.start = ns;
  pushNeighbors(sub, -1);
  updateSubtitleBoxDimensions(sub); syncTwin(sub);
  setCanvasTime(sub.start, 'keyboard'); uploadSubtitles();
}

function nudgeRightBoundary(sub, frames) {
  let ne = snapToFrame(sub.end + frames * FRAME);
  ne = Math.min(videoDuration, Math.max(ne, sub.start + FRAME));
  const rightNeighbors = subtitles
    .filter(s => s.tier === sub.tier && s.id !== sub.id && s.start >= sub.start)
    .sort((a, b) => a.start - b.start);
  let needed = ne;
  for (const s of rightNeighbors) { needed += (s.end - s.start); }
  if (needed > videoDuration) return; // no room to push; ignore this nudge
  sub.end = ne;
  pushNeighbors(sub, 1);
  updateSubtitleBoxWidth(sub); syncTwin(sub);
  setCanvasTime(sub.end, 'keyboard'); uploadSubtitles();
}

function nudgeWholeBlock(sub, frames) {
  const len = sub.end - sub.start;
  let ns = snapToFrame(sub.start + frames * FRAME);
  ns = Math.max(0, Math.min(ns, videoDuration - len));
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
  sub.start = ns; sub.end = ns + len;
  pushNeighbors(sub, frames >= 0 ? 1 : -1);
  updateSubtitleBoxDimensions(sub); syncTwin(sub);
  setCanvasTime(sub.start, 'keyboard'); uploadSubtitles();
}
```

## Ordering verification

### nudgeLeftBoundary
- Room guard uses `sub.end` (old value) to filter neighbors — BEFORE `sub.start = ns;` — CORRECT
- `pushNeighbors(sub, -1)` called AFTER `sub.start = ns;` — CORRECT
- `updateSubtitleBoxDimensions`, `syncTwin`, `setCanvasTime`, `uploadSubtitles` all present and last — CORRECT

### nudgeRightBoundary
- Room guard uses `sub.start` (old value) to filter neighbors — BEFORE `sub.end = ne;` — CORRECT
- `pushNeighbors(sub, 1)` called AFTER `sub.end = ne;` — CORRECT
- `updateSubtitleBoxWidth`, `syncTwin`, `setCanvasTime`, `uploadSubtitles` all present and last — CORRECT

### nudgeWholeBlock
- Room guard filters use OLD `sub.start`/`sub.end` (before any assignment) — CORRECT
- Guard is BEFORE `sub.start = ns; sub.end = ns + len;` — CORRECT
- `pushNeighbors` called AFTER assignment — CORRECT
- `updateSubtitleBoxDimensions`, `syncTwin`, `setCanvasTime`, `uploadSubtitles` all present and last — CORRECT

## PUSH HOOK confirmation

`grep "PUSH HOOK" /web/zin/subBeta8.html` returns 0 matches. Clean.

## Syntax check result

```
node --check /tmp/inline_script.js
SYNTAX OK
```

## Deviations

None. Implementation matches the brief exactly.

## Human test checklist

Setup: open subBeta8.html with a video loaded. In one tier, create 3 adjacent gloss blocks A, B, C (no gaps between them, e.g. A=0-2s, B=2-4s, C=4-6s). Video duration should be at least 10s for these tests.

### Test 1 — rightward cascade via nudgeRightBoundary
1. Select block A and extend its right boundary rightward (key that calls nudgeRightBoundary with +frames).
2. Expected: B and C cascade rightward, each keeping their original length, no overlap.
3. Verify on timeline: A.end < B.start, B.end < C.start (or equal, snapped to frame grid).

### Test 2 — leftward cascade via nudgeLeftBoundary
1. Select block C and shrink its left boundary leftward into B.
2. Expected: B cascades leftward, then A cascades leftward if needed, keeping lengths.
3. Verify: no overlap, all lengths preserved.

### Test 3 — whole-block rightward push via nudgeWholeBlock
1. Select block A and nudge the whole block rightward into B.
2. Expected: B pushed right, C pushed right in turn. Lengths preserved.

### Test 4 — whole-block leftward push
1. Select block C and nudge leftward into B.
2. Expected: B pushed left, A pushed left if needed.

### Test 5 — bounds: no room rightward
1. Place C so it ends exactly at videoDuration. Attempt to extend A's right boundary rightward.
2. Expected: nudge is IGNORED — no movement, no overlap, no clamp error.

### Test 6 — bounds: no room leftward
1. Place A starting at 0. Attempt to shrink C's left boundary leftward far enough that the cascade would push A before 0.
2. Expected: nudge is IGNORED.

### Test 7 — different tier isolation
1. Place block D in tier 1 at the same time range as B in tier 0.
2. Cascade B in tier 0; D in tier 1 must not move.

### Test 8 — lengths preserved
After any cascade, measure each block's duration before and after: it must be identical (within one frame rounding due to snapToFrame).
