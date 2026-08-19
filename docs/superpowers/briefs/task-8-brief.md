# Task 8 Brief: Separate "select gloss" from "edit text" (double-click to edit)

You are implementing ONE task in `/web/zin/subBeta8.html`. Do not touch other files.

## Problem
Currently each gloss box's `.content` div is `contentEditable = true` always, so single-clicking a gloss puts the cursor in the text. Then the new arrow-key boundary-nudge controls don't fire (a global typing guard stands down whenever focus is in editable text) and instead the arrows move the text cursor. The user wants: select a gloss for keyboard nudging WITHOUT entering text editing, and edit text only on an explicit gesture.

## New interaction model (approved by user)
- **Single-click on a gloss box (anywhere, incl. the text)** OR **Tab/Shift+Tab** → SELECT the gloss (cyan outline). Text is NOT focused. Arrow keys nudge boundaries (already implemented), Tab navigates (already implemented).
- **Double-click a gloss box**, OR **press Enter while a gloss is selected** → ENTER text-edit mode (cursor in the box, text selected). While editing, arrows/Tab behave as normal text editing (existing behavior).
- **Esc** (or **Enter**) while editing → EXIT edit mode (blur, which already autosaves) back to selected.
- For tier-2 boxes the glossary suggestion dropdown must keep working: while it's open, Arrow/Enter/Esc drive the dropdown (existing behavior), NOT the exit-edit logic.

## Global Constraints
- Preserve existing behavior: text autosave on blur, the tier-2 glossary dropdown (open/navigate/pick/close), and Tab-hop-between-text-boxes while editing.
- Purely additive where possible; minimal edits to existing lines.
- After any edit-mode exit, the box is re-rendered via the existing blur→renderAll path and returns to non-editable.

## Verified current code (line numbers are approximate — READ around each anchor before editing, the file shifts)
- `content` element created in `renderAll`'s `subtitles.forEach(sub => {...})` loop:
  - **~1307–1310:** `const content = document.createElement('div'); content.className = 'content'; content.contentEditable = true; content.innerText = sub.text;`
  - **~1311–1320:** `content.addEventListener('blur', () => { sub.text = content.innerText; sub.committed = true; syncTwin(sub); ... uploadSubtitles(); renderAll(); });`
  - **~1321:** `box.appendChild(content);`
- **Box selection mousedown (~1665–1668):**
  ```
  box.addEventListener('mousedown', (e) => {
    if (e.target.closest('.content, .resize-handle, .drag-handle, .dropdown, button')) return;
    selectSub(sub.id);
  });
  ```
- **Tier-2 glossary dropdown keydown (~1458–1490):** a `content.addEventListener('keydown', ...)` that returns early if `glossaryDD.style.display === 'none'`, else handles ArrowDown/ArrowUp/Enter (pick)/Escape (close dropdown). Inside the Enter-pick branch (~1477–1483) it re-focuses the recreated content:
  ```
  const newContent = newBox.querySelector('.content');
  if (newContent) newContent.focus();
  ```
- **Global keydown handler (~3225):** starts with the typing guard `if (e.target.isContentEditable || INPUT || TEXTAREA || SELECT) return;`. Contains P/speed/zoom/Escape, then the Arrow-nudge branch (~3274) and Tab branch (~3286). No existing `e.key === 'Enter'` branch here (VERIFY before adding).
- **In-content Tab handler (separate listener, ~3586):** gated on `target.classList.contains('content') && target.isContentEditable`. Hops to the next box in the same tier and, in a `requestAnimationFrame` (~3623–3628), does `nextContent.focus();`.
- Existing helpers available at top level: `selectSub(id, opts)`, `getSelectedSub()`, `syncTwin`, `uploadSubtitles`, `setCanvasTime`, `updateTwinBox`, the nudge functions.

## Edits to make

### Edit 1 — default NOT editable (~line 1309)
Change `content.contentEditable = true;` to `content.contentEditable = false;`

### Edit 2 — add a top-level `enterEditMode` helper
Place near the other top-level helpers (e.g. right after the `updateTwinBox` function):
```js
function enterEditMode(box) {
  if (!box) return;
  const c = box.querySelector('.content');
  if (!c) return;
  c.contentEditable = true;
  c.focus();
  const range = document.createRange();
  range.selectNodeContents(c);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
}
```

