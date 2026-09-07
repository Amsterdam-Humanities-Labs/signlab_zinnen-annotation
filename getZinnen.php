<?php
header('Content-Type: application/json');

// Include the MySQL configuration file
include '../mysql_config.php';
require_once __DIR__ . '/mocapFiles.php';

//disable php warnings
error_reporting(E_ERROR | E_PARSE);
// Create a new MySQLi connection
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

// Check for a connection error
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $conn->connect_error]);
    exit();
}

// Determine the action to perform based on GET or POST parameters
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Handle actions based on the 'action' parameter
switch ($action) {
    case 'fetchThemas':
        fetchThemas($conn);
        break;

    case 'duplicate':
        duplicateRow($conn);
        break;

    case 'fetchRow':
        fetchRow($conn);
        break;

    case 'deleteVideo':
        deleteVideo($conn);
        break;

    case 'delete':
        deleteMatchedTranscription($conn);
        break;

    case 'editZin':
        editZin($conn);
        break;

    case 'downloadEAF':
        downloadEAF($conn);
        break;

    case 'downloadMP4':
        downloadMP4($conn);
        break;

    case 'uploadEAF':
        uploadEAF($conn);
        break;

    case 'fetchVideos':
        fetchVideos($conn);
        break;

    case 'restoreVideo':
        restoreVideo($conn);
        break;

    case 'permanentDeleteVideo':
        permanentDeleteVideo($conn);
        break;

    case 'saveComments':
        saveComments($conn);
        break;

    case 'saveStatusVideo':
        saveStatusVideo($conn);
        break;
    case 'saveStatusAnnotatie':
        saveStatusAnnotatie($conn);
        break;
    case 'saveStatusGlos':
        saveStatusGlos($conn);
        break;
    case 'saveStatusGvg':
        saveStatusGvg($conn);
        break;
    case 'saveMcpStatusPostprocessing':
        saveMcpStatusPostprocessing($conn);
        break;
    case 'saveMcpStatusTijdAnnotatie':
        saveMcpStatusTijdAnnotatie($conn);
        break;
    case 'saveMcpStatusTijdAnnotatieGvg':
        saveMcpStatusTijdAnnotatieGvg($conn);
        break;
    case 'updateStatusForThema':
        updateStatusForThema($conn);
        break;

    case 'deleteZin':
        deleteZin($conn);
        break;
    case 'deleteEAF':
        deleteEAF($conn);
        break;
    case 'fetchLogs':
        fetchLogs($conn);
        break;
    case 'fetchSentenceLog':
        fetchSentenceLog($conn);
        break;
    case 'fetchActionStats':
        fetchActionStats($conn);
        break;
    case 'generateJSONFeed':
        generateJSONFeed($conn);
        break;
    case 'downloadZIP':
        downloadZIP($conn);
        break;
    case 'fetchSubtitles':
        fetchSubtitles($conn);
        break;
    case 'countZinnen':
        countZinnen($conn);
        break;
    case 'detailedStats':
        detailedStats($conn);
        break;
    case 'listVideosWithoutMocap':
        listVideosWithoutMocap($conn);
        break;
    case 'listMocapFiles':
        listMocapFiles($conn);
        break;
    case 'saveSubtitlesAndEAFFiles':
        saveSubtitlesAndEAFFiles($conn);
        break;
    case 'verifySubtitles':
        verifySubtitles($conn);
        break;
    case 'findGloss':
        findGloss($conn);
        break;
    case 'findgvg':
        findgvg($conn);
        break;
    case 'fetchZinArrayByFilename': // New case
        fetchZinArrayByFilename($conn);
        break;
    case 'processSegmentation':
        processSegmentation($conn);
        break;
    case 'processSegmentationStreaming':
        processSegmentationStreaming($conn);
        break;
    case 'getLatestMocapFile':
        getLatestMocapFile($conn);
        break;
    case 'fetchDeletedVideos':
        fetchDeletedVideos($conn);
        break;
    case 'fetchNoVideoWorklist':
        fetchNoVideoWorklist($conn);
        break;
    case '':
        // Default action: Fetch Sentences
        fetchSentences($conn);
    break;

    

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
        break;
}
function deleteZin($conn){
    $rowId = $_GET['rowId'] ?? '';

    logAction($conn, 'deletezin', ['rowId' => $rowId]);
    if (!empty($rowId)) {
        // SQL query to delete the sentence from the database
        $sql = "DELETE FROM sentences WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("i", $rowId);
        if ($stmt->execute()) {
            // Return success
            echo json_encode(['success' => true]);
        } else {
            // Return error if deletion fails
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        // Return an error if row ID is missing
        echo json_encode(['success' => false, 'error' => 'Invalid row ID']);
    }
}


function deleteEAF($conn){
    $filename = $_GET['filename'] ?? '';

    logAction($conn, 'deleteEAF', ['filename' => $filename]);

    $filename = basename($filename);
    $eafDir = __DIR__ . '/eaf/zin/';
    //replace .wav with .eaf
    $filename = preg_replace('/\\.(wav)$/i', '.eaf', $filename);
    $eafPath = $eafDir . $filename;
    //then we are going to rename the file to .bak
    $eafPathBak = $eafDir . $filename . '.bak';
    if (file_exists($eafPath)) {
        //rename the file to .bak
        rename($eafPath, $eafPathBak);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'EAF file not found.']);
    }




}
function updateStatusForThema($conn){
    $thema = $_POST['thema'] ?? '';
    $status = $_POST['status'] ?? '';

    logAction($conn, 'updateStatusForThema', ['thema' => $thema, 'status' => $status]);

    if (!empty($thema) && !empty($status)) {
        // SQL query to update the sentence in the database
        $sql = "UPDATE sentences SET status_video = ? WHERE thema = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("ss", $status, $thema);
        if ($stmt->execute()) {
            // Return success
            echo json_encode(['success' => true]);
        } else {
            // Return error if update fails
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        // Return an error if parameters are missing
        echo json_encode(['success' => false, 'error' => 'Invalid input, received thema: ' . $thema . ' and status: ' . $status]);
    }
    exit();
}


function saveStatusVideo($conn){
    $rowId = $_POST['rowId'] ?? '';
    $statusVideo = $_POST['statusVideo'] ?? '';

    logAction($conn, 'saveStatusVideo', ['rowId' => $rowId, 'statusVideo' => $statusVideo]);

    if (!empty($rowId)) {
        // SQL query to update the sentence in the database
        $sql = "UPDATE sentences SET status_video = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $statusVideo, $rowId);
        if ($stmt->execute()) {
            // Return success
            echo json_encode(['success' => true]);
        } else {
            // Return error if update fails
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        // Return an error if parameters are missing
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}
function saveStatusAnnotatie($conn){
    $rowId = $_POST['rowId'] ?? '';
    $statusAnnotatie = $_POST['statusAnnotatie'] ?? '';

    logAction($conn, 'saveStatusAnnotatie', ['rowId' => $rowId, 'statusAnnotatie' => $statusAnnotatie]);

    if (!empty($rowId)) {
        $sql = "UPDATE sentences SET status_annotatie = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $statusAnnotatie, $rowId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}

function saveStatusGlos($conn){
    $rowId = $_POST['rowId'] ?? '';
    $statusGlos = $_POST['statusGlos'] ?? '';

    logAction($conn, 'saveStatusGlos', ['rowId' => $rowId, 'statusGlos' => $statusGlos]);
    if (!empty($rowId)) {
        $sql = "UPDATE sentences SET status_glos = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $statusGlos, $rowId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}

function saveStatusGvg($conn){
    $rowId = $_POST['rowId'] ?? '';
    $statusGvg = $_POST['statusGvg'] ?? '';

    logAction($conn, 'saveStatusGvg', ['rowId' => $rowId, 'statusGvg' => $statusGvg]);

    if (!empty($rowId)) {
        $sql = "UPDATE sentences SET status_gvg = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $statusGvg, $rowId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}

function saveMcpStatusPostprocessing($conn){
    $rowId = $_POST['rowId'] ?? '';
    $mcpStatusPostprocessing = $_POST['mcpStatusPostprocessing'] ?? '';

    logAction($conn, 'saveMcpStatusPostprocessing', ['rowId' => $rowId, 'mcpStatusPostprocessing' => $mcpStatusPostprocessing]);

    if (!empty($rowId)) {
        // Stored numerically: 1 = Klaar, 2 = Check nodig, NULL = Niet Klaar.
        // Accept legacy string labels too, in case a cached page submits them.
        if ($mcpStatusPostprocessing === 'Klaar') {
            $mcpStatusPostprocessing = '1';
        } elseif ($mcpStatusPostprocessing === 'Check nodig') {
            $mcpStatusPostprocessing = '2';
        } elseif ($mcpStatusPostprocessing === 'Niet Klaar') {
            $mcpStatusPostprocessing = '__NULL__';
        }
        if ($mcpStatusPostprocessing === '__NULL__' || $mcpStatusPostprocessing === '') {
            $sql = "UPDATE sentences SET mcp_status_postprocessing = NULL WHERE ID = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit();
            }
            $stmt->bind_param("i", $rowId);
        } else {
            $mcpValue = (int)$mcpStatusPostprocessing;
            $sql = "UPDATE sentences SET mcp_status_postprocessing = ? WHERE ID = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
                exit();
            }
            $stmt->bind_param("ii", $mcpValue, $rowId);
        }
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}

function saveMcpStatusTijdAnnotatie($conn){
    $rowId = $_POST['rowId'] ?? '';
    $mcpStatusTijdAnnotatie = $_POST['mcpStatusTijdAnnotatie'] ?? '';

    logAction($conn, 'saveMcpStatusTijdAnnotatie', ['rowId' => $rowId, 'mcpStatusTijdAnnotatie' => $mcpStatusTijdAnnotatie]);

    if (!empty($rowId)) {
        $sql = "UPDATE sentences SET mcp_status_tijd_annotatie = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $mcpStatusTijdAnnotatie, $rowId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}

function saveMcpStatusTijdAnnotatieGvg($conn){
    $rowId = $_POST['rowId'] ?? '';
    $mcpStatusTijdAnnotatieGvg = $_POST['mcpStatusTijdAnnotatieGvg'] ?? '';

    logAction($conn, 'saveMcpStatusTijdAnnotatieGvg', ['rowId' => $rowId, 'mcpStatusTijdAnnotatieGvg' => $mcpStatusTijdAnnotatieGvg]);

    if (!empty($rowId)) {
        $sql = "UPDATE sentences SET mcp_status_tijd_annotatie_gvg = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $mcpStatusTijdAnnotatieGvg, $rowId);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}

function saveComments($conn){
    $rowId = $_POST['rowId'] ?? '';
    $comments = $_POST['comments'] ?? '';

    logAction($conn, 'saveComments', ['rowId' => $rowId, 'comments' => $comments]);

    if (!empty($rowId) && !empty($comments)) {
        // SQL query to update the sentence in the database
        $sql = "UPDATE sentences SET comments = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $comments, $rowId);
        if ($stmt->execute()) {
            // Return success
            echo json_encode(['success' => true]);
        } else {
            // Return error if update fails
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        // Return an error if parameters are missing
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}



/**
 * Fetch Unique Thema Values
 */
function fetchThemas($conn) {
    // SQL query to select all distinct non-empty thema values
    $sql = "SELECT DISTINCT TRIM(thema) AS thema_trimmed FROM sentences WHERE thema IS NOT NULL AND TRIM(thema) != ''";
    $result = $conn->query($sql);
    $themas = [];

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Query failed: ' . $conn->error]);
        exit();
    }

    // Fetch the thema values into an array
    while ($row = $result->fetch_assoc()) {
        $thema = $row['thema_trimmed'];
        if (!empty($thema)) {
            $themas[] = $thema;
        }
    }

    // Return the thema values as a JSON response
    echo json_encode(['success' => true, 'themas' => $themas]);
    exit();
}

/**
 * Duplicate a Row and Move a Video
 */
function duplicateRow($conn) {
    $videoCenter = $_GET['videoCenter'] ?? '';
    $rowId = $_GET['rowId'] ?? '';
    logAction($conn, 'duplicate', ['videoCenter' => $videoCenter, 'rowId' => $rowId]);

    if (!empty($videoCenter) && !empty($rowId)) {
        // Begin a database transaction to ensure data integrity
        $conn->begin_transaction();

        try {
            // Duplicate the row in the sentences table
            $sql = "INSERT INTO sentences (zinID, glosArray, zinArray, sortArray, thema, userId)
                    SELECT zinID, glosArray, zinArray, sortArray, thema, userId FROM sentences WHERE ID = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("i", $rowId);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            // Get the new row ID
            $newRowId = $conn->insert_id;
            $stmt->close();

            // Update the matched_transcriptions with the newRowId where m_file matches videoCenter
            $sql = "UPDATE matched_transcriptions 
                    SET l_transcription = ?, m_transcription = ?, r_transcription = ?, 
                        a_transcription = ?, b_transcription = ?, definitive_outcome = ?
                    WHERE m_file = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            // Bind parameters: all transcription fields set to the new row ID
            $stmt->bind_param("sssssss", $newRowId, $newRowId, $newRowId, $newRowId, $newRowId, $newRowId, $videoCenter);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            // Commit the transaction
            $conn->commit();

            // Refresh video counts for both old and new rows
            refreshVideoCount($conn, $rowId);
            refreshVideoCount($conn, $newRowId);

            // Return success with the new row ID
            echo json_encode(['success' => true, 'newRowId' => $newRowId]);
        } catch (Exception $e) {
            // Roll back the transaction on error
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        // Return an error if parameters are missing
        echo json_encode(['success' => false, 'error' => 'Invalid row ID or video center provided.']);
    }
    exit();
}

function fetchVideos($conn){
    $rowId = $_GET['rowId'] ?? '';
    $includeDeleted = $_GET['includeDeleted'] ?? 'false';
    
    if (!empty($rowId)) {
        // Fetch the videos from the matched_transcriptions table
        // Include all videos with their 'added' status for proper filtering
        if ($includeDeleted === 'true') {
            // Include all videos regardless of 'added' status
            $sql = "SELECT *, ID as video_id FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'Zin' ORDER BY added DESC, ID ASC";
        } else {
            // Only include active videos (added = 1)
            $sql = "SELECT *, ID as video_id FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'Zin' AND added = 1 ORDER BY ID ASC";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $rowId);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
            exit();
        }
        $result = $stmt->get_result();
        $videos = [];
        while ($row = $result->fetch_assoc()) {
            $videos[] = $row;
        }

        echo json_encode(['success' => true, 'videos' => $videos]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No row ID provided']);
    }
}

/**
 * Refresh the cached video_count for a sentence after video add/delete/restore.
 * Pass either a matched_transcriptions ID or a sentence ID directly.
 */
function refreshVideoCount($conn, $sentenceId) {
    if (empty($sentenceId)) return;
    $stmt = $conn->prepare("UPDATE sentences SET video_count = (SELECT COUNT(*) FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'Zin' AND added = '1') WHERE ID = ?");
    $stmt->bind_param("si", $sentenceId, $sentenceId);
    $stmt->execute();
    $stmt->close();
}

function refreshVideoCountByVideoId($conn, $videoId) {
    if (empty($videoId)) return;
    $stmt = $conn->prepare("SELECT m_transcription FROM matched_transcriptions WHERE ID = ?");
    $stmt->bind_param("i", $videoId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        refreshVideoCount($conn, $row['m_transcription']);
    }
    $stmt->close();
}

function restoreVideo($conn) {
    $videoId = $_POST['videoId'] ?? '';
    logAction($conn, 'restoreVideo', ['videoId' => $videoId]);
    
    if (!empty($videoId)) {
        // Set 'added' to 1 to restore the video
        $sql = "UPDATE matched_transcriptions SET added = 1 WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        
        $stmt->bind_param("i", $videoId);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                refreshVideoCountByVideoId($conn, $videoId);
                echo json_encode(['success' => true, 'message' => 'Video restored successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'No video found with the given ID or video is already active']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Video ID is required']);
    }
    exit();
}

/**
 * Return a paginated list of deleted zin videos (zOg='Zin', added='0'),
 * each with its sentence text, video MP4 URLs and the gloss SRT URL
 * (when the SRT exists on disk). Used by undelete_videos.html.
 */
function fetchDeletedVideos($conn) {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = (int)($_GET['limit'] ?? 30);
    if ($limit < 1)   $limit = 30;
    if ($limit > 200) $limit = 200;
    $offset = ($page - 1) * $limit;

    $mediaBaseURL    = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/';
    $rawMediaBaseURL = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/';
    $srtBaseURL      = 'https://signcollect.nl/zin/eaf/zin/';
    $eafDir          = __DIR__ . '/eaf/zin/';

    // Total number of deleted zin videos (for pagination)
    $totalRow = $conn->query("SELECT COUNT(*) AS cnt FROM matched_transcriptions WHERE zOg = 'Zin' AND added = '0'");
    $total = $totalRow ? (int)$totalRow->fetch_assoc()['cnt'] : 0;

    $sql = "SELECT mt.ID AS video_id, mt.m_file, mt.a_file, mt.b_file, mt.l_file, mt.r_file,
                   mt.post_processed, mt.m_transcription,
                   s.zinArray, s.thema, s.ID AS sentence_id
            FROM matched_transcriptions mt
            LEFT JOIN sentences s ON mt.m_transcription = s.ID
            WHERE mt.zOg = 'Zin' AND mt.added = '0'
            ORDER BY mt.ID DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $videos = [];
    while ($row = $result->fetch_assoc()) {
        // Decode the sentence words into a readable sentence
        $zinWords = json_decode($row['zinArray'] ?? '[]', true);
        $zin = (json_last_error() === JSON_ERROR_NONE && is_array($zinWords) && count($zinWords))
            ? ucfirst(implode(' ', $zinWords))
            : '(geen zin gekoppeld)';

        $base      = preg_replace('/\.(wav|mp4)$/i', '', basename($row['m_file']));
        $mediaBase = ($row['post_processed'] == 1) ? $mediaBaseURL : $rawMediaBaseURL;

        $toUrl = function ($f) use ($mediaBase) {
            if (empty($f)) return null;
            return $mediaBase . preg_replace('/\.(wav)$/i', '.mp4', $f);
        };

        $glossSrtName = $base . '_Signbank_ID_glossen.srt';
        $hasGloss     = file_exists($eafDir . $glossSrtName);

        $videos[] = [
            'video_id'      => (int)$row['video_id'],
            'sentence_id'   => $row['sentence_id'],
            'zin'           => $zin,
            'thema'         => ucfirst(strtolower($row['thema'] ?? '')) ?: 'Uncategorized',
            'base'          => $base,
            'm_url'         => $toUrl($row['m_file']),
            'videos'        => [
                'a_file' => $toUrl($row['a_file']),
                'b_file' => $toUrl($row['b_file']),
                'm_file' => $toUrl($row['m_file']),
                'l_file' => $toUrl($row['l_file']),
                'r_file' => $toUrl($row['r_file']),
            ],
            'gloss_srt_url' => $hasGloss ? ($srtBaseURL . $glossSrtName) : null,
            'has_gloss'     => $hasGloss,
        ];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'page'    => $page,
        'limit'   => $limit,
        'count'   => count($videos),
        'videos'  => $videos,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

/**
 * Live equivalent of the (previously static) no_video_worklist.json feed.
 *
 * Returns every zin sentence that still has one or more DELETED zin videos
 * (added='0', on disk, restorable) but NO active (added='1') video — i.e.
 * the sentence currently has no usable take. Sentences that already have an
 * active copy are excluded (they are "already filmed"). Grouped per sentence
 * with all restorable takes so the werklijst reflects the live DB and a
 * restored video disappears on refresh.
 */
function fetchNoVideoWorklist($conn) {
    $mediaBaseURL    = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/';
    $rawMediaBaseURL = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/';
    $srtBaseURL      = 'https://signcollect.nl/zin/eaf/zin/';
    $eafDir          = __DIR__ . '/eaf/zin/';

    // Pass A: sentence IDs that already have an active (added='1') zin video —
    // these are "already filmed" and must be excluded. Done as a separate flat
    // query because a correlated NOT EXISTS subquery here is ~100x slower.
    $active = [];
    $ares = $conn->query("SELECT DISTINCT m_transcription FROM matched_transcriptions
                          WHERE zOg = 'Zin' AND added = '1'");
    if (!$ares) {
        echo json_encode(['success' => false, 'error' => 'Query failed: ' . $conn->error]);
        exit();
    }
    while ($a = $ares->fetch_assoc()) { $active[(int)$a['m_transcription']] = true; }
    $ares->free();

    // Pass B: every deleted (restorable) zin video, joined to its sentence.
    $sql = "SELECT mt.ID AS video_id, mt.m_file, mt.post_processed,
                   s.ID AS sentence_id, s.zinArray, s.thema,
                   s.status_video, s.status_annotatie, s.status_glos, s.status_gvg
            FROM matched_transcriptions mt
            JOIN sentences s ON mt.m_transcription = s.ID
            WHERE mt.zOg = 'Zin' AND mt.added = '0'
            ORDER BY s.ID, mt.ID";
    $result = $conn->query($sql);
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Query failed: ' . $conn->error]);
        exit();
    }

    $bySentence = [];   // sentence_id => entry (preserves first-seen order)
    while ($row = $result->fetch_assoc()) {
        $sid = (int)$row['sentence_id'];
        if (isset($active[$sid])) { continue; }   // already has an active take
        if (!isset($bySentence[$sid])) {
            $zinWords = json_decode($row['zinArray'] ?? '[]', true);
            $zin = (json_last_error() === JSON_ERROR_NONE && is_array($zinWords) && count($zinWords))
                ? implode(' ', $zinWords)
                : '(geen zin gekoppeld)';
            $bySentence[$sid] = [
                'sentence_id' => $sid,
                'zin'         => $zin,
                'thema'       => ucfirst(strtolower($row['thema'] ?? '')) ?: 'Uncategorized',
                'statuses'    => [
                    'video'     => $row['status_video']     ?? '',
                    'annotatie' => $row['status_annotatie'] ?? '',
                    'glos'      => $row['status_glos']       ?? '',
                    'gvg'       => $row['status_gvg']        ?? '',
                ],
                'takes'       => [],
            ];
        }

        $base      = preg_replace('/\.(wav|mp4)$/i', '', basename($row['m_file']));
        $mediaBase = ($row['post_processed'] == 1) ? $mediaBaseURL : $rawMediaBaseURL;
        $mUrl      = $mediaBase . preg_replace('/\.(wav)$/i', '.mp4', $row['m_file']);

        $glossSrtName = $base . '_Signbank_ID_glossen.srt';
        $srtPath      = $eafDir . $glossSrtName;
        $hasGloss     = file_exists($srtPath);
        $glossItems   = $hasGloss ? substr_count(file_get_contents($srtPath), '-->') : 0;

        $bySentence[$sid]['takes'][] = [
            'video_id'      => (int)$row['video_id'],
            'm_url'         => $mUrl,
            'thumb_url'     => preg_replace('/\.mp4$/i', '.jpg', $mUrl),
            'gloss_items'   => $glossItems,
            'gloss_srt_url' => $hasGloss ? ($srtBaseURL . $glossSrtName) : null,
        ];
    }
    $result->free();

    $recoverable = array_values($bySentence);
    echo json_encode([
        'success'           => true,
        'recoverable_count' => count($recoverable),
        'recoverable'       => $recoverable,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

function permanentDeleteVideo($conn) {
    $videoId = $_POST['videoId'] ?? '';
    logAction($conn, 'permanentDeleteVideo', ['videoId' => $videoId]);
    
    if (!empty($videoId)) {
        // Set 'added' to 'DELETE' to permanently delete the video
        $sql = "UPDATE matched_transcriptions SET added = 'DELETE' WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        
        $stmt->bind_param("i", $videoId);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                refreshVideoCountByVideoId($conn, $videoId);
                echo json_encode(['success' => true, 'message' => 'Video permanently deleted']);
            } else {
                echo json_encode(['success' => false, 'error' => 'No video found with the given ID']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Video ID is required']);
    }
    exit();
}



/**
 * Fetch a Single Row's Data
 */
function fetchRow($conn) {
    $rowId = $_GET['rowId'] ?? '';

    if (!empty($rowId)) {
        // Fetch the row from the sentences table
        $sql = "SELECT 
                    sentences.ID, 
                    sentences.zinID, 
                    sentences.glosArray, 
                    sentences.zinArray, 
                    sentences.sortArray, 
                    sentences.thema,
                    matched_transcriptions.ID AS transcription_ID,
                    matched_transcriptions.m_file,
                    sentences.comments,
                    sentences.status_video
                FROM sentences
                LEFT JOIN matched_transcriptions 
                    ON sentences.ID = matched_transcriptions.m_transcription 
                    AND matched_transcriptions.zOg = 'Zin'
                    AND matched_transcriptions.added = 1
                WHERE sentences.ID = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }

        $stmt->bind_param("i", $rowId);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
            exit();
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if ($row) {
            // Process the row to structure videos properly
            $videos = [];
            if (!empty($row['transcription_ID'])) {
                $videos[] = [
                    'ID' => $row['transcription_ID'],
                    'm_file' => $row['m_file'],
                    'thumbnail' => str_replace('.wav', '.jpg', $row['m_file']) // Generate thumbnail path
                ];
            }

            // Structure the row data
            $structuredRow = [
                'ID' => $row['ID'],
                'zinID' => $row['zinID'],
                'glosArray' => $row['glosArray'],
                'zinArray' => json_decode($row['zinArray'], true),
                'sortArray' => $row['sortArray'],
                'thema' => $row['thema'],
                'videos' => $videos,
                'comments' => $row['comments'] ?? '',
                'status_video' => $row['status_video'] ?? ''
            ];

            // Add EAF to row
            $eafDir = __DIR__ . '/eaf/zin/';
            $eafPath = $eafDir . $row['zinID'] . '.eaf';
            if (file_exists($eafPath)) {
                //we are going to read EAF file
                $eafContent = file_get_contents($eafPath);
                //then we are going to extract the media_url from the EAF file
                //the tiers we have to extract are: 
                // <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="Signbank ID glossen"/>
                // <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="Nederlands"/>
                // <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="Gebaar-voor-gebaar"/>

                //then we have to calculate how many annotations are in every tier and then when there is at least 1 annotation, then we return eaf_signbank_glossen = 1, and for every tier
                //matching same condition also 1, otherwise 0 
                $eaf_signbank_glossen = 0;
                $eaf_nederlands = 0;
                $eaf_gebaar_voor_gebaar = 0;
                $eafContent = simplexml_load_string($eafContent);
                foreach($eafContent->TIER as $tier)
                {
                    if($tier['TIER_ID'] == "Signbank ID glossen")
                    {
                        //then we count how many annotations are in this tier
                        $eaf_signbank_glossen = count($tier->ANNOTATION);
                    }
                    if($tier['TIER_ID'] == "Nederlands")
                    {
                        $eaf_nederlands = count($tier->ANNOTATION);
                    }
                    if($tier['TIER_ID'] == "Gebaar-voor-gebaar")
                    {
                        $eaf_gebaar_voor_gebaar = count($tier->ANNOTATION);
                    }
                }



                $structuredRow['eaf'] = $eafPath;
                $structuredRow['eaf_signbank_glossen'] = $eaf_signbank_glossen;
                $structuredRow['eaf_nederlands'] = $eaf_nederlands;
                $structuredRow['eaf_gebaar_voor_gebaar'] = $eaf_gebaar_voor_gebaar;
            } else {
                $structuredRow['eaf'] = null;
            }

            echo json_encode(['success' => true, 'row' => $structuredRow]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Row not found.']);
        }

        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Row ID not provided.']);
    }
    exit();
}

/**
 * Delete a Video
 */
function deleteVideo($conn) {
    // Get the filename without extension
    $filename = $_GET['filename'] ?? '';

    logAction($conn, 'deleteVideo', ['filename' => $filename]);

    if (!empty($filename)) {
        // Use wildcard to match m_file containing the filename
        // Using LIKE '%filename%' to match anywhere in the string
        $likePattern = '%' . $filename . '%';

        // Prepare the SQL statement
        $sql = "UPDATE matched_transcriptions SET added = 0 WHERE m_file LIKE ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }

        // Collect affected sentence IDs before update
        $idStmt = $conn->prepare("SELECT DISTINCT m_transcription FROM matched_transcriptions WHERE m_file LIKE ? AND zOg = 'Zin' AND added = '1'");
        $idStmt->bind_param("s", $likePattern);
        $idStmt->execute();
        $idResult = $idStmt->get_result();
        $affectedSentenceIds = [];
        while ($idRow = $idResult->fetch_assoc()) {
            $affectedSentenceIds[] = $idRow['m_transcription'];
        }
        $idStmt->close();

        $stmt->bind_param("s", $likePattern);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                foreach ($affectedSentenceIds as $sid) {
                    refreshVideoCount($conn, $sid);
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No matching videos found to delete.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        }

        $stmt->close();
    } else {
        // Return an error if filename is missing
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
    }
    exit();
}

/**
 * Delete a Matched Transcription (Set 'added' to 0)
 */
function deleteMatchedTranscription($conn) {
    $rowId = $_POST['rowId'] ?? '';

    logAction($conn, 'deleteMatchedTranscription', ['rowId' => $rowId]);

    if (!empty($rowId)) {
        // SQL query to set 'added' to 0 for the specified matched_transcription ID
        $sql = "UPDATE matched_transcriptions SET added = 0 WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("i", $rowId);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                refreshVideoCountByVideoId($conn, $rowId);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No matching transcription found to delete.']);
            }
        } else {
            // Return error if deletion fails
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        // Return an error if row ID is missing
        echo json_encode(['success' => false, 'error' => 'Invalid row ID']);
    }
    exit();
}

/**
 * Edit a Sentence
 */
function editZin($conn) {
    $rowId = $_POST['rowId'] ?? '';
    $zin = $_POST['zin'] ?? '';

    logAction($conn, 'editZin', ['rowId' => $rowId, 'zin' => $zin]);

    if (!empty($rowId) && !empty($zin)) {
        // Convert the sentence into an array and encode it as JSON
        $zinArray = json_encode(explode(' ', $zin), JSON_UNESCAPED_UNICODE); // Assuming zinArray is stored as JSON

        // SQL query to update the sentence in the database
        $sql = "UPDATE sentences SET zinArray = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("si", $zinArray, $rowId);
        if ($stmt->execute()) {
            // Return success
            echo json_encode(['success' => true]);
        } else {
            // Return error if update fails
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        // Return an error if parameters are missing
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
    }
    exit();
}
/**
 * Fetch Sentences with Optional Filtering, Search, and Pagination
 */
function fetchSentences($conn) {
    // Retrieve parameters from GET
    $page = $_GET['page'] ?? 1;
    $thema = $_GET['thema'] ?? null;
    $search = $_GET['search'] ?? null;
    $status_video = $_GET['statusVideo'] ?? null;
    $status_gvg = $_GET['statusGvg'] ?? null;
    $status_annotatie = $_GET['statusAnnotatie'] ?? null;
    $status_glos = $_GET['statusGlos'] ?? null;
    $mcp_status_postprocessing = $_GET['mcpStatusPostprocessing'] ?? null;
    $mcp_status_tijd_annotatie = $_GET['mcpStatusTijdAnnotatie'] ?? null;
    $mcp_status_tijd_annotatie_gvg = $_GET['mcpStatusTijdAnnotatieGvg'] ?? null;
    $motion_capture = $_GET['motionCapture'] ?? null;
    $searchGloss = $_GET['searchGloss'] ?? null;
    $searchGvg = $_GET['gvg'] ?? null;
    $withoutVideo = $_GET['withoutVideo'] ?? null;

    // Validate and sanitize 'page'
    $page = filter_var($page, FILTER_VALIDATE_INT, [
        'options' => [
            'default' => 1,
            'min_range' => 1
        ]
    ]);

    $itemsPerPage = 25; // Adjust as needed
    $offset = ($page - 1) * $itemsPerPage;

    // Initialize SQL query components
    // Using LEFT JOIN for video_count instead of correlated subquery for better performance
    $sql = "SELECT
                sentences.ID,
                sentences.zinID,
                sentences.glosArray,
                sentences.zinArray,
                sentences.sortArray,
                sentences.thema,
                sentences.comments,
                sentences.status_video,
                sentences.status_annotatie,
                sentences.status_glos,
                sentences.status_gvg,
                sentences.mcp_status_postprocessing,
                sentences.mcp_status_tijd_annotatie,
                sentences.mcp_status_tijd_annotatie_gvg,
                sentences.gvg,
                COUNT(DISTINCT CASE WHEN mt_video.zOg = 'Zin' AND mt_video.added = '1' THEN mt_video.id ELSE NULL END) AS video_count
            FROM sentences
            LEFT JOIN matched_transcriptions mt_video
                ON mt_video.m_transcription = sentences.ID";

    $whereClauses = [];
    $params = [];
    $types = '';

    // Check if any status filters are set
    $statusFilters = [
        'status_video' => $status_video,
        'status_gvg' => $status_gvg,
        'status_annotatie' => $status_annotatie,
        'status_glos' => $status_glos,
        'mcp_status_tijd_annotatie' => $mcp_status_tijd_annotatie,
        'mcp_status_tijd_annotatie_gvg' => $mcp_status_tijd_annotatie_gvg
    ];

    // Filter out empty values
    $activeStatusFilters = array_filter($statusFilters, function($value) {
        return !empty($value);
    });

    if (!empty($activeStatusFilters)) {
        // If any status filter is active, ignore 'thema' and apply only status filters
        foreach ($activeStatusFilters as $field => $value) {
            // For the MCP "tijd annotatie" columns an empty/NULL cell means "Niet Klaar",
            // so filtering for "Niet Klaar" must also match blank/NULL rows.
            if (($field === 'mcp_status_tijd_annotatie' || $field === 'mcp_status_tijd_annotatie_gvg')
                && $value === 'Niet Klaar') {
                $whereClauses[] = "(sentences.$field = ? OR sentences.$field IS NULL OR sentences.$field = '')";
                $types .= 's';
                $params[] = $value;
            } else {
                // Ensure that the field name matches the database column
                // If they differ, map them accordingly
                $whereClauses[] = "sentences.$field = ?";
                $types .= 's'; // Assuming all status fields are strings
                $params[] = $value;
            }
        }
    }

    // MCP postprocessing status is stored numerically by the mocap pipeline:
    //   1 = Klaar, 2 = Check nodig, NULL = Niet Klaar.
    // The frontend sends '1'/'2' for those, and the '__NULL__' sentinel for Niet Klaar.
    // Legacy string labels ('Klaar'/'Check nodig'/'Niet Klaar') are still accepted so
    // stale localStorage state or cached pages keep filtering correctly.
    if (!empty($mcp_status_postprocessing)) {
        $mcpValue = $mcp_status_postprocessing;
        if ($mcpValue === '__NULL__' || $mcpValue === 'Niet Klaar') {
            $whereClauses[] = "sentences.mcp_status_postprocessing IS NULL";
        } else {
            if ($mcpValue === 'Klaar') {
                $mcpValue = 1;
            } elseif ($mcpValue === 'Check nodig') {
                $mcpValue = 2;
            }
            $whereClauses[] = "sentences.mcp_status_postprocessing = ?";
            $types .= 'i';
            $params[] = (int)$mcpValue;
        }
    }
   
        // If no status filters, apply 'thema' filter if provided
        if (!empty($thema)) {
            $whereClauses[] = "sentences.thema = ?";
            $types .= 's';
            $params[] = $thema;
        }
    

    // Apply search filter if provided
    if (!empty($search)) {
        // Split the search query into individual words
        // This regex splits on any whitespace characters
        $searchWords = preg_split('/\s+/', trim($search));

        foreach ($searchWords as $word) {
            if (!empty($word)) {
                // Add a case-insensitive LIKE condition for each word
                $whereClauses[] = "LOWER(sentences.zinArray) LIKE ?";
                $types .= 's';
                $params[] = '%' . strtolower($word) . '%';
            }
        }
    }

    //apply search gloss if provided
    if (!empty($searchGloss)) {
                $whereClauses[] = "LOWER(sentences.glosses) LIKE ?";
                $types .= 's';
                $params[] = '%' . strtolower($searchGloss) . '%';

    }

    //apply search gvg if provided
    if (!empty($searchGvg)) {
                $whereClauses[] = "LOWER(sentences.gvg) LIKE ?";
                $types .= 's';
                $params[] = '%' . strtolower($searchGvg) . '%';

    }

    // Apply motion capture filter if specified
    // Using EXISTS instead of IN for better performance with the composite index
    if (!empty($motion_capture)) {
        if ($motion_capture === '1') {
            // Has motion capture - EXISTS is faster than IN and can use our index
            $whereClauses[] = "EXISTS (
                SELECT 1 FROM matched_transcriptions
                WHERE matched_transcriptions.m_transcription = sentences.ID
                AND matched_transcriptions.has_mocap = 1
                AND matched_transcriptions.zOg = 'Zin'
                AND matched_transcriptions.added = '1'
            )";
        } else if ($motion_capture === '0') {
            // No motion capture - NOT EXISTS is faster than NOT IN
            $whereClauses[] = "NOT EXISTS (
                SELECT 1 FROM matched_transcriptions
                WHERE matched_transcriptions.m_transcription = sentences.ID
                AND matched_transcriptions.has_mocap = 1
                AND matched_transcriptions.zOg = 'Zin'
                AND matched_transcriptions.added = '1'
            )";
        }
    }

    // Filter sentences without any video
    if (!empty($withoutVideo) && $withoutVideo === '1') {
        $whereClauses[] = "NOT EXISTS (
            SELECT 1 FROM matched_transcriptions
            WHERE matched_transcriptions.m_transcription = sentences.ID
            AND matched_transcriptions.zOg = 'Zin'
            AND matched_transcriptions.added = '1'
        )";
    }

    // Combine WHERE clauses
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }

    // Get total count for pagination - use the same WHERE conditions
    $countSql = "SELECT COUNT(DISTINCT sentences.ID) as total FROM sentences";
    if (!empty($whereClauses)) {
        $countSql .= " WHERE " . implode(" AND ", $whereClauses);
    }
    
    // Execute count query
    $countStmt = $conn->prepare($countSql);
    if ($countStmt === false) {
        echo json_encode(['success' => false, 'error' => 'Count prepare failed: ' . $conn->error]);
        exit();
    }
    
    // Bind parameters for count query (excluding limit/offset params)
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    
    if (!$countStmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Count execute failed: ' . $countStmt->error]);
        exit();
    }
    
    $countResult = $countStmt->get_result();
    $totalCount = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Get total video count using cached video_count column (fast: ~25ms vs ~52s with JOIN)
    $videoCountSql = "SELECT COALESCE(SUM(video_count), 0) as total_videos FROM sentences";
    if (!empty($whereClauses)) {
        $videoCountSql .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $videoCountStmt = $conn->prepare($videoCountSql);
    if ($videoCountStmt && !empty($params)) {
        $videoCountStmt->bind_param($types, ...$params);
    }
    if ($videoCountStmt && $videoCountStmt->execute()) {
        $videoCountResult = $videoCountStmt->get_result();
        $totalVideoCount = $videoCountResult->fetch_assoc()['total_videos'];
        $videoCountStmt->close();
    } else {
        $totalVideoCount = 0;
    }

    // Calculate total pages
    $totalPages = ceil($totalCount / $itemsPerPage);

    // Add GROUP BY for aggregation (needed for video_count with LEFT JOIN)
    $sql .= " GROUP BY sentences.ID";

    // Add ORDER BY clause for priority and alphabetical ordering
    $sql .= " ORDER BY
                sentences.ID DESC,
                sentences.zinArray ASC";

    // Add LIMIT and OFFSET for pagination
    $sql .= " LIMIT ?, ?";
    $types .= 'ii';
    $params[] = $offset;
    $params[] = $itemsPerPage;

    // Prepare and bind parameters
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    // Dynamically bind parameters
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
        exit();
    }

    $result = $stmt->get_result();

    $rows = [];
    $sentenceIDs = []; // To collect all sentence IDs for batch video fetching

    while ($row = $result->fetch_assoc()) {
        // Decode the zinArray JSON into an array
        $row['zinArray'] = json_decode($row['zinArray'], true);
        $rows[$row['ID']] = $row; // Use sentence ID as the key
        $sentenceIDs[] = $row['ID'];
    }

    $stmt->close();

    if (!empty($sentenceIDs)) {
        // Prepare placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($sentenceIDs), '?'));
        $video_sql = "SELECT * FROM matched_transcriptions 
                      WHERE zOg='Zin' AND m_transcription IN ($placeholders) AND added = 1
                      ORDER BY ID ASC"; // Adjust ORDER BY as needed

        $video_stmt = $conn->prepare($video_sql);
        if ($video_stmt === false) {
            echo json_encode(['success' => false, 'error' => 'Prepare failed for videos: ' . $conn->error]);
            exit();
        }

        // Bind parameters dynamically
        $video_types = str_repeat('i', count($sentenceIDs)); // Assuming m_transcription is integer
        $video_stmt->bind_param($video_types, ...$sentenceIDs);
        if (!$video_stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Execute failed for videos: ' . $video_stmt->error]);
            exit();
        }

        $video_result = $video_stmt->get_result();
        $videosGrouped = [];

        while ($video_row = $video_result->fetch_assoc()) {
            $transcriptionID = $video_row['m_transcription'];

            // Initialize the array if not already
            if (!isset($videosGrouped[$transcriptionID])) {
                $videosGrouped[$transcriptionID] = [];
            }

            // Process EAF files
            $eafDir = __DIR__ . '/eaf/zin/';
            $eafPath = $eafDir . preg_replace('/\.(wav)$/i', '.eaf', $video_row['m_file']);
            if (file_exists($eafPath)) {
                $eafContent = file_get_contents($eafPath);
                $eaf_signbank_glossen = 0;
                $eaf_nederlands = 0;
                $eaf_gebaar_voor_gebaar = 0;

                // Suppress errors with @ and handle invalid XML
                $eafXml = @simplexml_load_string($eafContent);
                if ($eafXml !== false) {
                    foreach($eafXml->TIER as $tier) {
                        $tierID = (string)$tier['TIER_ID'];
                        $annotationCount = count($tier->ANNOTATION);
                        if ($tierID === "Signbank ID glossen") {
                            $eaf_signbank_glossen = $annotationCount;
                        }
                        if ($tierID === "Nederlands") {
                            $eaf_nederlands = $annotationCount;
                        }
                        if ($tierID === "Gebaar-voor-gebaar") {
                            $eaf_gebaar_voor_gebaar = $annotationCount;
                        }
                    }
                }

                $video_row['eaf'] = preg_replace('/\.(wav)$/i', '.eaf', $video_row['m_file']);
                $video_row['eaf_signbank_glossen'] = $eaf_signbank_glossen;
                $video_row['eaf_nederlands'] = $eaf_nederlands;
                $video_row['eaf_gebaar_voor_gebaar'] = $eaf_gebaar_voor_gebaar;
            } else {
                $video_row['eaf'] = null;
                $video_row['eaf_signbank_glossen'] = 0;
                $video_row['eaf_nederlands'] = 0;
                $video_row['eaf_gebaar_voor_gebaar'] = 0;
            }

            //check if the video has srt file
            //get video basename
            $video_basename = pathinfo($video_row['m_file'], PATHINFO_FILENAME);
            //we have three srt extra path like: Nederlands, Signbank_ID_glossen and Gebaar_voor_gebaar
            //add the path to the video_basename then change .wav to .srt and check if it exists
            //when exists,  then add to the video_row
            $srt_nederlands = __DIR__ . '/eaf/zin/' . $video_basename . '_Nederlands.srt';
            $srt_signbank_id_glossen = __DIR__ . '/eaf/zin/' . $video_basename . '_Signbank_ID_glossen.srt';
            $srt_gebaar_voor_gebaar = __DIR__ . '/eaf/zin/' . $video_basename . '_Gebaar-voor-gebaar.srt';
            if(file_exists($srt_nederlands))
            {
                $video_row['srt_nederlands'] = str_replace('/web/zin/eaf/zin/', 'https://signcollect.nl/zin/eaf/zin/', $srt_nederlands);
            }
            else
            {
                $video_row['srt_nederlands'] = null;
            }
            if(file_exists($srt_signbank_id_glossen))
            {
                $video_row['srt_signbank_id_glossen']  = str_replace('/web/zin/eaf/zin/', 'https://signcollect.nl/zin/eaf/zin/', $srt_signbank_id_glossen);
            }
            else
            {
                $video_row['srt_signbank_id_glossen'] = null;
            }
            if(file_exists($srt_gebaar_voor_gebaar))
            {
                $video_row['srt_gebaar_voor_gebaar']  = str_replace('/web/zin/eaf/zin/', 'https://signcollect.nl/zin/eaf/zin/', $srt_gebaar_voor_gebaar);
            }
            else
            {
                $video_row['srt_gebaar_voor_gebaar'] = null;
            }

            // Append the video to the respective transcription's video list
            $videosGrouped[$transcriptionID][] = $video_row;
        }

        $video_stmt->close();

        // Assign videos to their respective sentences
        foreach ($videosGrouped as $transcriptionID => $videos) {
            if (isset($rows[$transcriptionID])) {
                $rows[$transcriptionID]['videos'] = $videos;
            }
        }
    }

    // Convert the associative array back to a numerically indexed array
    $rows = array_values($rows);

    // Optionally, handle sentences without videos
    foreach ($rows as &$row) {
        if (!isset($row['videos'])) {
            $row['videos'] = [];
        }
    }

    // Return the result as JSON with pagination info
    echo json_encode([
        'success' => true, 
        'rows' => $rows,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'totalVideoCount' => $totalVideoCount,
            'itemsPerPage' => $itemsPerPage
        ]
    ]);
}


/**
 * Download a Blank EAF File
 */
function downloadEAF($conn) {
    // Retrieve parameters from GET
    $filename = $_GET['filename'] ?? '';
    $post_processed = $_GET['post_processed'] ?? '';
    $zin = $_GET['zin'] ?? '';
    $type = $_GET['type'] ?? '';

    if($type){
        $type = "mp4";
    }
    else
    {
        $type = "avi";
    }

    // Convert $post_processed to int
    $post_processed = (int)$post_processed;
    $media_url = "";

    // Sanitize filename to prevent directory traversal
    $filename = basename($filename);
    $filename = preg_replace('/\.(wav|mp4)$/i', '', $filename); // Remove .wav or .mp4 extension if present

    if ($post_processed === 1) {
        $media_url = "https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/{$filename}.{$type}";
    } else {
        $media_url = "https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/{$filename}.{$type}";
    }

    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
        exit();
    }

    // Define the path to save EAF files
    $eafDir = __DIR__ . '/eaf/zin/';
    if (!is_dir($eafDir)) {
        if (!mkdir($eafDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create EAF directory.']);
            exit();
        }
    }

    // Define the EAF file path
    $eafPath = $eafDir . $filename . '.eaf';

    // Convert 'zin' to a safe filename by replacing whitespace with underscores and removing non-alphanumeric characters
    $downloadFilename = str_replace(" ", "_", $zin);
    $downloadFilename = preg_replace('/[^A-Za-z0-9_]/', '', $downloadFilename);

    if (file_exists($eafPath)) {
        // Load the existing EAF XML
        libxml_use_internal_errors(true);
        $eafXml = simplexml_load_file($eafPath);
        if ($eafXml === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to parse existing EAF file.']);
            exit();
        }

        // Locate the MEDIA_DESCRIPTOR element
        $mediaDescriptor = $eafXml->HEADER->MEDIA_DESCRIPTOR;
        if ($mediaDescriptor) {
            // Update MEDIA_URL
            $mediaDescriptor['MEDIA_URL'] = $media_url;

            // Update RELATIVE_MEDIA_URL
            // Assuming RELATIVE_MEDIA_URL should also be updated to the new $media_url
            // If it needs to be relative, adjust accordingly
            $mediaDescriptor['RELATIVE_MEDIA_URL'] = $media_url;
        } else {
            echo json_encode(['success' => false, 'error' => 'MEDIA_DESCRIPTOR not found in EAF file.']);
            exit();
        }

        // Convert the modified XML back to a string
        $modifiedEafContent = $eafXml->asXML();
        if ($modifiedEafContent === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to convert EAF XML to string.']);
            exit();
        }

        // Serve the modified EAF content as a download
        header('Content-Description: File Transfer');
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '.eaf"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Content-Length: ' . strlen($modifiedEafContent));
        header('Pragma: public');

        // Clear the output buffer to prevent any unexpected output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Output the modified EAF content
        echo $modifiedEafContent;
        exit();
    }

    // If EAF file does not exist, create a new one
    // Create a blank EAF content with the specified MEDIA_URL
    $eafContent = <<<EAF
<?xml version="1.0" encoding="UTF-8"?>
<ANNOTATION_DOCUMENT AUTHOR="" DATE="2024-09-05T14:03:19+01:00"
    FORMAT="3.0" VERSION="3.0"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.mpi.nl/tools/elan/EAFv3.0.xsd">
    <HEADER MEDIA_FILE="" TIME_UNITS="milliseconds">
        <MEDIA_DESCRIPTOR 
            MEDIA_URL="{$media_url}" 
            MIME_TYPE="video/mp4"/>
        <PROPERTY NAME="URN">urn:nl-mpi-tools-elan-eaf:c41d6c5b-db3a-496e-884d-838bed8c08f7</PROPERTY>
        <PROPERTY NAME="lastUsedAnnotationId">0</PROPERTY>
    </HEADER>
    <TIME_ORDER/>
    <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="Signbank ID glossen"/>
    <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="Nederlands"/>
    <TIER LINGUISTIC_TYPE_REF="default-lt" TIER_ID="Gebaar-voor-gebaar"/>
    <LINGUISTIC_TYPE GRAPHIC_REFERENCES="false"
        LINGUISTIC_TYPE_ID="default-lt" TIME_ALIGNABLE="true"/>
    <CONSTRAINT
        DESCRIPTION="Time subdivision of parent annotation's time interval, no time gaps allowed within this interval" 
        STEREOTYPE="Time_Subdivision"/>
    <CONSTRAINT
        DESCRIPTION="Symbolic subdivision of a parent annotation. Annotations referring to the same parent are ordered" 
        STEREOTYPE="Symbolic_Subdivision"/>
    <CONSTRAINT 
        DESCRIPTION="1-1 association with a parent annotation" 
        STEREOTYPE="Symbolic_Association"/>
    <CONSTRAINT
        DESCRIPTION="Time alignable annotations within the parent annotation's time interval, gaps are allowed" 
        STEREOTYPE="Included_In"/>
</ANNOTATION_DOCUMENT>
EAF;

    // Save the new EAF file to disk
    if (file_put_contents($eafPath, $eafContent) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to create EAF file.']);
        exit();
    }

    // Serve the newly created EAF file as a download
    header('Content-Description: File Transfer');
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '.eaf"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Content-Length: ' . strlen($eafContent));
    header('Pragma: public');

    // Clear the output buffer to prevent any unexpected output
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Output the EAF content
    echo $eafContent;
    exit();
}


/**
 * Download MP4 Video File
 */
function downloadMP4($conn) {
    // Retrieve parameters from GET
    $filename = $_GET['filename'] ?? '';
    $filename_a = $_GET['filename_a'] ?? '';
    $filename_b = $_GET['filename_b'] ?? '';
    $post_processed = $_GET['post_processed'] ?? '';
    $zin = $_GET['zin'] ?? '';

    // Collect all filenames
    $filenames = array_filter([$filename, $filename_a, $filename_b]);

    // Check that at least one filename is provided
    if (empty($filenames)) {
        echo json_encode(['success' => false, 'error' => 'At least one filename must be provided.']);
        exit();
    }

    // Sanitize and replace .wav with .mp4
    $sanitizedFilenames = [];
    foreach ($filenames as $file) {
        // Replace .wav with .mp4
        $file = preg_replace('/\.(wav)$/i', '.mp4', $file);

        // Prevent directory traversal
        $basename = basename($file);

        // Remove unwanted characters (allow letters, numbers, underscores, hyphens, and dots)
        $basename = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $basename);

        if (!empty($basename)) {
            $sanitizedFilenames[] = $basename;
        }
    }

    if (empty($sanitizedFilenames)) {
        echo json_encode(['success' => false, 'error' => 'No valid filenames after sanitization.']);
        exit();
    }

    // Define paths based on post_processed
    $paths = [];
    foreach ($sanitizedFilenames as $sanitizedFile) {
        if ($post_processed === "1") {
            $paths[] = __DIR__ . '/../gebarenoverleg_media/studioFilesMini/post/' . $sanitizedFile;
        } else {
            $paths[] = __DIR__ . '/../gebarenoverleg_media/studioFilesMini/raw/' . $sanitizedFile;
        }
    }

    // Check if all specified files exist
    $missingFiles = [];
    foreach ($paths as $index => $path) {
        if (!file_exists($path)) {
            $missingFiles[] = $sanitizedFilenames[$index];
        }
    }

    if (!empty($missingFiles)) {
        //remove the missinggilmes from the sanitized filenames
        $sanitizedFilenames = array_diff($sanitizedFilenames, $missingFiles);
        // print_r($sanitizedFilenames);
        // print_r($missingFiles);
    }

    // Initialize an array to keep track of temporary SRT files for cleanup
    $tempSrtFiles = [];

    // Initialize suffix mapping based on starting letter
    $suffixMapping = [
        'A' => 'links',
        'B' => 'rechts',
        'M' => 'midden'
    ];

    // Create a unique temporary directory
    $tempDir = "/tmp" . '/download_' . uniqid();
    if (!mkdir($tempDir, 0777, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create temporary directory.']);
        exit();
    }

    // Prepare the list of files for the zip command
    $zipFiles = [];

    foreach ($sanitizedFilenames as $index => $sanitizedFile) {
        $videoPath = $paths[$index];
        $videoName = basename($videoPath);

        // Determine the suffix based on the starting letter
        $firstLetter = strtoupper(substr($sanitizedFile, 0, 1));
        $suffix = $suffixMapping[$firstLetter] ?? 'overig'; // 'overig' for others

        // Define the desired name inside the ZIP
        $desiredVideoName = "{$suffix}_{$zin}.mp4";

        //remove specialchars in $desiredVideoName including , !
        $desiredVideoName = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $desiredVideoName);

        // Copy and rename the video file to the temporary directory
        $tempVideoPath = $tempDir . '/' . $desiredVideoName;
        if (!copy($videoPath, $tempVideoPath)) {
            // Cleanup and return error
            // deleteDirectory($tempDir);
            echo json_encode(['success' => false, 'error' => "Failed to copy video file: {$sanitizedFile}"]);
            exit();
        }

        // Add the video file to the zip
        $zipFiles[] = $tempVideoPath;

        // Corresponding EAF file path
        $eafPath = __DIR__ . '/eaf/zin/' . preg_replace('/\.(mp4)$/i', '.eaf', $sanitizedFile);

        if (file_exists($eafPath)) {
            // Parse EAF and generate SRT files
            $srtFiles = generateSrtFiles($eafPath, $zin, $suffix);



            foreach ($srtFiles as $tier => $srtContent) {
                // Define desired SRT filename
                $desiredSrtName = "{$zin}_{$tier}.srt";

                // Remove special characters from the desired SRT name
                $desiredSrtName = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $desiredSrtName);

                // Define temporary SRT file path
                $tempSrtPath = $tempDir . '/' . $desiredSrtName;
                file_put_contents($tempSrtPath, $srtContent);
                $tempSrtFiles[] = $tempSrtPath;

                // Add the SRT file to the zip
                $zipFiles[] = $tempSrtPath;
            }
        }
    }

    print_r($zipFiles);

    // Create a unique temporary filename for the ZIP
    $tempZipName = 'download_' . uniqid() . '.zip';
    $tempZipPath = "/tmp" . '/' . $tempZipName;
    // echo $tempZipName;

    // Construct the zip command
    // -j: Junk the path (store only the file names)
    // -q: Quiet mode
    $zipCommand = "zip -j -9 -r " . $tempZipPath . " " . $tempDir;
// tempVideoPath
    // echo $zipCommand;

    // Execute the zip command
    exec($zipCommand, $output, $returnVar);

    // echo $tempZipPath;
    // print_r($output);
    // print_r($returnVar);
    // exit();
    // exit();


    if ($returnVar !== 0 || !file_exists($tempZipPath)) {
        // Cleanup temporary SRT files and directory
        foreach ($tempSrtFiles as $tempSrtPath) {
            if (file_exists($tempSrtPath)) {
                // unlink($tempSrtPath);
            }
        }
        // deleteDirectory($tempDir);

        echo json_encode(['success' => false, 'error' => 'Failed to create ZIP archive.']);
        exit();
    }

    // Prepare the download filename based on 'zin'
    if (empty($zin)) {
        $downloadName = 'videos.zip';
    } else {
        $downloadName = str_replace(" ", "_", $zin);
        $downloadName = preg_replace('/[^A-Za-z0-9_]/', '', $downloadName);
        $downloadName .= ".zip";
    }

    // Send the ZIP file as a download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tempZipPath));
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    // Clear the output buffer to prevent any unexpected output
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Read the file and send it to the user
    readfile($tempZipPath);

    // Delete the temporary ZIP file
    // unlink($tempZipPath);

    // Cleanup temporary SRT files and directory
    // deleteDirectory($tempDir);

    exit();
}

/**
 * Generates SRT files content from an EAF file for specified tiers.
 *
 * @param string $eafPath Path to the EAF file.
 * @param string $zin The 'zin' parameter for naming.
 * @param string $suffix The suffix based on filename's starting letter.
 * @return array An associative array where keys are tier names and values are SRT content.
 */
function generateSrtFiles($eafPath, $zin, $suffix) {
    // Load the EAF XML
    libxml_use_internal_errors(true);
    $eafXml = simplexml_load_file($eafPath);
    if ($eafXml === false) {
        // Handle XML parsing errors if necessary
        error_log("Failed to parse EAF file: {$eafPath}");
        return [];
    }

    // Build a map of TIME_SLOT_ID to TIME_VALUE
    $timeSlotMap = [];
    foreach ($eafXml->TIME_ORDER->TIME_SLOT as $timeSlot) {
        $id = (string)$timeSlot['TIME_SLOT_ID'];
        $value = (int)$timeSlot['TIME_VALUE'];
        $timeSlotMap[$id] = $value;
    }

    // Define the tiers we are interested in
    $desiredTiers = [
        'Signbank ID glossen' => 'glos',
        'Nederlands' => 'nederlands',
        'Gebaar-voor-gebaar' => 'gebaar_voor_gebaar'
    ];

    // Initialize an array to hold SRT contents
    $srtContents = [];

    // Iterate over each desired tier
    foreach ($desiredTiers as $tierId => $srtSuffix) {
        // Find the tier
        $tier = null;
        foreach ($eafXml->TIER as $t) {
            if ((string)$t['TIER_ID'] === $tierId) {
                $tier = $t;
                break;
            }
        }

        // If tier not found or no annotations, skip
        if ($tier === null) {
            continue;
        }

        // Initialize SRT content
        $srtContent = "";
        $srtIndex = 1;

        // Iterate over each annotation in the tier
        foreach ($tier->ANNOTATION as $annotation) {
            $alignable = $annotation->ALIGNABLE_ANNOTATION;
            if ($alignable) {
                $tsRef1 = (string)$alignable['TIME_SLOT_REF1'];
                $tsRef2 = (string)$alignable['TIME_SLOT_REF2'];
                $text = (string)$alignable->ANNOTATION_VALUE;

                // Retrieve TIME_VALUEs from the TIME_SLOT_MAP
                if (!isset($timeSlotMap[$tsRef1]) || !isset($timeSlotMap[$tsRef2])) {
                    // Skip if TIME_SLOT_REF is missing
                    error_log("Missing TIME_SLOT_REF for annotation ID {$alignable['ANNOTATION_ID']} in tier {$tierId}");
                    continue;
                }

                $startTs = $timeSlotMap[$tsRef1];
                $endTs = $timeSlotMap[$tsRef2];

                // Convert timestamps from milliseconds to SRT format
                $startTime = convertMsToSrtTime($startTs);
                $endTime = convertMsToSrtTime($endTs);

                // Append to SRT content
                $srtContent .= "{$srtIndex}\n";
                $srtContent .= "{$startTime} --> {$endTime}\n";
                $srtContent .= "{$text}\n\n";

                $srtIndex++;
            }
        }

        // If there are annotations, add to the array
        if (!empty(trim($srtContent))) {
            $srtContents[$srtSuffix] = $srtContent;
        }
    }

    return $srtContents;
}


/**
 * Converts milliseconds to SRT time format (HH:MM:SS,mmm).
 *
 * @param int $ms Milliseconds.
 * @return string Formatted time.
 */
function convertMsToSrtTime($ms) {
    $hours = floor($ms / 3600000);
    $minutes = floor(($ms % 3600000) / 60000);
    $seconds = floor(($ms % 60000) / 1000);
    $milliseconds = $ms % 1000;

    return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds);
}

/**
 * Recursively deletes a directory and its contents.
 *
 * @param string $dir Directory path.
 * @return void
 */
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return;
    }

    if (!is_dir($dir)) {
        unlink($dir);
        return;
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

 

/**
 * Uploads an EAF file, handles backups, and converts it to SRT files for each annotation tier.
 *
 * @param mysqli $conn The database connection object.
 */
function uploadEAF($conn) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['eafFile']) && $_FILES['eafFile']['error'] === UPLOAD_ERR_OK) {
            // Retrieve the video filename from POST data
            $videoFilename = $_POST['videoFilename'] ?? '';

            logAction($conn, 'Upload EAF', ['videoFilename' => $videoFilename]);

            if (empty($videoFilename)) {
                echo json_encode(['success' => false, 'error' => 'Video filename not provided.']);
                exit();
            }

            // Sanitize video filename to prevent directory traversal
            $videoFilename = basename($videoFilename);

            // Define the path to save EAF files
            $eafDir = __DIR__ . '/eaf/zin/';
            if (!is_dir($eafDir)) {
                if (!mkdir($eafDir, 0755, true)) {
                    echo json_encode(['success' => false, 'error' => 'Failed to create EAF directory.']);
                    exit();
                }
            }

            // Remove .wav or .mp4 extension if present
            $baseFilename = preg_replace('/\\.(wav|mp4)$/i', '', $videoFilename);

            // Define the EAF file path
            $eafPath = $eafDir . $baseFilename . '.eaf';

            // Check if the EAF file already exists
            if (file_exists($eafPath)) {
                // Generate backup filename with current date
                $currentDate = date('Ymd_Hi'); // Format: YYYYMMDD_HHMM
                $backupFilename = $baseFilename . '_backup_' . $currentDate . '.eaf';
                $backupPath = $eafDir . $backupFilename;

                // Rename the existing EAF file to the backup filename
                if (!rename($eafPath, $backupPath)) {
                    echo json_encode(['success' => false, 'error' => 'Failed to create backup of existing EAF file.']);
                    exit();
                }
            }

            // Validate that the uploaded file is an EAF file
            $fileType = mime_content_type($_FILES['eafFile']['tmp_name']);
            if ($fileType !== 'application/xml' && $fileType !== 'text/xml') {
                echo json_encode(['success' => false, 'error' => 'Invalid EAF file type.']);
                exit();
            }

            // Move the uploaded file to the destination
            if (move_uploaded_file($_FILES['eafFile']['tmp_name'], $eafPath)) {
                // Optional: Log the upload in the database
                /*
                $stmt = $conn->prepare("INSERT INTO uploads (video_filename, eaf_path, upload_time) VALUES (?, ?, NOW())");
                $stmt->bind_param("ss", $videoFilename, $eafPath);
                $stmt->execute();
                $stmt->close();
                */

                // Proceed to convert the EAF to SRT files
                $conversionResult = convertEAFtoSRT($eafPath, $baseFilename, $eafDir);

                if ($conversionResult['success']) {
                    echo json_encode(['success' => true, 'message' => 'EAF file uploaded and converted to SRT files successfully.']);
                } else {
                    echo json_encode(['success' => false, 'error' => $conversionResult['error']]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    }
    exit();
}

/**
 * Converts an EAF file to SRT files for each annotation tier with annotations.
 *
 * @param string $eafPath The full path to the EAF file.
 * @param string $baseFilename The base filename without extension.
 * @param string $eafDir The directory where EAF and SRT files are stored.
 * @return array An associative array with 'success' (bool) and 'error' (string) keys.
 */
function convertEAFtoSRT($eafPath, $baseFilename, $eafDir) {
    // Load the EAF XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($eafPath);
    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        return ['success' => false, 'error' => 'Failed to parse EAF file.'];
    }

    // Create a mapping of TIME_SLOT_ID to TIME_VALUE
    $timeSlots = [];
    foreach ($xml->TIME_ORDER->TIME_SLOT as $timeSlot) {
        $id = (string)$timeSlot['TIME_SLOT_ID'];
        $value = (int)$timeSlot['TIME_VALUE']; // in milliseconds
        $timeSlots[$id] = $value;
    }

    // Iterate through each TIER
    foreach ($xml->TIER as $tier) {
        $tierID = (string)$tier['TIER_ID'];

        // Skip tiers without annotations
        if (!isset($tier->ANNOTATION) || count($tier->ANNOTATION) === 0) {
            continue;
        }

        $srtEntries = [];
        $counter = 1;

        // Iterate through each ANNOTATION in the TIER
        foreach ($tier->ANNOTATION as $annotation) {
            if (!isset($annotation->ALIGNABLE_ANNOTATION)) {
                continue;
            }

            $alignable = $annotation->ALIGNABLE_ANNOTATION;
            $tsRef1 = (string)$alignable['TIME_SLOT_REF1'];
            $tsRef2 = (string)$alignable['TIME_SLOT_REF2'];
            $text = trim((string)$alignable->ANNOTATION_VALUE);

            // Get start and end times in milliseconds
            if (!isset($timeSlots[$tsRef1]) || !isset($timeSlots[$tsRef2])) {
                continue; // Invalid time references
            }

            $startMs = $timeSlots[$tsRef1];
            $endMs   = $timeSlots[$tsRef2];

            // Convert milliseconds to SRT timecode format
            $startTime = msToSRTTimecode($startMs);
            $endTime = msToSRTTimecode($endMs);

            // Create SRT entry
            $srtEntry = "{$counter}\n{$startTime} --> {$endTime}\n{$text}\n";
            $srtEntries[] = $srtEntry;
            $counter++;
        }

        // If no valid annotations, skip creating SRT
        if (empty($srtEntries)) {
            continue;
        }

        // Define the SRT filename
        // Replace spaces and invalid characters in tier ID
        $safeTierID = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', $tierID));
        $srtFilename = "{$baseFilename}_{$safeTierID}.srt";
        $srtPath = $eafDir . $srtFilename;

        // Prepare SRT content with proper line breaks
        $srtContent = implode("\n", $srtEntries) . "\n";

        // Write to the SRT file (overwrite if exists)
        if (file_put_contents($srtPath, $srtContent) === false) {
            return ['success' => false, 'error' => "Failed to write SRT file for tier '{$tierID}'."];
        }
    }

    return ['success' => true];
}

/**
 * Converts milliseconds to SRT timecode format (HH:MM:SS,mmm)
 *
 * @param int $milliseconds
 * @return string
 */
function msToSRTTimecode($milliseconds) {
    $hours = floor($milliseconds / 3600000);
    $minutes = floor(($milliseconds % 3600000) / 60000);
    $seconds = floor(($milliseconds % 60000) / 1000);
    $ms = $milliseconds % 1000;

    return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $ms);
}

/**
 * Identify the logged-in user from the sessionObject cookie (set by login.html).
 * Returns "username (#userId)" or '' when not available.
 */
function currentLogUser() {
    if (empty($_COOKIE['sessionObject'])) return '';
    $raw = $_COOKIE['sessionObject'];
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = json_decode(urldecode($raw), true); }
    if (!is_array($data)) return '';
    $name = trim($data['username'] ?? '');
    $uid  = trim((string)($data['userId'] ?? ''));
    if ($name !== '' && $uid !== '') return $name . ' (#' . $uid . ')';
    return $name !== '' ? $name : $uid;
}

/** Whether sentences_logs has the optional `user` column (cached per request). */
function logUserColumnExists($conn) {
    static $exists = null;
    if ($exists === null) {
        $r = $conn->query("SHOW COLUMNS FROM sentences_logs LIKE 'user'");
        $exists = ($r && $r->num_rows > 0);
    }
    return $exists;
}

function logAction($conn, $action, $parameters = []) {
    // Get the client's IP address
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    // Encode parameters as JSON
    $parametersJson = json_encode($parameters, JSON_UNESCAPED_UNICODE);

    // Capture the logged-in user when the `user` column is present
    $hasUser = logUserColumnExists($conn);
    if ($hasUser) {
        $user = currentLogUser();
        $sql = "INSERT INTO sentences_logs (action, parameters, ip_address, user) VALUES (?, ?, ?, ?)";
    } else {
        $sql = "INSERT INTO sentences_logs (action, parameters, ip_address) VALUES (?, ?, ?)";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // Log the error to the server's error log and exit
        error_log('Prepare failed for logAction: ' . $conn->error);
        return;
    }

    // Bind parameters
    if ($hasUser) {
        $stmt->bind_param("ssss", $action, $parametersJson, $ipAddress, $user);
    } else {
        $stmt->bind_param("sss", $action, $parametersJson, $ipAddress);
    }

    // Execute the statement
    if (!$stmt->execute()) {
        // Log the error to the server's error log
        error_log('Execute failed for logAction: ' . $stmt->error);
    }
    
    // Close the statement
    $stmt->close();
}

function fetchLogs($conn) {
    // Optional: Implement pagination parameters
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $logsPerPage = 50; // Adjust as needed
    $offset = ($page - 1) * $logsPerPage;

    // Optional: Implement filtering by date or action
    $actionFilter = isset($_GET['actionFilter']) ? $_GET['actionFilter'] : '';
    $dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : '';
    $dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : '';

    $whereClauses = [];
    $params = [];
    $types = '';

    if (!empty($actionFilter)) {
        $whereClauses[] = "action = ?";
        $params[] = $actionFilter;
        $types .= 's';
    }

    if (!empty($dateFrom)) {
        $whereClauses[] = "datetime >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }

    if (!empty($dateTo)) {
        $whereClauses[] = "datetime <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    $whereSQL = '';
    if (!empty($whereClauses)) {
        $whereSQL = "WHERE " . implode(" AND ", $whereClauses);
    }

    // Total logs count for pagination
    $countSQL = "SELECT COUNT(*) as total FROM sentences_logs $whereSQL";
    $countStmt = $conn->prepare($countSQL);
    if (!$countStmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    if (!$countStmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $countStmt->error]);
        exit();
    }

    $countResult = $countStmt->get_result();
    $totalLogs = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch the logs with limit and offset
    $sql = "SELECT action, parameters, ip_address, datetime FROM sentences_logs $whereSQL ORDER BY datetime DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    // Bind parameters including limit and offset
    if (!empty($params)) {
        // Add limit and offset parameters
        $typesWithLimit = $types . 'ii';
        $paramsWithLimit = array_merge($params, [$logsPerPage, $offset]);
        $stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
    } else {
        $stmt->bind_param("ii", $logsPerPage, $offset);
    }

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        exit();
    }

    $result = $stmt->get_result();
    $logs = [];

    while ($row = $result->fetch_assoc()) {
        // Decode parameters JSON
        $row['parameters'] = json_decode($row['parameters'], true);
        $logs[] = $row;
    }

    $stmt->close();

    // Calculate total pages
    $totalPages = ceil($totalLogs / $logsPerPage);

    // Return the logs as a JSON response
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'logsPerPage' => $logsPerPage,
            'totalLogs' => $totalLogs
        ]
    ]);
    exit();
}

/**
 * Fetch the full history (logbook) for a single sentence.
 * Matches log entries by the sentence ID (rowId in parameters) AND by any of the
 * sentence's video filenames (deleteVideo / saveSubtitles / etc. log by filename).
 */
function fetchSentenceLog($conn) {
    $rowId = isset($_GET['rowId']) ? intval($_GET['rowId']) : 0;
    if ($rowId <= 0) {
        echo json_encode(['success' => false, 'error' => 'rowId not provided.']);
        exit();
    }

    // Collect all video file base names linked to this sentence (any zOg / added state)
    $bases = [];
    $vstmt = $conn->prepare("SELECT m_file, l_file, r_file, a_file, b_file FROM matched_transcriptions WHERE m_transcription = ?");
    $vstmt->bind_param("i", $rowId);
    $vstmt->execute();
    $vres = $vstmt->get_result();
    while ($v = $vres->fetch_assoc()) {
        foreach (['m_file','l_file','r_file','a_file','b_file'] as $col) {
            if (!empty($v[$col])) {
                $base = preg_replace('/\.(wav|mp4|avi|mov)$/i', '', basename($v[$col]));
                if ($base !== '') $bases[$base] = true;
            }
        }
    }
    $vstmt->close();

    // Build WHERE: match rowId in parameters OR any video base name
    $clauses = ['parameters LIKE ?'];
    $params  = ['%"rowId":"' . $rowId . '"%'];
    $types   = 's';
    foreach (array_keys($bases) as $base) {
        $clauses[] = 'parameters LIKE ?';
        $params[]  = '%' . $base . '%';
        $types    .= 's';
    }
    $whereSQL = implode(' OR ', $clauses);

    $userCol = logUserColumnExists($conn) ? ', user' : '';
    $sql = "SELECT action, parameters, ip_address, datetime$userCol
            FROM sentences_logs
            WHERE $whereSQL
            ORDER BY datetime ASC
            LIMIT 1000";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        exit();
    }
    $result = $stmt->get_result();
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row; // keep parameters as raw JSON string for display
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'rowId'   => $rowId,
        'videos'  => array_keys($bases),
        'logs'    => $logs,
        'total'   => count($logs)
    ]);
    exit();
}

/**
 * Generates a JSON feed containing video files with their corresponding SRT files,
 * categorized under their respective 'thema', and includes a count of available 'zin' files.
 * Implements caching to serve a cached JSON file if it's not older than 24 hours.
 *
 * @param mysqli $conn The MySQLi connection object.
 */
function generateJSONFeed($conn) {
    // Define cache parameters
    $cacheFile = __DIR__ . '/cache/zinnen_feed.json';
    $cacheDuration = 86400; // 24 hours in seconds

    //        guard let url = URL(string: "https://signcollect.nl/zin/getZinnen.php?action=generateJSONFeed&page=\(page)&limit=100&query=\(encodedQuery)") else {

    $query = $_GET['query'] ?? '';

    // Check if cache exists and is fresh
    // if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
    //     // Serve cached JSON
    //     header('Content-Type: application/json; charset=utf-8');
    //     readfile($cacheFile);
    //     exit();
    // }

    // Base URLs - Adjust these to match your server's configuration
    $mediaBaseURL = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/'; // URL to video files
    $rawMediaBaseURL = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/raw/'; // URL to video files (raw)
    $srtBaseURL = 'https://signcollect.nl/zin/eaf/zin/'; // URL to SRT files
    $domainURL = 'https://signcollect.nl/'; // Base domain URL

    // Fetch data: matched_transcriptions joined with sentences
    $sql = "SELECT mt.a_file, mt.b_file, mt.m_file, mt.r_file, mt.l_file, mt.zOg, mt.post_processed, s.zinArray, s.thema, s.id
            FROM matched_transcriptions mt
            JOIN sentences s ON mt.m_transcription = s.ID
            WHERE mt.zOg = 'Zin' AND mt.added = 1 AND mt.post_processed = 1";

    $result = $conn->query($sql);   
    if (!$result) {
        // Return JSON error response
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Database query failed: ' . $conn->error
        ]);
        exit();
    }

    $zinnen = [];
    while ($row = $result->fetch_assoc()) {
        // Decode the zinArray JSON into a sentence
        $zinWords = json_decode($row['zinArray'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($zinWords)) {
            // Skip if zinArray is invalid
            continue;
        }

        // Join words into a sentence and capitalize the first letter
        $zinSentence = ucfirst(implode(' ', $zinWords));

        // Determine directory path based on post_processed flag
        $mediaDir = ($row['post_processed'] == 1) ? 'post/' : 'raw/';

        // Base URL for video files
        $currentMediaBaseURL = ($row['post_processed'] == 1) ? $mediaBaseURL : $rawMediaBaseURL;

        // Extract base filename without extension and adapt .wav to .mp4
        $baseFilename = preg_replace('/\.(wav|mp4)$/i', '', $row['m_file']);

        // Define paths to SRT files
        $srtFiles = [
            "{$baseFilename}_Signbank_ID_glossen.srt",
            "{$baseFilename}_Nederlands.srt",
            "{$baseFilename}_Gebaar_voor_gebaar.srt"
        ];

        // Check if SRT files exist and collect their URLs
        $existingSRTs = [];
        $eafDir = __DIR__ . '/eaf/zin/';
        foreach ($srtFiles as $srtFile) {
            $srtPath = $eafDir . $srtFile;
            if (file_exists($srtPath)) {
                $existingSRTs[] = $srtBaseURL . $srtFile;
            }
        }
        $id = $row['id'];

        // Prepare video file URLs
        $videoFields = ['a_file', 'b_file', 'm_file', 'l_file', 'r_file'];
        $videoURLs = [];
        foreach ($videoFields as $field) {
            // Adapt .wav to .mp4
            $mp4FilePath = preg_replace('/\.(wav)$/i', '.mp4', $row[$field]);
            $videoURLs[] = $currentMediaBaseURL . $mp4FilePath;
        }

        $count = 1;
            $unique_id = $id;
            while (in_array($unique_id, array_column($zinnen, 'zinId'))) {
                $unique_id = "{$id}_{$count}";
                $count++;
            }

        // Organize the data
        $zinnen[] = [
            'zinId' => $unique_id,
            'zin' => $zinSentence,
            'thema' => ucfirst(strtolower($row['thema'])) ?: 'Uncategorized',
            'videos' => [
                'a_file' => $videoURLs[0],
                'b_file' => $videoURLs[1],
                'm_file' => $videoURLs[2],
                'l_file' => $videoURLs[3],
                'r_file' => $videoURLs[4]
            ],
            'subtitles' => $existingSRTs
        ];
    }

    //filter out items from zinnen that has len of 0 on subtitles
    $zinnen = array_filter($zinnen, function($item) {
        return count($item['subtitles']) > 0;
    });

    if($query){
        $zinnen = array_filter($zinnen, function($item) use ($query) {
            return strpos(strtolower($item['zin']), strtolower($query)) !== false;
        });
    }
    //convert zinnen to array
    $zinnen = array_values($zinnen);

    // Count of available 'zin' files
    $count = count($zinnen);

    // Prepare the final JSON structure
    $jsonData = [
        'count' => $count,
        'domain' => $domainURL,
        'zinnen' => $zinnen,
        'generated_at' => date(DATE_ATOM)
    ];

    // Ensure the cache directory exists
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Save JSON to cache file
    file_put_contents($cacheFile, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Output the JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}


// here we are going to construct ZIP file by getting filenames from matched_transcription table and get the filepath, 
//then we put them in temporary ZIP file and then we serve the ZIP file as download

function downloadZIP($conn)
{
    // Retrieve rowId parameter
    $filename = $_GET['filename'] ?? '';
    $zin = $_GET['zin'] ?? '';
    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => 'Row ID not provided.']);
        exit();
    }

    // Query matched_transcriptions for files matching the given rowId and zOg = 'Zin'
    $sql = "SELECT a_file, b_file, m_file, l_file, r_file FROM matched_transcriptions WHERE m_file = ? AND zOg = 'Zin'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("s", $filename);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'No files found for given row ID.']);
        exit();
    }
    $files = [];
    while ($row = $result->fetch_assoc()) {
        // print_r($row);
        foreach (['a_file', 'b_file', 'm_file', 'l_file', 'r_file'] as $col) {
            if (!empty($row[$col])) {
                $files[] = $row[$col];
            }
        }
    }
    $stmt->close();

    $files = array_unique($files);
    // Define directories for video and annotation files
    $videoDir = __DIR__ . '/../gebarenoverleg_media/studioFilesMini/post/';
    $eafDir   = __DIR__ . '/eaf/zin/';


    $allFiles = [];
    foreach ($files as $file) {
        // Video file path
        //replace .wav with .mp4 in $file
        $file = preg_replace('/\.(wav)$/i', '.mp4', $file);
        $videoPath = $videoDir . $file;

        // echo $videoPath;
        if (file_exists($videoPath)) {
            $allFiles[] = $videoPath;
        }
        // Build corresponding EAF file path (replace extension with .eaf)
        $base = pathinfo($file, PATHINFO_FILENAME);
        $eafFile = $base . '.eaf';
        $eafPath = $eafDir . $eafFile;
        if (file_exists($eafPath)) {
            $allFiles[] = $eafPath;
        }
        // Build corresponding SRT file path (replace extension with .srt)
        $srtFile = $base . '.srt';
        $srtPath = $eafDir . $srtFile;
        if (file_exists($srtPath)) {
            $allFiles[] = $srtPath;
        }
    }
    //we are going to forloop with wildcard $filename with .srt extension in eaf/zin
    //take away extension from $filename
    $zin = preg_replace('/\.(wav|mp4)$/i', '', $filename);
    //then forloop in eafDir with $zin and .srt extension
    $srtFiles = glob($eafDir . $zin . '*.srt');
    foreach ($srtFiles as $srtFile) {
        $allFiles[] = $srtFile;
    }
    


    if (empty($allFiles)) {
        echo json_encode(['success' => false, 'error' => 'No files found to include in ZIP.']);
        exit();
    }

    // Create a temporary ZIP archive using libzip (via ZipArchive)
    $zip = new ZipArchive();
    $tempZipPath = sys_get_temp_dir() . '/' . uniqid('download_', true) . '.zip';
    if ($zip->open($tempZipPath, ZipArchive::CREATE) !== TRUE) {
        echo json_encode(['success' => false, 'error' => 'Cannot create ZIP file with libzip.']);
        exit();
    }

    foreach ($allFiles as $filePath) {
        $zip->addFile($filePath, basename($filePath));
    }
    $zip->close();

    $zin = str_replace(" ", "_", $zin);
    // Serve the ZIP file as a download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zin . '.zip"');
    header('Content-Length: ' . filesize($tempZipPath));
    readfile($tempZipPath);
    unlink($tempZipPath);
    exit();
}

