# 3DAnn2 — Per-tier annotation sync from the source mp4

**Date:** 2026-08-17
**Status:** Approved design, not yet implemented
**Scope:** `3DAnn2.html` only. No PHP changes.

## Problem

A mocap take is annotated in `3DAnn2.html` under the GLB basename, e.g.
`M20260127_2128_260317_1`. Its annotation file does not start out as its own —
`fetchSubtitles()` in `getZinnen.php:2816-2829` creates it by copying the source
mp4's EAF (`M20260127_2128.eaf`) the first time the mocap take is opened:

```php
if (!file_exists($eafPath)) {
    if (preg_match('/^(M\d{8}_\d{4})/', $baseFilename, $matches)) {
        $extractedBase = $matches[1];
        if ($extractedBase !== $baseFilename) {
            $baseEafPath = $eafDir . $extractedBase . '.eaf';
            if (file_exists($baseEafPath)) {
                copy($baseEafPath, $eafPath);
            }
        }
    }
}
```

That copy is a **one-time snapshot and is never refreshed.** When the mocap take
is opened before the mp4's annotation is finished, the take inherits an
incomplete annotation and has no way to pick up the mp4's later work.

There are 444 versioned mocap EAFs in `eaf/zin/`, every one with its source
`M\d{8}_\d{4}.eaf` present on disk.

## Solution

Five controls in the `3DAnn2.html` toolbar: one **Sync** button per tier, plus
**Save Sync** and **Revert Sync**. A sync pulls one tier from the source mp4's
annotation and stages it in memory. Nothing reaches disk until Save.

### Decisions

| Question | Decision |
|---|---|
| What does a sync copy? | **Text and timings** — full replacement of the tier, the same semantics as the original copy |
| Autosave interaction | **Staged.** Autosave is suppressed while a sync is pending; Save commits, Revert discards |
| Leaving with a sync staged | **Ask** — save / discard / stay. Never commit or discard silently |
| What does Revert restore? | **Only the synced tiers.** Manual edits made to other tiers while staged survive and are then saved normally |
| Where does source data come from? | The existing `fetchSubtitles` endpoint. No new PHP |

### Tier mapping

Established by `transformSubtitles()` (`3DAnn2.html:3132-3137`):

| Tier | Track name | Timeline colour (`:2269`) |
|---|---|---|
| 0 | Nederlands | `#1976d2` blue |
| 1 | Gebaar-voor-Gebaar | `#f57c00` orange |
| 2 | Signbank ID glossen | `#388e3c` green |

## Architecture

### Source resolution

```js
// Returns the source mp4 basename, or null when there is nothing to sync from.
function getSyncSourceBase() {
    const current = getSaveFilename();          // existing, :3197
    const m = /^(M\d{8}_\d{4})/.exec(current);
    if (!m) return null;                        // unrecognised filename
    if (m[1] === current) return null;           // already editing the mp4 itself
    return m[1];
}
```

The same regex the PHP copy uses, so the sync source is by construction the file
the annotation was originally copied from.

### State

```js
// tier index -> deep copy of that tier's subs as they were before its first sync
let syncBaseline = {};
let manualEditsWhileStaged = false;

const isSyncStaged = () => Object.keys(syncBaseline).length > 0;
```

`syncBaseline`'s keys are exactly the staged tiers. A tier's baseline is captured
**only on its first sync**, so syncing the same tier twice does not overwrite the
baseline with already-synced data.

### Sync flow

1. **Flush pending autosave** — `clearTimeout(autosaveTimer); await performUpload()`.
   Guarantees the baseline equals what is on disk. Skipped when a sync is already
   staged, since autosave is suppressed and disk has not moved.
   If the flush fails, **abort the sync** — an unflushed edit would make the
   baseline disagree with disk and Revert would silently restore the wrong state.
2. `fetch('getZinnen.php?action=fetchSubtitles&filename=' + sourceBase)`, then
   `transformSubtitles(data.subtitles)`, keeping only the requested tier.
3. If that tier is empty, toast and **abort without staging.** An empty source
   tier is the "not finished yet" case; replacing a mocap tier with nothing is
   never the intent.
4. Capture the baseline if not already held, replace the tier in `subtitles`,
   renumber that tier's ids, `renderAll()`.

Ids follow the existing scheme from `transformSubtitles()` — `tier{N}_{i}`,
counted per tier. Because the prefix encodes the tier, renumbering a replaced
tier cannot collide with ids on the other two.

The response carries all three tiers, but a fresh fetch is issued per click.
Sync exists precisely because the source changes, so caching would defeat it.

#### Reusing `fetchSubtitles` is safe

Calling it with a plain mp4 basename cannot write a versioned EAF: the copy is
guarded by `$extractedBase !== $baseFilename`, and for a plain base those are
equal. Its only side effect is regenerating the mp4's *own* SRTs from its EAF
when they are absent, which is harmless.

