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
