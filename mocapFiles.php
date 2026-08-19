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
