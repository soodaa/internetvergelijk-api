<?php
/**
 * Test Ziggo V2 API Implementation
 * URL: https://api.internetvergelijk.nl/test_ziggo_v2_implementation.php?postcode=2723AB&number=106
 */

// Bootstrap Laravel
require __DIR__ . '/../bootstrap/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

// Create test address object
$address = (object)[
    'postcode' => $_GET['postcode'] ?? '2723AB',
    'number' => $_GET['number'] ?? '106',
    'extension' => $_GET['extension'] ?? null
];

echo "<h1>Ziggo V2 API Implementation Test</h1>";
echo "<p><strong>Test Address:</strong> {$address->postcode} {$address->number}" . ($address->extension ? " {$address->extension}" : "") . "</p>";
echo "<hr>";

// Test V2 Implementation
try {
    echo "<h2>Testing ZiggoPostcodeCheckV2</h2>";
    
    $ziggoV2 = new \App\Libraries\ZiggoPostcodeCheckV2();
    
    // Create a test Postcode model
    $result = new \App\Models\Postcode();
    $result->postcode = $address->postcode;
    $result->kabel_max = 0;
    $result->max_download = 0;
    
    // Enable verbose output
    ob_start();
    $response = $ziggoV2->request($address, $result, 1); // verbose = 1
    $output = ob_get_clean();
    
    echo "<h3>Debug Output:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    if ($response) {
        echo "<h3 style='color: green;'>✓ Success!</h3>";
        echo "<p><strong>Cable Max Speed:</strong> {$result->kabel_max} Mbps</p>";
        echo "<p><strong>Max Download:</strong> {$result->max_download} Mbps</p>";
    } else {
        echo "<h3 style='color: orange;'>⚠ No Result</h3>";
        echo "<p>V2 API returned no coverage or no speed data</p>";
    }
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>✗ Error</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<h2>Test Health Check</h2>";
try {
    $ziggoV2 = new \App\Libraries\ZiggoPostcodeCheckV2();
    $health = $ziggoV2->healthCheck();
    
    if ($health) {
        echo "<p style='color: green;'>✓ Health check passed</p>";
    } else {
        echo "<p style='color: red;'>✗ Health check failed</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Health check error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><small>Test andere adressen: <a href='?postcode=1011AB&number=1'>1011AB 1 Amsterdam</a> | <a href='?postcode=3011AB&number=1'>3011AB 1 Rotterdam</a></small></p>";
