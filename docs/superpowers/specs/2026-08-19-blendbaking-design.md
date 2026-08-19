# blendBaking — baked-animation SRT browser and gloss categorizer

Date: 2026-08-19
Status: approved (design)

## Problem

Two gaps, one shared join.

`getZinnen.php` has no way to list animation files. `getLatestMocapFile`
resolves one video at a time from a `?mFile=` parameter and ignores every MCP
status column; `listVideosWithoutMocap` returns the inverse set and no
filenames. Answering "which baked animations have their gloss annotation
finished" therefore takes three chained calls per sentence:
`fetchSentences&mcpStatusTijdAnnotatie=Klaar` (paged 25) → `fetchVideos&rowId=`
for each `m_file` → `getLatestMocapFile&mFile=` for each video.

Separately, there is no interface for pulling the gloss SRT files that belong
to baked animations, and no categorization of the gloss vocabulary those files
contain.

## Measured baseline

Taken 2026-08-19 against the live database and filesystem.

| Metric | Unit | Count |
| --- | --- | --- |
| Rows in `sentences` | sentences | 4,134 |
| `mcp_status_postprocessing = 1` | sentences | 529 |
| `mcp_status_tijd_annotatie = 'Klaar'` | sentences | 410 |
| `mcp_status_tijd_annotatie_gvg = 'Klaar'` | sentences | 6 |
| Active zin videos (`zOg='Zin' AND added=1`) | videos | 4,246 |
| ...with a baked GLB in `fbx/post_processed/` | videos | 568 |
| ...that also have a `_Signbank_ID_glossen.srt` | videos | 568 (555 distinct sentences) |
| ...whose sentence has `mcp_status_tijd_annotatie = 'Klaar'` | videos | 410 (406 distinct sentences) |
| ...whose sentence has `mcp_status_postprocessing = 1` | videos | 540 |
| GLB files in `fbx/post_processed/` | files | 780 (751 distinct bases) |
| FBX files at top level of `fbx/` | files | 25,886 (9,300 matching the take pattern) |
| Base glosses in the baked subset | glosses | 677 |
| Base glosses corpus-wide | glosses | 1,519 (from 1,970 distinct cue strings) |
| Wall time to parse all 568 gloss SRTs | seconds | 2.07 |

The unit column is load-bearing. A sentence can have several videos, so the
two 410s in this table are different quantities that coincide: 410 sentences
carry `mcp_status_tijd_annotatie = 'Klaar'`, and 410 baked videos belong to a
sentence carrying it — but those videos span only 406 sentences. The status
columns live on `sentences`; the rows this tool lists are `videos`. Anything
that reports a count must say which it means.

That every one of the 568 baked videos already has a gloss SRT means the
"baked AND has gloss tier" filter is, today, equivalent to "baked". The
conjunction is still implemented explicitly, because the two sets are produced
by independent pipelines and will diverge.

## Environment constraints

Both were found by measurement and both change how the code must be written.

**`find` does not work on `/web/gebarenoverleg_media/`.** It is an rclone
mount. `/usr/bin/find -maxdepth 1 -name '*.fbx'` returns zero results in a
directory where `/bin/ls` lists 86,760 entries and `stat` resolves individual
files. All directory enumeration under that mount must use `scandir`/`glob`.

**`getLatestMocapFile` returns unverified GLB URLs.** At `getZinnen.php:4051`
it derives `glbUrl` by string-substituting `.fbx` → `.glb` and prefixing
`post_processed/`, on the stated assumption that the caller only shows the
button for post-processed videos. Only 780 of the 25,886 top-level takes have a
post-processed GLB, so any other caller receives a URL to a file that does not
exist. The new endpoint verifies existence and reports `baked` explicitly. The
existing function is left alone; changing its contract is out of scope.

## Architecture

`blendBaking` is a thin client of `getZinnen.php`. The database/filesystem join
lives in exactly one place — the new `listMocapFiles` action — and
`blendBaking/api.php` implements only what is specific to it: ZIP bundling,
gloss extraction, and category lookup.

Rejected: making `blendBaking` self-contained (duplicates the join, which will
drift), and extracting a shared library (`/web/zin` has no root-level `src/`
convention to slot into; inventing one for two callers is unwarranted).

### Repository note

`/web/blendBaking` sits outside the `/web/zin` git repository, so it is not
version-controlled by this repo. Decided 2026-08-19: it becomes its own
repository via `git init`, with its own `.gitignore` excluding `cache/`.
`categories.json` is committed there; the `listMocapFiles` change is committed
to `/web/zin` separately.

