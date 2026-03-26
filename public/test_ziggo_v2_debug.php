<?php
/**
 * Debug Ziggo V2 Implementation with Laravel
 * URL: https://api.internetvergelijk.nl/test_ziggo_v2_debug.php?postcode=2723AB&number=106
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Try to find and load Laravel bootstrap
$possiblePaths = [
    __DIR__ . '/../bootstrap/autoload.php',
    '/var/www/vhosts/internetvergelijk.nl/api/bootstrap/autoload.php',
];

$bootstrapPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $bootstrapPath = $path;
        break;
    }
}

if (!$bootstrapPath) {
    die("Could not find Laravel bootstrap. Paths tried: " . implode(', ', $possiblePaths));
}

require $bootstrapPath;
$app = require_once dirname($bootstrapPath) . '/app.php';

$postcode = $_GET['postcode'] ?? '2723AB';
$number = $_GET['number'] ?? '106';
$extension = $_GET['extension'] ?? null;

echo "<h1>Ziggo V2 Debug with Laravel</h1>";
echo "<p><strong>Test Address:</strong> {$postcode} {$number}" . ($extension ? " {$extension}" : "") . "</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<hr>";

try {
    $address = (object)[
        'postcode' => $postcode,
        'number' => $number,
        'extension' => $extension
    ];
    
    $ziggoV2 = new \App\Libraries\ZiggoPostcodeCheckV2();
    
    // Create a mock result object (NOT saving to database)
    $result = new \App\Models\Postcode();
    $result->postcode = $postcode;
    $result->kabel_max = 0;
    $result->max_download = 0;
    
    echo "<h2>Testing V2 API with verbose output</h2>";
    echo "<div style='background: #f5f5f5; padding: 20px; border: 1px solid #ddd;'>";
    
    // Call with verbose mode
    ob_start();
    $response = $ziggoV2->request($address, $result, 1);
    $output = ob_get_clean();
    
    echo "<pre>";
    // Convert dump output to readable format
    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        echo htmlspecialchars($line) . "\n";
    }
    echo "</pre>";
    echo "</div>";
    
    echo "<hr>";
    echo "<h2>Result Summary</h2>";
    
    if ($response) {
        echo "<p style='color: green; font-size: 18px;'><strong>✓ Success</strong></p>";
        echo "<table style='border-collapse: collapse;'>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>kabel_max:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$result->kabel_max} Mbps</td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>max_download:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$result->max_download} Mbps</td></tr>";
        echo "</table>";
        
        if ($result->kabel_max == 1000) {
            echo "<p style='color: orange;'><strong>⚠️ WARNING:</strong> Expected 2000 Mbps but got 1000 Mbps!</p>";
        } elseif ($result->kabel_max == 2000) {
            echo "<p style='color: green;'><strong>✓ CORRECT:</strong> Got expected 2000 Mbps!</p>";
        }
    } else {
        echo "<p style='color: red; font-size: 18px;'><strong>✗ Failed</strong></p>";
        echo "<p>No coverage or error occurred</p>";
    }
    
    echo "<hr>";
    echo "<h2>Direct API Test (for comparison)</h2>";
    echo "<p><a href='test_ziggo_v2_direct.php?postcode={$postcode}&number={$number}' target='_blank'>Click here to see raw V2 API response</a></p>";
    
} catch (\Exception $e) {
    echo "<h3 style='color: red;'>✗ Exception</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><small>Test andere adressen: <a href='?postcode=2723AB&number=106'>2723AB 106</a> | <a href='?postcode=2725DN&number=25'>2725DN 25</a></small></p>";
