# 3DAnn2 Per-Tier Annotation Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three per-tier "sync from mp4" buttons plus staged Save/Revert to `3DAnn2.html`, so a mocap take can pull in annotation work finished on its source mp4 after the initial one-time copy.

**Architecture:** All pure logic (source-filename resolution, tier splice, baseline capture, revert) moves into a new dependency-free `js/syncTiers.js`, testable under `node --test`. `3DAnn2.html` keeps only DOM wiring: toolbar controls, a staged-state flag that suppresses autosave, and a navigation guard on four exit paths. No PHP changes — the existing `getZinnen.php?action=fetchSubtitles` endpoint supplies the source data.

**Tech Stack:** Vanilla JS (ES5-compatible, no build step), Bootstrap 4.5.2 + jQuery 3.5.1 slim (already loaded), Node 22.20 `node --test` for unit tests, PHP 8 built-in server for manual verification.

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-08-17-3dann2-tier-sync-design.md`. Read it before starting.
- **No PHP changes.** `getZinnen.php` is not to be modified by this plan.
- **`/web/zin` is not a git repository.** There is no commit step. Each task ends with a timestamped backup instead, matching the project's existing convention (`addZinnen.php.backup_20260215_184004`, `zinnen.html.backup_*`).
- **Tier mapping is fixed:** `0 = Nederlands`, `1 = Gebaar-voor-Gebaar`, `2 = Signbank ID glossen`.
- **Tier colours** must match the timeline's existing `tierBorderColors` (`3DAnn2.html:2269`): tier 0 `#1976d2` blue, tier 1 `#f57c00` orange, tier 2 `#388e3c` green.
- **No new runtime dependencies.** `js/syncTiers.js` must load as a plain `<script src>` with no bundler and no ES module syntax.
- **jQuery and Bootstrap load at line 3870-3872, *after* the inline script at 672-3869.** Never call `$(...)` at inline-script parse time. Inside event handlers is fine — they run after load.
- **Button labels are English** (matching `Load GLB` / `Auto-Segment` / `Revert Autoseg`); **toasts and modal copy are Dutch** (matching `Opgeslagen!` / `Opslaan mislukt!`).
- **A sync either stages cleanly or does nothing at all.** No partial states on any failure path.

## Deviation from the spec (approved rationale, carry it forward)

The spec says dangling `cloneId` / `masterId` are stripped "from surviving partners" during the tier replace. **Do not do that at splice time.** Stripping during splice permanently destroys a tier-1 master's link to its tier-2 twin, so a later Revert could no longer restore byte-identical state — which defeats the Revert semantics the spec commits to.

Instead: leave link fields untouched while staged (every consumer is already null-guarded — `3DAnn2.html:2625`, `:2734`, `:2797`, `:2856`, `:2985`), and strip dangling links only on **Save**, when the state is being committed anyway. Task 1 and Task 4 implement it this way.

**Second correction, found by mutation-testing the plan's own code.** The spec claims an orphaned link "degrades to *this pair no longer moves together*". That is only true if the replaced tier's ids are not reused. The obvious implementation — renumbering incoming glosses as `tier<N>_<i>` — *does* reuse them, and the result is not an orphan but a **silent wrong re-binding**: a surviving `tier1_1` with `cloneId: 'tier2_1'` ends up paired with whatever synced-in gloss happens to land on `tier2_1`, and the pair-drag path at `:2985` then moves two unrelated glosses together.

Verified concretely during planning:

```
master.cloneId = tier2_1
now points at  = {"id":"tier2_1","text":"HEEL-ANDER-GEBAAR","start":9}
```

Fix: synced-in glosses get `sync<tier>_<n>` ids, which cannot collide with `tier<N>_<i>` or with the numeric ids `addSubtitle` assigns. The link then dangles harmlessly, as the spec intended. Test 7 in Task 1 locks this in.

## File Structure

| File | Responsibility |
|---|---|
| `js/syncTiers.js` (create) | Pure, DOM-free logic: source resolution, baseline capture, tier splice, revert, dangling-link cleanup. No `fetch`, no `document`. |
| `test_sync_tiers.js` (create) | `node --test` unit tests for the above. Root-level `test_*` name matches the project's 72 existing `api/test_*` scripts. |
| `3DAnn2.html` (modify) | Script include, toolbar markup + CSS, `#syncPendingModal`, staged-state wiring, autosave suppression, navigation guard. |

This split exists so the logic that carries the correctness risk is testable without booting Babylon.js, WebGL and a GLB. `3DAnn2.html` is already 3875 lines / 154KB; keeping new logic out of it is deliberate.

---

### Task 1: Pure sync logic module

**Files:**
- Create: `js/syncTiers.js`
- Test: `test_sync_tiers.js`