### Autosave suppression

`uploadSubtitles()` (`:3296`) gets an early return:

```js
function uploadSubtitles() {
    if (isSyncStaged()) { manualEditsWhileStaged = true; updateSyncStatusUI(); return; }
    /* ...existing body unchanged... */
}
```

Every existing call site — drag, resize, keyboard nudge, delete, add — keeps
calling it exactly as today. No call-site churn.

### Revert

For each tier in `syncBaseline`: drop that tier's current subs, re-insert the
baseline copies. Clear `syncBaseline`, then reset `manualEditsWhileStaged` — but
only after reading it, and only after `syncBaseline` is cleared, since
`uploadSubtitles()` early-returns while a sync is still considered staged:

```js
const hadManualEdits = manualEditsWhileStaged;
syncBaseline = {};
manualEditsWhileStaged = false;
if (hadManualEdits) uploadSubtitles();   // hand edits on other tiers save normally
```

Pure in-memory; cannot fail.

### Save

`performUpload()` **first**, clear `syncBaseline` and `manualEditsWhileStaged`
**only on success.** Clearing first would strand the user: a failed upload with no
baseline left means Revert can no longer restore. `performUpload()` already throws
and toasts on failure (`:3325-3334`), so a failed save simply leaves the sync
staged for retry.

Note that `performUpload()` writes the whole `subtitles` array, so a Save commits
the staged sync *and* any hand edits made while it was staged — which is the
intended behaviour, since both were being held back together.

### Master/twin links

Tiers 1 and 2 can be linked as master/twin pairs (`:2946-2948`). A wholesale
tier replace orphans those links. Every consumer — `:2625`, `:2734`, `:2797`,
`:2856`, `:2985` — is null-guarded (`if (master && twin)`), so an orphan degrades
to "this pair no longer moves together" rather than an error.

**Two corrections found while writing the implementation plan.** Both change the
design; the plan implements the corrected version.

1. **Do not strip dangling links at splice time.** Stripping during the replace
   permanently destroys a tier-1 master's link to its tier-2 twin, so a later
   Revert could no longer restore byte-identical state — defeating the Revert
   semantics this design commits to. Leave link fields untouched while staged
   (consumers are null-guarded) and strip only on **Save**, when state is being
   committed anyway.

2. **Synced-in glosses must not reuse existing ids.** The "orphan degrades
   harmlessly" claim above holds *only* if the replaced tier's ids are not
   reused. Numbering incoming glosses `tier<N>_<i>` reuses them, producing not an
   orphan but a silent **wrong re-binding**: a surviving `tier1_1` with
   `cloneId: 'tier2_1'` gets paired with whatever synced-in gloss lands on
   `tier2_1`, and the pair-drag path at `:2985` then drags two unrelated glosses
   together. Verified during planning:

   ```
   master.cloneId = tier2_1
   now points at  = {"id":"tier2_1","text":"HEEL-ANDER-GEBAAR","start":9}
   ```

   Synced-in glosses therefore take `sync<tier>_<n>` ids, which cannot collide
   with the `tier<N>_<i>` ids `transformSubtitles` assigns or the numeric ids
   `addSubtitle` assigns.

## UI

Placed in `.add-bar` immediately after `keyboardControlsBtn` (`:619`), grouped so
it reads as one unit:

```
[⌨ Keyboard Controls] │ Sync van mp4: [Nederlands] [Gebaar-voor-Gebaar] [Signbank ID glossen]  [Save Sync] [Revert Sync]
```

Tier buttons use outline variants matching each tier's timeline colour, tying the
button to the tier it rewrites.

**Disabled states:**

- No resolvable source base → all five disabled, group title explains why.
- Nothing staged → Save / Revert disabled (mirrors `revertAutoSegBtn`, `:617`).
- Fetch in flight → that tier's button disabled with a spinner.
- A staged tier's button takes an active look, showing at a glance what is pending.

**Status indicator** beside the group: `● 2 tiers gesynct — niet opgeslagen`, in
the existing unsaved-state warning colour. This is the primary cue that autosave
is paused.

**Language:** English button verbs, matching the adjacent `Load GLB` /
`Auto-Segment` / `Revert Autoseg`. Dutch toasts and modal text, matching the
existing `Opgeslagen!` / `Opslaan mislukt!`.

## Navigation guard

Four exit paths. Each needs a staged-sync branch ahead of its current logic.

| Path | Today | With a sync staged |
|---|---|---|
| `#backToZinnenBtn` (`:875`) | sets `window.location.href` directly | 3-way modal |
| `beforeunload` (`:3245`) | `sendBeacon` commits silently | **Do not beacon.** `preventDefault()` → native prompt |
| anchor click (`:3257`) | `saveAndNavigate(href)` | `preventDefault()` → 3-way modal |
| `popstate` (`:3283`) | `saveAndNavigate(...)` | push state back, then 3-way modal |

