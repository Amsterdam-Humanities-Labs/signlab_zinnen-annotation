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

    public function testLatestTakeOrdersBySessionDateNotJustTakeNumber() {
        // Take numbers restart per recording session (per date), so a base can
        // have a high take number on an old date and a low one on a new date.
        // The new date is the real latest take even though its take number is
        // lower.
        $tmp = sys_get_temp_dir() . '/mocapsession_' . getmypid();
        @mkdir($tmp . '/fbx', 0777, true);
        @mkdir($tmp . '/glb', 0777, true);

        // M3: older session (250101) has the higher take number (5), but the
        // newer session (260101) holds take 1 and the only GLB. The newer
        // session must win as the latest take, and its GLB must be reported.
        touch($tmp . '/fbx/M3_250101_0.fbx');
        touch($tmp . '/fbx/M3_250101_5.fbx');
        touch($tmp . '/fbx/M3_260101_1.fbx');
        touch($tmp . '/glb/M3_260101_1.glb');

        // M4: the newest take overall (260101_2) was recorded but never baked;
        // an older take (250101_0) was. baked must still be true, the GLB
        // must come from the older take, and glbIsLatestTake must say so.
        touch($tmp . '/fbx/M4_250101_0.fbx');
        touch($tmp . '/fbx/M4_260101_2.fbx');
        touch($tmp . '/glb/M4_250101_0.glb');

        $index = mocap_build_index($tmp . '/fbx', $tmp . '/glb');

        $m3 = mocap_latest_take($index, 'M3');
        $this->assertEquals(1, $m3['takeNumber'], "newer session's take wins despite lower take number");
        $this->assertEquals('M3_260101_1.fbx', $m3['fbxFilename'], "latest fbx is from the newer session");
        $this->assertEquals(true, $m3['baked'], "M3 latest take is baked");
        $this->assertEquals('M3_260101_1.glb', $m3['glbFilename'], "glb is the newer session's glb");
        $this->assertEquals(true, $m3['glbIsLatestTake'], "glb belongs to the latest take");

        $m4 = mocap_latest_take($index, 'M4');
        $this->assertEquals(2, $m4['takeNumber'], "latest take overall is the newest session's take");
        $this->assertEquals('M4_260101_2.fbx', $m4['fbxFilename'], "latest fbx filename");
        $this->assertEquals(true, $m4['baked'], "an older take's glb still counts as baked");
        $this->assertEquals('M4_250101_0.glb', $m4['glbFilename'], "glb comes from the older, baked take");
        $this->assertEquals(false, $m4['glbIsLatestTake'], "glb does not belong to the latest take");

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

    public function runTests() {
        foreach (get_class_methods($this) as $m) {
            if (strpos($m, 'test') === 0) { $this->$m(); }
        }
        return ['passed' => $this->passed, 'failed' => $this->failed, 'messages' => $this->messages];
    }
}
