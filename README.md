# zin — the Zinnen sentence-annotation interface

The interface annotators use to turn a studio video of a signed sentence into
time-aligned ELAN annotation, and the largest repository in the SignCollect
estate.

## What it does

`zinnen.html` is the app: a filterable table of every sentence in the corpus
with its videos, EAF and SRT status, mocap takes and Signbank glosses. From a
row you open one of the annotation editors, upload or download an `.eaf`, check
a video, or push the result back to the database. Everything behind it is one
big PHP endpoint, `getZinnen.php`, dispatching on an `action` parameter —
`fetchSentences`, `fetchRow`, `saveSubtitlesAndEAFFiles`, `editZin`,
`uploadEAF`/`downloadEAF`, `deleteVideo`/`deleteZin`/`deleteEAF`,
`findGloss`/`findgvg`, `listMocapFiles`.

Around it are the supporting pages: `dashboard.html` (gloss/lemma coverage
matrix), `addZinnen.html` (batch sentence entry), `webapp.html` (ZINinNGT, a
lighter viewer), `zinnenVideoStatus.html`, `no_video_worklist.html`,
`undelete_videos.html`, `action_stats.html` and `lemmaProcessingMonitor.html`.

Annotation output is three tiers per video — Nederlands, Signbank ID glossen,
Gebaar-voor-gebaar — written to `eaf/zin/` as one `.eaf` plus one `.srt` per
tier (`{video}_Nederlands.srt` and so on), served at `/zin/eaf/zin/`.

**The editors themselves are not here.** `subBeta8` and `3DAnn3` moved to
`signlab_annotation-editors`; `zinnen.html` navigates to
`/annotation-editors/subBeta8/zin/subBeta8.html` and
`/annotation-editors/3DAnn3/zin/3DAnn3.html`. Everything in this repo that
still names `subBeta3`, `subBeta5` or `3DAnn.html` — including parts of
`CLAUDE.md`, `claude_3dann.md` and `README_WEBSOCKET.md` — is describing files
that no longer live here.

## Where it runs

The **signcollect core server** (production VPS), served at
`https://signcollect.nl/zin/` from `<root>/zin`. The demo hosts deploy the same
tree: dev2 under `/web`, dev-1 under `/srv/signcollect/web`.

