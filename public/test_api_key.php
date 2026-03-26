<?php
/**
 * Test Ziggo V2 API Key and Access
 * 
 * This script tests direct API access to identify authentication issues
 */

echo "<h1>Ziggo V2 API Key Test</h1>";
echo "<pre>";

// Test configuration
$apiKey = getenv('ZIGGO_V2_API_KEY') ?: getenv('ZIGGO_API_KEY');
$baseUrl = 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2';

if (!$apiKey) {
    echo "ERROR: Missing ZIGGO_V2_API_KEY / ZIGGO_API_KEY in environment.\n";
    echo "</pre>";
    exit(1);
}

// Test addresses
$tests = [
    ['postcode' => '2723AB', 'number' => '106', 'expected' => '2200 Mbps'],
    ['postcode' => '2728AA', 'number' => '1', 'expected' => '2000 Mbps'],
];

echo "=== Configuration ===\n";
echo "API Key: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -10) . "\n";
echo "Base URL: {$baseUrl}\n";
echo "Server IP: " . ($_SERVER['SERVER_ADDR'] ?? 'unknown') . "\n";
echo "Client IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
echo "\n";

foreach ($tests as $i => $test) {
    $testNum = $i + 1;
    echo "=== Test {$testNum}: {$test['postcode']} {$test['number']} (expected {$test['expected']}) ===\n";
    
    // Step 1: Footprint check
    $footprintUrl = "{$baseUrl}/footprint/{$test['postcode']}/{$test['number']}";
    echo "\n1. Footprint Check\n";
    echo "URL: {$footprintUrl}\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $footprintUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_VERBOSE, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Status: {$httpCode}\n";
    
    if ($curlError) {
        echo "cURL Error: {$curlError}\n";
        continue;
    }
    
    if ($httpCode !== 200) {
        echo "✗ Failed with HTTP {$httpCode}\n";
        echo "Response: " . substr($response, 0, 500) . "\n";
        
        // Try with different header case
        echo "\nRetrying with uppercase header...\n";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $footprintUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response2 = curl_exec($ch);
        $httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "HTTP Status (uppercase): {$httpCode2}\n";
        if ($httpCode2 !== 200) {
            echo "Response: " . substr($response2, 0, 500) . "\n";
        }
        
        continue;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['data']['ADDRESSES'])) {
        echo "✗ No addresses found\n";
        echo "Response: " . print_r($data, true) . "\n";
        continue;
    }
    
    echo "✓ Footprint check passed\n";
    echo "Addresses found: " . count($data['data']['ADDRESSES']) . "\n";
    
    // Step 2: Availability check for first address
    $addressId = $data['data']['ADDRESSES'][0]['ID'];
    echo "\n2. Availability Check\n";
    echo "Address ID: {$addressId}\n";
    
    $availabilityUrl = "{$baseUrl}/availability/{$addressId}";
    echo "URL: {$availabilityUrl}\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $availabilityUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Status: {$httpCode}\n";
    
    if ($httpCode !== 200) {
        echo "✗ Failed with HTTP {$httpCode}\n";
        echo "Response: " . substr($response, 0, 500) . "\n";
        continue;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['data']['MAXNETWORKDOWNLOADSPEED'])) {
        echo "✗ No speed data found\n";
        echo "Response: " . print_r($data, true) . "\n";
        continue;
    }
    
    $speedGbit = floatval($data['data']['MAXNETWORKDOWNLOADSPEED']);
    $speedMbps = (int)($speedGbit * 1000);
    
    echo "✓ Speed: {$speedGbit} Gbit/s = {$speedMbps} Mbps\n";
    
    echo "\n";
}

echo "\n=== Environment Check ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "cURL Version: " . curl_version()['version'] . "\n";
echo "SSL Version: " . curl_version()['ssl_version'] . "\n";

// Check if we can reach the API at all
echo "\n=== Network Connectivity Test ===\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.prod.aws.ziggo.io');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_NOBODY, true);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Can reach api.prod.aws.ziggo.io: " . ($httpCode > 0 ? "✓ Yes (HTTP {$httpCode})" : "✗ No") . "\n";

echo "\n=== Summary ===\n";
echo "If all tests show HTTP 403, the API key may be:\n";
echo "1. Expired or revoked\n";
echo "2. IP-restricted (server IP not whitelisted)\n";
echo "3. Rate-limited\n";
echo "\nIf tests work here but fail in Laravel, check:\n";
echo "1. Environment variables (.env file)\n";
echo "2. Guzzle configuration\n";
echo "3. Request headers\n";

echo "</pre>";
?>