**Interfaces:**
- Consumes: nothing (first task).
- Produces — a global `SyncTiers` object (also `module.exports` under Node) with:
  - `TIER_NAMES: {0:'Nederlands', 1:'Gebaar-voor-Gebaar', 2:'Signbank ID glossen'}`
  - `getSyncSourceBase(currentFilename: string) -> string|null`
  - `captureTierBaseline(subtitles: Array, tier: number) -> Array` (deep copies)
  - `spliceTier(subtitles: Array, tier: number, incoming: Array) -> Array` (new array)
  - `revertTiers(subtitles: Array, baseline: {[tier:number]: Array}) -> Array` (new array)
  - `stripDanglingLinks(subtitles: Array) -> Array` (mutates and returns)

- [ ] **Step 1: Write the failing test**

Create `test_sync_tiers.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const S = require('./js/syncTiers.js');

const sub = (id, tier, text, start, end, extra) =>
  Object.assign({ id, tier, text, start, end, committed: true }, extra || {});

test('getSyncSourceBase extracts the mp4 base from a mocap take name', () => {
  assert.strictEqual(S.getSyncSourceBase('M20260127_2128_260317_1'), 'M20260127_2128');
  assert.strictEqual(S.getSyncSourceBase('M20240925_1838_251210_2'), 'M20240925_1838');
});

test('getSyncSourceBase returns null when already editing the mp4 itself', () => {
  assert.strictEqual(S.getSyncSourceBase('M20260127_2128'), null);
});

test('getSyncSourceBase returns null for unrecognised or empty names', () => {
  assert.strictEqual(S.getSyncSourceBase('PalmerPolo1024uastc'), null);
  assert.strictEqual(S.getSyncSourceBase(''), null);
  assert.strictEqual(S.getSyncSourceBase(null), null);
});

test('spliceTier replaces only the target tier and leaves others identical', () => {
  const before = [
    sub('tier0_1', 0, 'de man loopt', 0, 2),
    sub('tier2_1', 2, 'OUD', 0.4, 0.9),
    sub('tier2_2', 2, '', 1.1, 1.5)
  ];
  const incoming = [
    sub('x', 2, 'HUIS', 1.2, 1.8),
    sub('y', 2, 'GROOT', 2.0, 2.6)
  ];
  const after = S.spliceTier(before, 2, incoming);

  assert.deepStrictEqual(
    after.filter(s => s.tier === 0),
    [sub('tier0_1', 0, 'de man loopt', 0, 2)]
  );
  assert.deepStrictEqual(
    after.filter(s => s.tier === 2).map(s => [s.id, s.text, s.start, s.end]),
    [['sync2_1', 'HUIS', 1.2, 1.8], ['sync2_2', 'GROOT', 2.0, 2.6]]
  );
});

test('spliceTier never reuses an existing id, so pair links cannot re-bind', () => {
  const before = [
    sub('tier1_1', 1, 'huis', 0.4, 0.9, { cloneId: 'tier2_1' }),
    sub('tier2_1', 2, 'HUIS', 0.4, 0.9, { masterId: 'tier1_1' })
  ];
  const after = S.spliceTier(before, 2, [sub('x', 2, 'HEEL-ANDER-GEBAAR', 9, 9.5)]);
  const master = after.find(s => s.tier === 1);
  assert.strictEqual(
    after.find(s => s.id === master.cloneId),
    undefined,
    'cloneId must dangle harmlessly, never point at an unrelated synced-in gloss'
  );
});

test('spliceTier does not mutate the input array', () => {
  const before = [sub('tier2_1', 2, 'OUD', 0.4, 0.9)];
  S.spliceTier(before, 2, [sub('x', 2, 'NIEUW', 1, 2)]);
  assert.strictEqual(before.length, 1);
  assert.strictEqual(before[0].text, 'OUD');
});

test('spliceTier leaves link fields on other tiers alone', () => {
  const before = [
    sub('tier1_1', 1, 'huis', 0.4, 0.9, { cloneId: 'tier2_1' }),
    sub('tier2_1', 2, 'HUIS', 0.4, 0.9, { masterId: 'tier1_1' })
  ];
  const after = S.spliceTier(before, 2, [sub('x', 2, 'HUIS-NIEUW', 1, 2)]);
  const master = after.find(s => s.tier === 1);
  assert.strictEqual(master.cloneId, 'tier2_1', 'link must survive splice so revert can restore it');
});

test('captureTierBaseline deep-copies so later edits do not leak into it', () => {
  const subs = [sub('tier2_1', 2, 'HUIS', 0.4, 0.9)];
  const baseline = S.captureTierBaseline(subs, 2);
  subs[0].text = 'GEWIJZIGD';
  assert.strictEqual(baseline[0].text, 'HUIS');
});

test('revertTiers restores the baseline exactly, ids included', () => {
  const original = [
    sub('tier0_1', 0, 'de man loopt', 0, 2),
    sub(7, 2, 'HANDGEMAAKT', 0.4, 0.9)
  ];
  const baseline = { 2: S.captureTierBaseline(original, 2) };
  const synced = S.spliceTier(original, 2, [sub('x', 2, 'UIT-MP4', 1.2, 1.8)]);
  const reverted = S.revertTiers(synced, baseline);

  assert.deepStrictEqual(
    reverted.filter(s => s.tier === 2),
    [sub(7, 2, 'HANDGEMAAKT', 0.4, 0.9)],
    'revert must not renumber ids - hand-added subs use numeric ids referenced by cloneId'
  );
});

test('revertTiers keeps hand edits made on non-synced tiers', () => {
  const original = [sub('tier0_1', 0, 'oud', 0, 2), sub('tier2_1', 2, 'HUIS', 0.4, 0.9)];
  const baseline = { 2: S.captureTierBaseline(original, 2) };
  let state = S.spliceTier(original, 2, [sub('x', 2, 'UIT-MP4', 1.2, 1.8)]);
  state = state.map(s => s.tier === 0 ? Object.assign({}, s, { text: 'handmatig bewerkt' }) : s);

  const reverted = S.revertTiers(state, baseline);
  assert.strictEqual(reverted.find(s => s.tier === 0).text, 'handmatig bewerkt');
  assert.strictEqual(reverted.find(s => s.tier === 2).text, 'HUIS');
});

test('revertTiers restores several tiers at once', () => {
  const original = [sub('tier0_1', 0, 'nl', 0, 2), sub('tier2_1', 2, 'GLOS', 0.4, 0.9)];
  const baseline = { 0: S.captureTierBaseline(original, 0), 2: S.captureTierBaseline(original, 2) };
  let state = S.spliceTier(original, 0, [sub('a', 0, 'nl-mp4', 1, 3)]);
  state = S.spliceTier(state, 2, [sub('b', 2, 'GLOS-MP4', 1.2, 1.8)]);

  const reverted = S.revertTiers(state, baseline);
  assert.strictEqual(reverted.find(s => s.tier === 0).text, 'nl');
  assert.strictEqual(reverted.find(s => s.tier === 2).text, 'GLOS');
});

test('stripDanglingLinks removes only references to absent ids', () => {
  const subs = [
    sub('tier1_1', 1, 'huis', 0.4, 0.9, { cloneId: 'weg' }),
    sub('tier1_2', 1, 'boom', 1.0, 1.4, { cloneId: 'tier2_1' }),
    sub('tier2_1', 2, 'BOOM', 1.0, 1.4, { masterId: 'tier1_2' })
  ];
  S.stripDanglingLinks(subs);
  assert.ok(!('cloneId' in subs[0]), 'dangling cloneId removed');
  assert.strictEqual(subs[1].cloneId, 'tier2_1', 'live cloneId kept');
  assert.strictEqual(subs[2].masterId, 'tier1_2', 'live masterId kept');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /web/zin && node --test test_sync_tiers.js`
