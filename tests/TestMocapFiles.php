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