function fetchSubtitles($conn){
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    $filename = $_GET['filename'] ?? '';
    if(empty($filename)){
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
        exit();
    }

    // Get the base filename without extension
    $baseFilename = preg_replace('/\\.(wav|mp4)$/i', '', basename($filename));
    $eafDir = __DIR__ . '/eaf/zin/';
    
    // Define expected subtitle file paths per tier
    $tiers = [
        'Gebaar-voor-Gebaar' => $eafDir . $baseFilename . '_Gebaar-voor-gebaar.srt',
        'Nederlands'        => $eafDir . $baseFilename . '_Nederlands.srt',
        'Signbank ID glossen'=> $eafDir . $baseFilename . '_Signbank_ID_glossen.srt'
    ];
    
    // If any subtitle file does not exist, try to generate it from the EAF file
    $eafPath = $eafDir . $baseFilename . '.eaf';

    // Fallback: if versioned EAF doesn't exist, copy from base EAF
    if (!file_exists($eafPath)) {
        // Extract base filename pattern: MYYYYMMDD_{4digits}
        if (preg_match('/^(M\d{8}_\d{4})/', $baseFilename, $matches)) {
            $extractedBase = $matches[1];
            // Only attempt fallback if current filename differs from extracted base
            if ($extractedBase !== $baseFilename) {
                $baseEafPath = $eafDir . $extractedBase . '.eaf';
                if (file_exists($baseEafPath)) {
                    // Copy base EAF to create versioned file
                    copy($baseEafPath, $eafPath);
                }
            }
        }
    }

    foreach($tiers as $tier => $srtPath){
        if(!file_exists($srtPath)){
            if(file_exists($eafPath)){
                // Call your EAF-to-SRT conversion (assumed to return an associative array with tier keys)
                $conversion = convertEAFtoSRT($eafPath, $baseFilename, $eafDir);
                // For simplicity, assume conversion writes the SRT files
            } else {
                echo json_encode(['success' => false, 'error' => "Neither subtitle nor EAF file exists for $baseFilename"]);
                exit();
            }
        }
    }
    
    // Read each subtitle file content into an array (split by newlines)
    $subtitles = [];
    foreach($tiers as $tier => $srtPath){
        $content = file_exists($srtPath) ? file_get_contents($srtPath) : "";
        // Convert file content to an array of subtitle lines (each element represents one subtitle element)
        $subtitles[$tier] = array_filter(array_map('trim', explode("\n", $content)));
    }
    
    echo json_encode(['success' => true, 'subtitles' => $subtitles]);
    exit();
}