Expected: FAIL — `Cannot find module './js/syncTiers.js'`

- [ ] **Step 3: Write the implementation**

Create `js/syncTiers.js`:

```js
/*
 * Pure helpers for syncing annotation tiers from a source mp4 into a mocap take.
 * No DOM, no fetch - so it can be unit tested with `node --test test_sync_tiers.js`.
 * Loaded into 3DAnn2.html as a plain script; also require()-able under Node.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root) root.SyncTiers = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {

  var TIER_NAMES = { 0: 'Nederlands', 1: 'Gebaar-voor-Gebaar', 2: 'Signbank ID glossen' };

  function deepCopy(sub) { return JSON.parse(JSON.stringify(sub)); }

  // The mocap take is named <mp4base>_<date>_<take>; the annotation was originally
  // copied from <mp4base>.eaf by getZinnen.php. Same regex, so the sync source is
  // by construction the file it was copied from.
  function getSyncSourceBase(currentFilename) {
    if (!currentFilename || typeof currentFilename !== 'string') return null;
    var m = /^(M\d{8}_\d{4})/.exec(currentFilename);
    if (!m) return null;
    if (m[1] === currentFilename) return null;   // already editing the mp4 itself
    return m[1];
  }

  function captureTierBaseline(subtitles, tier) {
    return subtitles.filter(function (s) { return s.tier === tier; }).map(deepCopy);
  }

  // Replaces one tier wholesale. Link fields on OTHER tiers are deliberately left
  // intact - consumers are null-guarded, and keeping them lets revert restore
  // byte-identical state. Dangling links are cleaned up at save time instead.
  //
  // Incoming ids use a 'sync<tier>_<n>' prefix, which cannot collide with the
  // 'tier<N>_<i>' ids transformSubtitles assigns or the numeric ids addSubtitle
  // assigns. Reusing an existing id would silently RE-BIND a surviving gloss's
  // cloneId/masterId to an unrelated synced-in gloss - worse than an orphan,
  // because the mismatched pair would then drag together.
  function spliceTier(subtitles, tier, incoming) {
    var kept = subtitles.filter(function (s) { return s.tier !== tier; });
    var n = 0;
    var added = incoming.map(function (s) {
      n++;
      var copy = deepCopy(s);
      copy.tier = tier;
      copy.id = 'sync' + tier + '_' + n;
      delete copy.cloneId;      // incoming glosses carry no pair links
      delete copy.masterId;
      return copy;
    });
    return kept.concat(added);
  }

  // Restores exactly what captureTierBaseline stored, ids included. Never renumber:
  // hand-added subs use numeric ids that cloneId/masterId point at.
  function revertTiers(subtitles, baseline) {
    var tiers = Object.keys(baseline).map(Number);
    var result = subtitles.filter(function (s) { return tiers.indexOf(s.tier) === -1; });
    tiers.forEach(function (t) { result = result.concat(baseline[t].map(deepCopy)); });
    return result;
  }

  function stripDanglingLinks(subtitles) {
    var ids = {};
    subtitles.forEach(function (s) { ids[s.id] = true; });
    subtitles.forEach(function (s) {
      if (s.cloneId !== undefined && !ids[s.cloneId]) delete s.cloneId;
      if (s.masterId !== undefined && !ids[s.masterId]) delete s.masterId;
    });
    return subtitles;
  }

  return {
    TIER_NAMES: TIER_NAMES,
    getSyncSourceBase: getSyncSourceBase,
    captureTierBaseline: captureTierBaseline,
    spliceTier: spliceTier,
    revertTiers: revertTiers,
    stripDanglingLinks: stripDanglingLinks
  };
});
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd /web/zin && node --test test_sync_tiers.js`
Expected: PASS — 12 tests, 0 failures.