## Component 1 — `listMocapFiles`

New action in `getZinnen.php`, registered in the `switch` alongside the other
cases.

### Request

`GET getZinnen.php?action=listMocapFiles`

| Parameter | Values | Default |
| --- | --- | --- |
| `mcpStatusTijdAnnotatie` | `Klaar`, `Check nodig`, `Niet Klaar` | unset |
| `mcpStatusTijdAnnotatieGvg` | as above | unset |
| `mcpStatusPostprocessing` | `1`, `2`, `__NULL__` (plus legacy string labels) | unset |
| `baked` | `1` = only rows with a post-processed GLB | unset |
| `hasGloss` | `1` = only rows whose gloss SRT exists on disk | unset |
| `search` | substring match on `zinArray` | unset |
| `page` | integer >= 1 | 1 |
| `limit` | 1..500 | 100 |

`baked` and `hasGloss` are deliberately separate predicates. They select the
same 568 videos today, but they are produced by independent pipelines and the
endpoint must not conflate them; blendBaking passes both.

`Niet Klaar` on either `mcp_status_tijd_annotatie` column must also match
`NULL` and `''`, matching the existing behaviour at `getZinnen.php:1215`. The
observed domain of `mcp_status_tijd_annotatie` on 2026-08-19 is exactly `NULL`
(3,720 sentences), `'Klaar'` (410), and `''` (4) — no `'Check nodig'` rows
exist yet, so that filter legitimately returns zero.
`mcp_status_postprocessing` is numeric (`1` = Klaar, `2` = Check nodig, `NULL`
= Niet Klaar) and accepts the legacy string labels, matching
`getZinnen.php:1232`.

### Response

```json
{
  "success": true,
  "total": 568,
  "page": 1,
  "limit": 100,
  "count": 100,
  "videos": [
    {
      "sentence_id": 1234,
      "video_id": 5678,
      "m_file": "M20240925_1824.wav",
      "base": "M20240925_1824",
      "zin": "De aap eet een banaan",
      "thema": "Dieren",
      "status_video": "Klaar",
      "status_annotatie": "Klaar",
      "status_glos": "Klaar",
      "status_gvg": "",
      "mcp_status_postprocessing": 1,
      "mcp_status_tijd_annotatie": "Klaar",
      "mcp_status_tijd_annotatie_gvg": null,
      "takeNumber": 2,
      "fbxFilename": "M20240925_1824_251217_2.fbx",
      "baked": true,
      "glbUrl": "/gebarenoverleg_media/fbx/post_processed/M20240925_1824_251217_2.glb",
      "hasGloss": true,
      "glossSrtUrl": "https://signcollect.nl/zin/eaf/zin/M20240925_1824_Signbank_ID_glossen.srt"
    }
  ]
}
```

`glbUrl` is `null` whenever `baked` is `false`.

### Take resolution

One `scandir` of `/web/gebarenoverleg_media/fbx/` and one of
`fbx/post_processed/`, reduced to a map of base filename → list of
`{take, fbxFilename, hasGlb}`, cached to `/web/zin/cache/mocap_index.json`.
Cache is used when younger than the TTL (600 s) and rebuilt otherwise; a
`refreshIndex=1` parameter forces a rebuild. Per-row `glob()` is specifically
avoided: at 568 rows against an rclone mount it is the dominant cost.

Take number is the trailing integer before `.fbx`, per the existing
`/_(\d+)\.fbx$/` pattern. Highest take wins. A base with no matching FBX yields
`takeNumber: null, fbxFilename: null, baked: false`.

## Component 2 — `/web/blendBaking/`

```
index.html                      two-tab UI (Bootstrap + jQuery, as in /web/zin)
api.php                         action = list | zip | glosses | categories | rebuildIndex
categories.json                 base gloss -> category slug (committed)
cache/gloss_index.json          parsed gloss occurrences
scripts/find_uncategorized.php  CLI: base glosses absent from categories.json
README.md
```

### Tab 1 — SRT bestanden

Rows are the baked videos that have a gloss tier, fetched from
`listMocapFiles` with `baked=1`. One row is one video, not one sentence: 568
videos span 555 sentences, so a sentence with two baked takes appears twice,
with a different base filename and a different gloss SRT in each row. Columns: checkbox, base filename, sentence,
thema, take, GLB link, gloss SRT download link, MCP tijd-annotatie status.
Controls: text search, MCP status filter, page size.