`<root>` is the install root. Nothing in this repo spells it: `sc_paths.php` (a
vendored copy of `signcollect-lib`'s resolver) answers `sc_path()`, falling
back to `/web` on a host with no library.

The public read API is served from inside this tree — Apache aliases `/api` to
`<root>/zin/api`, and `api/` is `signlab_sCAPI` (see *Known gaps*).

## Status

**Production.** This is the annotators' daily tool.

## How to deploy it

No build step for the web app: plain PHP with mysqli, plus static
HTML/JS/Bootstrap/jQuery. `package.json` and `requirements.txt` belong to the
optional side services, not to the app.

Deployment is by `interface_deploy/scripts/repos.tsv` in
`signlab_signcollect-stack`, which maps `zin` → this repo on branch `main`.
`scripts/install.sh` runs `host-bootstrap.sh`, which clones (or fetches and
hard-resets) the repo into `<root>/zin`. On a demo host `rewrite-urls.sh` then
repoints hardcoded `signcollect.nl` URLs at the demo's hostname.

## Configuration

| file | what it is |
|---|---|
| `<root>/mysql_config.php` | database credentials, as `$servername`/`$username`/`$password`/`$database`. Every PHP file here reaches it as `include '../mysql_config.php'` — one level *above* this docroot, shared with the sibling apps. Gitignored, and there is no template in this repo. |
| `iss_client/config.php` | the ISS segmentation client's settings. Required by `iss_client/`, gitignored, absent from the tree — create it per host; `iss_client/README.md` describes the shape. |
| `.env` | Discord bot token / webhook for the annotation-status monitor, and `WS_PORT` for the timecode server. `.env.example` is the template. |
| `eaf/`, `record3D/`, `backups/` | host-local content, gitignored. A fresh clone has no `eaf/zin/`, and the EAF/SRT features find nothing until it exists and is writable by the web user. |
| `cache/mocap_index.json` | generated, not config — `mocapFiles.php` rebuilds it on a 600 s TTL. |

## Dependencies

| what | why |
|---|---|
| MySQL `admin_gebarenoverleg` | `sentences`, `sentences_logs`, `matched_transcriptions`, `uploads`, `lemmaTable`, `labels`, `mocap_files`, `form_data`, `CameraRecords` |
| `signlab_annotation-editors` at `<root>/annotation-editors` | the two editors `zinnen.html` launches. Without it every edit button 404s. |
| `signlab_sCAPI` | the public read API, mounted here as the `api/` submodule and aliased to `/api` |
| `signlab_signcollect-lib` at `<root>/lib` | optional — `sc_paths.php` degrades to the compiled `/web` default without it |
| `<root>/gebarenoverleg_media/studioFilesMini/{raw,post}` | the video. An **rclone mount**: `find` silently returns nothing there, so directory enumeration must use `scandir`/`glob`. |
| `<root>/signbank_data/glosses_transformed.json` | the Signbank gloss dump, produced by the connector in `signlab_signCollect-v2`. `getSenses.php` and `dropdownFono.html` fall back to the pre-connector path at the docroot root. |
| handshape service on `ws://localhost:9000` | `HandshapeClient.php` / `getHandshapes.php` |
| sign-segmentation service on `ws://localhost:8765` | `SignSegmentationClient.php` / `processSegmentation.php` |
| ISS_Server on `wss://signcollect.nl/ISS_Server/ws` | `iss_client/` |
| `/userProtect.js` | the deploy's shared auth guard, vendored in `interface_deploy/web_extra/` |
| `https://leffe.science.uva.nl:8043` | a UvA host `getSenses.php` and `webapp.html` still call directly |

The three WebSocket services, ISS_Server and Signbank are all out of scope for
the demo deploy, so on dev2 and dev-1 the features that use them fail quietly.

Optional side services shipped alongside the app, neither required to serve it:
`websocket-server.js` (Node; relays video timecode to Python receivers, port
8766 via the `/zin_wss` proxy — see `README_WEBSOCKET.md`) and the Discord
annotation monitor described in `MONITOR_README.md`.

## Known gaps

- **`api/` is a submodule with no `.gitmodules`.** The tree records a gitlink at
  `api` pointing at `signlab_sCAPI` commit `9c33848`, but nothing tells git
  where to fetch it from, so `git clone` — and therefore `host-bootstrap.sh` —
  leaves `<root>/zin/api` empty and `/api` returns nothing. *TODO: either add
  a `.gitmodules` entry and initialise the submodule in the deploy, or give
  `signlab_sCAPI` its own `repos.tsv` row.*
- **There is a lot of cruft in the docroot.** Dozens of one-off `test_*.php`
  and `check_*.php` scripts, dated report snapshots (`.txt`, `.csv`, `.json`),
  four variant chunked-video loader prototypes, and `zinnen.html_back`. None of
  it is part of the running app, and it is all publicly served. *TODO: decide
  what to delete; `phpinfo.php` in particular should not be on a production
  docroot.* The large binaries this list used to name are gone — 26 MB of GLB,
  USDZ, a stray log and a two-file video test fixture, none of them referenced
  by anything.
- Only two Python files survive the docroot cleanup (`copyAB.py`,
  `iss_client/iss_worker.py`). `DOWNLOAD_README.md` and `MONITOR_README.md`
  still describe `.py` scripts that were removed.

## Further documentation

`CLAUDE.md` (endpoint actions and two operational gotchas worth knowing),
`lemmaTable.MD` and `lemmaMCP.MD` (the lemma normalisation table and its API),
`IMPLEMENTATION_NOTES_MCP_FILTERS.md` and
`PERFORMANCE_OPTIMIZATION_SUMMARY.md` (the MCP status columns and the index
that made filtering on them fast), `README_WEBSOCKET.md` (the timecode relay),
`docs/` (specs and plans).