This exact module and test file were run verbatim during planning: 12 pass, 0 fail.
Mutation-checked too — reverting `spliceTier` to reuse `tier<N>_<i>` ids fails tests
4 and 7, and renumbering ids inside `revertTiers` fails test 8. The tests defend the
two design decisions, they are not decorative.

- [ ] **Step 5: Checkpoint**

```bash
cd /web/zin && node --test test_sync_tiers.js 2>&1 | tail -5
```
No backup needed — both files are new. Do not proceed while any test fails.

---

### Task 2: Toolbar controls and script include

Renders the controls and gets their enabled/disabled state right. **No sync behaviour yet** — buttons are inert. This is separately reviewable: a reviewer can confirm placement, colours, and that the group correctly disables itself on an mp4-base file, without any network behaviour in play.

**Files:**
- Modify: `3DAnn2.html:8` (script include), `:619` (toolbar), CSS block near `:194`

**Interfaces:**
- Consumes: `SyncTiers.getSyncSourceBase`, `SyncTiers.TIER_NAMES` from Task 1.
- Produces: DOM ids `#syncGroup`, `#syncTier0Btn`, `#syncTier1Btn`, `#syncTier2Btn`, `#saveSyncBtn`, `#revertSyncBtn`, `#syncStatus`; and `function updateSyncStatusUI()` which Tasks 3-5 call after every state change.

- [ ] **Step 1: Back up the file before first modification**

```bash
cd /web/zin && cp 3DAnn2.html "3DAnn2.html.backup_$(date +%Y%m%d_%H%M%S)" && ls -1 3DAnn2.html.backup_* | tail -1
```

- [ ] **Step 2: Add the script include**

After line 8 (`babylonjs.loaders.min.js`), add:

```html
  <script src="js/syncTiers.js"></script>
```

It must load before the inline script at line 672, which references `SyncTiers`.

- [ ] **Step 3: Add the CSS**

In the `<style>` block, after the tier background rules at `:194-197`, add:

```css
    /* Sync-from-mp4 control group */
    .sync-group { display: inline-flex; align-items: center; gap: 4px;
                  padding: 2px 8px; margin-left: 8px;
                  border-left: 1px solid #ccc; }
    .sync-group-label { font-size: 12px; color: #555; margin-right: 2px; }
    .sync-group .btn { font-size: 12px; }
    .sync-tier-btn.staged { font-weight: 600; box-shadow: 0 0 0 2px rgba(0,0,0,.15) inset; }
    .sync-status { font-size: 12px; color: #b8860b; font-weight: 600; margin-left: 4px; }
```

- [ ] **Step 4: Add the toolbar markup**

Immediately after the `keyboardControlsBtn` line (`:619`), insert:

```html
      <span id="syncGroup" class="sync-group">
        <span class="sync-group-label">Sync van mp4:</span>
        <button id="syncTier0Btn" class="btn btn-sm btn-outline-primary sync-tier-btn" data-tier="0" disabled>Nederlands</button>
        <button id="syncTier1Btn" class="btn btn-sm btn-outline-warning sync-tier-btn" data-tier="1" disabled>Gebaar-voor-Gebaar</button>
        <button id="syncTier2Btn" class="btn btn-sm btn-outline-success sync-tier-btn" data-tier="2" disabled>Signbank ID glossen</button>
        <button id="saveSyncBtn" class="btn btn-sm btn-success" disabled>Save Sync</button>
        <button id="revertSyncBtn" class="btn btn-sm btn-outline-danger" disabled>Revert Sync</button>
        <span id="syncStatus"></span>
      </span>
```

- [ ] **Step 5: Add the state module and UI updater**

In the inline script, immediately before `function uploadSubtitles()` (`:3296`), add:

```js
    // ============================================
    // TIER SYNC FROM SOURCE MP4
    // ============================================
    // tier index -> deep copy of that tier's subs as they were before its first sync
    let syncBaseline = {};
    let manualEditsWhileStaged = false;
    let syncFetchInFlight = false;

    function isSyncStaged() { return Object.keys(syncBaseline).length > 0; }

    function getSyncSourceBase() { return SyncTiers.getSyncSourceBase(getSaveFilename()); }

    function updateSyncStatusUI() {
      const source = getSyncSourceBase();
      const staged = Object.keys(syncBaseline).map(Number);
      const group = document.getElementById('syncGroup');

      group.title = source
        ? 'Haal een tier op uit de annotatie van ' + source
        : 'Geen bron-mp4 gevonden voor dit bestand';

      [0, 1, 2].forEach(function (t) {
        const btn = document.getElementById('syncTier' + t + 'Btn');
        btn.disabled = !source || syncFetchInFlight;
        btn.classList.toggle('staged', staged.indexOf(t) !== -1);
      });

      document.getElementById('saveSyncBtn').disabled = !staged.length;
      document.getElementById('revertSyncBtn').disabled = !staged.length;

      const status = document.getElementById('syncStatus');
      status.textContent = staged.length
        ? '● ' + staged.length + (staged.length === 1 ? ' tier' : ' tiers') + ' gesynct — niet opgeslagen'
        : '';
    }

    updateSyncStatusUI();
```

- [ ] **Step 6: Verify in the browser**

```bash
cd /web/zin && php -S localhost:8765 >/dev/null 2>&1 &
```

Open `http://localhost:8765/3DAnn2.html?filename=M20240925_1838_251210_2`.

Expected:
- The group appears right of `⌨ Keyboard Controls`, reading `Sync van mp4:` then five buttons.
- All three tier buttons are **enabled**; Save Sync and Revert Sync are **disabled**; status text is empty.
- Hovering the group shows `Haal een tier op uit de annotatie van M20240925_1838`.

Then open `http://localhost:8765/3DAnn2.html?filename=M20240925_1838` (the mp4 base itself).

Expected: **all five** buttons disabled, tooltip `Geen bron-mp4 gevonden voor dit bestand`.

Check the browser console is free of errors — in particular no `SyncTiers is not defined`.

- [ ] **Step 7: Checkpoint**

```bash
cd /web/zin && cp 3DAnn2.html "3DAnn2.html.backup_$(date +%Y%m%d_%H%M%S)"
```

---

### Task 3: Sync fetch, staging, and autosave suppression

**Files:**
- Modify: `3DAnn2.html` — the sync block added in Task 2, plus `uploadSubtitles()` at `:3296`

**Interfaces:**
- Consumes: `updateSyncStatusUI()`, `syncBaseline`, `manualEditsWhileStaged`, `syncFetchInFlight`, `isSyncStaged()`, `getSyncSourceBase()` from Task 2; `SyncTiers.captureTierBaseline`, `SyncTiers.spliceTier`, `SyncTiers.TIER_NAMES` from Task 1. Existing: `transformSubtitles()` (`:3132`), `performUpload()` (`:3306`), `autosaveTimer`, `renderAll()`, `showToast()` (`:3208`).
- Produces: `async function syncTierFromSource(tier)`.

- [ ] **Step 1: Add the autosave suppression**

Change the top of `uploadSubtitles()` (`:3296`) from:

```js
    function uploadSubtitles() {
      hasUnsavedChanges = true;
```

to:

```js
    function uploadSubtitles() {
      // While a sync is staged, nothing is written to disk - Save Sync or Revert
      // Sync decides. Hand edits made in the meantime are remembered and saved
      // once the staged sync is resolved.
      if (isSyncStaged()) {
        manualEditsWhileStaged = true;
        updateSyncStatusUI();
        return;
      }
      hasUnsavedChanges = true;
```

Leave the rest of the function untouched. Every existing call site keeps working unchanged.

- [ ] **Step 2: Add the sync function**

Below `updateSyncStatusUI()`, add:

```js
    async function syncTierFromSource(tier) {
      const source = getSyncSourceBase();
      if (!source || syncFetchInFlight) return;
      const tierName = SyncTiers.TIER_NAMES[tier];

      // Close the re-entry guard BEFORE the first await. The pre-sync flush
      // suspends this function while the buttons are still enabled, so setting
      // the flag after it would let a double-click start a second concurrent
      // sync: two uploads, two fetches, and a flag that one flow clears while
      // the other is still running.
      syncFetchInFlight = true;
      updateSyncStatusUI();
      try {
        // Flush any debounced edit first, so the baseline provably equals disk.
        // Skipped when already staged: autosave is suppressed and disk has not moved.
        if (!isSyncStaged()) {
          clearTimeout(autosaveTimer);
          if (hasUnsavedChanges) {
            try {
              await performUpload();
            } catch (err) {
              showToast('Kon huidige wijzigingen niet opslaan — sync afgebroken', 'error', 5000);
              return;
            }
          }
        }

        const response = await fetch('getZinnen.php?action=fetchSubtitles&filename=' + encodeURIComponent(source));
        const data = await response.json();
        if (!data.success || !data.subtitles) {
          showToast('Geen mp4-annotatie gevonden voor ' + source, 'error', 5000);
          return;
        }

        const incoming = transformSubtitles(data.subtitles).filter(function (s) { return s.tier === tier; });
        if (!incoming.length) {
          showToast(tierName + ' is leeg in de mp4-annotatie — niets gesynct', 'error', 5000);
          return;
        }

        // Capture the baseline only on this tier's FIRST sync, so syncing twice
        // does not overwrite it with already-synced data.
        if (!(tier in syncBaseline)) {
          syncBaseline[tier] = SyncTiers.captureTierBaseline(subtitles, tier);
        }
        subtitles = SyncTiers.spliceTier(subtitles, tier, incoming);
        renderAll();
        showToast(tierName + ' gesynct uit ' + source + ' (' + incoming.length + ' items) — nog niet opgeslagen', 'success', 4000);
      } catch (err) {
        console.error('Sync error', err);
        showToast('Netwerkfout bij synchroniseren — niets gewijzigd', 'error', 5000);
      } finally {
        syncFetchInFlight = false;
        updateSyncStatusUI();
      }
    }

    [0, 1, 2].forEach(function (t) {
      document.getElementById('syncTier' + t + 'Btn')
        .addEventListener('click', function () { syncTierFromSource(t); });
    });
```

- [ ] **Step 3: Verify a successful sync in the browser**

Restart the server if needed, open
`http://localhost:8765/3DAnn2.html?filename=M20240925_1838_251210_2`, then:

1. Note the current contents of the Signbank ID glossen tier.
2. Click `Signbank ID glossen`.

Expected: the tier is replaced, a green toast names the source and item count, the button gains the `staged` outline, status reads `● 1 tier gesynct — niet opgeslagen`, Save/Revert become enabled.

3. In the Network tab, confirm exactly one request to `fetchSubtitles&filename=M20240925_1838` and **no** request to `saveSubtitlesAndEAFFiles`.

- [ ] **Step 4: Verify autosave is genuinely suppressed**

With the sync still staged, drag a gloss on the Nederlands tier.

Expected: **no** `saveSubtitlesAndEAFFiles` request appears in the Network tab, even after waiting 5 seconds (the debounce is 1s). The status text stays as it was.

Confirm on disk that nothing moved:

```bash
cd /web/zin && ls -l --time-style=+%H:%M:%S eaf/zin/M20240925_1838_251210_2_Signbank_ID_glossen.srt
```
The mtime must be unchanged from before the sync.

- [ ] **Step 5: Verify the refusal paths**

- Click a tier whose source is empty → red toast `<tier> is leeg in de mp4-annotatie — niets gesynct`, and **nothing stages** (Save/Revert stay disabled, no `staged` outline).
- Temporarily rename the source to force a miss, then click a tier:

```bash
cd /web/zin/eaf/zin && mkdir -p /tmp/synctest && mv M20240925_1838.eaf M20240925_1838_*.srt /tmp/synctest/ 2>/dev/null; ls M20240925_1838* 2>&1 | head -3
```
Expected: red toast `Geen mp4-annotatie gevonden voor M20240925_1838`, nothing staged.

Restore immediately:

```bash
mv /tmp/synctest/* /web/zin/eaf/zin/ && ls /web/zin/eaf/zin/M20240925_1838.eaf
```

- [ ] **Step 6: Checkpoint**

```bash
cd /web/zin && node --test test_sync_tiers.js 2>&1 | tail -3 && cp 3DAnn2.html "3DAnn2.html.backup_$(date +%Y%m%d_%H%M%S)"
```

---

### Task 4: Save Sync and Revert Sync

**Files:**
- Modify: `3DAnn2.html` — sync block from Task 3

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces: `async function saveSync()` and `function revertSync()`, both used by Task 5's navigation modal.

- [ ] **Step 1: Add both functions**

Below `syncTierFromSource`, add:

```js
    // Commits the staged sync AND any hand edits made while it was staged -
    // performUpload writes the whole subtitles array, and both were held back together.
    async function saveSync() {
      if (!isSyncStaged()) return;
      SyncTiers.stripDanglingLinks(subtitles);
      const btn = document.getElementById('saveSyncBtn');
      btn.disabled = true;
      try {
        hasUnsavedChanges = true;      // performUpload clears it on success
        await performUpload();
        // Clear ONLY on success: dropping the baseline after a failed upload
        // would leave the user unable to revert.
        syncBaseline = {};
        manualEditsWhileStaged = false;
        showToast('Sync opgeslagen', 'success', 2500);
      } catch (err) {
        showToast('Opslaan van sync mislukt — sync blijft staan', 'error', 5000);
      } finally {
        updateSyncStatusUI();
      }
    }

    function revertSync() {
      if (!isSyncStaged()) return;
      subtitles = SyncTiers.revertTiers(subtitles, syncBaseline);
      const hadManualEdits = manualEditsWhileStaged;
      // Clear the staged state BEFORE calling uploadSubtitles, or its
      // early return swallows the very save meant to persist the hand edits.
      syncBaseline = {};
      manualEditsWhileStaged = false;
      renderAll();
      if (hadManualEdits) uploadSubtitles();
      updateSyncStatusUI();
      showToast('Sync verworpen', 'success', 2500);
    }

    document.getElementById('saveSyncBtn').addEventListener('click', saveSync);
    document.getElementById('revertSyncBtn').addEventListener('click', revertSync);
```

