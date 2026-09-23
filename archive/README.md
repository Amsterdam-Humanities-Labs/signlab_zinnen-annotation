# archive

Files kept for their history only. Nothing here runs, and Apache denies web access (`.htaccess`).
In September 2026 no file in the 34 signlab repos, no signlab_pythonCron config and no cron job referred to any of them
([signlab_signcollect-stack#19](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/issues/19)).

| Path | What it was |
|---|---|
| `corpora/` | Source sentence lists (`zin.csv`, `Extra_2000zinnen.csv`, `extrazinnen_v1.csv`, `2k.csv`, `2k_enhanced.csv`, `lorraine_sentences.csv`). The sentences are in the `sentences` table now |
| `iss_client/` | PHP/Python client and worker for the ISS sign segmentation server. No page called it; `test.html` fetched two `/web/` URLs that always returned 404 |
| `ISS_examples/` | Four demo pages for the ISS server (WebSocket, SSE, combined) |
| `websocket/` | Node timecode relay (`websocket-server.js`, port 8766) for the retired subBeta5 editor. No client, no systemd unit |
| `pages/addZin.html` | "Batch Add Sentences" page. `addZinnen.html` replaced it; only a stale comment in `zinnen.html` names it |
| `pages/sortable.html` | "Glos/Zinnen Opnemen" recording page. Unlinked, and it called `leffe.science.uva.nl:8043` |

To bring a file back, `git mv` it out of `archive/`.
