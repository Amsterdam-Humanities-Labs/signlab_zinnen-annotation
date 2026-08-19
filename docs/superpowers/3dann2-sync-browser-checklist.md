# 3DAnn2 tier-sync — browser verification checklist

Everything below needs a human at a browser; none of it could be automated.

**Rollback if anything is badly wrong:**
```bash
cp /web/zin/3DAnn2.html.backup_20260817_095919 /web/zin/3DAnn2.html
```

**Start a server** (Apache returns 403 on this path; the built-in server works):
```bash
cd /web/zin && php -S localhost:8765
```

Then open a mocap take — this pair is confirmed present on disk:
```
http://localhost:8765/3DAnn2.html?filename=M20240925_1838_251210_2
```
Its source mp4 annotation is `M20240925_1838`.

Keep DevTools open on the **Network** tab throughout. The single most important
thing to watch for is any unexpected request to `saveSubtitlesAndEAFFiles`.

---

## 1. Controls render and gate correctly

- [ ] The group appears right of `⌨ Keyboard Controls`: `Sync van mp4:` then
      Nederlands / Gebaar-voor-Gebaar / Signbank ID glossen / Save Sync / Revert Sync
- [ ] Tier buttons enabled; Save Sync and Revert Sync disabled; status text empty
- [ ] Hovering the group shows `Haal een tier op uit de annotatie van M20240925_1838`
- [ ] Console is free of errors — especially `SyncTiers is not defined` or `$ is not defined`

Now open the mp4 base itself: `?filename=M20240925_1838`

- [ ] All five buttons disabled, tooltip `Geen bron-mp4 gevonden voor dit bestand`

## 2. A sync stages without touching disk

Back on `M20240925_1838_251210_2`. Note the Signbank ID glossen tier's contents first.

- [ ] Click `Signbank ID glossen` → the tier is replaced
- [ ] Green toast names the source and the item count
- [ ] Button takes the `staged` outline; status reads `● 1 tier gesynct — niet opgeslagen`
- [ ] Save Sync and Revert Sync become enabled
- [ ] Network shows exactly one `fetchSubtitles&filename=M20240925_1838`
- [ ] **No `saveSubtitlesAndEAFFiles` request**

Record the mtime, then drag a gloss on the **Nederlands** tier and wait 5 seconds:

```bash
ls -l --time-style=+%H:%M:%S /web/zin/eaf/zin/M20240925_1838_251210_2_*.srt
```

- [ ] Still no `saveSubtitlesAndEAFFiles` request (the debounce is 1s)
- [ ] mtimes unchanged

## 3. Revert keeps your hand edits

With the sync still staged and the Nederlands gloss still moved:

- [ ] Click `Revert Sync`
- [ ] Signbank tier returns to exactly its pre-sync contents
- [ ] **The Nederlands gloss stays where you dragged it**
- [ ] Toast `Sync verworpen`, status clears
- [ ] A `saveSubtitlesAndEAFFiles` request fires ~1s later, persisting the Nederlands edit
- [ ] Reload: Signbank original, Nederlands edit preserved

## 4. Save commits

- [ ] Sync a tier, click `Save Sync` → one save request, toast `Sync opgeslagen`, status clears
- [ ] mtime is now current and contents match the source mp4's tier
- [ ] Reload — the synced content persisted

## 5. Failed save stays staged

With DevTools set to **offline**:

- [ ] Sync a tier, click `Save Sync`
- [ ] Red toast `Opslaan van sync mislukt — sync blijft staan`
- [ ] Status still shows the staged tier; Save and Revert still enabled
- [ ] Go back online, click `Save Sync` again → succeeds

## 6. Refusal paths stage nothing

- [ ] A tier that is empty in the mp4 → red toast `... is leeg in de mp4-annotatie — niets gesynct`,
      nothing stages (Save/Revert stay disabled, no `staged` outline)

## 7. Navigation guard — the critical section

With a sync staged, for each path:

- [ ] **`Go back to Zinnen`** → modal appears. Test all three buttons:
  - `Op deze pagina blijven` → stays, sync still staged
  - `Sync verwerpen` → reverts, then navigates
  - `Sync opslaan` → saves, then navigates
- [ ] **Browser back button** → modal appears, page does not navigate
- [ ] **Tab close** → native "leave site?" prompt. Cancel it, then confirm in Network
      that **no `sendBeacon` to `saveSubtitlesAndEAFFiles` was sent**

Note: Chrome suppresses the native prompt unless you have interacted with the page
first — click something before testing tab close, or it will look like a failure
when it isn't.

Then the decisive disk check:

```bash
ls -l --time-style=+%H:%M:%S /web/zin/eaf/zin/M20240925_1838_251210_2_*.srt
```

- [ ] Sync a tier, close the tab, confirm the prompt → **no mtime changed**

This is the single most important check here. It is the failure the whole staged
model exists to prevent.

- [ ] With **nothing** staged, `Go back to Zinnen` navigates immediately, no modal

## 8. Auto-Segment mutual exclusion

- [ ] With a sync staged, `Auto-Segment`, `Auto-Segment 2D` and `Revert Autoseg`
      are all disabled, with a Dutch tooltip explaining why
- [ ] Save or Revert the sync → they return to their normal enabled state
- [ ] Start an Auto-Segment, and while it is running sync a tier; when Auto-Segment
      completes, the autoseg buttons must **stay disabled** because a sync is staged

## 9. Watch for this one

Sync a tier on a take whose **source mp4 is longer than the mocap animation**.

- [ ] Check whether any synced gloss lands past the end of the timeline track

If it does, it cannot be selected or dragged back but will still be saved into the
EAF. This is inherent to the "text and timings, full replace" semantics — the
original PHP copy behaves identically, so it is not a regression. Say the word if
you want the timings clamped to the animation duration.

## 10. Regression pass

- [ ] Autosave works normally when no sync is staged
- [ ] `Auto-Segment` / `Revert Autoseg` still work when no sync is staged
- [ ] `Download Subtitles` still works
- [ ] Adding a subtitle on tier 1 still creates its tier-2 twin and they move together
- [ ] After syncing and saving tier 2, a tier-1 master whose twin was replaced no
      longer drags its old twin — and throws nothing in the console
- [ ] Syncing one tier twice, then reverting, restores the **original** pre-first-sync state
- [ ] Syncing all three tiers, then reverting, restores all three
- [ ] `cd /web/zin && node --test test_sync_tiers.js` → 12/12