function verifySubtitles($conn) {
    $filename = $_GET['filename'] ?? '';
    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
        exit();
    }

    $baseFilename = preg_replace('/\.(wav|mp4)$/i', '', basename($filename));
    $eafDir = __DIR__ . '/eaf/zin/';

    // Get last saved log entry for this file
    $stmt = $conn->prepare("SELECT parameters FROM sentences_logs WHERE action = 'saveSubtitles' AND parameters LIKE ? ORDER BY datetime DESC LIMIT 1");
    $search = '%"filename":"' . $conn->real_escape_string($baseFilename) . '"%';
    $stmt->bind_param("s", $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $logRow = $result->fetch_assoc();
    $stmt->close();

    if (!$logRow) {
        echo json_encode(['success' => true, 'verified' => false, 'reason' => 'No save log found for this file.']);
        exit();
    }

    $logData = json_decode($logRow['parameters'], true);
    $logTiers = $logData['tiers'] ?? [];

    // Map tier names to SRT filenames
    $tierFiles = [
        'Nederlands' => $eafDir . $baseFilename . '_Nederlands.srt',
        'Gebaar-voor-gebaar' => $eafDir . $baseFilename . '_Gebaar-voor-gebaar.srt',
        'Signbank ID glossen' => $eafDir . $baseFilename . '_Signbank_ID_glossen.srt',
    ];

    // Parse SRT file into array of {start, end, text}
    $parseSrt = function($path) {
        if (!file_exists($path)) return null;
        $content = trim(file_get_contents($path));
        if ($content === '') return [];
        $blocks = preg_split('/\n\s*\n/', $content);
        $entries = [];
        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if (count($lines) >= 3 && preg_match('/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $lines[1], $m)) {
                $toSec = function($t) {
                    $parts = explode(',', $t);
                    $hms = explode(':', $parts[0]);
                    return round((int)$hms[0] * 3600 + (int)$hms[1] * 60 + (int)$hms[2] + (int)$parts[1] / 1000, 3);
                };
                $entries[] = [
                    'start' => $toSec($m[1]),
                    'end' => $toSec($m[2]),
                    'text' => trim($lines[2])
                ];
            }
        }
        return $entries;
    };

    $mismatches = [];
    foreach ($tierFiles as $tierName => $srtPath) {
        $logEntries = $logTiers[$tierName] ?? [];
        $diskEntries = $parseSrt($srtPath);

        if ($diskEntries === null) {
            $mismatches[] = ['tier' => $tierName, 'issue' => 'SRT file missing'];
            continue;
        }

        if (count($logEntries) !== count($diskEntries)) {
            $mismatches[] = [
                'tier' => $tierName,
                'issue' => 'Entry count mismatch',
                'log_count' => count($logEntries),
                'disk_count' => count($diskEntries)
            ];
            continue;
        }

        for ($i = 0; $i < count($logEntries); $i++) {
            $l = $logEntries[$i];
            $d = $diskEntries[$i];
            if (abs($l['start'] - $d['start']) > 0.002 || abs($l['end'] - $d['end']) > 0.002 || $l['text'] !== $d['text']) {
                $mismatches[] = [
                    'tier' => $tierName,
                    'issue' => 'Content mismatch at entry ' . ($i + 1),
                    'log' => $l,
                    'disk' => $d
                ];
            }
        }
    }

    if (empty($mismatches)) {
        echo json_encode(['success' => true, 'verified' => true]);
    } else {
        echo json_encode(['success' => true, 'verified' => false, 'mismatches' => $mismatches]);
    }
    exit();
}

