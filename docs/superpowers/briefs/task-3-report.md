# Task 3 Report: Frame helpers + clamps + arrow nudge

## Anchors / Line Numbers Used

| Change | Location in original | Final line(s) |
|---|---|---|
| Frame helpers (`FRAME`, `snapToFrame`) | After line 571 (`let frames = [], fps = 60...`) | 572–573 |
| Nudge functions (`nudgeLeftBoundary`, `nudgeRightBoundary`, `nudgeWholeBlock`) | After `updateSubtitleBoxDimensions` closing brace (original ~1039) | 1043–1068 |
| Arrow key branch | Inside `document.addEventListener('keydown', ...)` at ~3122, after all existing key handlers | 3170–3180 |

## Referenced Function Existence (grep verified)

| Symbol | Found at line |
|---|---|
| `videoDuration` | 530 (declaration: `let videoDuration = 5`) |
| `getSelectedSub()` | 532–534 |
| `updateSubtitleBoxDimensions` | 1032 (function definition) |
| `updateSubtitleBoxWidth` | 1022 (function definition) |
| `syncTwin` | 1863 (function definition) |
| `setCanvasTime` | 628 (function definition) |
| `uploadSubtitles` | 2184 (function definition) |

All symbols confirmed present in the file.

## Syntax Check

Method: Python script extracted all 5 inline `<script>` blocks from the HTML into `/tmp/subBeta8_script.js` (128,909 chars), then ran:

```
node --check /tmp/subBeta8_script.js
```

Result: **Exit code 0 — no syntax errors.**

## Arrow Branch Placement (exact surrounding lines)

```js
        // Escape cancels pending subtitle end-time selection
        if (e.key === 'Escape' && waitingForEnd) {
            ...
        }

        // Arrow keys nudge selected gloss boundary (left/right/whole)
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
    });  // <-- closing brace of the keydown callback
```

The arrow branch is:
- INSIDE the `document.addEventListener('keydown', (e) => { ... })` callback (line 3122–3181)
- AFTER the early-return typing guard (line 3124–3126)
- AFTER all existing key handlers (P, 1-4, +/-, Escape)
- Does NOT conflict with any existing ArrowLeft/ArrowRight handling in this handler (none existed)

## Deviations

None. Code matches the brief verbatim. The parameter shadowing of `frames` (global array vs. nudge function parameter) is intentional per brief note — not renamed.

## PUSH HOOK Comments

All three nudge functions contain the exact `// PUSH HOOK` comments specified:
- `nudgeLeftBoundary`: `// PUSH HOOK (leftward)`
- `nudgeRightBoundary`: `// PUSH HOOK (rightward)`
- `nudgeWholeBlock`: `// PUSH HOOK (direction = sign of frames)`

## Human Browser Checklist

1. Load a video with subtitles. Click a subtitle block on the timeline to select it (it should highlight).
2. **Left boundary nudge**: Press `ArrowLeft` — the selected block's left edge should move 1 frame (~16.7ms) left. Press `ArrowRight` — left edge moves 1 frame right. Verify the block cannot shrink below 1 frame width.
3. **Right boundary nudge**: Hold `Shift` + press `ArrowLeft` — right edge moves 1 frame left. `Shift+ArrowRight` — right edge moves right. Verify right edge cannot go below `start + 1 frame` and cannot exceed `videoDuration`.
4. **Whole block nudge**: Hold `Ctrl` (or `Cmd` on Mac) + `ArrowLeft`/`ArrowRight` — entire block slides left/right 1 frame. Verify block cannot slide past 0 or past `videoDuration - blockLength`.
5. **No selection**: With nothing selected, press any arrow key — nothing should happen (early return when `getSelectedSub()` is null).
6. **Typing guard**: Click inside a subtitle's text area (contentEditable), press arrow keys — the cursor should move within the text, NOT trigger nudge.
7. **Autosave**: After any nudge, verify `uploadSubtitles()` is called (check network tab for the save request).
8. **Playhead**: After nudging, the playhead (red line on canvas) should jump to `sub.start` (left/whole nudge) or `sub.end` (right nudge).
9. **Sync**: If the subtitle has a twin (linked tier), verify `syncTwin` kept it in sync after nudge.