- [ ] **Step 2: Verify Save**

Sync a tier, then click `Save Sync`.

Expected: one `saveSubtitlesAndEAFFiles` request, toast `Sync opgeslagen`, status clears, Save/Revert disable, `staged` outline clears.

Confirm the file actually changed on disk:

```bash
cd /web/zin && ls -l --time-style=+%H:%M:%S eaf/zin/M20240925_1838_251210_2_Signbank_ID_glossen.srt && head -8 eaf/zin/M20240925_1838_251210_2_Signbank_ID_glossen.srt
```
The mtime must be current and the contents must match the source mp4's tier.

Reload the page and confirm the synced content persisted.

- [ ] **Step 3: Verify Revert keeps hand edits on other tiers**

This is the behaviour the design was chosen for — test it precisely.

1. Note the Signbank ID glossen tier contents.
2. Click `Signbank ID glossen` to sync.
3. Drag a gloss on the **Nederlands** tier to a visibly different position.
4. Click `Revert Sync`.

Expected:
- Signbank ID glossen returns to exactly its pre-sync contents.
- The Nederlands gloss **stays where you dragged it**.
- Toast `Sync verworpen`, status clears.
- A `saveSubtitlesAndEAFFiles` request fires ~1s later, persisting the Nederlands edit.

Reload and confirm: Signbank tier original, Nederlands edit preserved.

- [ ] **Step 4: Verify a failed save leaves the sync staged**

With DevTools set to offline, sync a tier and click `Save Sync`.

Expected: red toast `Opslaan van sync mislukt — sync blijft staan`, status text **still shows the staged tier**, Save and Revert **still enabled**. Go back online and click `Save Sync` again — it must now succeed.

- [ ] **Step 5: Checkpoint**

```bash
cd /web/zin && node --test test_sync_tiers.js 2>&1 | tail -3 && cp 3DAnn2.html "3DAnn2.html.backup_$(date +%Y%m%d_%H%M%S)"
```

---

### Task 5: Navigation guard

Stops the existing save-before-leaving machinery from silently committing a staged sync.

**Files:**
- Modify: `3DAnn2.html:660` (modal markup), `:875-882` (`backToZinnenBtn`), `:3245` (`beforeunload`), `:3257` (anchor click), `:3284` (`popstate`)

**Interfaces:**
- Consumes: `isSyncStaged()`, `saveSync()`, `revertSync()` from Tasks 2-4. Existing: `saveAndNavigate()` (`:3266`), `hasUnsavedChanges`.
- Produces: `function guardStagedSync(proceed)` — runs `proceed()` immediately when nothing is staged, otherwise opens the modal.

- [ ] **Step 1: Add the modal markup**

After the `keyboardControlsModal` closing `</div>` at `:660`, insert:

```html
    <!-- Staged-sync guard: shown when leaving with an unsaved sync -->
    <div class="modal fade" id="syncPendingModal" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Niet-opgeslagen sync</h5>
          </div>
          <div class="modal-body">
            <p class="mb-0">Je hebt een niet-opgeslagen sync. Wat wil je doen?</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-success" id="syncPendingSaveBtn">Sync opslaan</button>
            <button type="button" class="btn btn-outline-danger" id="syncPendingDiscardBtn">Sync verwerpen</button>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Op deze pagina blijven</button>
          </div>
        </div>
      </div>
    </div>
```

- [ ] **Step 2: Add the guard**

Below `revertSync()`, add:

```js
    // Runs proceed() straight away when no sync is staged. Otherwise asks first -
    // a staged sync must never be committed or discarded without the user saying so.
    let syncPendingProceed = null;

    function guardStagedSync(proceed) {
      if (!isSyncStaged()) { proceed(); return; }
      syncPendingProceed = proceed;
      $('#syncPendingModal').modal({ backdrop: 'static', keyboard: false });
    }

    document.getElementById('syncPendingSaveBtn').addEventListener('click', async function () {
      await saveSync();
      if (isSyncStaged()) return;        // save failed - stay put, toast already shown
      // Capture BEFORE hiding: the hidden.bs.modal handler clears it.
      const proceed = syncPendingProceed; syncPendingProceed = null;
      $('#syncPendingModal').modal('hide');
      if (proceed) proceed();
    });

    document.getElementById('syncPendingDiscardBtn').addEventListener('click', function () {
      revertSync();
      const proceed = syncPendingProceed; syncPendingProceed = null;
      $('#syncPendingModal').modal('hide');
      if (proceed) proceed();
    });

    // jQuery loads at :3870, AFTER this inline script - so this one parse-time
    // jQuery call must wait for load. The $ calls above are inside handlers that
    // only run later, and are therefore safe as written.
    window.addEventListener('load', function () {
      $('#syncPendingModal').on('hidden.bs.modal', function () { syncPendingProceed = null; });
    });
```