Downloads: a per-row link; checkbox multi-select feeding "Download
geselecteerde als ZIP"; and "Download alles (huidige filter)" which zips every
row matching the active filter, not just the current page.

`api.php?action=zip` accepts a list of base filenames, resolves each to
`eaf/zin/{base}_Signbank_ID_glossen.srt`, and streams a ZIP. Every resolved
path is confirmed to sit inside `/web/zin/eaf/zin/` via `realpath` prefix check
before being added; anything else is skipped. Bases are accepted only as
`[A-Za-z0-9_#+.-]+`.

### Tab 2 — Glossen

Unique glosses across the same 568 SRTs, grouped by base gloss. Columns: base
gloss, variants, total occurrences, number of source videos, category. Controls:
search, category filter, and CSV/JSON export.

Parsing: SRT cue text is every line that is not blank, not a bare integer, and
does not contain `-->`. Base gloss is the cue with a trailing single-letter
variant suffix removed, case-insensitively (`/-[A-Za-z]$/`). Results are cached
to `cache/gloss_index.json`, rebuilt on demand via `action=rebuildIndex` or
when older than the source SRTs.

Cue strings that must survive parsing and base-stripping unchanged, all
observed in live data: `PT-1hand:1`, `PO+PT`, `MOVE+C`, `MOVE+geld`,
`MOVE+Baby_snavel`, `#J`, `-`, `nvt`. `MOOI-a` must fold to the same base as
`MOOI-A`. Note that stripping is deliberately lossy for a base gloss that
legitimately ends in `-` plus one letter; no such gloss exists in the current
1,519, and the variants column preserves the original strings either way.

### Categories

`categories.json` maps all 1,519 corpus-wide base glosses — not only the 677
currently baked, so the file stays useful as more videos bake — to a category
slug. Structure:

```json
{
  "categories": [
    {"slug": "dier", "label": "Dieren"},
    {"slug": "pointing", "label": "Wijsgebaren"}
  ],
  "glosses": {"AAP": "dier", "PT-1hand": "pointing"}
}
```

Lexical slugs: `dier`, `eten_drinken`, `familie_personen`, `werkwoord_actie`,
`tijd`, `plaats`, `kleur`, `getal`, `emotie`, `lichaam`, `kleding`, `vervoer`,
`natuur`, `wonen`, `school_werk`, `communicatie`, `overig`. Non-lexical slugs:
`pointing` (`PT-*`, `PO`, `PO+PT`), `vingerspelling` (`#X`), `classifier`
(`MOVE+*`), `leeg` (`nvt`, `-`). Labels are Dutch; slugs are stable ASCII keys.

The file is generated once and committed. `scripts/find_uncategorized.php`
prints base glosses present in the SRTs but absent from `categories.json`, so
regeneration covers only new glosses. No API key is needed at runtime and no
categorization happens during a page load.

## Testing

A PHP CLI harness, run from the command line, covering:

- SRT parsing against the edge cases listed above, plus blank cues and CRLF
  line endings.
- Base-gloss stripping: `MOOI-a` and `MOOI-A` fold together; `#J`, `MOVE+C`,
  `PT-1hand:1` are unchanged.
- ZIP path safety: a base of `../../etc/passwd` or `..%2f` is rejected; a base
  containing characters outside `[A-Za-z0-9_#+.-]` is rejected.
- `listMocapFiles` filter arithmetic reproduces the measured baseline, in
  videos: `baked=1` returns `total: 568`, and
  `baked=1&mcpStatusTijdAnnotatie=Klaar` returns `total: 410`. Both are video
  counts; the corresponding distinct-sentence counts are 555 and 406.
- `Niet Klaar` on `mcp_status_tijd_annotatie` matches `NULL` and `''` rows.
- Take resolution picks the highest take, and reports `baked: false` with
  `glbUrl: null` for a base whose FBX exists but whose GLB does not.

## Out of scope

- Changing or fixing `getLatestMocapFile`; its unverified `glbUrl` is
  documented above but its contract is left as is.
- Editing categories through the UI. `categories.json` is corrected by hand or
  by regeneration.
- Any Blender-side baking. This tool reads the output of that pipeline.
- The `Nederlands`, `Gebaar-voor-gebaar`, and `Handvorm` SRT tiers.
