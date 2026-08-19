# videoTop column + M20241216 inspection page — Design

**Date:** 2026-06-29
**Status:** Approved (pending spec review)

## Goal

Add a `videoTop` column to the `sentences` table holding the matched "top" video
for each sentence, and build a webpage to visually inspect each sentence next to
its matched video.

## Scope

Only the **M20241216** (16 Dec 2024) recording session is relevant. That session
has **526 recordings across 399 distinct sentences**. Only those 399 sentences are
populated and inspected. All other sentences keep `videoTop = NULL`.

## Data model (confirmed)

- Join key: `matched_transcriptions.m_transcription = sentences.ID`
  (`m_transcription` is the sentence ID; only numeric values are valid).
- Sentence recordings have `zOg` in (`'zin'`,`'Zin'`) — but for the Dec-16 scope we
  filter strictly on the filename prefix `m_file LIKE 'M20241216%'`, which is
  unambiguous and already all `zOg='zin'`.
- `m_file` is the `.wav` master name, e.g. `M20241216_0813.wav`. The playable video
  is the same name with `.mp4`, located on disk at
  `/web/gebarenoverleg_media/studioFilesMini/post/<name>.mp4` and served at
  `https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/<name>.mp4`.

## Decisions (from brainstorming)

1. **Which recording per sentence** → the **last M20241216 take**, i.e. highest
   `id` among that sentence's `M20241216%` rows. *(Includes deleted takes — no
   `added` filter, per "latest overall".)*
2. **What `videoTop` stores** → the **mp4 filename**, e.g. `M20241216_0813.mp4`.
3. **The 108 sentences that were re-recorded after Dec 16** → still get their
   **Dec 16** mp4 (uniform; M20241216 is the only relevant session).

## Components

### 1. Schema migration
```sql
ALTER TABLE sentences ADD COLUMN videoTop VARCHAR(255) NULL DEFAULT NULL;
```
Idempotent guard: check `SHOW COLUMNS FROM sentences LIKE 'videoTop'` before adding.

### 2. Backfill script — `populate_videoTop_dec16.php`
For each sentence with ≥1 `M20241216%` recording, set `videoTop` to the mp4 name of
its highest-`id` Dec-16 take.

```sql
UPDATE sentences s
JOIN (
  SELECT t.m_transcription AS sid,
         REPLACE(t.m_file, '.wav', '.mp4') AS mp4
  FROM matched_transcriptions t
  JOIN (
    SELECT m_transcription, MAX(id) AS mid
    FROM matched_transcriptions
    WHERE m_file LIKE 'M20241216%' AND m_transcription REGEXP '^[0-9]+$'
    GROUP BY m_transcription
  ) last ON last.mid = t.id
) v ON v.sid = s.ID
SET s.videoTop = v.mp4;
```
- Run as a CLI/one-off script that prints rows-affected (expected: 399).
- Re-runnable (the UPDATE simply re-sets the same values).
- `.wav→.mp4` via `REPLACE` (filenames are lowercase `.wav`; confirmed consistent).

### 3. Inspection page — `videoTop_inspect.php`
A standalone read-only page listing the 399 Dec-16 sentences. For each:
- Sentence `ID` and Dutch text (`zinString`).
- `videoTop` filename + the `added` status of the chosen take, with deleted takes
  (`added <> '1'`) flagged with a red badge.
- An embedded `<video controls preload="none">` pointing at the `post/` URL.
- A "file missing on disk" note when the mp4 isn't present under `post/`.

Layout: simple responsive grid/list, lazy `preload="none"` so 399 videos don't all
load at once. Pulls the chosen take + status with the same join as the backfill so
the page reflects exactly what's in `videoTop`.

## Edge cases
- **Missing mp4 on disk** → still listed; show a "missing" note instead of a broken
  player (checked via `file_exists` on the `post/` path).
- **Non-numeric `m_transcription`** → excluded by the `REGEXP '^[0-9]+$'` guard.
- **Sentence row absent for an ID** → the `JOIN sentences` naturally drops it.
- **Deleted (`added='0'`) last take** → intentionally kept; flagged in the UI.

## Verification
1. After migration: `SHOW COLUMNS FROM sentences LIKE 'videoTop'` returns the column.
2. After backfill: `SELECT COUNT(*) FROM sentences WHERE videoTop IS NOT NULL` = **399**.
3. Spot-check 3 rows against the brainstorming sample (e.g. sent#348 →
   `M20241216_0813.mp4`).
4. Load `videoTop_inspect.php`, confirm 399 entries render and a sample video plays.

## Out of scope
- No changes to other sessions or to the existing `getZinnen.php` flows.
- No edit/write actions from the inspection page — it is read-only.
