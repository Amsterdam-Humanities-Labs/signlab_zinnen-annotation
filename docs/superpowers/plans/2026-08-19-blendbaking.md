# blendBaking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `listMocapFiles` action to `getZinnen.php` that lists baked animations with their gloss SRTs and MCP statuses, and build `/web/blendBaking` — a two-tab tool for downloading those SRTs and browsing their categorized gloss vocabulary.

**Architecture:** All database/filesystem join logic lives in one new include, `/web/zin/mocapFiles.php`, which has no top-level side effects and is therefore directly unit-testable. `getZinnen.php` gains a thin `listMocapFiles($conn)` wrapper that parses request parameters, calls into that include, echoes JSON, and exits. `/web/blendBaking` is a separate git repository that consumes `listMocapFiles` over HTTP and adds only what is specific to it: ZIP bundling, gloss extraction, and category lookup.

**Tech Stack:** PHP 8.3.6 (mysqli, ZipArchive — both confirmed present), Bootstrap + jQuery for the UI, plain-PHP class-based tests in the style of `/web/zin/api/tests/`.

**Spec:** `/web/zin/docs/superpowers/specs/2026-08-19-blendbaking-design.md`

## Global Constraints

- **Never use `find` under `/web/gebarenoverleg_media/`.** It is an rclone mount where `find` silently returns zero results in directories containing tens of thousands of files. Use `scandir`/`glob` only. This applies to implementation code, tests, and any shell command run while working on this plan.
- **Counts must state their unit.** Status columns live on `sentences`; the rows this tool lists are `videos`. 568 baked videos span 555 sentences; 410 baked videos with `mcp_status_tijd_annotatie = 'Klaar'` span 406 sentences. Never report a bare number.
- **Directory index is built by `scandir`, never per-row `glob()`.** Measured: `scandir` of `fbx/` is 86,768 entries in 0.93 s; `post_processed/` is 1,567 entries in 0.02 s. Per-row `glob()` across 568 rows on this mount is the dominant cost and is prohibited.
- **Take filename grammar:** `{base}_{yymmdd}_{take}.{fbx|glb}`, parsed with the non-greedy regex `/^(.+?)_(\d+)_(\d+)\.(fbx|glb)$/`. Verified against live filenames: `M20240925_1824_251217_2.fbx` → base `M20240925_1824`, take `2`; `#A_241120_0.fbx` → base `#A`, take `0`; `#EUD.fbx` and `#A_241120_0_GlassesGuyRecord_C_1.fbx` correctly do not match.
- **Paths:** FBX takes `/web/gebarenoverleg_media/fbx/`; baked GLBs `/web/gebarenoverleg_media/fbx/post_processed/`; SRTs `/web/zin/eaf/zin/`; public SRT base URL `https://signcollect.nl/zin/eaf/zin/`; public GLB base URL `/gebarenoverleg_media/fbx/post_processed/`.
- **DB credentials** come from `include '/web/mysql_config.php'` which defines `$servername`, `$username`, `$password`, `$database`. Never hardcode them; never commit them.
- **`mcp_status_postprocessing` is numeric** (`1` = Klaar, `2` = Check nodig, `NULL` = Niet Klaar). The `mcp_status_tijd_annotatie` and `mcp_status_tijd_annotatie_gvg` columns are strings, where `'Niet Klaar'` must also match `NULL` and `''`.
- **Do not modify `getLatestMocapFile`.** Its unverified `glbUrl` is documented in the spec and explicitly out of scope.
- **UI copy is Dutch.** Category slugs are stable ASCII keys; category labels are Dutch.

---

### Task 1: Mocap directory index and take resolution

**Files:**
- Create: `/web/zin/mocapFiles.php`
- Create: `/web/zin/tests/TestMocapFiles.php`
- Create: `/web/zin/tests/TestRunner.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `mocap_parse_take(string $filename): ?array` → `['base' => string, 'date' => string, 'take' => int, 'ext' => string]` or `null`
  - `mocap_build_index(string $fbxDir, string $glbDir): array` → `['built_at' => int, 'bases' => array<string, array<int, array{take:int, fbx:string, glb:?string}>>]`
  - `mocap_get_index(string $cacheFile, string $fbxDir, string $glbDir, int $ttl = 600, bool $force = false): array`
  - `mocap_latest_take(array $index, string $base): ?array` → `['takeNumber' => int, 'fbxFilename' => string, 'baked' => bool, 'glbFilename' => ?string]`

- [ ] **Step 1: Write the failing test**

Create `/web/zin/tests/TestMocapFiles.php`:

```php
<?php
/**
 * Unit tests for mocapFiles.php — take parsing and directory indexing.
 */
require_once __DIR__ . '/../mocapFiles.php';

class TestMocapFiles {
    private $passed = 0;
    private $failed = 0;
    private $messages = [];

    private function assertTrue($condition, $message = "Assertion failed") {
        if ($condition) { $this->passed++; return true; }
        $this->failed++; $this->messages[] = "FAIL: $message"; return false;
    }

    private function assertEquals($expected, $actual, $message = "Values are not equal") {
        return $this->assertTrue(
            $expected === $actual,
            $message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")"
        );
    }

    public function testParseTakeAcceptsZinFilenames() {
        $r = mocap_parse_take('M20240925_1824_251217_2.fbx');
        $this->assertEquals('M20240925_1824', $r['base'], "base of a zin take");
        $this->assertEquals(2, $r['take'], "take number of a zin take");
        $this->assertEquals('fbx', $r['ext'], "extension of a zin take");
    }

    public function testParseTakeAcceptsGlbAndZeroTake() {
        $r = mocap_parse_take('M20260224_5439_260318_0.glb');
        $this->assertEquals('M20260224_5439', $r['base'], "base of a glb take");
        $this->assertEquals(0, $r['take'], "take 0 parses as int 0, not null");
        $this->assertEquals('glb', $r['ext'], "extension of a glb take");
    }

    public function testParseTakeAcceptsHashBase() {
        $r = mocap_parse_take('#A_241120_0.fbx');
        $this->assertEquals('#A', $r['base'], "base may contain a leading hash");
    }

    public function testParseTakeRejectsNonTakeFilenames() {
        $this->assertEquals(null, mocap_parse_take('#EUD.fbx'), "no date/take segments");
        $this->assertEquals(null, mocap_parse_take('#A_241120_0_GlassesGuyRecord_C_1.fbx'), "trailing non-numeric segments");
        $this->assertEquals(null, mocap_parse_take('M20240925_1824_251217_2.npz'), "wrong extension");
    }

    public function testBuildIndexPairsFbxWithGlb() {
        $tmp = sys_get_temp_dir() . '/mocaptest_' . getmypid();
        @mkdir($tmp . '/fbx', 0777, true);
        @mkdir($tmp . '/glb', 0777, true);
        touch($tmp . '/fbx/M1_250101_0.fbx');
        touch($tmp . '/fbx/M1_250101_3.fbx');
        touch($tmp . '/fbx/M2_250101_0.fbx');
        touch($tmp . '/fbx/notatake.fbx');
        touch($tmp . '/glb/M1_250101_3.glb');

        $index = mocap_build_index($tmp . '/fbx', $tmp . '/glb');

        $this->assertTrue(isset($index['bases']['M1']), "M1 is indexed");
        $this->assertEquals(2, count($index['bases']['M1']), "M1 has two takes");
        $this->assertTrue(!isset($index['bases']['notatake']), "non-take files are skipped");

        $latest = mocap_latest_take($index, 'M1');
        $this->assertEquals(3, $latest['takeNumber'], "highest take wins");
        $this->assertEquals('M1_250101_3.fbx', $latest['fbxFilename'], "latest fbx filename");
        $this->assertEquals(true, $latest['baked'], "latest take has a glb");
        $this->assertEquals('M1_250101_3.glb', $latest['glbFilename'], "latest glb filename");

        $unbaked = mocap_latest_take($index, 'M2');
        $this->assertEquals(false, $unbaked['baked'], "M2 has no glb");
        $this->assertEquals(null, $unbaked['glbFilename'], "unbaked take reports null glb");

        $this->assertEquals(null, mocap_latest_take($index, 'NOPE'), "unknown base returns null");

        array_map('unlink', glob($tmp . '/fbx/*'));
        array_map('unlink', glob($tmp . '/glb/*'));
        rmdir($tmp . '/fbx'); rmdir($tmp . '/glb'); rmdir($tmp);
    }

