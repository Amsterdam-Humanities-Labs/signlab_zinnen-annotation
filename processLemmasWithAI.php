<?php
/**
 * Process sentences with Google Gemini API to generate lemmas
 * Can be run from command line or via web interface
 */

// Check if called from web
$isWeb = php_sapi_name() !== 'cli';

if ($isWeb) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
    ob_implicit_flush(true);
    ob_end_flush();
} else {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
}

// Configuration. The Gemini key comes from the environment or GEMINI_API_KEY
// in the untracked .env next to this file (see .env.example). Never a literal.
function gemini_api_key(): string {
    $key = getenv('GEMINI_API_KEY');
    if ($key !== false && $key !== '') return $key;
    foreach (@file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*GEMINI_API_KEY\s*=\s*(.*)$/', $line, $m)) return trim($m[1], "\"' ");
    }
    return '';
}
define('GEMINI_API_KEY', gemini_api_key());
define('GEMINI_MODEL', 'gemini-2.5-flash');
define('BATCH_SIZE', 10); // Number of sentences to process at once
define('RATE_LIMIT_DELAY', 1); // Seconds between API calls

// Database connection
require_once dirname(__DIR__) . '/mysql_config.php';
$dbname = $database;

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

$conn->set_charset("utf8mb4");

// Get parameters from command line or web
if ($isWeb) {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0; // 0 means no limit (process all)
    $force = isset($_GET['force']);
    $specific_sentence_id = isset($_GET['sentence_id']) ? intval($_GET['sentence_id']) : null;
} else {
    $options = getopt("", ["limit:", "force", "sentence_id:"]);
    $limit = isset($options['limit']) ? intval($options['limit']) : 0; // 0 means no limit (process all)
    $force = isset($options['force']);
    $specific_sentence_id = isset($options['sentence_id']) ? intval($options['sentence_id']) : null;
}

// Statistics
$stats = [
    'processed' => 0,
    'errors' => 0,
    'skipped' => 0,
    'start_time' => microtime(true)
];

/**
 * Call Google Gemini API to get lemmas for a sentence
 */
function getLemmasFromGemini($sentence) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key=" . GEMINI_API_KEY;
    
    $prompt = "Given the Dutch sentence \"$sentence\", provide the lemma (dictionary form) for each word following these rules:\n\n"
            . "1. For diminutives ending in -je, -tje, -pje, -kje, etc., convert to the plural form:\n"
            . "   - boekje → boeken\n"
            . "   - boek → boeken\n"
            . "   - huisje → huizen\n"
            . "   - kindje → kinderen\n\n"
            . "2. For separable verbs, combine them into one lemma:\n"
            . "   - 'doe ... aan' → aandoen\n"
            . "   - 'ga ... weg' → weggaan\n"
            . "   - 'kom ... binnen' → binnenkomen\n\n"
            . "3. For words with slash (/) treat as separate words:\n"
            . "   - bliepen/scannen → lemmas: bliepen, scannen\n\n"
            . "4. Standard lemmatization rules:\n"
            . "   - Verbs to infinitive: loopt → lopen\n"
            . "   - Nouns to singular (except diminutives which go to plural)\n"
            . "   - Keep proper nouns capitalized\n\n"
            . "Respond in JSON format with an array called \"lemmas\" where each element has \"word\" and \"lemma\" fields. "
            . "Keep the original capitalization of the words but provide lowercase lemmas unless they are proper nouns.";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'response_mime_type' => 'application/json'
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("API request failed with code $httpCode: $response");
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception("Unexpected API response format");
    }
    
    $lemmaData = json_decode($result['candidates'][0]['content']['parts'][0]['text'], true);
    
    if (!isset($lemmaData['lemmas']) || !is_array($lemmaData['lemmas'])) {
        throw new Exception("Invalid lemma data format");
    }
    
    return $lemmaData['lemmas'];
}

/**
 * Process lemmas and update database
 */
