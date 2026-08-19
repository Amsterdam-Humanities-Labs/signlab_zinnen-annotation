<?php
/**
 * EAF to Database Synchronization Script
 *
 * Extracts Nederlands tier text from EAF files and syncs to sentences.zinStringEAF column
 * Supports both CLI and web execution modes
 *
 * CLI Usage:
 *   php syncEafToDatabase.php [--limit=N] [--force] [--sentence_id=ID]
 *
 * Web Usage:
 *   syncEafToDatabase.php?limit=N&force&sentence_id=ID
 *
 * Parameters:
 *   --limit=N       : Process max N sentences (0 = unlimited, default: 100)
 *   --force         : Re-sync all sentences, not just pending/error
 *   --sentence_id=ID: Process only specific sentence ID
 */

// Database configuration (safe to include multiple times via require_once)
require_once 'api/mysql_config.php';

/**
 * Log message to output
 */
function logMessage($message, $isWeb) {
    if ($isWeb) {
        echo $message . "<br>\n";
        flush();
        ob_flush();
    } else {
        echo $message . "\n";
    }
}

/**
 * Extract Nederlands tier text from EAF file
 *
 * @param string $eafPath Path to EAF file
 * @return array ['success' => bool, 'text' => string|null, 'error' => string|null]
 */
function extractNederlandsFromEaf($eafPath) {
    if (!file_exists($eafPath)) {
        return ['success' => false, 'text' => null, 'error' => 'File not found'];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($eafPath);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parse error';
        return ['success' => false, 'text' => null, 'error' => 'XML parse error: ' . trim($errorMsg)];
    }

    // Find Nederlands tier
    foreach ($xml->TIER as $tier) {
        if ((string)$tier['TIER_ID'] === 'Nederlands') {
            $textParts = [];
            foreach ($tier->ANNOTATION as $annotation) {
                if (isset($annotation->ALIGNABLE_ANNOTATION->ANNOTATION_VALUE)) {
                    $textParts[] = trim((string)$annotation->ALIGNABLE_ANNOTATION->ANNOTATION_VALUE);
                }
            }

            $fullText = implode(' ', $textParts);

            if (empty($fullText)) {
                return ['success' => false, 'text' => null, 'error' => 'Nederlands tier is empty'];
            }

            return ['success' => true, 'text' => $fullText, 'error' => null];
        }
    }

    return ['success' => false, 'text' => null, 'error' => 'Nederlands tier not found'];
}

/**
 * Extract Gebaar-voor-gebaar tier text from EAF file
 *
 * @param string $eafPath Path to EAF file
 * @return array ['success' => bool, 'text' => string|null, 'error' => string|null]
 */
function extractGvgFromEaf($eafPath) {
    if (!file_exists($eafPath)) {
        return ['success' => false, 'text' => null, 'error' => 'File not found'];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($eafPath);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parse error';
        return ['success' => false, 'text' => null, 'error' => 'XML parse error: ' . trim($errorMsg)];
    }

    // Find Gebaar-voor-gebaar tier
    foreach ($xml->TIER as $tier) {
        if ((string)$tier['TIER_ID'] === 'Gebaar-voor-gebaar') {
            $textParts = [];
            foreach ($tier->ANNOTATION as $annotation) {
                if (isset($annotation->ALIGNABLE_ANNOTATION->ANNOTATION_VALUE)) {
                    $val = trim((string)$annotation->ALIGNABLE_ANNOTATION->ANNOTATION_VALUE);
                    if ($val !== '') {
                        $textParts[] = $val;
                    }
                }
            }

            if (empty($textParts)) {
                return ['success' => false, 'text' => null, 'error' => 'Gebaar-voor-gebaar tier is empty'];
            }

            return ['success' => true, 'text' => json_encode($textParts, JSON_UNESCAPED_UNICODE), 'error' => null];
        }
    }

    return ['success' => false, 'text' => null, 'error' => 'Gebaar-voor-gebaar tier not found'];
}

/**
 * Extract Signbank ID glossen tier text from EAF file
 *
 * @param string $eafPath Path to EAF file
 * @return array ['success' => bool, 'text' => string|null, 'error' => string|null]
 */
