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