function processLemmas($conn, $sentenceId, $lemmaData) {
    $conn->begin_transaction();
    
    try {
        $lemmaIds = [];
        
        // Extract just the lemmas in order
        foreach ($lemmaData as $item) {
            $lemma = trim($item['lemma']);
            
            if (empty($lemma)) {
                continue;
            }
            
            // Check if lemma already exists
            $checkStmt = $conn->prepare("SELECT id FROM lemmaTable WHERE lemma = ?");
            $checkStmt->bind_param("s", $lemma);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Lemma exists, use existing ID
                $lemmaIds[] = $row['id'];
            } else {
                // Insert new lemma
                $insertStmt = $conn->prepare("INSERT INTO lemmaTable (lemma) VALUES (?)");
                $insertStmt->bind_param("s", $lemma);
                $insertStmt->execute();
                $lemmaIds[] = $conn->insert_id;
                $insertStmt->close();
            }
            
            $checkStmt->close();
        }
        
        // Update sentence with lemma list and processing status
        $lemmaListJson = json_encode($lemmaIds);
        $updateStmt = $conn->prepare("UPDATE sentences SET lemmaList = ?, lemma_processed = TRUE, lemma_processed_at = NOW(), lemma_error = NULL WHERE id = ?");
        $updateStmt->bind_param("si", $lemmaListJson, $sentenceId);
        $updateStmt->execute();
        $updateStmt->close();
        
        $conn->commit();
        
        return [
            'success' => true,
            'lemma_count' => count($lemmaIds),
            'lemma_ids' => $lemmaIds
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Main processing function
 */
function processSentences($conn, $limit, $force, $specific_sentence_id) {
    global $stats;
    
    // Build query
    $where_conditions = ["zinString IS NOT NULL", "zinString != ''"];
    
    if (!$force) {
        $where_conditions[] = "(lemma_processed = FALSE OR lemma_processed IS NULL)";
    }
    
    if ($specific_sentence_id) {
        $where_conditions[] = "id = $specific_sentence_id";
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    $sql = "SELECT id, zinString FROM sentences WHERE $where_clause";
    if ($limit > 0) {
        $sql .= " LIMIT $limit";
    }
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $sentences = [];
    while ($row = $result->fetch_assoc()) {
        $sentences[] = $row;
    }
    
    $startData = [
        'status' => 'starting',
        'total_sentences' => count($sentences)
    ];
    
    if ($isWeb) {
        echo "data: " . json_encode($startData) . "\n\n";
        flush();
    } else {
        echo json_encode($startData, JSON_PRETTY_PRINT) . "\n\n";
    }
    
    // Process each sentence
    foreach ($sentences as $index => $sentence) {
        $sentenceId = $sentence['id'];
        $sentenceText = $sentence['zinString'];
        
        $processingData = [
            'processing' => $sentenceId,
            'progress' => ($index + 1) . '/' . count($sentences),
            'sentence' => mb_substr($sentenceText, 0, 50) . (mb_strlen($sentenceText) > 50 ? '...' : '')
        ];
        
        if ($isWeb) {
            echo "data: " . json_encode($processingData) . "\n\n";
            flush();
        } else {
            echo json_encode($processingData) . "\n";
        }
        
        try {
            // Skip empty sentences
            if (trim($sentenceText) === '') {
                $stats['skipped']++;
                continue;
            }
            
            // Get lemmas from Gemini API
            $lemmaData = getLemmasFromGemini($sentenceText);
            
            // Process and store lemmas
            $result = processLemmas($conn, $sentenceId, $lemmaData);
            
            $stats['processed']++;
            
            $successData = [
                'success' => true,
                'sentence_id' => $sentenceId,
                'lemma_count' => $result['lemma_count']
            ];
            
            if ($isWeb) {
                echo "data: " . json_encode($successData) . "\n\n";
                flush();
            } else {
                echo json_encode($successData) . "\n";
            }
            
            // Rate limiting
            if ($index < count($sentences) - 1) {
                sleep(RATE_LIMIT_DELAY);
            }
            
        } catch (Exception $e) {
            $stats['errors']++;
            
            // Log error to database
            $errorStmt = $conn->prepare("UPDATE sentences SET lemma_error = ?, lemma_processed_at = NOW() WHERE id = ?");
            $errorMsg = $e->getMessage();
            $errorStmt->bind_param("si", $errorMsg, $sentenceId);
            $errorStmt->execute();
            $errorStmt->close();
            
            $errorData = [
                'error' => true,
                'sentence_id' => $sentenceId,
                'message' => $e->getMessage()
            ];
            
            if ($isWeb) {
                echo "data: " . json_encode($errorData) . "\n\n";
                flush();
            } else {
                echo json_encode($errorData) . "\n";
            }
        }
    }
    
    return $stats;
}

// Run the processing
try {
    $stats = processSentences($conn, $limit, $force, $specific_sentence_id);
    
    $stats['end_time'] = microtime(true);
    $stats['duration'] = round($stats['end_time'] - $stats['start_time'], 2);
    
    $completeData = [
        'status' => 'completed',
        'statistics' => $stats
    ];
    
    if ($isWeb) {
        echo "data: " . json_encode($completeData) . "\n\n";
        flush();
    } else {
        echo "\n" . json_encode($completeData, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    $errorData = [
        'status' => 'error',
        'error' => $e->getMessage()
    ];
    
    if ($isWeb) {
        echo "data: " . json_encode($errorData) . "\n\n";
        flush();
    } else {
        echo json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
    }
}

$conn->close();
?>