function detailedStats($conn) {
    header('Content-Type: application/json; charset=utf-8');

    // Sentence distribution by video count
    $sql = "SELECT
        COUNT(*) as total_sentences,
        SUM(CASE WHEN video_count = 0 THEN 1 ELSE 0 END) as zinnen_0_videos,
        SUM(CASE WHEN video_count = 1 THEN 1 ELSE 0 END) as zinnen_1_video,
        SUM(CASE WHEN video_count = 2 THEN 1 ELSE 0 END) as zinnen_2_videos,
        SUM(CASE WHEN video_count = 3 THEN 1 ELSE 0 END) as zinnen_3_videos,
        SUM(CASE WHEN video_count >= 4 THEN 1 ELSE 0 END) as zinnen_4plus_videos
    FROM (
        SELECT s.ID,
            (SELECT COUNT(*) FROM matched_transcriptions mt
             WHERE mt.m_transcription = s.ID AND mt.zOg = 'Zin' AND mt.added = 1) as video_count
        FROM sentences s
    ) counts";
    $r = $conn->query($sql);
    $sentenceDist = $r->fetch_assoc();

    // Video-level stats
    $sql = "SELECT
        COUNT(*) as total_videos,
        SUM(CASE WHEN mt.has_mocap = 1 THEN 1 ELSE 0 END) as mocap_yes,
        SUM(CASE WHEN mt.has_mocap = 0 OR mt.has_mocap IS NULL THEN 1 ELSE 0 END) as mocap_no,
        SUM(CASE WHEN COALESCE(s.status_video, '') = 'Klaar' THEN 1 ELSE 0 END) as video_klaar,
        SUM(CASE WHEN COALESCE(s.status_annotatie, '') = 'Klaar' THEN 1 ELSE 0 END) as nederlands_klaar,
        SUM(CASE WHEN COALESCE(s.status_glos, '') = 'Klaar' THEN 1 ELSE 0 END) as glos_klaar,
        SUM(CASE WHEN COALESCE(s.status_gvg, '') = 'Klaar' THEN 1 ELSE 0 END) as gvg_klaar,
        SUM(CASE WHEN s.mcp_status_postprocessing = 1 THEN 1 ELSE 0 END) as mcp_postprocessing_klaar,
        SUM(CASE WHEN COALESCE(s.mcp_status_tijd_annotatie, '') = 'Klaar' THEN 1 ELSE 0 END) as mcp_tijd_annotatie_klaar,
        SUM(CASE WHEN COALESCE(s.mcp_status_tijd_annotatie_gvg, '') = 'Klaar' THEN 1 ELSE 0 END) as mcp_tijd_annotatie_gvg_klaar
    FROM matched_transcriptions mt
    INNER JOIN sentences s ON mt.m_transcription = s.ID
    WHERE mt.zOg = 'Zin' AND mt.added = 1";
    $r = $conn->query($sql);
    $videoStats = $r->fetch_assoc();

    // EAF file stats
    $eafDir = __DIR__ . '/eaf/zin/';
    $eafFiles = glob($eafDir . '*.eaf');
    $eafFilesNoBackup = array_filter($eafFiles, function($f) {
        return strpos(basename($f), 'backup') === false;
    });
    $eafBasenames = array_map(function($f) {
        return pathinfo(basename($f), PATHINFO_FILENAME);
    }, $eafFilesNoBackup);
    $eafSet = array_flip($eafBasenames);

    // Get video basenames to check which have EAF files
    $r = $conn->query("SELECT REPLACE(REPLACE(m_file, '.wav', ''), '.mp4', '') as basename
        FROM matched_transcriptions WHERE zOg = 'Zin' AND added = 1 AND m_file IS NOT NULL");
    $withEaf = 0;
    $withoutEaf = 0;
    while ($row = $r->fetch_assoc()) {
        if (isset($eafSet[$row['basename']])) {
            $withEaf++;
        } else {
            $withoutEaf++;
        }
    }

    echo json_encode([
        'success' => true,
        'sentences' => $sentenceDist,
        'videos' => $videoStats,
        'eaf' => ['with_eaf' => $withEaf, 'without_eaf' => $withoutEaf]
    ]);
    exit();
}

function listVideosWithoutMocap($conn) {
    header('Content-Type: application/json; charset=utf-8');
    $sql = "SELECT mt.id as video_id, mt.m_file, mt.m_transcription as sentence_id,
                s.zinArray, s.thema, s.status_video
            FROM matched_transcriptions mt
            INNER JOIN sentences s ON mt.m_transcription = s.ID
            WHERE mt.zOg = 'Zin' AND mt.added = 1
            AND (mt.has_mocap = 0 OR mt.has_mocap IS NULL)
            ORDER BY s.ID DESC";
    $r = $conn->query($sql);
    $videos = [];
    while ($row = $r->fetch_assoc()) {
        $row['zinArray'] = json_decode($row['zinArray'], true);
        $videos[] = $row;
    }
    echo json_encode(['success' => true, 'videos' => $videos]);
    exit();
}

//here we are going to count rows in table sentences based on status = Klaar, status_annotatie = Klaar, status_glos = Klaar and status=video = Klaar and status = signbank
//also we are going to count how much .eaf files without backup in it are in /web/zin/eaf/zin

function countZinnen($conn)
{
    // Cache for 1 hour
    $cacheFile = __DIR__ . '/cache/countZinnen.json';
    $cacheDuration = 3600; // 1 hour in seconds

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
        $cached = file_get_contents($cacheFile);
        $cachedData = json_decode($cached, true);
        if ($cachedData && isset($cachedData['videos_mocap_ready'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo $cached;
            exit();
        }
    }

    // Updated aggregate sentence counts query using CASE expressions
    $sql = "SELECT
COUNT(*) AS total_sentences,
SUM(CASE WHEN COALESCE(status_gvg, '0') <> 'Klaar' THEN 1 ELSE 0 END) AS count_status_gvg_not_klaar,
SUM(CASE WHEN COALESCE(status_video, '0') = 'Klaar'
       AND COALESCE(status_annotatie, '0') = 'Klaar'
       AND COALESCE(status_glos, '0') = 'Klaar'
       AND COALESCE(status_gvg, '0') = 'Klaar'
       THEN 1 ELSE 0 END) AS ready_sentences,
SUM(CASE WHEN COALESCE(status_video, '0') = 'Klaar' THEN 1 ELSE 0 END) AS count_status_video_klaar,
SUM(CASE WHEN COALESCE(status_annotatie, '0') = 'Klaar' THEN 1 ELSE 0 END) AS count_status_annotatie_klaar,
SUM(CASE WHEN COALESCE(status_glos, '0') = 'Klaar' THEN 1 ELSE 0 END) AS count_status_glos_klaar,
SUM(CASE WHEN COALESCE(status_gvg, '0') = 'Klaar' THEN 1 ELSE 0 END) AS count_status_gvg_klaar,
SUM(CASE WHEN COALESCE(status_video, '0') <> 'Klaar' THEN 1 ELSE 0 END) AS count_status_video_not_klaar,
SUM(CASE WHEN COALESCE(status_annotatie, '0') <> 'Klaar' THEN 1 ELSE 0 END) AS count_status_annotatie_not_klaar,
SUM(CASE WHEN COALESCE(status_glos, '0') <> 'Klaar' THEN 1 ELSE 0 END) AS count_status_glos_not_klaar,
SUM(CASE WHEN COALESCE(status_annotatie, '0') = 'Controle nodig' OR COALESCE(status_gvg, '0') = 'Check nodig' THEN 1 ELSE 0 END) AS count_josje_check,
(SELECT COUNT(*) FROM sentences s2 WHERE NOT EXISTS (SELECT 1 FROM matched_transcriptions mt WHERE mt.m_transcription = s2.ID AND mt.added = 1 AND mt.zOg = 'Zin')) AS sentences_without_video,
(SELECT COUNT(*) FROM sentences s3 WHERE EXISTS (SELECT 1 FROM matched_transcriptions mt2 WHERE mt2.m_transcription = s3.ID AND mt2.added = 1 AND mt2.zOg = 'Zin')) AS sentences_with_video,
(SELECT COUNT(*) FROM matched_transcriptions WHERE zOg = 'Zin' AND has_mocap = 1 AND added = 1) AS videos_with_mocap,
(SELECT COUNT(*) FROM matched_transcriptions WHERE zOg = 'Zin' AND (has_mocap = 0 OR has_mocap IS NULL) AND added = 1) AS videos_without_mocap,
(SELECT COUNT(*) FROM sentences s4 INNER JOIN (SELECT m_transcription, has_mocap, ROW_NUMBER() OVER (PARTITION BY m_transcription ORDER BY m_file DESC) as rn FROM matched_transcriptions WHERE zOg = 'Zin' AND added = '1' AND m_file IS NOT NULL) latest ON latest.m_transcription = s4.ID AND latest.rn = 1 WHERE s4.status_video = 'Klaar' AND (latest.has_mocap IS NULL OR latest.has_mocap = 0)) AS videos_mocap_ready

    FROM sentences";
    $result = $conn->query($sql);
    $counts = ($result && $row = $result->fetch_assoc()) ? $row : [];

    // Count videos for each status category (only active videos: added=1, zOg='Zin')
    $videoSql = "SELECT
        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         WHERE mt.added = 1 AND mt.zOg = 'Zin') AS total_videos,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_video, '0') = 'Klaar'
         AND COALESCE(s.status_annotatie, '0') = 'Klaar'
         AND COALESCE(s.status_glos, '0') = 'Klaar'
         AND COALESCE(s.status_gvg, '0') = 'Klaar') AS video_ready_sentences,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_video, '0') = 'Klaar') AS video_count_status_video_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_video, '0') <> 'Klaar') AS video_count_status_video_not_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_annotatie, '0') = 'Klaar') AS video_count_status_annotatie_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_annotatie, '0') <> 'Klaar') AS video_count_status_annotatie_not_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_glos, '0') = 'Klaar') AS video_count_status_glos_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_glos, '0') <> 'Klaar') AS video_count_status_glos_not_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_gvg, '0') = 'Klaar') AS video_count_status_gvg_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND COALESCE(s.status_gvg, '0') <> 'Klaar') AS video_count_status_gvg_not_klaar,

        (SELECT COUNT(mt.ID) FROM matched_transcriptions mt
         INNER JOIN sentences s ON mt.m_transcription = s.ID
         WHERE mt.added = 1 AND mt.zOg = 'Zin'
         AND (COALESCE(s.status_annotatie, '0') = 'Controle nodig'
              OR COALESCE(s.status_gvg, '0') = 'Check nodig')) AS video_count_josje_check";

    $videoResult = $conn->query($videoSql);
    $videoCounts = ($videoResult && $videoRow = $videoResult->fetch_assoc()) ? $videoRow : [];

    // ...existing code for counting .eaf files...
    $eafDir = __DIR__ . '/eaf/zin/';
    $files = glob($eafDir . '*.eaf');
    $eafCount = 0;
    foreach ($files as $eafFile) {
        if (strpos($eafFile, 'backup') === false) {
            $eafCount++;
        }
    }

    // Merge sentence counts, video counts, and EAF count
    $json = json_encode(array_merge(['success' => true, 'eaf_files' => $eafCount], $counts, $videoCounts));

    // Write to cache
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($cacheFile, $json);

    echo $json;
    exit();
}

