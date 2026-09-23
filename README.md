# signlab_zinnen-annotation
The Zinnen interface: annotators turn studio videos of signed sentences (zinnen) into time-aligned ELAN annotation.

## What it does
- `zinnen.html` is a filterable table of every sentence with its videos, EAF/SRT status, mocap takes and Signbank glosses.
- `getZinnen.php` is the one large endpoint behind it. It dispatches on `action`. No `action` means `fetchSentences`; others include `fetchRow`, `saveSubtitlesAndEAFFiles`, `editZin`, `uploadEAF`/`downloadEAF`, `delete*`, `findGloss`/`findgvg`, `listMocapFiles`.
- Per video it writes one `.eaf` and one `.srt` per tier (Nederlands, Signbank ID glossen, Gebaar-voor-gebaar) to `eaf/zin/`, served at `/zin/eaf/zin/`.
- Side pages: `dashboard.html`, `addZinnen.html`, `webapp.html`, `zinnenVideoStatus.html`, `no_video_worklist.html`, `undelete_videos.html`, `action_stats.html`, `lemmaProcessingMonitor.html`.
- Lemmas: `sentences.lemmaList` holds a JSON array of `lemmaTable` ids (`lemmaTable.sql`). `getLemmas.php?page=N` returns 10 sentences with lemma strings; `updateLemmas.php` takes a POST of `{"sentenceId", "lemmas"}`. Neither checks a login.
- `zinCrop/` lists sentence videos with their crop-fix status. It reads the queue of [signlab_crop-fix-manager](https://github.com/Amsterdam-Humanities-Labs/signlab_crop-fix-manager) (`<root>/videofix_data/crop_fixes.json`) and adds fixes through `/videoFix/api.php`.
- The editors (subBeta8, 3DAnn3) live in [signlab_annotation-editors](https://github.com/Amsterdam-Humanities-Labs/signlab_annotation-editors). `zinnen.html` links to `/annotation-editors/…`.

## Where it runs
- Production: core server, `<root>/zin`, <https://signcollect.nl/zin/>. Demo hosts: dev2 `/web/zin`, dev-1 `/srv/signcollect/web/zin`.
- `<root>` comes from the vendored signcollect-lib resolvers `sc_paths.php` and `sc_paths.py` They fall back to `/web`.
- [signlab_pythonCron](https://github.com/Amsterdam-Humanities-Labs/signlab_pythonCron) runs `copy_ab_to_post.py` (service `copy_ab_files`). It copies the MP4s in the studio `AB/` folders to `studioFilesMini/post/`. `copyAB.py` is the old name and imports it.
- Cron (user `gomer`, not in any repo): `30 3 * * * php /web/zin/resync_video_count.php`.

## Status
Production. The annotators use it every day.

## How to run / deploy
There is no build step: plain PHP/mysqli and static HTML/JS. The stack deploys `main` into `<root>/zin` (`repos.tsv`); see
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
`archive/` holds retired files (old corpora, the ISS client, the WebSocket relay). Apache denies it; see `archive/README.md`.

## Configuration
| File | What |
|---|---|
| `<root>/mysql_config.php` | DB credentials (`$servername`, `$username`, `$password`, `$database`), included as `../mysql_config.php` (`../../mysql_config.php` from `zinCrop/`). Gitignored, no template |
| `.env` | Credentials that [signlab_pythonCron](https://github.com/Amsterdam-Humanities-Labs/signlab_pythonCron) scripts read from `<root>/zin/.env`. Template: `.env.example` |
| `SC_LEGACY_WEB_ROOT`, `SC_LEGACY_BASE_URL` | env or `/web/.env`. `getMT.php` rewrites SRT paths under the first (default `/var/www/html`) to URLs under the second (default `https://leffe.science.uva.nl:8043`) |
| `eaf/`, `record3D/`, `backups/` | host-local content, gitignored. `eaf/zin/` must exist and be writable by the web user |
| `cache/mocap_index.json` | written by `mocapFiles.php`, kept for 600 s. `cache/` must be writable by the web user |

The mocap filter needs the index `idx_mocap_filter` on `matched_transcriptions` (`migrations/add_mocap_index.sql`).

## Dependencies
- MySQL `admin_gebarenoverleg` (`sentences`, `matched_transcriptions`, `lemmaTable`, `mocap_files` and more).
- signlab_annotation-editors at `<root>/annotation-editors`. Without it the edit buttons return 404. `/userProtect.js` comes from the stack's `web_extra/`.
- [signlab_signCollect-API-TYD](https://github.com/Amsterdam-Humanities-Labs/signlab_signCollect-API-TYD), which the stack deploys into `api/` (gitignored here). Apache aliases it to `/api`.
- Video: `<root>/gebarenoverleg_media/studioFilesMini/{raw,post}`. This is an rclone mount where `find` returns nothing, so use `scandir` or `glob`.
- Signbank dump `<root>/signbank_data/glosses_transformed.json`, from [signlab_signCollect-v2](https://github.com/Amsterdam-Humanities-Labs/signlab_signCollect-v2). `getSenses.php` and `webapp.html` call `leffe.science.uva.nl:8043`.
- WebSocket services: handshape `ws://localhost:9000`, sign segmentation `ws://localhost:8765`.
