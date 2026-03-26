<?php
/**
 * Simple Ziggo V2 API Test
 * URL: https://api.internetvergelijk.nl/test_ziggo_v2_simple.php?postcode=2723AB&number=106
 */

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Ziggo V2 Simple Test</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<hr>";

try {
    echo "<h2>Step 1: Loading Bootstrap</h2>";
    
    // Try multiple possible paths
    $possibleBootstrapPaths = [
        __DIR__ . '/../bootstrap/autoload.php',  // Standard Laravel
        __DIR__ . '/../../../../api/bootstrap/autoload.php',  // Plesk structure
        '/var/www/vhosts/internetvergelijk.nl/api/bootstrap/autoload.php',  // Absolute path
    ];
    
    $bootstrapPath = null;
    foreach ($possibleBootstrapPaths as $path) {
        echo "<p>Trying: {$path} - " . (file_exists($path) ? 'EXISTS' : 'Not found') . "</p>";
        if (file_exists($path)) {
            $bootstrapPath = $path;
            break;
        }
    }
    
    if (!$bootstrapPath) {
        die("<p style='color: red;'>Bootstrap file not found in any location!</p>");
    }
    
    echo "<p style='color: green;'>Using: {$bootstrapPath}</p>";
    require $bootstrapPath;
    echo "<p style='color: green;'>✓ Bootstrap loaded</p>";
    
    echo "<h2>Step 2: Loading App</h2>";
    
    // Try multiple possible paths for app.php
    $possibleAppPaths = [
        dirname($bootstrapPath) . '/app.php',  // Same dir as bootstrap
        __DIR__ . '/../bootstrap/app.php',
        __DIR__ . '/../../../../api/bootstrap/app.php',
        '/var/www/vhosts/internetvergelijk.nl/api/bootstrap/app.php',
    ];
    
    $appPath = null;
    foreach ($possibleAppPaths as $path) {
        echo "<p>Trying: {$path} - " . (file_exists($path) ? 'EXISTS' : 'Not found') . "</p>";
        if (file_exists($path)) {
            $appPath = $path;
            break;
        }
    }
    
    if (!$appPath) {
        die("<p style='color: red;'>App file not found in any location!</p>");
    }
    
    echo "<p style='color: green;'>Using: {$appPath}</p>";
    $app = require_once $appPath;
    echo "<p style='color: green;'>✓ App loaded</p>";
    
    echo "<h2>Step 3: Creating ZiggoPostcodeCheckV2</h2>";
    
    if (!class_exists('\App\Libraries\ZiggoPostcodeCheckV2')) {
        die("<p style='color: red;'>ZiggoPostcodeCheckV2 class not found!</p>");
    }
    
    $ziggoV2 = new \App\Libraries\ZiggoPostcodeCheckV2();
    echo "<p style='color: green;'>✓ ZiggoPostcodeCheckV2 created</p>";
    
    echo "<h2>Step 4: Testing Health Check</h2>";
    $health = $ziggoV2->healthCheck();
    
    if ($health) {
        echo "<p style='color: green;'>✓ Health check passed</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Health check failed</p>";
    }
    
    echo "<h2>Step 5: Testing with Address</h2>";
    
    $postcode = $_GET['postcode'] ?? '2723AB';
    $number = $_GET['number'] ?? '106';
    $extension = $_GET['extension'] ?? null;
    
    echo "<p><strong>Test Address:</strong> {$postcode} {$number}" . ($extension ? " {$extension}" : "") . "</p>";
    
    $address = (object)[
        'postcode' => $postcode,
        'number' => $number,
        'extension' => $extension
    ];
    
    // Check if Postcode model exists
    if (!class_exists('\App\Models\Postcode')) {
        die("<p style='color: red;'>Postcode model not found!</p>");
    }
    
    $result = new \App\Models\Postcode();
    $result->postcode = $address->postcode;
    $result->kabel_max = 0;
    $result->max_download = 0;
    
    echo "<p>Calling request() with verbose output...</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    
    $response = $ziggoV2->request($address, $result, 1);
    
    echo "</pre>";
    
    if ($response) {
        echo "<h3 style='color: green;'>✓ Success!</h3>";
        echo "<p><strong>Cable Max Speed:</strong> {$result->kabel_max} Mbps</p>";
        echo "<p><strong>Max Download:</strong> {$result->max_download} Mbps</p>";
    } else {
        echo "<h3 style='color: orange;'>⚠ No Result</h3>";
        echo "<p>API returned no data</p>";
    }
    
} catch (\Exception $e) {
    echo "<h3 style='color: red;'>✗ Exception Caught</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
} catch (\Error $e) {
    echo "<h3 style='color: red;'>✗ Error Caught</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><small>Test: <a href='?postcode=2723AB&number=106'>2723AB 106</a> | <a href='?postcode=1011AB&number=1'>1011AB 1</a></small></p>";