function saveSubtitlesAndEAFFiles($conn) {
    // Read JSON input from the request body
    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!$inputData) {
        echo json_encode(['success' => false, 'error' => 'Invalid input JSON.']);
        exit();
    }
    // If the JSON contains a "subtitles" property, use that value.
    $subtitleData = isset($inputData['subtitles']) ? $inputData['subtitles'] : $inputData;


    
    // Retrieve filename from GET parameter and derive base filename
    $filename = $_GET['filename'] ?? '';
    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
        exit();
    }

    //we create url for media_url
    $baseFilename = preg_replace('/\\.(wav|mp4)$/i', '', basename($filename));
    $eafDir = __DIR__ . '/eaf/zin/';

    $media_url = 'https://signcollect.nl/gebarenoverleg_media/studioFilesMini/post/' . $baseFilename . ".mp4";

    
    // Map tiers: 1 = "Gebaar-voor-gebaar", 0 = "Nederlands", 2 = "Signbank_ID_glossen"
    $tierNames = [1 => 'Gebaar-voor-gebaar', 0 => 'Nederlands', 2 => 'Signbank ID glossen'];
    
    // Group annotations by tier
    $grouped = [];
    foreach ($subtitleData as $item) {
        $tier = $item['tier'] ?? null;
        if ($tier === null || !isset($tierNames[$tier])) {
            continue;
        }
        $grouped[$tier][] = $item;
    }
    
    // Helper: convert seconds (float) to SRT time format
    $convertSecondsToSrt = function($sec) {
        $ms = round($sec * 1000);
        $hours = floor($ms / 3600000);
        $minutes = floor(($ms % 3600000) / 60000);
        $seconds = floor(($ms % 60000) / 1000);
        $milliseconds = $ms % 1000;
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $seconds, $milliseconds);
    };
    
    // Generate and save SRT files for each expected tier (even if empty)
    foreach ($tierNames as $tierKey => $tierName) {
        $annotations = $grouped[$tierKey] ?? []; // Default to empty if tier missing
        $srtContent = "";
        $index = 1;
        foreach ($annotations as $anno) {
            $start = $convertSecondsToSrt($anno['start']);
            $end = $convertSecondsToSrt($anno['end']);
            $text = trim($anno['text'] ?? '');
            if ($text === '') {
                $text = '-';
            }
            $srtContent .= "{$index}\n{$start} --> {$end}\n{$text}\n\n";
            $index++;
        }
        $srtPath = $eafDir . $baseFilename . '_' . $tierName . ".srt";
        //for srtpath check if there is whitespace, otherwise replace it with underscore
        $srtPath = preg_replace('/\s+/', '_', $srtPath);
        // Backup existing SRT file if it exists
        if (file_exists($srtPath)) {
            $backupSrt = $eafDir . $baseFilename . '_' . $tierName . '_backup_' . date('Ymd_His') . '.srt';
            //if there is any whitespace, replace it with underscore
            $backupSrt = preg_replace('/\s+/', '_', $backupSrt);
            rename($srtPath, $backupSrt);
        }
        if (file_put_contents($srtPath, $srtContent) === false) {
            echo json_encode(['success' => false, 'error' => "Failed to write SRT for tier {$tierName}"]);
            exit();
        }
    }

    // Log subtitle save per tier for recovery purposes
    $logEntries = [];
    foreach ($tierNames as $tierKey => $tierName) {
        $annotations = $grouped[$tierKey] ?? [];
        $tierLog = [];
        foreach ($annotations as $anno) {
            $tierLog[] = [
                'start' => round($anno['start'], 3),
                'end' => round($anno['end'], 3),
                'text' => trim($anno['text'] ?? '')
            ];
        }
        $logEntries[$tierName] = $tierLog;
    }
    logAction($conn, 'saveSubtitles', [
        'filename' => $baseFilename,
        'tiers' => $logEntries
    ]);

    // Collect all time values (in milliseconds)
    $allTimes = [];
    foreach ($grouped as $tier => $annotations) {
        foreach ($annotations as $anno) {
            $allTimes[] = round($anno['start'] * 1000);
            $allTimes[] = round($anno['end'] * 1000);
        }
    }
    $allTimes = array_unique($allTimes);
    sort($allTimes);
    
    // Create EAF XML document with namespace and schema location
    $eafDoc = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ANNOTATION_DOCUMENT xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.mpi.nl/tools/elan/EAFv3.0.xsd"></ANNOTATION_DOCUMENT>');
    $eafDoc->addAttribute('AUTHOR', '');
    $eafDoc->addAttribute('DATE', date('c'));
    $eafDoc->addAttribute('FORMAT', '3.0');
    $eafDoc->addAttribute('VERSION', '3.0');
    
    // HEADER
    $header = $eafDoc->addChild('HEADER');
    $header->addAttribute('MEDIA_FILE', '');
    $header->addAttribute('TIME_UNITS', 'milliseconds');
    $mediaDescriptor = $header->addChild('MEDIA_DESCRIPTOR');
    $mediaDescriptor->addAttribute('MEDIA_URL', $media_url);
    $mediaDescriptor->addAttribute('MIME_TYPE', 'video/mp4');
    
    // TIME_ORDER with TIME_SLOT elements
    $timeOrder = $eafDoc->addChild('TIME_ORDER');
    $timeSlotMap = [];
    $slotId = 1;
    foreach ($allTimes as $timeMs) {
        $tsId = 'ts' . $slotId;
        $ts = $timeOrder->addChild('TIME_SLOT');
        $ts->addAttribute('TIME_SLOT_ID', $tsId);
        $ts->addAttribute('TIME_VALUE', $timeMs);
        $timeSlotMap[$timeMs] = $tsId;
        $slotId++;
    }
    
    // Always add TIER elements for all expected tiers, even if empty
    foreach ($tierNames as $tierKey => $tierName) {
        $tierElement = $eafDoc->addChild('TIER');
        $tierElement->addAttribute('TIER_ID', $tierName);
        $tierElement->addAttribute('LINGUISTIC_TYPE_REF', 'default-lt');
        $annoIndex = 1;
        $annotations = $grouped[$tierKey] ?? []; // Get annotations or an empty array
        foreach ($annotations as $anno) {
            $annotationEl = $tierElement->addChild('ANNOTATION');
            $alignable = $annotationEl->addChild('ALIGNABLE_ANNOTATION');
            $alignable->addAttribute('ANNOTATION_ID', $tierName . '_' . $annoIndex);
            $startMs = round($anno['start'] * 1000);
            $endMs   = round($anno['end'] * 1000);
            $alignable->addAttribute('TIME_SLOT_REF1', $timeSlotMap[$startMs]);
            $alignable->addAttribute('TIME_SLOT_REF2', $timeSlotMap[$endMs]);
            $alignable->addChild('ANNOTATION_VALUE', htmlspecialchars($anno['text'] ?? ''));
            $annoIndex++;
        }
    }
    
    // Define the linguistic type for the tiers
    $linguisticType = $eafDoc->addChild('LINGUISTIC_TYPE');
    $linguisticType->addAttribute('LINGUISTIC_TYPE_ID', 'default-lt');
    $linguisticType->addAttribute('TIME_ALIGNABLE', 'true');
    $linguisticType->addAttribute('GRAPHIC_REFERENCES', 'false');
    
    // Add required constraints
    $constraints = [
        ['Time subdivision of parent annotation\'s time interval, no time gaps allowed within this interval', 'Time_Subdivision'],
        ['Symbolic subdivision of a parent annotation. Annotations referring to the same parent are ordered', 'Symbolic_Subdivision'],
        ['1-1 association with a parent annotation', 'Symbolic_Association'],
        ['Time alignable annotations within the parent annotation\'s time interval, gaps are allowed', 'Included_In']
    ];
    
    foreach ($constraints as $c) {
        $constraint = $eafDoc->addChild('CONSTRAINT');
        $constraint->addAttribute('DESCRIPTION', $c[0]);
        $constraint->addAttribute('STEREOTYPE', $c[1]);
    }
        
    $eafPath = $eafDir . $baseFilename . '.eaf';
    // Backup existing EAF file if it exists
    if (file_exists($eafPath)) {
        $backupEaf = $eafDir . $baseFilename . '_backup_' . date('Ymd_His') . '.eaf';
        rename($eafPath, $backupEaf);
    }
    if ($eafDoc->asXML($eafPath)) {
        // Real-time sync: Extract Nederlands tier and update database immediately
        require_once 'syncEafToDatabase.php';

        // Find the sentence ID for this EAF file (try both .mp4 and .wav)
        $row = null;
        foreach (['.mp4', '.wav'] as $ext) {
            $videoFilename = $baseFilename . $ext;
            $stmt = $conn->prepare("SELECT m_transcription FROM matched_transcriptions WHERE m_file = ? AND zOg = 'zin' AND added = '1' LIMIT 1");
            $stmt->bind_param("s", $videoFilename);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['m_transcription'])) break;
            $row = null;
        }

        if ($row && !empty($row['m_transcription'])) {
            $sentenceId = $row['m_transcription'];
            $nedResult = extractNederlandsFromEaf($eafPath);
            $gvgResult = extractGvgFromEaf($eafPath);
            $glossResult = extractGlossesFromEaf($eafPath);

            $nedText = $nedResult['success'] ? $nedResult['text'] : '';
            $gvgText = $gvgResult['success'] ? $gvgResult['text'] : '[]';
            $glossText = $glossResult['success'] ? $glossResult['text'] : '[]';

            $updateStmt = $conn->prepare(
                "UPDATE sentences SET zinStringEAF=?, gvg=?, glosses=?, eaf_sync_status='synced', eaf_synced_at=NOW(), eaf_sync_error=NULL WHERE ID=?"
            );
            $updateStmt->bind_param("sssi", $nedText, $gvgText, $glossText, $sentenceId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        echo json_encode(['success' => true, 'message' => "EAF file created at {$eafPath}"]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write EAF file.']);
    }
    exit();
}

function findgvg($conn){
    $gvg = $_GET['gvg'] ?? '';
    if(empty($gvg)){
        echo json_encode(['success' => false, 'error' => 'GVG not provided.']);
        exit();
    }
    $likegvg = "%" . $gvg . "%";
    $sql = "SELECT * FROM sentences WHERE gvg LIKE ?";
    $stmt = $conn->prepare($sql);
    if(!$stmt){
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("s", $likegvg);
    if(!$stmt->execute()){
        echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        exit();
    }
    $result = $stmt->get_result();
    $rows = [];
    while($row = $result->fetch_assoc()){
        // Decode the zinArray JSON into an array
        $row['zinArray'] = json_decode($row['zinArray'], true);
        $rows[] = $row;
    }
    $stmt->close();
    
    // Format the response in the same structure as fetchSentences
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit();
}


function findGloss($conn){
    $gloss = $_GET['gloss'] ?? '';
    if(empty($gloss)){
        echo json_encode(['success' => false, 'error' => 'Gloss not provided.']);
        exit();
    }
    $likeGloss = "%" . $gloss . "%";
    $sql = "SELECT * FROM sentences WHERE glosses LIKE ?";
    $stmt = $conn->prepare($sql);
    if(!$stmt){
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("s", $likeGloss);
    if(!$stmt->execute()){
        echo json_encode(['success' => false, 'error' => 'Execute failed: ' . $stmt->error]);
        exit();
    }
    $result = $stmt->get_result();
    $rows = [];
    while($row = $result->fetch_assoc()){
        $rows[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit();
}

/**
 * Fetch zinArray based on video filename
 */
function fetchZinArrayByFilename($conn) {
    $filename = $_GET['filename'] ?? '';

    if (empty($filename)) {
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
        exit();
    }

    // Sanitize filename
    $filename = basename($filename);

    // First, find the sentences.ID (m_transcription) from matched_transcriptions
    $sql_mt = "SELECT m_transcription FROM matched_transcriptions WHERE m_file = ? AND zOg = 'Zin' LIMIT 1";
    $stmt_mt = $conn->prepare($sql_mt);
    if (!$stmt_mt) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed (matched_transcriptions): ' . $conn->error]);
        exit();
    }
    $stmt_mt->bind_param("s", $filename);
    if (!$stmt_mt->execute()) {
        echo json_encode(['success' => false, 'error' => 'Execute failed (matched_transcriptions): ' . $stmt_mt->error]);
        exit();
    }
    $result_mt = $stmt_mt->get_result();
    $row_mt = $result_mt->fetch_assoc();
    $stmt_mt->close();

    if (!$row_mt || empty($row_mt['m_transcription'])) {
        echo json_encode(['success' => false, 'error' => 'No matching sentence found for the given filename.']);
        exit();
    }

    $sentenceId = $row_mt['m_transcription'];

    // Now, fetch the zinArray from the sentences table using the ID
    $sql_s = "SELECT zinArray, ID as sentenceRowId, status_video, status_annotatie, status_glos, status_gvg, video_count, mcp_status_postprocessing, mcp_status_tijd_annotatie, mcp_status_tijd_annotatie_gvg FROM sentences WHERE ID = ? LIMIT 1";
    $stmt_s = $conn->prepare($sql_s);
    if (!$stmt_s) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed (sentences): ' . $conn->error]);
        exit();
    }
    $stmt_s->bind_param("i", $sentenceId);
    if (!$stmt_s->execute()) {
        echo json_encode(['success' => false, 'error' => 'Execute failed (sentences): ' . $stmt_s->error]);
        exit();
    }
    $result_s = $stmt_s->get_result();
    $row_s = $result_s->fetch_assoc();
    $stmt_s->close();

    if (!$row_s || empty($row_s['zinArray'])) {
        echo json_encode(['success' => false, 'error' => 'Sentence found, but zinArray is empty or missing.']);
        exit();
    }

    // Decode the JSON zinArray
    $zinArray = json_decode($row_s['zinArray'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Failed to decode zinArray JSON.']);
        exit();
    }

    // MCP postprocessing is stored numerically (1 = Klaar, 2 = Check nodig, NULL = Niet Klaar);
    // map it back to the dropdown label so the editor can select it directly.
    $mcpPost = $row_s['mcp_status_postprocessing'] ?? null;
    if ($mcpPost === null || $mcpPost === '') {
        $mcpPostLabel = 'Niet Klaar';
    } elseif ((int)$mcpPost === 1) {
        $mcpPostLabel = 'Klaar';
    } elseif ((int)$mcpPost === 2) {
        $mcpPostLabel = 'Check nodig';
    } else {
        $mcpPostLabel = (string)$mcpPost;
    }

    // Return the zinArray along with status fields
    echo json_encode([
        'success' => true,
        'zinArray' => $zinArray,
        'sentenceRowId' => $row_s['sentenceRowId'] ?? null,
        'status_video' => $row_s['status_video'] ?? '',
        'status_annotatie' => $row_s['status_annotatie'] ?? '',
        'status_glos' => $row_s['status_glos'] ?? '',
        'status_gvg' => $row_s['status_gvg'] ?? '',
        'video_count' => isset($row_s['video_count']) ? (int)$row_s['video_count'] : 0,
        'mcp_status_postprocessing' => $mcpPostLabel,
        'mcp_status_tijd_annotatie' => $row_s['mcp_status_tijd_annotatie'] ?? '',
        'mcp_status_tijd_annotatie_gvg' => $row_s['mcp_status_tijd_annotatie_gvg'] ?? ''
    ]);
    exit();
}

function fetchActionStats($conn) {
    $period = $_GET['period'] ?? 'daily';
    
    try {
        $stats = [
            'overview' => getOverviewStats($conn),
            'timeline' => getTimelineStats($conn, $period),
            'actionTypes' => getActionTypeStats($conn),
            'recentActivity' => getRecentActivity($conn),
            'topUsers' => getTopUsers($conn)
        ];
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch statistics: ' . $e->getMessage()
        ]);
    }
    exit();
}

function getOverviewStats($conn) {
    // Total actions
    $totalQuery = "SELECT COUNT(*) as total FROM sentences_logs";
    $totalResult = $conn->query($totalQuery);
    $totalActions = $totalResult->fetch_assoc()['total'];
    
    // Unique users
    $usersQuery = "SELECT COUNT(DISTINCT ip_address) as unique_users FROM sentences_logs";
    $usersResult = $conn->query($usersQuery);
    $uniqueUsers = $usersResult->fetch_assoc()['unique_users'];
    
    // Today's actions
    $todayQuery = "SELECT COUNT(*) as today FROM sentences_logs WHERE DATE(datetime) = CURDATE()";
    $todayResult = $conn->query($todayQuery);
    $todayActions = $todayResult->fetch_assoc()['today'];
    
    // Yesterday's actions for comparison
    $yesterdayQuery = "SELECT COUNT(*) as yesterday FROM sentences_logs WHERE DATE(datetime) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $yesterdayResult = $conn->query($yesterdayQuery);
    $yesterdayActions = $yesterdayResult->fetch_assoc()['yesterday'];
    
    // Calculate percentage change
    $todayChange = $yesterdayActions > 0 ? round((($todayActions - $yesterdayActions) / $yesterdayActions) * 100, 1) : 0;
    
    // Top action
    $topActionQuery = "SELECT action, COUNT(*) as count FROM sentences_logs GROUP BY action ORDER BY count DESC LIMIT 1";
    $topActionResult = $conn->query($topActionQuery);
    $topActionRow = $topActionResult->fetch_assoc();
    $topAction = $topActionRow['action'] ?? 'None';
    $topActionCount = $topActionRow['count'] ?? 0;
    
    return [
        'totalActions' => (int)$totalActions,
        'uniqueUsers' => (int)$uniqueUsers,
        'todayActions' => (int)$todayActions,
        'todayChange' => $todayChange,
        'topAction' => $topAction,
        'topActionCount' => (int)$topActionCount
    ];
}

function getTimelineStats($conn, $period) {
    switch ($period) {
        case 'daily':
            return getDailyStats($conn);
        case 'weekly':
            return getWeeklyStats($conn);
        case 'monthly':
            return getMonthlyStats($conn);
        default:
            return getDailyStats($conn);
    }
}

function getDailyStats($conn) {
    $query = "
        SELECT 
            DATE(datetime) as date,
            action,
            COUNT(*) as count
        FROM sentences_logs 
        WHERE datetime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(datetime), action
        ORDER BY date ASC, action ASC
    ";
    
    $result = $conn->query($query);
    $stats = [];
    
    while ($row = $result->fetch_assoc()) {
        $stats[] = [
            'date' => $row['date'],
            'action' => $row['action'],
            'count' => (int)$row['count']
        ];
    }
    
    return $stats;
}

function getWeeklyStats($conn) {
    $query = "
        SELECT 
            YEARWEEK(datetime, 1) as yearweek,
            WEEK(datetime, 1) as week,
            YEAR(datetime) as year,
            action,
            COUNT(*) as count
        FROM sentences_logs 
        WHERE datetime >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
        GROUP BY YEARWEEK(datetime, 1), WEEK(datetime, 1), YEAR(datetime), action
        ORDER BY yearweek ASC, action ASC
    ";
    
    $result = $conn->query($query);
    $stats = [];
    
    while ($row = $result->fetch_assoc()) {
        $stats[] = [
            'date' => $row['year'] . '-W' . sprintf('%02d', $row['week']),
            'week' => (int)$row['week'],
            'action' => $row['action'],
            'count' => (int)$row['count']
        ];
    }
    
    return $stats;
}

function getMonthlyStats($conn) {
    $query = "
        SELECT 
            YEAR(datetime) as year,
            MONTH(datetime) as month,
            action,
            COUNT(*) as count
        FROM sentences_logs 
        WHERE datetime >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY YEAR(datetime), MONTH(datetime), action
        ORDER BY year ASC, month ASC, action ASC
    ";
    
    $result = $conn->query($query);
    $stats = [];
    
    while ($row = $result->fetch_assoc()) {
        $stats[] = [
            'date' => sprintf('%04d-%02d-01', $row['year'], $row['month']),
            'action' => $row['action'],
            'count' => (int)$row['count']
        ];
    }
    
    return $stats;
}

function getActionTypeStats($conn) {
    $query = "
        SELECT 
            action,
            COUNT(*) as count
        FROM sentences_logs 
        GROUP BY action
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $result = $conn->query($query);
    $stats = [];
    
    while ($row = $result->fetch_assoc()) {
        $stats[] = [
            'action' => $row['action'],
            'count' => (int)$row['count']
        ];
    }
    
    return $stats;
}

function getRecentActivity($conn) {
    $query = "
        SELECT 
            action,
            ip_address,
            datetime
        FROM sentences_logs 
        ORDER BY datetime DESC
        LIMIT 10
    ";
    
    $result = $conn->query($query);
    $activity = [];
    
    while ($row = $result->fetch_assoc()) {
        $activity[] = [
            'action' => $row['action'],
            'ip_address' => $row['ip_address'],
            'datetime' => $row['datetime']
        ];
    }
    
    return $activity;
}

function getTopUsers($conn) {
    $query = "
        SELECT 
            ip_address,
            COUNT(*) as actionCount,
            MAX(datetime) as lastActivity,
            (
                SELECT action 
                FROM sentences_logs sl2 
                WHERE sl2.ip_address = sl1.ip_address 
                GROUP BY action 
                ORDER BY COUNT(*) DESC 
                LIMIT 1
            ) as topAction
        FROM sentences_logs sl1
        GROUP BY ip_address
        ORDER BY actionCount DESC
        LIMIT 10
    ";
    
    $result = $conn->query($query);
    $users = [];
    
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'ip_address' => $row['ip_address'],
            'actionCount' => (int)$row['actionCount'],
            'lastActivity' => $row['lastActivity'],
            'topAction' => $row['topAction']
        ];
    }
    
    return $users;
}

