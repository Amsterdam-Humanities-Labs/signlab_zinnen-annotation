# Task 6 Brief: "⌨ Keyboard Controls" button + help modal

You are implementing ONE task in `/web/zin/subBeta8.html`. Do not touch other files or implement other tasks.

## Where this fits
Final feature task: a discoverable help button in the toolbar that opens a Bootstrap modal listing all keyboard shortcuts (the new boundary-editing keys plus existing ones). Purely additive, static HTML — no JS logic.

## Global Constraints
- Bootstrap 4.5.2 (already loaded); use its modal markup conventions (data-toggle / data-target / data-dismiss).
- Match the existing toolbar button styling.
- Do not alter existing behavior.

## Existing code facts (verified)
- Toolbar buttons live around lines 365–407, e.g. `addSubtitleBtn`, a Regex button using `data-toggle="modal" data-target="#patternHelpModal"`, `downloadSubtitleBtn` (class `btn btn-info`), `saveSubtitlesBtn`, `toggleAutoloopBtn`, etc.
- Existing modals (Bootstrap structure) are around lines 412–507, e.g. `#patternHelpModal` with `<div class="modal fade" id="..." role="dialog">` → `modal-dialog` → `modal-content` → `modal-header` (colored e.g. `bg-info text-white`) → `modal-body` → `modal-footer`.

## What to implement

### 1. Toolbar button
Add near the other toolbar buttons (e.g. right after `downloadSubtitleBtn`):
```html
<button id="keyboardControlsBtn" class="btn btn-outline-secondary ml-2"
        data-toggle="modal" data-target="#keyboardControlsModal">⌨ Keyboard Controls</button>
```

### 2. Modal — add near the other modals (e.g. after `#patternHelpModal`)
```html
<div class="modal fade" id="keyboardControlsModal" tabindex="-1" role="dialog" aria-labelledby="keyboardControlsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="keyboardControlsModalLabel">⌨ Keyboard Controls</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p><strong>Select a gloss first</strong> — click it, or press <kbd>Tab</kbd>. The selected gloss is outlined in cyan.</p>
        <table class="table table-sm table-bordered">
          <thead><tr><th>Keys</th><th>Action</th></tr></thead>
          <tbody>
            <tr><td><kbd>Tab</kbd> / <kbd>Shift</kbd>+<kbd>Tab</kbd></td><td>Next / previous gloss (same tier)</td></tr>
            <tr><td><kbd>&larr;</kbd> / <kbd>&rarr;</kbd></td><td>Move <strong>left</strong> boundary by 1 frame</td></tr>
            <tr><td><kbd>Shift</kbd>+<kbd>&larr;</kbd>/<kbd>&rarr;</kbd></td><td>Move <strong>right</strong> boundary by 1 frame</td></tr>
            <tr><td><kbd>Ctrl</kbd>+<kbd>&larr;</kbd>/<kbd>&rarr;</kbd> (<kbd>Cmd</kbd> on Mac)</td><td>Move the <strong>whole block</strong> by 1 frame</td></tr>
          </tbody>
        </table>
        <p class="text-muted small mb-2">The red playhead jumps to whichever edge you're editing, so you always see the exact frame. Extending a gloss into its neighbor pushes the neighbor aside (keeping its length); pushes cascade down the tier.</p>
        <hr>
        <p class="mb-1"><strong>Existing shortcuts</strong></p>
        <table class="table table-sm table-bordered mb-0">
          <tbody>
            <tr><td><kbd>P</kbd></td><td>Play / pause</td></tr>
            <tr><td><kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd> <kbd>4</kbd></td><td>Speed 0.25&times; / 0.5&times; / 0.75&times; / 1&times;</td></tr>
            <tr><td><kbd>+</kbd> / <kbd>&minus;</kbd></td><td>Zoom in / out</td></tr>
          </tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
```

## Verification you CAN do
- Confirm the button and modal IDs match (`#keyboardControlsModal`).
- Confirm the button is inside the toolbar area near the other buttons and the modal is at the same nesting level as the other `modal fade` divs (not accidentally nested inside another element).
- Confirm tags are balanced (open the file region you edited and check).
- (Optional) verify there is no duplicate `id="keyboardControlsModal"` or `id="keyboardControlsBtn"`.

Browser verification (button appears styled like neighbors, modal opens/closes via button/×/Close, table readable) is done by a human later; include that checklist in your report.

## Backup (final step)
```bash
cp /web/zin/subBeta8.html /web/zin/subBeta8.html.backup_task6
```

## Report
Write details to `/web/zin/docs/superpowers/briefs/task-6-report.md`: where the button and modal were inserted (anchors/line numbers), confirmation IDs match and tags balanced, any deviation, human checklist. Return only: STATUS, backup file, one-line summary, concerns.