function extractGlossesFromEaf($eafPath) {
    if (!file_exists($eafPath)) {
        return ['success' => false, 'text' => null, 'error' => 'File not found'];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($eafPath);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parse error';
        return ['success' => false, 'text' => null, 'error' => 'XML parse error: ' . trim($errorMsg)];
    }

    // Find Signbank ID glossen tier
    foreach ($xml->TIER as $tier) {
        if ((string)$tier['TIER_ID'] === 'Signbank ID glossen') {
            $textParts = [];
            foreach ($tier->ANNOTATION as $annotation) {
                if (isset($annotation->ALIGNABLE_ANNOTATION->ANNOTATION_VALUE)) {
                    $val = trim((string)$annotation->ALIGNABLE_ANNOTATION->ANNOTATION_VALUE);
                    if ($val !== '') {
                        $textParts[] = $val;
                    }
                }
            }

            if (empty($textParts)) {
                return ['success' => false, 'text' => null, 'error' => 'Signbank ID glossen tier is empty'];
            }

            return ['success' => true, 'text' => json_encode($textParts, JSON_UNESCAPED_UNICODE), 'error' => null];
        }
    }

    return ['success' => false, 'text' => null, 'error' => 'Signbank ID glossen tier not found'];
}

/**
 * Find EAF file for a sentence ID by looking up the video filename in matched_transcriptions
 * Excludes backup files
 *
 * @param mysqli $conn Database connection
 * @param int $sentenceId The sentence ID
 * @return string|null EAF file path or null if not found
 */
function findEafFileForSentence($conn, $sentenceId) {
    // Look up the video filename from matched_transcriptions
    $stmt = $conn->prepare("SELECT m_file FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'zin' AND added = '1' LIMIT 1");
    $stmt->bind_param("i", $sentenceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['m_file'])) {
        return null;
    }

    $videoFile = $row['m_file'];

    // Convert video filename to EAF filename (replace .wav/.mp4 with .eaf)
    $eafFilename = preg_replace('/\.(wav|mp4)$/i', '.eaf', $videoFile);

    // Check if EAF file exists (excluding backups)
    $eafPath = '/web/zin/eaf/zin/' . $eafFilename;

    if (file_exists($eafPath) && strpos($eafFilename, '_backup_') === false) {
        return $eafPath;
    }

    return null;
}

/**
 * Update sentence with EAF data
 *
 * @param mysqli $conn Database connection
 * @param int $sentenceId Sentence ID
 * @param array $stats Statistics array (passed by reference)
 * @param bool $isWeb Web execution mode
 */
function updateSentenceWithEafData($conn, $sentenceId, &$stats, $isWeb) {
    $eafPath = findEafFileForSentence($conn, $sentenceId);

    if (!$eafPath) {
        // Mark as no_eaf status
        $stmt = $conn->prepare("UPDATE sentences SET eaf_sync_status='no_eaf', eaf_synced_at=NOW() WHERE ID=?");
        $stmt->bind_param("i", $sentenceId);
        $stmt->execute();
        $stmt->close();
        $stats['no_eaf']++;
        logMessage("⊘ No EAF file for ID {$sentenceId}", $isWeb);
        return;
    }

    // Extract all three tiers — always sync regardless of which are empty
    $nedResult = extractNederlandsFromEaf($eafPath);
    $gvgResult = extractGvgFromEaf($eafPath);
    $glossResult = extractGlossesFromEaf($eafPath);

    $nedText = $nedResult['success'] ? $nedResult['text'] : '';
    $gvgText = $gvgResult['success'] ? $gvgResult['text'] : '[]';
    $glossText = $glossResult['success'] ? $glossResult['text'] : '[]';

    $stmt = $conn->prepare(
        "UPDATE sentences SET zinStringEAF=?, gvg=?, glosses=?, eaf_sync_status='synced', eaf_synced_at=NOW(), eaf_sync_error=NULL WHERE ID=?"
    );
    $stmt->bind_param("sssi", $nedText, $gvgText, $glossText, $sentenceId);
    $stmt->execute();
    $stmt->close();
    $stats['synced']++;

    $displayNed = strlen($nedText) > 40 ? substr($nedText, 0, 37) . '...' : $nedText;
    $displayGvg = strlen($gvgText) > 40 ? substr($gvgText, 0, 37) . '...' : $gvgText;
    $displayGloss = strlen($glossText) > 40 ? substr($glossText, 0, 37) . '...' : $glossText;
    logMessage("✓ Synced ID {$sentenceId}: ned=\"{$displayNed}\" gvg=\"{$displayGvg}\" glosses=\"{$displayGloss}\"", $isWeb);

    $stats['processed']++;
}