/**
 * Process segmentation - Auto-segmentation using WebSocket server
 */
function processSegmentation($conn) {
    // Include WebSocket client class
    require_once __DIR__ . '/SignSegmentationClient.php';

    // Read JSON input
    $inputData = json_decode(file_get_contents('php://input'), true);

    if (!$inputData || !isset($inputData['filename'])) {
        echo json_encode(['success' => false, 'error' => 'Filename not provided.']);
        exit();
    }

    // Sanitize filename to prevent path traversal
    $baseFilename = basename($inputData['filename']);
    $baseFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $baseFilename);

    // Construct .hamer file path
    $rawDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
    $hamerPath = $rawDir . $baseFilename . '.hamer';

    // Check if .hamer file exists
    if (!file_exists($hamerPath)) {
        echo json_encode([
            'success' => false,
            'error' => 'Hamer file not available for this video.'
        ]);
        exit();
    }

    try {
        // Initialize WebSocket client
        $client = new SignSegmentationClient('ws://localhost:8765');

        // Process the hamer file (fps = 60)
        $result = $client->processHamerFile($hamerPath, 60);

        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'vtt_content' => $result['vtt_content'],
                'metadata' => $result['metadata']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error_message'] ?? 'Segmentation processing failed.'
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    }
}

/**
 * Process segmentation with streaming progress updates via SSE
 */
