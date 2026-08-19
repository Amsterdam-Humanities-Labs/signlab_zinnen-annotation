<?php

function getBaseFormFromApi($searchTerm, $debug = false) {
    // Define the API endpoint
    $apiUrl = 'https://leffe.science.uva.nl:8043/getWords/search?term=' . urlencode($searchTerm);

    // Initialize a cURL session
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // Execute the cURL request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo json_encode(['error' => 'Error: ' . curl_error($ch)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        curl_close($ch);
        return null;
    }

    // Decode the JSON response
    $data = json_decode($response, true);

    // Close the cURL session
    curl_close($ch);

    $baseForm = null;

    // Iterate through the results to find the base form
    foreach ($data as $entry) {
        if (isset($entry['lang'], $entry['lang_code'], $entry['pos']) &&
            $entry['lang'] === 'Dutch' && $entry['lang_code'] === 'nl') {

            if ($entry['pos'] === 'verb') {
                // Check if the search term is already a valid verb in infinitive form
                if ($entry['word'] === $searchTerm && substr($searchTerm, -2) === 'en') {
                    $baseForm = $searchTerm;
                    break;
                }

                // Check if the entry contains links to a base verb with #Dutch
                if (isset($entry['senses'])) {
                    foreach ($entry['senses'] as $sense) {
                        if (isset($sense['links'][0][0])) {
                            foreach ($sense['links'] as $link) {
                                if (strpos($link[1], '#Dutch') !== false) {
                                    $baseForm = $link[0]; // Use the Dutch verb link
                                    break 3; // Exit all loops
                                }
                            }
                        }
                    }
                }
            } elseif ($entry['pos'] === 'noun') {
                // Check if the search term is already a valid noun
                if ($entry['word'] === $searchTerm) {
                    $baseForm = $searchTerm;
                }

                // Check if the entry contains links to a singular noun
                if (isset($entry['senses'])) {
                    foreach ($entry['senses'] as $sense) {
                        if (isset($sense['form_of'][0]['word'])) {
                            $baseForm = $sense['form_of'][0]['word'];
                            break 2; // Exit loops once a match is found
                        }
                    }
                }
            }
        }
    }

    // Prepare the JSON response
    if ($baseForm) {
        echo json_encode(['base_form' => $baseForm], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['base_form' => null, 'message' => "No base form found for '$searchTerm'."], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // If debug mode is enabled, print the full JSON response
    if ($debug) {
        echo "\nDebug Mode: Full JSON Response:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    return $baseForm;
}

// Example usage
$searchTerm = 'boek';  // Searching for "appels"
$debug = false;  // Set this to true to enable debug mode
$baseForm = getBaseFormFromApi($searchTerm, $debug);

?>