// Only run main execution when script is called directly (not require_once'd)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__) || (php_sapi_name() === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__))) {

// Detect execution mode
$isWeb = php_sapi_name() !== 'cli';

// Parse parameters
$limit = 100;
$force = false;
$specific_sentence_id = null;

if ($isWeb) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $force = isset($_GET['force']);
    $specific_sentence_id = isset($_GET['sentence_id']) ? (int)$_GET['sentence_id'] : null;
    header('Content-Type: text/plain; charset=utf-8');
} else {
    $options = getopt('', ['limit::', 'force', 'sentence_id::']);
    if (isset($options['limit'])) {
        $limit = (int)$options['limit'];
    }
    $force = isset($options['force']);
    if (isset($options['sentence_id'])) {
        $specific_sentence_id = (int)$options['sentence_id'];
    }
}

// Statistics tracking
$stats = [
    'processed' => 0,
    'synced' => 0,
    'errors' => 0,
    'no_eaf' => 0
];

try {
    // Connect to database
    $conn = new mysqli($servername, $username, $password, $database);

    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $conn->set_charset("utf8mb4");

    logMessage("=== EAF to Database Sync ===", $isWeb);
    logMessage("Mode: " . ($isWeb ? "Web" : "CLI"), $isWeb);
    logMessage("Limit: " . ($limit > 0 ? $limit : "unlimited"), $isWeb);
    logMessage("Force: " . ($force ? "yes" : "no"), $isWeb);
    if ($specific_sentence_id) {
        logMessage("Target: Sentence ID {$specific_sentence_id}", $isWeb);
    }
    logMessage("", $isWeb);

    if ($specific_sentence_id) {
        // Process single sentence
        $stmt = $conn->prepare("SELECT ID FROM sentences WHERE ID = ?");
        $stmt->bind_param("i", $specific_sentence_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            updateSentenceWithEafData($conn, $row['ID'], $stats, $isWeb);
        } else {
            logMessage("✗ Sentence ID {$specific_sentence_id} not found", $isWeb);
        }

        $stmt->close();
    } else {
        // Process batch
        $whereClause = !$force ? "WHERE eaf_sync_status IN ('pending', 'error')" : "";
        $limitClause = $limit > 0 ? "LIMIT {$limit}" : "";

        $sql = "SELECT ID FROM sentences {$whereClause} ORDER BY ID ASC {$limitClause}";
        $result = $conn->query($sql);

        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }

        logMessage("Processing " . $result->num_rows . " sentences...", $isWeb);
        logMessage("", $isWeb);

        while ($row = $result->fetch_assoc()) {
            updateSentenceWithEafData($conn, $row['ID'], $stats, $isWeb);
        }
    }

    // Output statistics
    logMessage("", $isWeb);
    logMessage("=== Summary ===", $isWeb);
    logMessage("Processed: {$stats['processed']}", $isWeb);
    logMessage("Synced: {$stats['synced']}", $isWeb);
    logMessage("Errors: {$stats['errors']}", $isWeb);
    logMessage("No EAF: {$stats['no_eaf']}", $isWeb);

    $conn->close();

    // Send heartbeat to Client Monitor API
    sendHeartbeat('success', "Processed: {$stats['processed']}, Synced: {$stats['synced']}, Errors: {$stats['errors']}", $stats);

} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage(), $isWeb);

    // Send error heartbeat to Client Monitor API
    sendHeartbeat('error', $e->getMessage(), ['error_type' => get_class($e)]);

    exit(1);
}

} // end direct-execution guard

/**
 * Send heartbeat to Client Monitor API
 *
 * @param string $status Status of the execution (success/error)
 * @param string $message Description message
 * @param array $stats Optional statistics
 */
function sendHeartbeat($status, $message, $stats = []) {
    $apiUrl = 'https://signcollect.nl/client_monitor_api/api.php?action=heartbeat';
    $clientId = 'sync-eaf-to-database';

    $data = [
        'client_id' => $clientId,
        'metadata' => array_merge([
            'last_run' => date('c'),
            'hostname' => gethostname(),
            'status' => $status,
            'message' => $message
        ], $stats)
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    curl_close($ch);

    // Silent failure - don't interrupt main script if heartbeat fails
}
