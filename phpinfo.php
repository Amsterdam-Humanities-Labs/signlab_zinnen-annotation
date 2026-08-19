<?php
// Display complete PHP configuration
phpinfo();

// Test the libzip integration via ZipArchive
if (extension_loaded('zip')) {
    $zip = new ZipArchive();
    // Create an in-memory ZIP archive
    if ($zip->open('php://memory', ZipArchive::CREATE) === TRUE) {
        // Try to output the libzip version if defined
        echo "<pre>ZipArchive LIBZIP version: " 
            . (defined('ZipArchive::LIBZIP_VERSION') ? ZipArchive::LIBZIP_VERSION : 'Not available') 
            . "</pre>";
        $zip->close();
    } else {
        echo "<pre>Could not create zip archive for testing.</pre>";
    }
} else {
    echo "<pre>zip extension not loaded.</pre>";
}
?>