function processSegmentationStreaming($conn) {
    // Disable ALL output buffering
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Set SSE headers FIRST
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Disable nginx buffering

    // Disable Apache compression
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }

    // Turn off output buffering and enable implicit flush
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);
    ob_implicit_flush(true);

    // Send padding to fill buffers (Apache needs 1KB to start sending)
    echo str_repeat(' ', 1024) . "\n";
    flush();

    // Read JSON input
    $inputData = json_decode(file_get_contents('php://input'), true);

    if (!$inputData || !isset($inputData['filename'])) {
        sendSSE(['status' => 'error', 'message' => 'Filename not provided.']);
        return;
    }

    // Sanitize filename to prevent path traversal
    $baseFilename = basename($inputData['filename']);
    $baseFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $baseFilename);

    // Construct .hamer file path
    $rawDir = '/web/gebarenoverleg_media/studioFilesMini/raw/';
    $hamerPath = $rawDir . $baseFilename . '.hamer';

    // Check if .hamer file exists
    if (!file_exists($hamerPath)) {
        sendSSE([
            'status' => 'error',
            'message' => 'Hamer file not available for this video.'
        ]);
        return;
    }

    // Send initial status
    sendSSE(['status' => 'starting', 'message' => 'Connecting to segmentation service...']);

    try {
        // Include WebSocket client class
        require_once __DIR__ . '/SignSegmentationClient.php';

        // Initialize WebSocket client
        $client = new SignSegmentationClient('ws://localhost:8765');

        // Define progress callback to stream updates to client
        $progressCallback = function($progressData) {
            sendSSE([
                'status' => 'progress',
                'stage' => $progressData['stage'] ?? 'processing',
                'percentage' => $progressData['percentage'] ?? 0,
                'message' => $progressData['message'] ?? 'Processing...'
            ]);
        };

        // Process the hamer file with progress callback (fps = 60)
        $result = $client->processHamerFile($hamerPath, 60, $progressCallback);

        if ($result['success']) {
            // Debug: log what we received
            $debugInfo = "=== SEGMENTATION RESULT ===\n";
            $debugInfo .= "Success: " . ($result['success'] ? 'true' : 'false') . "\n";
            $debugInfo .= "VTT content length: " . strlen($result['vtt_content'] ?? '') . "\n";
            $debugInfo .= "Result keys: " . implode(', ', array_keys($result)) . "\n";
            $debugInfo .= "VTT preview: " . substr($result['vtt_content'] ?? '', 0, 200) . "\n";
            $debugInfo .= "About to send completed message...\n";
            file_put_contents('/web/zin/debug_segmentation.log', $debugInfo, FILE_APPEND);

            // Send completed message
            $completedMessage = [
                'status' => 'completed',
                'vtt_content' => $result['vtt_content'] ?? '',
                'metadata' => $result['metadata'] ?? []
            ];

            file_put_contents('/web/zin/debug_segmentation.log',
                "Completed message JSON: " . json_encode($completedMessage) . "\n",
                FILE_APPEND);

            sendSSE($completedMessage);

            file_put_contents('/web/zin/debug_segmentation.log',
                "Completed message SENT\n===========================\n",
                FILE_APPEND);
        } else {
            sendSSE([
                'status' => 'error',
                'message' => $result['error_message'] ?? 'Segmentation processing failed.'
            ]);
        }

    } catch (Exception $e) {
        sendSSE([
            'status' => 'error',
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
}

/**
 * Send data as Server-Sent Event with aggressive flushing
 */
function sendSSE($data) {
    echo "data: " . json_encode($data) . "\n\n";

    // Flush all output buffers
    while (ob_get_level() > 0) {
        ob_flush();
    }

    // Force flush to client
    flush();

    // Give the server a moment to send
    usleep(1000); // 1ms
}

/**
 * List zin videos with their latest mocap take, baked GLB and gloss SRT,
 * filtered by the MCP status columns. See mocapFiles.php for the join.
 */
function listMocapFiles($conn) {
    header('Content-Type: application/json; charset=utf-8');

    $force = ($_GET['refreshIndex'] ?? '') === '1';
    $index = mocap_get_index(MOCAP_CACHE, MOCAP_FBX_DIR, MOCAP_GLB_DIR, MOCAP_CACHE_TTL, $force);

    // A failed directory scan (rclone mount hiccup, permissions, etc.) must
    // never be reported as "success: true, total: 0" — that is
    // indistinguishable in the UI from a genuinely unbaked corpus. See
    // mocap_build_index()'s 'ok' flag in mocapFiles.php.
    if (empty($index['ok'])) {
        echo json_encode([
            'success' => false,
            'error'   => 'Kon de mocap-bestandenindex niet opbouwen (map-scan mislukte); probeer het later opnieuw.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit();
    }

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
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

/**
 * Get the latest motion capture FBX file for a given video
 */
function getLatestMocapFile($conn) {
    $mFile = $_GET['mFile'] ?? '';

    if (empty($mFile)) {
        echo json_encode(['success' => false, 'error' => 'No m_file provided']);
        exit();
    }

    // Extract base filename (e.g., "M20240925_1824" from "M20240925_1824.wav")
    $baseFilename = preg_replace('/\.(wav|mp4)$/i', '', $mFile);

    // FBX directory path
    $fbxDir = '/web/gebarenoverleg_media/fbx/';

    // Find all FBX files matching pattern: {baseFilename}_*_*.fbx
    $pattern = $fbxDir . $baseFilename . '_*_*.fbx';
    $matchingFiles = glob($pattern);

    if (empty($matchingFiles)) {
        echo json_encode(['success' => true, 'hasMocap' => false]);
        exit();
    }

    // Extract take numbers and find highest
    $maxTake = -1;
    $latestFile = null;

    foreach ($matchingFiles as $file) {
        $filename = basename($file);

        // Extract last number before .fbx (the take number)
        if (preg_match('/_(\d+)\.fbx$/', $filename, $matches)) {
            $takeNumber = intval($matches[1]);
            if ($takeNumber > $maxTake) {
                $maxTake = $takeNumber;
                $latestFile = $filename;
            }
        }
    }

    if ($latestFile) {
        // The Bewerk Motion Capture button is only shown for post-processed videos
        // (mcp_status_postprocessing == 1), so always point at the post-processed glb,
        // which lives in fbx/post_processed/ under the same filename.
        $glbFilename = preg_replace('/\.fbx$/i', '.glb', $latestFile);
        $glbUrl = '/gebarenoverleg_media/fbx/post_processed/' . $glbFilename;

        // Preferred output: the FBXtoGLBCompression pipeline's GLB plus its facial
        // shape-key sidecar (see viconSync/cc_pipeline/README.md). It only exists for
        // captures whose CC export has been converted, so the caller falls back to
        // $glbUrl and the older editor when ccGlbUrl is null.
        $baseName = preg_replace('/\.fbx$/i', '', $latestFile);
        $ccGlbFilename = $baseName . '_anim.glb';
        $ccGlbPath = '/web/gebarenoverleg_media/fbx/cc_pipeline/' . $ccGlbFilename;
        $ccShapekeysPath = '/web/gebarenoverleg_media/fbx/cc_pipeline/' . $baseName . '_shapekeys.json';

        // Require both halves: a GLB without its sidecar would load with a frozen face.
        $ccGlbUrl = null;
        if (is_readable($ccGlbPath) && is_readable($ccShapekeysPath)) {
            $ccGlbUrl = '/gebarenoverleg_media/fbx/cc_pipeline/' . $ccGlbFilename;
        }

        echo json_encode([
            'success' => true,
            'hasMocap' => true,
            'fbxFilename' => $latestFile,
            'glbUrl' => $glbUrl,
            'ccGlbUrl' => $ccGlbUrl,
            'takeNumber' => $maxTake
        ]);
    } else {
        echo json_encode(['success' => true, 'hasMocap' => false]);
    }

    exit();
}

?>