    public function testGetIndexUsesAndRefreshesCache() {
        $tmp = sys_get_temp_dir() . '/mocapcache_' . getmypid();
        @mkdir($tmp . '/fbx', 0777, true);
        @mkdir($tmp . '/glb', 0777, true);
        touch($tmp . '/fbx/M1_250101_0.fbx');
        $cache = $tmp . '/index.json';

        $first = mocap_get_index($cache, $tmp . '/fbx', $tmp . '/glb', 600, false);
        $this->assertTrue(file_exists($cache), "cache file is written");
        $this->assertTrue(isset($first['bases']['M1']), "first build sees M1");

        touch($tmp . '/fbx/M2_250101_0.fbx');
        $cached = mocap_get_index($cache, $tmp . '/fbx', $tmp . '/glb', 600, false);
        $this->assertTrue(!isset($cached['bases']['M2']), "fresh cache is reused, M2 not seen");

        $forced = mocap_get_index($cache, $tmp . '/fbx', $tmp . '/glb', 600, true);
        $this->assertTrue(isset($forced['bases']['M2']), "force rebuild sees M2");

        unlink($cache);
        array_map('unlink', glob($tmp . '/fbx/*'));
        rmdir($tmp . '/fbx'); rmdir($tmp . '/glb'); rmdir($tmp);
    }

    public function runTests() {
        foreach (get_class_methods($this) as $m) {
            if (strpos($m, 'test') === 0) { $this->$m(); }
        }
        return ['passed' => $this->passed, 'failed' => $this->failed, 'messages' => $this->messages];
    }
}
```

Create `/web/zin/tests/TestRunner.php`:

```php
<?php
/**
 * Test runner for /web/zin root-level libraries.
 * Usage: php tests/TestRunner.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/TestMocapFiles.php';

$classes = ['TestMocapFiles'];
$totalPassed = 0; $totalFailed = 0;

foreach ($classes as $class) {
    $suite = new $class();
    $result = $suite->runTests();
    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    printf("%-24s %3d passed, %3d failed\n", $class, $result['passed'], $result['failed']);
    foreach ($result['messages'] as $msg) { echo "  $msg\n"; }
}

printf("\nTOTAL: %d passed, %d failed\n", $totalPassed, $totalFailed);
exit($totalFailed > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/TestRunner.php`
Expected: FAIL — `require_once` of `mocapFiles.php` errors with "Failed opening required '/web/zin/mocapFiles.php'".

- [ ] **Step 3: Write minimal implementation**

Create `/web/zin/mocapFiles.php`:

```php
<?php
/**
 * Mocap file index and lookup.
 *
 * Pure library: including this file has no side effects, performs no output,
 * and opens no database connection. getZinnen.php includes it to serve the
 * listMocapFiles action; tests include it directly.
 *
 * /web/gebarenoverleg_media is an rclone mount on which find(1) silently
 * returns nothing. Enumeration here uses scandir only.
 */

if (!defined('MOCAP_FBX_DIR'))   define('MOCAP_FBX_DIR', '/web/gebarenoverleg_media/fbx/');
if (!defined('MOCAP_GLB_DIR'))   define('MOCAP_GLB_DIR', '/web/gebarenoverleg_media/fbx/post_processed/');
if (!defined('MOCAP_GLB_URL'))   define('MOCAP_GLB_URL', '/gebarenoverleg_media/fbx/post_processed/');
if (!defined('MOCAP_EAF_DIR'))   define('MOCAP_EAF_DIR', '/web/zin/eaf/zin/');
if (!defined('MOCAP_SRT_URL'))   define('MOCAP_SRT_URL', 'https://signcollect.nl/zin/eaf/zin/');
if (!defined('MOCAP_CACHE'))     define('MOCAP_CACHE', '/web/zin/cache/mocap_index.json');
if (!defined('MOCAP_CACHE_TTL')) define('MOCAP_CACHE_TTL', 600);

/**
 * Parse a take filename of the form {base}_{yymmdd}_{take}.{fbx|glb}.
 *
 * The base capture is non-greedy so that M20240925_1824_251217_2.fbx yields
 * base M20240925_1824 (not M20240925) while #A_241120_0.fbx still yields #A.
 *
 * @return array|null ['base','date','take','ext'] or null if not a take file
 */
function mocap_parse_take($filename) {
    if (!preg_match('/^(.+?)_(\d+)_(\d+)\.(fbx|glb)$/', $filename, $m)) {
        return null;
    }
    return ['base' => $m[1], 'date' => $m[2], 'take' => (int)$m[3], 'ext' => $m[4]];
}

/**
 * Scan the take and baked directories once and build a base -> takes index.
 *
 * @return array ['built_at' => int, 'bases' => [base => [ ['take','fbx','glb'], ... ]]]
 */
function mocap_build_index($fbxDir, $glbDir) {
    $glb = [];
    $glbEntries = @scandir($glbDir);
    if ($glbEntries !== false) {
        foreach ($glbEntries as $f) {
            $p = mocap_parse_take($f);
            if ($p !== null && $p['ext'] === 'glb') {
                $glb[$p['base'] . '_' . $p['date'] . '_' . $p['take']] = $f;
            }
        }
    }

    $bases = [];
    $fbxEntries = @scandir($fbxDir);
    if ($fbxEntries !== false) {
        foreach ($fbxEntries as $f) {
            $p = mocap_parse_take($f);
            if ($p === null || $p['ext'] !== 'fbx') { continue; }
            $key = $p['base'] . '_' . $p['date'] . '_' . $p['take'];
            $bases[$p['base']][] = [
                'take' => $p['take'],
                'fbx'  => $f,
                'glb'  => isset($glb[$key]) ? $glb[$key] : null,
            ];
        }
    }

    return ['built_at' => time(), 'bases' => $bases];
}

/**
 * Return the index, using a JSON cache younger than $ttl seconds when possible.
 */
function mocap_get_index($cacheFile, $fbxDir, $glbDir, $ttl = MOCAP_CACHE_TTL, $force = false) {
    if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $raw = @file_get_contents($cacheFile);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['bases'])) {
            return $decoded;
        }
    }

    $index = mocap_build_index($fbxDir, $glbDir);

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents($cacheFile, json_encode($index), LOCK_EX);

    return $index;
}

/**
 * Highest-numbered take for a base, with whether that take has been baked.
 *
 * @return array|null ['takeNumber','fbxFilename','baked','glbFilename'] or null
 */