- [ ] **Step 3: Guard `#backToZinnenBtn`**

This is the path users actually take, and the existing anchor interceptor never sees it — it is a `<button>` setting `window.location.href`, and the page contains no `<a href>` elements at all.

Replace the body of the listener at `:875-882`:

```js
    document.getElementById('backToZinnenBtn').addEventListener('click', function() {
      const urlParams = new URLSearchParams(window.location.search);
      const filename = urlParams.get('filename');
      let returnUrl = 'zinnen.html';
      if (filename) {
        returnUrl += `?edited=${filename}`;
      }
      guardStagedSync(function () { window.location.href = returnUrl; });
    });
```

- [ ] **Step 4: Guard `beforeunload`**

Replace the handler at `:3245`:

```js
    window.addEventListener('beforeunload', function (e) {
      // A staged sync must not be beaconed to disk - that would commit something
      // the user never approved. Fall back to the browser's own leave prompt.
      if (isSyncStaged()) {
        e.preventDefault();
        e.returnValue = '';
        return '';
      }
      if (hasUnsavedChanges && !isSavingBeforeUnload) {
        const fileParam = getSaveFilename();
        if (fileParam) {
          const blob = new Blob([JSON.stringify({ subtitles: subtitles })], { type: 'application/json' });
          navigator.sendBeacon('getZinnen.php?action=saveSubtitlesAndEAFFiles&filename=' + encodeURIComponent(fileParam), blob);
        }
      }
    });
```

- [ ] **Step 5: Guard the anchor interceptor and `popstate`**

In the click interceptor at `:3257`, replace `saveAndNavigate(href)` with:

```js
      guardStagedSync(function () { saveAndNavigate(href); });
```

Move the `if (!link || !hasUnsavedChanges) return;` guard to `if (!link) return;` and re-check `hasUnsavedChanges` inside the proceed callback, so a staged sync is caught even when `hasUnsavedChanges` is false:

```js
    document.addEventListener('click', function (e) {
      const link = e.target.closest('a[href]');
      if (!link) return;
      if (!hasUnsavedChanges && !isSyncStaged()) return;
      const href = link.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
      e.preventDefault();
      guardStagedSync(function () {
        isSavingBeforeUnload = true;
        saveAndNavigate(href);
      });
    });
```

And `popstate` at `:3284`:

```js
    window.addEventListener('popstate', function () {
      if (isSyncStaged()) {
        history.pushState(null, '', document.location.href);   // cancel the navigation
        guardStagedSync(function () { saveAndNavigate(document.location.href); });
        return;
      }
      if (hasUnsavedChanges) {
        saveAndNavigate(document.location.href);
      }
    });
```

- [ ] **Step 6: Verify each exit path**

For each of the four, first sync a tier so a sync is staged:

1. **`Go back to Zinnen`** → modal appears. Test all three buttons:
   - `Op deze pagina blijven` → modal closes, still on the page, sync still staged.
   - `Sync verwerpen` → tier reverts, then navigates to `zinnen.html`.
   - `Sync opslaan` → saves, then navigates.
2. **Tab close** → browser's native "leave site?" prompt appears. Cancel it, then confirm via DevTools Network that **no** `sendBeacon` to `saveSubtitlesAndEAFFiles` was sent.
3. **Browser back button** → modal appears, page does not navigate.
4. Repeat 1 with **nothing staged** → navigates immediately, no modal. This confirms the guard is transparent when unused.

- [ ] **Step 7: Verify the critical no-beacon case on disk**

```bash
cd /web/zin && ls -l --time-style=+%H:%M:%S eaf/zin/M20240925_1838_251210_2_*.srt
```
Sync a tier, close the tab, confirm the leave prompt, then re-run the command. **No mtime may have changed.** This is the single most important check in the plan — it is the failure the staged model exists to prevent.

- [ ] **Step 8: Final checkpoint**

```bash
cd /web/zin && node --test test_sync_tiers.js 2>&1 | tail -3 && cp 3DAnn2.html "3DAnn2.html.backup_$(date +%Y%m%d_%H%M%S)"
```

Then stop the dev server:

```bash
pkill -f "php -S localhost:8765"
```

---

## Full regression checklist

Run once after Task 5, against `?filename=M20240925_1838_251210_2`:

- [ ] Existing autosave still works normally when no sync is staged (drag a gloss, see the request fire after ~1s)
- [ ] `Auto-Segment` and `Revert Autoseg` still work and are unaffected
- [ ] `Download Subtitles` still works
- [ ] Adding a subtitle on tier 1 still creates its tier-2 twin, and they still move together
- [ ] After a sync + save of tier 2, a tier-1 master whose twin was replaced no longer moves its old twin — and does not throw in the console
- [ ] Syncing the same tier twice, then reverting, restores the **original** pre-first-sync state
- [ ] Syncing all three tiers, then reverting, restores all three
- [ ] `node --test test_sync_tiers.js` passes
