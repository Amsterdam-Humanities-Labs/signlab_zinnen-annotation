<?php
/**
 * Test script for Motion Capture API endpoint
 *
 * Usage: php test_mocap_api.php
 *
 * Tests the getLatestMocapFile function with various inputs
 */

// Include the MySQL configuration
include '../mysql_config.php';

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "=== Motion Capture API Test Suite ===\n\n";

// Test cases
$testCases = [
    [
        'name' => 'Valid file with multiple takes',
        'mFile' => 'M20240925_1824.wav',
        'expected' => [
            'success' => true,
            'hasMocap' => true,
            'fbxFilename' => 'M20240925_1824_251217_5.fbx',
            'takeNumber' => 5
        ]
    ],
    [
        'name' => 'Valid file with .mp4 extension',
        'mFile' => 'M20240925_1824.mp4',
        'expected' => [
            'success' => true,
            'hasMocap' => true,
            'fbxFilename' => 'M20240925_1824_251217_5.fbx',
            'takeNumber' => 5
        ]
    ],
    [
        'name' => 'Non-existent file',
        'mFile' => 'NonExistent_999999.wav',
        'expected' => [
            'success' => true,
            'hasMocap' => false
        ]
    ],
    [
        'name' => 'File with single take',
        'mFile' => 'M20240925_1819.wav',
        'expected' => [
            'success' => true,
            'hasMocap' => true,
            'fbxFilename' => 'M20240925_1819_251202_0.fbx',
            'takeNumber' => 0
        ]
    ]
];

$passed = 0;
$failed = 0;

foreach ($testCases as $test) {
    echo "Test: {$test['name']}\n";
    echo "Input: {$test['mFile']}\n";

    // Simulate the API call
    $result = testGetLatestMocapFile($test['mFile']);

    echo "Result: ";
    print_r($result);

    // Validate result
    $testPassed = true;

    if ($result['success'] !== $test['expected']['success']) {
        echo "  ❌ FAIL: success mismatch\n";
        $testPassed = false;
    }

    if ($result['hasMocap'] !== $test['expected']['hasMocap']) {
        echo "  ❌ FAIL: hasMocap mismatch\n";
        $testPassed = false;
    }

    if ($result['hasMocap'] && isset($test['expected']['fbxFilename'])) {
        if ($result['fbxFilename'] !== $test['expected']['fbxFilename']) {
            echo "  ❌ FAIL: fbxFilename mismatch\n";
            echo "     Expected: {$test['expected']['fbxFilename']}\n";
            echo "     Got: {$result['fbxFilename']}\n";
            $testPassed = false;
        }

        if ($result['takeNumber'] !== $test['expected']['takeNumber']) {
            echo "  ❌ FAIL: takeNumber mismatch\n";
            echo "     Expected: {$test['expected']['takeNumber']}\n";
            echo "     Got: {$result['takeNumber']}\n";
            $testPassed = false;
        }
    }

    if ($testPassed) {
        echo "  ✅ PASS\n";
        $passed++;
    } else {
        $failed++;
    }

    echo "\n";
}

echo "=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

$conn->close();

/**
 * Test version of getLatestMocapFile function
 */
function testGetLatestMocapFile($mFile) {
    if (empty($mFile)) {
        return ['success' => false, 'error' => 'No m_file provided'];
    }

    // Extract base filename
    $baseFilename = preg_replace('/\.(wav|mp4)$/i', '', $mFile);

    // FBX directory path
    $fbxDir = '/web/gebarenoverleg_media/fbx/';

    // Find all FBX files matching pattern
    $pattern = $fbxDir . $baseFilename . '_*_*.fbx';
    $matchingFiles = glob($pattern);

    if (empty($matchingFiles)) {
        return ['success' => true, 'hasMocap' => false];
    }

    // Extract take numbers and find highest
    $maxTake = -1;
    $latestFile = null;

    foreach ($matchingFiles as $file) {
        $filename = basename($file);

        // Extract last number before .fbx
        if (preg_match('/_(\d+)\.fbx$/', $filename, $matches)) {
            $takeNumber = intval($matches[1]);
            if ($takeNumber > $maxTake) {
                $maxTake = $takeNumber;
                $latestFile = $filename;
            }
        }
    }

    if ($latestFile) {
        return [
            'success' => true,
            'hasMocap' => true,
            'fbxFilename' => $latestFile,
            'takeNumber' => $maxTake
        ];
    } else {
        return ['success' => true, 'hasMocap' => false];
    }
}
?>