### Edit 3 — box mousedown selects even when clicking text (~line 1666)
Remove `.content` from the exclusion list so clicking the (now non-editable) text selects the gloss:
```js
box.addEventListener('mousedown', (e) => {
  if (e.target.closest('.resize-handle, .drag-handle, .dropdown, button')) return;
  selectSub(sub.id);
});
```

### Edit 4 — double-click to edit (add right after the mousedown listener, before `timelineTrack.appendChild`/`box.appendChild(content)` area — anywhere inside the forEach for this box is fine, but place it just after the mousedown handler)
```js
box.addEventListener('dblclick', (e) => {
  if (e.target.closest('.resize-handle, .drag-handle, .dropdown, button')) return;
  selectSub(sub.id);
  enterEditMode(box);
});
```

### Edit 5 — per-box Esc/Enter exit-edit listener (add immediately AFTER the blur listener, ~after line 1320, before `box.appendChild(content)`)
This MUST be registered before the tier-2 dropdown keydown listener (which is added later, ~1458) so it runs first and can defer to the dropdown:
```js
content.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const dd = box.querySelector('.glossary-dropdown');
    if (dd && dd.style.display !== 'none') return; // dropdown open: let it close first, keep editing
    e.preventDefault();
    content.blur();   // blur autosaves + renderAll re-creates non-editable
    return;
  }
  if (e.key === 'Enter') {
    const dd = box.querySelector('.glossary-dropdown');
    if (dd && dd.style.display !== 'none') return; // dropdown open: let it pick the suggestion
    e.preventDefault();
    content.blur();
    return;
  }
});
```

### Edit 6 — glossary pick must re-enter edit mode (~lines 1480–1481)
The recreated content is now non-editable by default; make the re-focus also re-enable editing:
```js
const newContent = newBox.querySelector('.content');
if (newContent) { newContent.contentEditable = true; newContent.focus(); }
```

### Edit 7 — global Enter enters edit mode on the selected gloss
In the global keydown handler (AFTER the typing guard, e.g. right after the Tab branch), add — but FIRST grep/confirm there is no other `e.key === 'Enter'` handling in this global handler that this would break:
```js
// Enter: edit the selected gloss's text
if (e.key === 'Enter') {
  const sub = getSelectedSub();
  if (!sub) return;            // nothing selected: leave Enter for any other behavior
  e.preventDefault();
  const box = document.querySelector('.subtitle-box[data-id="' + sub.id + '"]');
  enterEditMode(box);
  return;
}
```

### Edit 8 — Tab-while-editing must keep editing the next box (~line 3628)
Change `nextContent.focus();` to:
```js
nextContent.contentEditable = true;
nextContent.focus();
```

### Edit 9 — update the help modal (#keyboardControlsModal) to document the model
- Change the intro paragraph to:
  `<strong>Select a gloss</strong> — single-click it or press <kbd>Tab</kbd> (it gets a cyan outline). <strong>Double-click</strong> (or press <kbd>Enter</kbd>) to edit its text; press <kbd>Esc</kbd> to stop editing.`
- Add two rows to the FIRST table (the new-keys table), e.g. after the Tab row:
  ```html
  <tr><td><kbd>Double-click</kbd> / <kbd>Enter</kbd></td><td>Edit the selected gloss's text</td></tr>
  <tr><td><kbd>Esc</kbd></td><td>Stop editing (back to selecting)</td></tr>
  ```

## Verification you CAN do
- Grep to CONFIRM: there is no pre-existing `e.key === 'Enter'` branch in the global keydown handler before you add Edit 7 (report what you find).
- Grep to CONFIRM the per-box exit listener (Edit 5) is registered earlier in the file than the tier-2 dropdown keydown (Edit's existing ~1458 listener), so it fires first.
- Confirm `content.contentEditable` now defaults to false and is only set true via `enterEditMode`, the glossary re-focus (Edit 6), and the Tab-hop (Edit 8).
- Extract the inline `<script>` and run `node --check`; report result.

Browser verification is done by the human; provide a precise checklist covering: single-click selects (no caret in text); arrows nudge after single-click; double-click edits; Enter-on-selected edits; Esc exits edit; typing + blur saves; tier-2 glossary open/navigate/pick/close still works; Tab navigates selection when not editing AND hops text boxes when editing.

## Backup (final step)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task8
```

## Report
Write full details to `/web/zin/docs/superpowers/briefs/task-8-report.md`: each edit's final anchor/line, the grep confirmations (no conflicting global Enter; listener ordering), node --check result, any deviation + why, and the human checklist. Return only: STATUS, backup file, one-line summary, concerns.