function mocap_latest_take($index, $base) {
    if (!isset($index['bases'][$base]) || !count($index['bases'][$base])) {
        return null;
    }

    $best = null;
    foreach ($index['bases'][$base] as $take) {
        if ($best === null || $take['take'] > $best['take']) { $best = $take; }
    }

    return [
        'takeNumber'  => $best['take'],
        'fbxFilename' => $best['fbx'],
        'baked'       => $best['glb'] !== null,
        'glbFilename' => $best['glb'],
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/zin && php tests/TestRunner.php`
Expected: `TestMocapFiles` reports 0 failed, exit code 0.

- [ ] **Step 5: Sanity-check the index against live data**

Run:
```bash
cd /web/zin && php -r '
require "mocapFiles.php";
$t = microtime(true);
$i = mocap_build_index(MOCAP_FBX_DIR, MOCAP_GLB_DIR);
printf("bases: %d, built in %.2fs\n", count($i["bases"]), microtime(true) - $t);
var_export(mocap_latest_take($i, "M20240925_1824"));
'
```
Expected: several thousand bases, build under ~2 s, and a populated array for `M20240925_1824`. If `bases` is 0, `find` semantics have leaked into the implementation — re-read the Global Constraints.

- [ ] **Step 6: Commit**

```bash
cd /web/zin
git add mocapFiles.php tests/TestMocapFiles.php tests/TestRunner.php
git commit -m "feat: add mocap take index library with tests"
```

---

### Task 2: `listMocapFiles` action

**Files:**
- Modify: `/web/zin/mocapFiles.php` (append `mocap_file_list`)
- Modify: `/web/zin/getZinnen.php` (add `case 'listMocapFiles'` near line 131 beside `listVideosWithoutMocap`, and the `listMocapFiles($conn)` function beside `getLatestMocapFile` near line 4008)
- Modify: `/web/zin/tests/TestMocapFiles.php`

**Interfaces:**
- Consumes: `mocap_get_index()`, `mocap_latest_take()` from Task 1.
- Produces:
  - `mocap_file_list(mysqli $conn, array $index, array $opts): array` → `['total'=>int,'page'=>int,'limit'=>int,'count'=>int,'videos'=>array]`. `$opts` keys: `mcpStatusTijdAnnotatie`, `mcpStatusTijdAnnotatieGvg`, `mcpStatusPostprocessing`, `baked`, `hasGloss`, `search`, `page`, `limit`.
  - `listMocapFiles(mysqli $conn): void` — echoes JSON and exits.

- [ ] **Step 1: Write the failing test**

Append these methods to `class TestMocapFiles` in `/web/zin/tests/TestMocapFiles.php`, before `runTests()`:

```php
    private function liveConn() {
        include '/web/mysql_config.php';
        $c = new mysqli($servername, $username, $password, $database);
        if ($c->connect_error) { return null; }
        $c->set_charset('utf8');
        return $c;
    }

    public function testFileListReproducesMeasuredBaseline() {
        $conn = $this->liveConn();
        if ($conn === null) { $this->assertTrue(false, "could not connect to database"); return; }
        $index = mocap_get_index(MOCAP_CACHE, MOCAP_FBX_DIR, MOCAP_GLB_DIR, 600, true);

        // Baseline measured 2026-08-19. Units are VIDEOS, not sentences.
        $baked = mocap_file_list($conn, $index, ['baked' => '1', 'limit' => 1]);
        $this->assertEquals(568, $baked['total'], "baked=1 total, in videos");

        $klaar = mocap_file_list($conn, $index, [
            'baked' => '1', 'mcpStatusTijdAnnotatie' => 'Klaar', 'limit' => 1,
        ]);
        $this->assertEquals(410, $klaar['total'], "baked=1 + tijd annotatie Klaar, in videos");

        $conn->close();
    }

    public function testFileListRowShape() {
        $conn = $this->liveConn();
        if ($conn === null) { $this->assertTrue(false, "could not connect to database"); return; }
        $index = mocap_get_index(MOCAP_CACHE, MOCAP_FBX_DIR, MOCAP_GLB_DIR, 600, false);

        $res = mocap_file_list($conn, $index, ['baked' => '1', 'limit' => 5]);
        $this->assertEquals(5, $res['count'], "limit is honoured");

        $row = $res['videos'][0];
        foreach (['sentence_id','video_id','m_file','base','zin','thema','takeNumber',
                  'fbxFilename','baked','glbUrl','hasGloss','glossSrtUrl',
                  'mcp_status_postprocessing','mcp_status_tijd_annotatie',
                  'mcp_status_tijd_annotatie_gvg'] as $k) {
            $this->assertTrue(array_key_exists($k, $row), "row has key $k");
        }
        $this->assertEquals(true, $row['baked'], "baked=1 rows report baked true");
        $this->assertTrue(is_string($row['glbUrl']) && $row['glbUrl'] !== '', "baked row has a glbUrl");
        $this->assertTrue(
            file_exists(MOCAP_GLB_DIR . basename($row['glbUrl'])),
            "glbUrl points at a file that actually exists"
        );

        $conn->close();
    }

    public function testUnbakedRowsReportNullGlbUrl() {
        $conn = $this->liveConn();
        if ($conn === null) { $this->assertTrue(false, "could not connect to database"); return; }
        $index = mocap_get_index(MOCAP_CACHE, MOCAP_FBX_DIR, MOCAP_GLB_DIR, 600, false);

        $all = mocap_file_list($conn, $index, ['limit' => 500]);
        $sawUnbaked = false;
        foreach ($all['videos'] as $row) {
            if ($row['baked'] === false) {
                $sawUnbaked = true;
                $this->assertEquals(null, $row['glbUrl'], "unbaked row must not invent a glbUrl");
            }
        }
        $this->assertEquals(true, $sawUnbaked, "sample contained at least one unbaked row");

        $conn->close();
    }

    public function testNietKlaarMatchesNullAndEmpty() {
        $conn = $this->liveConn();
        if ($conn === null) { $this->assertTrue(false, "could not connect to database"); return; }
        $index = mocap_get_index(MOCAP_CACHE, MOCAP_FBX_DIR, MOCAP_GLB_DIR, 600, false);

        $niet = mocap_file_list($conn, $index, ['mcpStatusTijdAnnotatie' => 'Niet Klaar', 'limit' => 1]);
        $klaar = mocap_file_list($conn, $index, ['mcpStatusTijdAnnotatie' => 'Klaar', 'limit' => 1]);
        $check = mocap_file_list($conn, $index, ['mcpStatusTijdAnnotatie' => 'Check nodig', 'limit' => 1]);
        $none = mocap_file_list($conn, $index, ['limit' => 1]);

        $this->assertEquals(
            $none['total'],
            $niet['total'] + $klaar['total'] + $check['total'],
            "Niet Klaar covers NULL and empty, so the three statuses partition the set"
        );
        // Observed domain on 2026-08-19: NULL (3720 sentences), 'Klaar' (410), '' (4).
        // No 'Check nodig' rows exist yet, so that arm is legitimately 0 today.

        $conn->close();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/zin && php tests/TestRunner.php`
Expected: FAIL with "Call to undefined function mocap_file_list()".

- [ ] **Step 3: Write minimal implementation**

Append to `/web/zin/mocapFiles.php`:

```php
/**
 * List zin videos joined to their sentence status, latest mocap take and gloss SRT.
 *
 * Status columns live on `sentences`; the rows returned here are `videos`.
 * A sentence with two baked takes appears as two rows.
 */
function mocap_file_list($conn, $index, $opts) {
    $page  = max(1, (int)($opts['page'] ?? 1));
    $limit = (int)($opts['limit'] ?? 100);
    if ($limit < 1)   { $limit = 100; }
    if ($limit > 500) { $limit = 500; }

    $where  = ["mt.zOg = 'Zin'", "mt.added = 1"];
    $params = [];
    $types  = '';

    foreach (['mcpStatusTijdAnnotatie' => 'mcp_status_tijd_annotatie',
              'mcpStatusTijdAnnotatieGvg' => 'mcp_status_tijd_annotatie_gvg'] as $opt => $col) {
        if (empty($opts[$opt])) { continue; }
        $value = $opts[$opt];
        if ($value === 'Niet Klaar') {
            // An empty or NULL cell means Niet Klaar, matching getZinnen.php:1215.
            $where[] = "(s.$col = ? OR s.$col IS NULL OR s.$col = '')";
        } else {
            $where[] = "s.$col = ?";
        }
        $types .= 's';
        $params[] = $value;
    }

    if (!empty($opts['mcpStatusPostprocessing'])) {
        // Stored numerically, matching getZinnen.php:1232.
        $v = $opts['mcpStatusPostprocessing'];
        if ($v === '__NULL__' || $v === 'Niet Klaar') {
            $where[] = "s.mcp_status_postprocessing IS NULL";
        } else {
            if ($v === 'Klaar')            { $v = 1; }
            elseif ($v === 'Check nodig')  { $v = 2; }
            $where[] = "s.mcp_status_postprocessing = ?";
            $types .= 'i';
            $params[] = (int)$v;
        }
    }

    if (!empty($opts['search'])) {
        $where[] = "LOWER(s.zinArray) LIKE ?";
        $types .= 's';
        $params[] = '%' . strtolower($opts['search']) . '%';
    }

    $sql = "SELECT mt.ID AS video_id, mt.m_file, mt.m_transcription AS sentence_id,
                   s.zinArray, s.thema,
                   s.status_video, s.status_annotatie, s.status_glos, s.status_gvg,
                   s.mcp_status_postprocessing, s.mcp_status_tijd_annotatie,
                   s.mcp_status_tijd_annotatie_gvg
            FROM matched_transcriptions mt
            JOIN sentences s ON mt.m_transcription = s.ID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY mt.ID DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return ['total' => 0, 'page' => $page, 'limit' => $limit, 'count' => 0,
                'videos' => [], 'error' => 'Prepare failed: ' . $conn->error];
    }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();

    // baked and hasGloss are filesystem facts, not SQL predicates, so the full
    // candidate set is filtered in PHP and paginated afterwards. The candidate
    // set is a few thousand rows at most.
    $rows = [];
    $onlyBaked = (($opts['baked'] ?? null) === '1');
    $onlyGloss = (($opts['hasGloss'] ?? null) === '1');

    while ($row = $result->fetch_assoc()) {
        $base = preg_replace('/\.(wav|mp4)$/i', '', basename($row['m_file']));
        $take = mocap_latest_take($index, $base);

        $baked = ($take !== null && $take['baked']);
        if ($onlyBaked && !$baked) { continue; }

        $glossSrtName = $base . '_Signbank_ID_glossen.srt';
        $hasGloss = file_exists(MOCAP_EAF_DIR . $glossSrtName);
        if ($onlyGloss && !$hasGloss) { continue; }

        $zinWords = json_decode($row['zinArray'] ?? '[]', true);
        $zin = (json_last_error() === JSON_ERROR_NONE && is_array($zinWords) && count($zinWords))
            ? ucfirst(implode(' ', $zinWords))
            : '';

        $rows[] = [
            'sentence_id' => (int)$row['sentence_id'],
            'video_id'    => (int)$row['video_id'],
            'm_file'      => $row['m_file'],
            'base'        => $base,
            'zin'         => $zin,
            'thema'       => $row['thema'],
            'status_video'      => $row['status_video'],
            'status_annotatie'  => $row['status_annotatie'],
            'status_glos'       => $row['status_glos'],
            'status_gvg'        => $row['status_gvg'],
            'mcp_status_postprocessing'     => $row['mcp_status_postprocessing'] === null
                                                ? null : (int)$row['mcp_status_postprocessing'],
            'mcp_status_tijd_annotatie'     => $row['mcp_status_tijd_annotatie'],
            'mcp_status_tijd_annotatie_gvg' => $row['mcp_status_tijd_annotatie_gvg'],
            'takeNumber'  => $take === null ? null : $take['takeNumber'],
            'fbxFilename' => $take === null ? null : $take['fbxFilename'],
            'baked'       => $baked,
            'glbUrl'      => $baked ? (MOCAP_GLB_URL . $take['glbFilename']) : null,
            'hasGloss'    => $hasGloss,
            'glossSrtUrl' => $hasGloss ? (MOCAP_SRT_URL . $glossSrtName) : null,
        ];
    }
    $stmt->close();

    $total = count($rows);
    $slice = array_slice($rows, ($page - 1) * $limit, $limit);

    return [
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'count'  => count($slice),
        'videos' => array_values($slice),
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/zin && php tests/TestRunner.php`
Expected: 0 failed. If `testFileListReproducesMeasuredBaseline` reports totals other than 568/410, more videos have been baked since 2026-08-19 — re-measure with the query in the spec's "Measured baseline" section and update both the spec table and the test constants in the same commit.

- [ ] **Step 5: Wire the HTTP action into getZinnen.php**

Add the include directly after the existing `include '../mysql_config.php';` near `getZinnen.php:5`:

```php
require_once __DIR__ . '/mocapFiles.php';
```

Add the case beside `listVideosWithoutMocap` (near `getZinnen.php:131`):

```php
    case 'listMocapFiles':
        listMocapFiles($conn);
        break;
```

Add the function beside `getLatestMocapFile` (near `getZinnen.php:4008`):

```php
/**
 * List zin videos with their latest mocap take, baked GLB and gloss SRT,
 * filtered by the MCP status columns. See mocapFiles.php for the join.
 */
function listMocapFiles($conn) {
    header('Content-Type: application/json; charset=utf-8');

    $force = ($_GET['refreshIndex'] ?? '') === '1';
    $index = mocap_get_index(MOCAP_CACHE, MOCAP_FBX_DIR, MOCAP_GLB_DIR, MOCAP_CACHE_TTL, $force);

    $result = mocap_file_list($conn, $index, [
        'mcpStatusTijdAnnotatie'    => $_GET['mcpStatusTijdAnnotatie'] ?? null,
        'mcpStatusTijdAnnotatieGvg' => $_GET['mcpStatusTijdAnnotatieGvg'] ?? null,
        'mcpStatusPostprocessing'   => $_GET['mcpStatusPostprocessing'] ?? null,
        'baked'                     => $_GET['baked'] ?? null,
        'hasGloss'                  => $_GET['hasGloss'] ?? null,
        'search'                    => $_GET['search'] ?? null,
        'page'                      => $_GET['page'] ?? 1,
        'limit'                     => $_GET['limit'] ?? 100,
    ]);

    $result['success'] = !isset($result['error']);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}
```

- [ ] **Step 6: Verify the endpoint over HTTP**

Run:
```bash
curl -s 'https://signcollect.nl/zin/getZinnen.php?action=listMocapFiles&baked=1&mcpStatusTijdAnnotatie=Klaar&limit=2' \
  | php -r '$d=json_decode(file_get_contents("php://stdin"),true);
    echo "success: "; var_export($d["success"]); echo "\ntotal (videos): {$d["total"]}\n";
    echo "first glbUrl: {$d["videos"][0]["glbUrl"]}\n";'
```
Expected: `success: true`, `total (videos): 410`, and a `glbUrl` under `/gebarenoverleg_media/fbx/post_processed/`.

If the host is unreachable from this machine, serve locally instead — note that `php -S` is the documented workaround for the Apache 403 on this project:
```bash
cd /web/zin && php -S 127.0.0.1:8099 >/dev/null 2>&1 &
sleep 1
curl -s 'http://127.0.0.1:8099/getZinnen.php?action=listMocapFiles&baked=1&limit=1'
kill %1
```

- [ ] **Step 7: Commit**

```bash
cd /web/zin
git add mocapFiles.php getZinnen.php tests/TestMocapFiles.php
git commit -m "feat: add listMocapFiles action listing baked animations with gloss SRTs"
```

---

### Task 3: blendBaking repository and gloss SRT parser

**Files:**
- Create: `/web/blendBaking/.gitignore`
- Create: `/web/blendBaking/srtGloss.php`
- Create: `/web/blendBaking/tests/TestSrtGloss.php`
- Create: `/web/blendBaking/tests/TestRunner.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `srt_cues(string $contents): array<string>` — cue text lines in file order, duplicates kept
  - `srt_base_gloss(string $cue): string` — cue with a trailing single-letter variant suffix removed
  - `gloss_aggregate(array $files): array` — `['bases' => [base => ['count'=>int,'videos'=>int,'variants'=>[variant=>count]]], 'files' => int]`, where `$files` is a map of `base filename => absolute SRT path`

- [ ] **Step 1: Initialise the repository and write the failing test**

```bash
mkdir -p /web/blendBaking/tests /web/blendBaking/scripts /web/blendBaking/cache
cd /web/blendBaking
git init
printf 'cache/\n*.log\n' > .gitignore
```

Create `/web/blendBaking/tests/TestSrtGloss.php`:

```php
<?php
/**
 * Unit tests for srtGloss.php — SRT cue extraction and base-gloss folding.
 */
require_once __DIR__ . '/../srtGloss.php';

class TestSrtGloss {
    private $passed = 0;
    private $failed = 0;
    private $messages = [];

    private function assertTrue($condition, $message = "Assertion failed") {
        if ($condition) { $this->passed++; return true; }
        $this->failed++; $this->messages[] = "FAIL: $message"; return false;
    }

    private function assertEquals($expected, $actual, $message = "Values are not equal") {
        return $this->assertTrue(
            $expected === $actual,
            $message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")"
        );
    }

    public function testCuesSkipIndexesTimecodesAndBlanks() {
        $srt = "1\n00:00:01,975 --> 00:00:02,871\nDOEN-C\n\n2\n00:00:03,044 --> 00:00:03,277\nDOEN-A\n";
        $this->assertEquals(['DOEN-C', 'DOEN-A'], srt_cues($srt), "plain two-cue file");
    }

    public function testCuesHandleCrlf() {
        $srt = "1\r\n00:00:01,975 --> 00:00:02,871\r\nPT-1hand\r\n\r\n";
        $this->assertEquals(['PT-1hand'], srt_cues($srt), "CRLF line endings");
    }

    public function testCuesHandleEmptyFile() {
        $this->assertEquals([], srt_cues(''), "empty file yields no cues");
        $this->assertEquals([], srt_cues("1\n00:00:01,000 --> 00:00:02,000\n\n"), "cue with blank text");
    }

    public function testBaseGlossStripsSingleLetterVariant() {
        $this->assertEquals('DOEN', srt_base_gloss('DOEN-C'), "uppercase variant");
        $this->assertEquals('EVEN', srt_base_gloss('EVEN-B'), "uppercase variant B");
        $this->assertEquals('MOOI', srt_base_gloss('MOOI-a'), "lowercase variant folds to same base");
        $this->assertEquals('MOOI', srt_base_gloss('MOOI-A'), "uppercase counterpart of the same base");
    }

    public function testBaseGlossLeavesNonVariantsIntact() {
        // Every one of these appears in live data and must survive unchanged.
        $this->assertEquals('PT-1hand', srt_base_gloss('PT-1hand'), "multi-letter suffix is not a variant");
        $this->assertEquals('PT-1hand:1', srt_base_gloss('PT-1hand:1'), "colon-suffixed pointing sign");
        $this->assertEquals('PO+PT', srt_base_gloss('PO+PT'), "compound pointing sign");
        $this->assertEquals('MOVE+C', srt_base_gloss('MOVE+C'), "classifier ending in a single letter but no hyphen");
        $this->assertEquals('MOVE+geld', srt_base_gloss('MOVE+geld'), "classifier with a word");
        $this->assertEquals('MOVE+Baby_snavel', srt_base_gloss('MOVE+Baby_snavel'), "classifier with underscore");
        $this->assertEquals('#J', srt_base_gloss('#J'), "fingerspelling");
        $this->assertEquals('-', srt_base_gloss('-'), "bare hyphen placeholder");
        $this->assertEquals('nvt', srt_base_gloss('nvt'), "nvt placeholder");
        $this->assertEquals('GAAN-NAAR', srt_base_gloss('GAAN-NAAR-A'), "hyphenated lemma keeps its internal hyphens");
    }

    public function testAggregateCountsOccurrencesAndVideos() {
        $tmp = sys_get_temp_dir() . '/srttest_' . getmypid();
        @mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/A.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nAAP-A\n\n2\n00:00:02,000 --> 00:00:03,000\nAAP-B\n");
        file_put_contents($tmp . '/B.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nAAP-A\n\n2\n00:00:02,000 --> 00:00:03,000\nBOEK\n");

        $agg = gloss_aggregate(['A' => $tmp . '/A.srt', 'B' => $tmp . '/B.srt']);

        $this->assertEquals(2, $agg['files'], "two files parsed");
        $this->assertEquals(3, $agg['bases']['AAP']['count'], "AAP occurs three times across both files");
        $this->assertEquals(2, $agg['bases']['AAP']['videos'], "AAP appears in two videos");
        $this->assertEquals(2, $agg['bases']['AAP']['variants']['AAP-A'], "AAP-A counted twice");
        $this->assertEquals(1, $agg['bases']['AAP']['variants']['AAP-B'], "AAP-B counted once");
        $this->assertEquals(1, $agg['bases']['BOEK']['videos'], "BOEK appears in one video");

        unlink($tmp . '/A.srt'); unlink($tmp . '/B.srt'); rmdir($tmp);
    }

    public function testAggregateSkipsMissingFiles() {
        $agg = gloss_aggregate(['X' => '/nonexistent/nope.srt']);
        $this->assertEquals(0, $agg['files'], "missing files are skipped, not fatal");
        $this->assertEquals([], $agg['bases'], "no bases from a missing file");
    }

    public function runTests() {
        foreach (get_class_methods($this) as $m) {
            if (strpos($m, 'test') === 0) { $this->$m(); }
        }
        return ['passed' => $this->passed, 'failed' => $this->failed, 'messages' => $this->messages];
    }
}
```

Create `/web/blendBaking/tests/TestRunner.php`:

```php
<?php
/**
 * Test runner for blendBaking.
 * Usage: php tests/TestRunner.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/TestSrtGloss.php';

$classes = ['TestSrtGloss'];
$totalPassed = 0; $totalFailed = 0;

foreach ($classes as $class) {
    $suite = new $class();
    $result = $suite->runTests();
    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    printf("%-24s %3d passed, %3d failed\n", $class, $result['passed'], $result['failed']);
    foreach ($result['messages'] as $msg) { echo "  $msg\n"; }
}

printf("\nTOTAL: %d passed, %d failed\n", $totalPassed, $totalFailed);
exit($totalFailed > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/blendBaking && php tests/TestRunner.php`
Expected: FAIL — "Failed opening required '/web/blendBaking/srtGloss.php'".

- [ ] **Step 3: Write minimal implementation**

Create `/web/blendBaking/srtGloss.php`:

```php
<?php
/**
 * Gloss extraction from Signbank ID gloss SRT files.
 *
 * Pure library: no output, no I/O beyond reading the paths it is handed.
 */

/**
 * Cue text lines from an SRT file's contents, in file order, duplicates kept.
 *
 * A cue line is any line that is not blank, not a bare sequence number, and
 * does not contain a timecode arrow.
 */
function srt_cues($contents) {
    $cues = [];
    foreach (preg_split('/\r?\n/', $contents) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if (preg_match('/^\d+$/', $line)) { continue; }
        if (strpos($line, '-->') !== false) { continue; }
        $cues[] = $line;
    }
    return $cues;
}

/**
 * Fold a cue to its base gloss by removing a trailing single-letter variant.
 *
 * AAP-A and AAP-B both fold to AAP; MOOI-a folds to MOOI. Deliberately lossy
 * for a lemma that genuinely ends in hyphen-plus-one-letter — no such gloss
 * exists in the current vocabulary, and callers keep the original strings in
 * the variants map either way.
 */
function srt_base_gloss($cue) {
    $stripped = preg_replace('/-[A-Za-z]$/', '', $cue);
    // Never strip a cue down to nothing: '-' and '-A' must survive as themselves.
    return ($stripped === '' || $stripped === false) ? $cue : $stripped;
}

/**
 * Aggregate glosses across a set of SRT files.
 *
 * @param array $files map of base filename => absolute SRT path
 * @return array ['files' => int, 'bases' => [base => ['count','videos','variants'=>[variant=>count]]]]
 */
function gloss_aggregate($files) {
    $bases = [];
    $parsed = 0;

    foreach ($files as $videoBase => $path) {
        if (!is_string($path) || !file_exists($path)) { continue; }
        $contents = @file_get_contents($path);
        if ($contents === false) { continue; }
        $parsed++;

        $seenInThisFile = [];
        foreach (srt_cues($contents) as $cue) {
            $base = srt_base_gloss($cue);
            if (!isset($bases[$base])) {
                $bases[$base] = ['count' => 0, 'videos' => 0, 'variants' => []];
            }
            $bases[$base]['count']++;
            $bases[$base]['variants'][$cue] = ($bases[$base]['variants'][$cue] ?? 0) + 1;
            if (!isset($seenInThisFile[$base])) {
                $bases[$base]['videos']++;
                $seenInThisFile[$base] = true;
            }
        }
    }

    return ['files' => $parsed, 'bases' => $bases];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/blendBaking && php tests/TestRunner.php`
Expected: `TestSrtGloss` reports 0 failed, exit code 0.

- [ ] **Step 5: Sanity-check against live SRT data**

Run:
```bash
cd /web/blendBaking && php -r '
require "srtGloss.php";
$suffix = "_Signbank_ID_glossen.srt";   // 24 characters
$files = [];
foreach (scandir("/web/zin/eaf/zin") as $f) {
    if (strpos($f, "backup") !== false) { continue; }
    if (substr($f, -strlen($suffix)) !== $suffix) { continue; }
    $files[substr($f, 0, -strlen($suffix))] = "/web/zin/eaf/zin/" . $f;
}
$t = microtime(true);
$agg = gloss_aggregate(array_slice($files, 0, 500, true));
printf("files=%d bases=%d in %.2fs\n", $agg["files"], count($agg["bases"]), microtime(true) - $t);
'
```
Expected: several hundred files parsed, on the order of 600–800 bases, well under 5 s. A `bases` count in the thousands means variant folding is not running.

- [ ] **Step 6: Commit**

```bash
cd /web/blendBaking
git add .gitignore srtGloss.php tests/TestSrtGloss.php tests/TestRunner.php
git commit -m "feat: add gloss SRT parser with tests"
```

---

### Task 4: blendBaking API — list, glosses, ZIP

**Files:**
- Create: `/web/blendBaking/api.php`
- Modify: `/web/blendBaking/tests/TestSrtGloss.php` (append ZIP-safety tests)

**Interfaces:**
- Consumes: `srt_cues()`, `srt_base_gloss()`, `gloss_aggregate()` from Task 3.
- Produces:
  - `bb_safe_base(string $base): ?string` — returns the base if it is a legal base filename, else `null`
  - `bb_srt_path(string $base): ?string` — absolute, `realpath`-confirmed path inside `/web/zin/eaf/zin/`, else `null`
  - `bb_fetch_videos(array $query): array` — rows from `listMocapFiles`
  - HTTP actions: `list`, `glosses`, `zip`, `categories`, `rebuildIndex`

- [ ] **Step 1: Write the failing test**

Append to `class TestSrtGloss` in `/web/blendBaking/tests/TestSrtGloss.php`, before `runTests()`:

```php
    public function testSafeBaseAcceptsRealBases() {
        require_once __DIR__ . '/../api.php';
        $this->assertEquals('M20240925_1824', bb_safe_base('M20240925_1824'), "ordinary zin base");
        $this->assertEquals('#A_241120_0', bb_safe_base('#A_241120_0'), "base with hash and digits");
    }

    public function testSafeBaseRejectsTraversal() {
        require_once __DIR__ . '/../api.php';
        $this->assertEquals(null, bb_safe_base('../../etc/passwd'), "parent traversal");
        $this->assertEquals(null, bb_safe_base('..%2fetc%2fpasswd'), "encoded traversal");
        $this->assertEquals(null, bb_safe_base('/etc/passwd'), "absolute path");
        $this->assertEquals(null, bb_safe_base('M2024 1824'), "space is not allowed");
        $this->assertEquals(null, bb_safe_base(''), "empty base");
        $this->assertEquals(null, bb_safe_base('M2024/1824'), "slash is not allowed");
    }

    public function testSrtPathStaysInsideEafDir() {
        require_once __DIR__ . '/../api.php';
        $this->assertEquals(null, bb_srt_path('../../etc/passwd'), "traversal yields no path");
        $this->assertEquals(null, bb_srt_path('definitely_not_a_real_base_xyz'), "nonexistent yields no path");

        // A base known to exist from the live measurement.
        $p = bb_srt_path('M20240828_0037');
        $this->assertTrue(
            is_string($p) && strpos($p, '/web/zin/eaf/zin/') === 0,
            "a real base resolves inside the SRT directory"
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /web/blendBaking && php tests/TestRunner.php`
Expected: FAIL — "Failed opening required '/web/blendBaking/api.php'".

- [ ] **Step 3: Write minimal implementation**

Create `/web/blendBaking/api.php`:

```php
<?php
/**
 * blendBaking API.
 *
 * Thin layer over getZinnen.php's listMocapFiles action. Everything specific
 * to this tool lives here: ZIP bundling, gloss aggregation, category lookup.
 *
 * Including this file defines functions only; it dispatches on $_GET['action']
 * solely when requested over HTTP, so tests may require_once it safely.
 */

require_once __DIR__ . '/srtGloss.php';

define('BB_EAF_DIR',    '/web/zin/eaf/zin/');
define('BB_SRT_SUFFIX', '_Signbank_ID_glossen.srt');
define('BB_ZIN_API',    'https://signcollect.nl/zin/getZinnen.php');
define('BB_CACHE',      __DIR__ . '/cache/gloss_index.json');
define('BB_CATEGORIES', __DIR__ . '/categories.json');
define('BB_MAX_ZIP',    2000);

/**
 * Validate a video base filename. Returns the base, or null if unsafe.
 *
 * Allowed characters are exactly those observed in real bases; anything else
 * — including any path separator or dot-segment — is rejected outright rather
 * than sanitised, so traversal cannot survive normalisation.
 */
function bb_safe_base($base) {
    if (!is_string($base) || $base === '') { return null; }
    if (!preg_match('/^[A-Za-z0-9_#+.-]+$/', $base)) { return null; }
    if (strpos($base, '..') !== false) { return null; }
    return $base;
}

/**
 * Absolute path to a base's gloss SRT, confirmed to sit inside BB_EAF_DIR.
 */
function bb_srt_path($base) {
    $base = bb_safe_base($base);
    if ($base === null) { return null; }

    $candidate = BB_EAF_DIR . $base . BB_SRT_SUFFIX;
    $real = realpath($candidate);
    if ($real === false) { return null; }

    $root = realpath(BB_EAF_DIR);
    if ($root === false || strpos($real, $root . '/') !== 0) { return null; }

    return $real;
}

/**
 * Fetch rows from getZinnen.php's listMocapFiles action.
 */
function bb_fetch_videos($query) {
    $query['action'] = 'listMocapFiles';
    $url = BB_ZIN_API . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => 'Upstream request failed: ' . $err];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'Upstream returned invalid JSON'];
    }
    return $decoded;
}

/**
 * Aggregate glosses for the baked videos, cached to BB_CACHE.
 */
function bb_glosses($force = false) {
    if (!$force && file_exists(BB_CACHE)) {
        $decoded = json_decode(@file_get_contents(BB_CACHE), true);
        if (is_array($decoded) && isset($decoded['bases'])) { return $decoded; }
    }

    $res = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => 1]);
    if (empty($res['success'])) { return $res; }

    $rows = $res['videos'];
    $total = (int)$res['total'];
    $page = 2;
    while (count($rows) < $total) {
        $next = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => $page]);
        if (empty($next['success']) || !count($next['videos'])) { break; }
        $rows = array_merge($rows, $next['videos']);
        $page++;
    }

    $files = [];
    foreach ($rows as $row) {
        if (empty($row['hasGloss'])) { continue; }
        $p = bb_srt_path($row['base']);
        if ($p !== null) { $files[$row['base']] = $p; }
    }

    $agg = gloss_aggregate($files);
    $agg['built_at'] = time();
    $agg['videos'] = count($rows);

    $dir = dirname(BB_CACHE);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents(BB_CACHE, json_encode($agg), LOCK_EX);

    return $agg;
}

/**
 * Category map, or an empty structure when categories.json is absent.
 */
function bb_categories() {
    if (!file_exists(BB_CATEGORIES)) {
        return ['categories' => [], 'glosses' => []];
    }
    $decoded = json_decode(@file_get_contents(BB_CATEGORIES), true);
    if (!is_array($decoded) || !isset($decoded['glosses'])) {
        return ['categories' => [], 'glosses' => []];
    }
    return $decoded;
}

/**
 * Stream a ZIP of gloss SRTs for the given bases.
 */
function bb_stream_zip($bases) {
    $paths = [];
    foreach ($bases as $base) {
        if (count($paths) >= BB_MAX_ZIP) { break; }
        $p = bb_srt_path($base);
        if ($p !== null) { $paths[basename($p)] = $p; }
    }

    if (!count($paths)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Geen geldige SRT-bestanden gevonden.']);
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'bbzip');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Kon ZIP niet aanmaken.']);
        return;
    }
    foreach ($paths as $name => $path) { $zip->addFile($path, $name); }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="blendBaking_glossen_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
}

// ---------------------------------------------------------------------------
// HTTP dispatch. Skipped on CLI so tests can include this file.
// ---------------------------------------------------------------------------
if (php_sapi_name() !== 'cli' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'zip') {
        $raw = $_POST['bases'] ?? $_GET['bases'] ?? '';
        $bases = is_array($raw) ? $raw : array_filter(explode(',', $raw));
        bb_stream_zip($bases);
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
    switch ($action) {
        case 'list':
            echo json_encode(bb_fetch_videos([
                'baked' => '1',
                'hasGloss' => '1',
                'mcpStatusTijdAnnotatie' => $_GET['mcpStatusTijdAnnotatie'] ?? null,
                'search' => $_GET['search'] ?? null,
                'page'   => $_GET['page'] ?? 1,
                'limit'  => $_GET['limit'] ?? 500,
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'glosses':
            $agg = bb_glosses(($_GET['refresh'] ?? '') === '1');
            $agg['success'] = !isset($agg['error']);
            $agg['categoryMap'] = bb_categories();
            echo json_encode($agg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'categories':
            echo json_encode(bb_categories(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'rebuildIndex':
            $agg = bb_glosses(true);
            echo json_encode(['success' => !isset($agg['error']),
                              'bases' => count($agg['bases'] ?? []),
                              'files' => $agg['files'] ?? 0]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Onbekende actie: ' . $action]);
    }
    exit();
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /web/blendBaking && php tests/TestRunner.php`
Expected: 0 failed.

- [ ] **Step 5: Verify the API over HTTP**

Run:
```bash
cd /web/blendBaking && php -S 127.0.0.1:8098 >/dev/null 2>&1 &
sleep 1
curl -s 'http://127.0.0.1:8098/api.php?action=list&limit=2' | head -c 400; echo
curl -s 'http://127.0.0.1:8098/api.php?action=glosses&refresh=1' \
  | php -r '$d=json_decode(file_get_contents("php://stdin"),true);
    echo "files: {$d["files"]}, bases: " . count($d["bases"]) . "\n";'
curl -s -o /tmp/bb.zip -w '%{http_code} %{size_download}\n' \
  'http://127.0.0.1:8098/api.php?action=zip&bases=M20240828_0037,M20240828_0039'
unzip -l /tmp/bb.zip
curl -s 'http://127.0.0.1:8098/api.php?action=zip&bases=../../etc/passwd'
kill %1
```
Expected: `list` returns JSON rows; `glosses` reports roughly `files: 568, bases: 677`; the ZIP contains exactly the two named `_Signbank_ID_glossen.srt` files; the traversal request returns the JSON error, **not** a ZIP and not `/etc/passwd`.

- [ ] **Step 6: Commit**

```bash
cd /web/blendBaking
git add api.php tests/TestSrtGloss.php
git commit -m "feat: add blendBaking API for listing, gloss aggregation and ZIP export"
```

---

### Task 5: Gloss categories

**Files:**
- Create: `/web/blendBaking/scripts/dump_base_glosses.php`
- Create: `/web/blendBaking/scripts/find_uncategorized.php`
- Create: `/web/blendBaking/categories.json`

**Interfaces:**
- Consumes: `srt_base_gloss()` from Task 3, `bb_categories()` from Task 4.
- Produces: `categories.json` with shape `['categories' => [['slug','label'], ...], 'glosses' => [base => slug]]`.

- [ ] **Step 1: Write the corpus dump script**

Create `/web/blendBaking/scripts/dump_base_glosses.php`:

```php
<?php
/**
 * Print every base gloss in the corpus, one per line, with its occurrence
 * count, most frequent first. Input for generating categories.json.
 *
 * Usage: php scripts/dump_base_glosses.php [--baked-only]
 */
require_once __DIR__ . '/../srtGloss.php';
require_once __DIR__ . '/../api.php';

$bakedOnly = in_array('--baked-only', $argv, true);

$files = [];
if ($bakedOnly) {
    $res = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => 1]);
    $rows = $res['videos'] ?? [];
    $total = (int)($res['total'] ?? 0);
    $page = 2;
    while (count($rows) < $total) {
        $next = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => $page]);
        if (empty($next['videos'])) { break; }
        $rows = array_merge($rows, $next['videos']);
        $page++;
    }
    foreach ($rows as $row) {
        $p = bb_srt_path($row['base']);
        if ($p !== null) { $files[$row['base']] = $p; }
    }
} else {
    // scandir, never find: see the plan's Global Constraints.
    foreach (scandir(BB_EAF_DIR) as $f) {
        if (strpos($f, 'backup') !== false) { continue; }
        $suffixLen = strlen(BB_SRT_SUFFIX);
        if (substr($f, -$suffixLen) !== BB_SRT_SUFFIX) { continue; }
        $files[substr($f, 0, -$suffixLen)] = BB_EAF_DIR . $f;
    }
}

$agg = gloss_aggregate($files);
$sorted = $agg['bases'];
uasort($sorted, function ($a, $b) { return $b['count'] - $a['count']; });

fwrite(STDERR, sprintf("%d files, %d base glosses\n", $agg['files'], count($sorted)));
foreach ($sorted as $base => $info) {
    printf("%s\t%d\n", $base, $info['count']);
}
```

- [ ] **Step 2: Run it to produce the input list**

Run:
```bash
cd /web/blendBaking && php scripts/dump_base_glosses.php > /tmp/base_glosses.tsv
wc -l /tmp/base_glosses.tsv
head -20 /tmp/base_glosses.tsv
```
Expected: about 1,519 lines; the head shows `PT-1hand`, `nvt`, `PO`, `EVEN`, `WILLEN` with high counts.

- [ ] **Step 3: Generate `categories.json`**

Read `/tmp/base_glosses.tsv` in full and assign every base gloss exactly one slug from this fixed set. Do not invent slugs; anything that does not fit goes to `overig`.

Non-lexical slugs, assigned by pattern before any semantic judgement:
- `pointing` — any gloss starting `PT-`, plus `PO` and `PO+PT`
- `vingerspelling` — any gloss starting `#`
- `classifier` — any gloss starting `MOVE+`
- `leeg` — `nvt`, `NVT`, `-`, and any gloss that is only punctuation

Lexical slugs, assigned by meaning: `dier`, `eten_drinken`, `familie_personen`, `werkwoord_actie`, `tijd`, `plaats`, `kleur`, `getal`, `emotie`, `lichaam`, `kleding`, `vervoer`, `natuur`, `wonen`, `school_werk`, `communicatie`, `overig`.

Write `/web/blendBaking/categories.json`:

```json
{
  "categories": [
    {"slug": "dier",            "label": "Dieren"},
    {"slug": "eten_drinken",    "label": "Eten en drinken"},
    {"slug": "familie_personen","label": "Familie en personen"},
    {"slug": "werkwoord_actie", "label": "Werkwoorden en acties"},
    {"slug": "tijd",            "label": "Tijd"},
    {"slug": "plaats",          "label": "Plaats en richting"},
    {"slug": "kleur",           "label": "Kleuren"},
    {"slug": "getal",           "label": "Getallen en hoeveelheid"},
    {"slug": "emotie",          "label": "Emoties en eigenschappen"},
    {"slug": "lichaam",         "label": "Lichaam en gezondheid"},
    {"slug": "kleding",         "label": "Kleding"},
    {"slug": "vervoer",         "label": "Vervoer"},
    {"slug": "natuur",          "label": "Natuur en weer"},
    {"slug": "wonen",           "label": "Wonen en huishouden"},
    {"slug": "school_werk",     "label": "School en werk"},
    {"slug": "communicatie",    "label": "Communicatie"},
    {"slug": "pointing",        "label": "Wijsgebaren"},
    {"slug": "vingerspelling",  "label": "Vingerspelling"},
    {"slug": "classifier",      "label": "Classifiers"},
    {"slug": "leeg",            "label": "Leeg of n.v.t."},
    {"slug": "overig",          "label": "Overig"}
  ],
  "glosses": {
    "AAP": "dier",
    "PT-1hand": "pointing",
    "nvt": "leeg"
  }
}
```

The `glosses` map above shows the shape with three entries. Populate it with an entry for **every** line in `/tmp/base_glosses.tsv` — leaving glosses out is a plan failure, since `find_uncategorized.php` in the next step exists to prove the map is complete.

- [ ] **Step 4: Write the completeness checker**

Create `/web/blendBaking/scripts/find_uncategorized.php`:

```php
<?php
/**
 * Print base glosses that appear in the SRT corpus but are missing from
 * categories.json, or that carry a slug not declared in its category list.
 *
 * Exit code 0 when categories.json is complete and consistent, 1 otherwise.
 *
 * Usage: php scripts/find_uncategorized.php [--baked-only]
 */
require_once __DIR__ . '/../srtGloss.php';
require_once __DIR__ . '/../api.php';

$args = implode(' ', array_slice($argv, 1));
$dump = shell_exec('php ' . escapeshellarg(__DIR__ . '/dump_base_glosses.php') . ' ' . $args . ' 2>/dev/null');

$corpus = [];
foreach (explode("\n", trim((string)$dump)) as $line) {
    if ($line === '') { continue; }
    $parts = explode("\t", $line);
    $corpus[$parts[0]] = (int)($parts[1] ?? 0);
}

$cat = bb_categories();
$known = [];
foreach ($cat['categories'] as $c) { $known[$c['slug']] = true; }

$missing = [];
foreach ($corpus as $base => $count) {
    if (!isset($cat['glosses'][$base])) { $missing[$base] = $count; }
}

$badSlug = [];
foreach ($cat['glosses'] as $base => $slug) {
    if (!isset($known[$slug])) { $badSlug[$base] = $slug; }
}

$stale = array_diff_key($cat['glosses'], $corpus);

printf("corpus base glosses : %d\n", count($corpus));
printf("categorized         : %d\n", count($cat['glosses']));
printf("missing             : %d\n", count($missing));
printf("undeclared slugs    : %d\n", count($badSlug));
printf("stale (not in corpus): %d\n", count($stale));

if (count($missing)) {
    echo "\n-- missing, most frequent first --\n";
    arsort($missing);
    foreach ($missing as $base => $count) { printf("%s\t%d\n", $base, $count); }
}
if (count($badSlug)) {
    echo "\n-- glosses with an undeclared slug --\n";
    foreach ($badSlug as $base => $slug) { printf("%s\t%s\n", $base, $slug); }
}

exit((count($missing) || count($badSlug)) ? 1 : 0);
```

- [ ] **Step 5: Verify categories.json is complete**

Run:
```bash
cd /web/blendBaking
php -r 'json_decode(file_get_contents("categories.json")); echo json_last_error_msg(), "\n";'
php scripts/find_uncategorized.php; echo "exit=$?"
```
Expected: `No error` from the JSON check; `missing: 0`, `undeclared slugs: 0`, `exit=0`. A non-zero exit means the `glosses` map is incomplete — add the printed glosses and re-run.

- [ ] **Step 6: Commit**

```bash
cd /web/blendBaking
git add categories.json scripts/dump_base_glosses.php scripts/find_uncategorized.php
git commit -m "feat: categorize gloss vocabulary with a completeness checker"
```

---

### Task 6: Two-tab UI

**Files:**
- Create: `/web/blendBaking/index.html`

**Interfaces:**
- Consumes: `api.php?action=list`, `api.php?action=glosses`, `api.php?action=zip` from Task 4; `categories.json` shape from Task 5.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the page**

Create `/web/blendBaking/index.html`. Requirements, all of which the manual check in Step 2 exercises:

- Bootstrap 5 and jQuery from the same CDNs already used by `/web/zin/subBeta3.html`; match that file's markup conventions.
- Two Bootstrap tabs: **SRT bestanden** and **Glossen**. All copy in Dutch.
- Tab 1 loads `api.php?action=list&limit=500`, paging until `videos.length === total`, and renders a table with columns: select checkbox, `base`, `zin`, `thema`, `takeNumber`, GLB link (`glbUrl`, opens in a new tab), SRT download link (`glossSrtUrl`), and `mcp_status_tijd_annotatie` shown as a badge (`Klaar` green, `Check nodig` amber, empty/null rendered as the text `Niet Klaar` in grey).
- Tab 1 controls: a free-text search box filtering on `base` and `zin` client-side; a status `<select>` with options Alle / Klaar / Check nodig / Niet Klaar; a header checkbox toggling all **currently visible** rows; a row counter that states its unit, e.g. `568 video's (555 zinnen)` — compute the sentence count as the number of distinct `sentence_id` values in the filtered rows.
- Tab 1 buttons: **Download geselecteerde als ZIP** posts the checked `base` values to `api.php?action=zip`; **Download alles (huidige filter)** posts every base matching the active filter, not only the visible page. Both submit via a generated `<form method="POST">` so the request survives long base lists, and both are disabled when the relevant set is empty.
- Tab 2 loads `api.php?action=glosses` and renders a table of base glosses: base, variants (the `variants` keys joined by `, `), total `count`, `videos`, and the category label resolved through `categoryMap.glosses[base]` → `categoryMap.categories[].label`, falling back to the literal `Niet ingedeeld`.
- Tab 2 controls: search box filtering on base and variants; a category `<select>` populated from `categoryMap.categories`; sortable by clicking the count and videos headers; and **Exporteer CSV** / **Exporteer JSON** buttons that download the currently filtered rows client-side via a Blob.
- Tab 2 also shows a **Herbouw index** button calling `api.php?action=rebuildIndex`, then reloading the tab.
- Every `fetch` failure and every response with `success === false` renders the error text in a Bootstrap alert above the table. No silent failures, and no `console.log`-only error paths.
- Escape all interpolated values before inserting them into the DOM — gloss and sentence text comes from user-edited data.

- [ ] **Step 2: Verify in a browser**

Run:
```bash
cd /web/blendBaking && php -S 127.0.0.1:8098 >/dev/null 2>&1 &
sleep 1
echo "open http://127.0.0.1:8098/index.html"
```

Check by hand, then `kill %1`:
1. Tab 1 lists 568 rows and the counter reads `568 video's (555 zinnen)`.
2. Filtering status to `Klaar` leaves 410 rows and the counter updates to `410 video's (406 zinnen)`.
3. A single SRT link downloads a file whose first cue is a gloss.
4. Selecting three rows and pressing **Download geselecteerde als ZIP** yields a ZIP with exactly three `.srt` entries.
5. Tab 2 lists roughly 677 base glosses; filtering to `Dieren` shows only animal glosses; `AAP` shows its variants and a count matching `grep -c` against the SRTs.
6. **Exporteer CSV** downloads a file whose row count matches the filtered table.

- [ ] **Step 3: Commit**

```bash
cd /web/blendBaking
git add index.html
git commit -m "feat: add two-tab UI for SRT downloads and gloss categories"
```

---

### Task 7: Documentation

**Files:**
- Create: `/web/blendBaking/README.md`
- Modify: `/web/zin/CLAUDE.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Write the blendBaking README**

Create `/web/blendBaking/README.md` covering: what the tool is; that it is its own git repository separate from `/web/zin`; the two tabs; the `api.php` actions with their parameters; that all data comes from `getZinnen.php?action=listMocapFiles`; how to regenerate `categories.json` (`dump_base_glosses.php` → categorize → `find_uncategorized.php` must exit 0); how to run the tests (`php tests/TestRunner.php`); and the two environment constraints — `find` does not work under `/web/gebarenoverleg_media`, and counts must state whether they are videos or sentences.

- [ ] **Step 2: Document the new action in the zin project instructions**

In `/web/zin/CLAUDE.md`, under the "Database Operations (getZinnen.php actions)" list, add:

```markdown
- `listMocapFiles`: List zin videos with their latest mocap take, baked GLB and gloss SRT, filtered by MCP status columns (see `mocapFiles.php`)
```

And under "Development Notes", add:

```markdown
- `/web/gebarenoverleg_media` is an rclone mount where `find` silently returns nothing; use `scandir`/`glob` for any directory enumeration there
- MCP status columns live on `sentences`, but mocap/SRT rows are `videos` — a sentence may have several videos, so always state which unit a count refers to
```

- [ ] **Step 3: Run the full test suites one last time**

Run:
```bash
cd /web/zin && php tests/TestRunner.php; echo "zin exit=$?"
cd /web/blendBaking && php tests/TestRunner.php; echo "blendBaking exit=$?"
cd /web/blendBaking && php scripts/find_uncategorized.php >/dev/null; echo "categories exit=$?"
```
Expected: all three exit 0.

- [ ] **Step 4: Commit**

```bash
cd /web/blendBaking
git add README.md
git commit -m "docs: document blendBaking usage and constraints"

cd /web/zin
git add CLAUDE.md
git commit -m "docs: document listMocapFiles action and mount constraints"
```