`#backToZinnenBtn` is the path users actually take and the one most easily
missed: it is a `<button>`, not an anchor, so the existing interceptor at `:3257`
never sees it. The page contains no `<a href>` elements at all, making that
interceptor currently dead for this page — it is kept and extended for safety.

Suppressing the `beforeunload` beacon is the critical change. Leaving it would
commit an unapproved sync, the exact failure the staged model exists to prevent.

The modal is a Bootstrap 4 `#syncPendingModal`; the file already uses
`data-toggle="modal"` for the keyboard help, so no new dependency:

```
Je hebt een niet-opgeslagen sync.
  [ Sync opslaan ]           → performUpload() → navigate
  [ Sync verwerpen ]         → revertSync()    → navigate
  [ Op deze pagina blijven ] → dismiss, stay
```

## Error handling

The rule: **a sync either stages cleanly or does nothing at all.** No partial states.

| Case | Behaviour |
|---|---|
| Source EAF and SRTs both missing | `fetchSubtitles` returns `success:false` → toast *"Geen mp4-annotatie gevonden voor {base}"*, nothing staged |
| Network or parse error | Toast, nothing staged |
| Source tier empty | Toast *"{Tier} is leeg in de mp4-annotatie — niets gesynct"*, nothing staged |
| Pre-sync flush fails | Abort the sync; baseline would not match disk |
| Save fails | Stays staged, banner remains, retry available |
| Revert | Pure in-memory, cannot fail |

## Testing

The project has no test framework; its convention is standalone `test_*` scripts
run by hand (72 under `api/`). This design matches that rather than introducing
tooling.

### Automated — `test_sync_logic.js`, run with `node`

Requires `getSyncSourceBase()`, the tier splice, and the revert to be written as
side-effect-free functions over `(subtitles, tier, incoming)` returning new arrays.

- Base resolution: `M20260127_2128_260317_1` → `M20260127_2128`; plain mp4 base → `null`; unrecognised → `null`
- Splice replaces only the target tier, leaving others byte-identical
- Ids renumber within the tier without cross-tier collision
- Revert restores a baseline byte-identical to the pre-sync state
- Syncing one tier twice keeps the original baseline, not the intermediate

### Manual — browser checklist

- Autosave does not fire while a sync is staged
- Revert restores the synced tier, keeps another tier's hand edits, then saves them
- Each of the four exit paths hits the modal or native prompt
- `beforeunload` does not beacon while staged
- Empty source tier refuses cleanly, stages nothing
- Missing source annotation refuses cleanly, stages nothing
- Failed save leaves the sync staged and retryable

Verified against a real pair from disk — `M20240925_1838_251210_2` ←
`M20240925_1838`, both confirmed present — rather than synthetic data.

## Design changes found during implementation review

Three further corrections, all implemented. Each was found by review, not by the
original design.

1. **`syncTierFromSource` must close its re-entry guard before the first
   `await`.** The pre-sync flush suspends the function while the tier buttons
   are still enabled, so setting `syncFetchInFlight` after it let a double-click
   start a second concurrent sync — two uploads, two fetches, and a flag one
   flow cleared while the other was still running.

2. **Auto-Segment and a staged sync must be mutually exclusive.** `Revert
   Autoseg` restores `subtitles = autoSegSnapshot` and then calls
   `uploadSubtitles()`. If that snapshot was captured while a sync was staged it
   contains the sync, so after the user *discards* the sync, clicking Revert
   Autoseg writes the rejected sync to disk — autosave is no longer suppressed.
   `updateSyncStatusUI()` therefore disables `autoSegBtn` / `autoSeg2DBtn` /
   `revertAutoSegBtn` while staged, `runAutoSegment`'s completion handlers
   re-check `isSyncStaged()` before re-enabling them, and `syncTierFromSource`
   nulls `autoSegSnapshot` at splice time.

3. **`stripDanglingLinks` must run only after a successful upload.** Running it
   before `await performUpload()` meant a failed save had already deleted
   `cloneId` / `masterId` permanently while the sync stayed staged for retry — a
   later Revert would restore a twin whose master's link was gone, leaving a
   half-linked pair. The server ignores both fields, so moving the call after the
   upload does not change the payload.

Two smaller guards were added at the same time: the tier buttons stay disabled
until the initial `fetchSubtitlesFromServer()` has resolved (an early click
would otherwise capture a baseline from an empty array and wedge the page), and
a failed initial load now raises a Dutch toast instead of failing silently.

## Out of scope

- Any change to `getZinnen.php`
- Syncing in the other direction (mocap → mp4)
- Automatic or background re-sync; every sync is explicit and user-initiated
- Per-gloss or partial-tier merge; replacement is wholesale by tier

## Note

`/web/zin` is not a git repository, so this document cannot be committed. It
lives at `docs/superpowers/specs/2026-08-17-3dann2-tier-sync-design.md`.
