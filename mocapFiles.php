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

// Bumped whenever the shape of a build_index take entry changes. mocap_get_index
// refuses to trust a cached index whose 'schema' doesn't match, so a cache written
// by older code is rebuilt instead of silently trusted with fields missing.
if (!defined('MOCAP_INDEX_SCHEMA')) define('MOCAP_INDEX_SCHEMA', 2);

/**
 * Scan the take and baked directories once and build a base -> takes index.
 *
 * @return array ['built_at' => int, 'schema' => int, 'bases' => [base => [ ['take','date','fbx','glb'], ... ]]]
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
                'date' => $p['date'],
                'fbx'  => $f,
                'glb'  => isset($glb[$key]) ? $glb[$key] : null,
            ];
        }
    }

    return ['built_at' => time(), 'schema' => MOCAP_INDEX_SCHEMA, 'bases' => $bases];
}

/**
 * Return the index, using a JSON cache younger than $ttl seconds when possible.
 */
function mocap_get_index($cacheFile, $fbxDir, $glbDir, $ttl = MOCAP_CACHE_TTL, $force = false) {
    if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $raw = @file_get_contents($cacheFile);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['bases'])
            && ($decoded['schema'] ?? null) === MOCAP_INDEX_SCHEMA) {
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
 * Latest take for a base, and separately its latest baked take.
 *
 * Take numbers restart per recording session (per date), so they do not by
 * themselves order takes across sessions: a base can have take 5 on an old
 * date and take 1 on a newer date, and the newer date's take 1 is the real
 * latest take. Takes are therefore ordered by the pair (date, take). The
 * date string is not guaranteed to be a fixed-width yymmdd (mocap_parse_take's
 * \d+ accepts any digit run, and the live index has at least one entry with
 * date="1") — this still orders correctly because PHP 8 compares two
 * all-digit strings numerically, not byte-by-byte, so "9" > "260101" is
 * false the same way 9 > 260101 is false. Do not "harden" this into
 * strcmp()/string comparison; that would break on differing widths.
 *
 * The latest take overall and the latest take that has a GLB can differ: a
 * newer take may have been recorded but never baked, while an older take on
 * the same base was. Reporting baked=false there would hide a usable
 * animation, so glbFilename/glbUrl always point at the newest take that
 * actually has a GLB, and glbIsLatestTake tells the caller whether that GLB
 * belongs to the newest take or an older one.
 *
 * @return array|null ['takeNumber','fbxFilename','baked','glbFilename','glbIsLatestTake'] or null
 */
function mocap_latest_take($index, $base) {
    if (!isset($index['bases'][$base]) || !count($index['bases'][$base])) {
        return null;
    }

    $best = null;
    $bestBaked = null;
    foreach ($index['bases'][$base] as $take) {
        if ($best === null || [$take['date'], $take['take']] > [$best['date'], $best['take']]) {
            $best = $take;
        }
        if ($take['glb'] !== null
            && ($bestBaked === null || [$take['date'], $take['take']] > [$bestBaked['date'], $bestBaked['take']])) {
            $bestBaked = $take;
        }
    }

    return [
        'takeNumber'      => $best['take'],
        'fbxFilename'     => $best['fbx'],
        'baked'           => $bestBaked !== null,
        'glbFilename'     => $bestBaked !== null ? $bestBaked['glb'] : null,
        'glbIsLatestTake' => $bestBaked !== null
            && $bestBaked['date'] === $best['date']
            && $bestBaked['take'] === $best['take'],
    ];
}

/**
 * List zin videos joined to their sentence status, latest mocap take and gloss SRT.
 *
 * Status columns live on `sentences`; the rows returned here are `videos`.
 * A sentence with two baked takes appears as two rows. Rows with a NULL
 * m_file have no file to resolve a base/take from and are skipped.
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
    if (!$stmt->execute()) {
        $error = 'Execute failed: ' . $stmt->error;
        $stmt->close();
        return ['total' => 0, 'page' => $page, 'limit' => $limit, 'count' => 0,
                'videos' => [], 'error' => $error];
    }
    $result = $stmt->get_result();

    // baked and hasGloss are filesystem facts, not SQL predicates, so the full
    // candidate set is filtered in PHP and paginated afterwards. The candidate
    // set is a few thousand rows at most.
    $rows = [];
    $onlyBaked = (($opts['baked'] ?? null) === '1');
    $onlyGloss = (($opts['hasGloss'] ?? null) === '1');

    while ($row = $result->fetch_assoc()) {
        if ($row['m_file'] === null) { continue; }
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
            'glbIsLatestTake' => $baked ? $take['glbIsLatestTake'] : false,
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
