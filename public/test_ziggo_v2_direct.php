<?php
/**
 * Direct Ziggo V2 API Test (without Laravel)
 * URL: https://api.internetvergelijk.nl/test_ziggo_v2_direct.php?postcode=2723AB&number=106
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$postcode = $_GET['postcode'] ?? '2723AB';
$number = $_GET['number'] ?? '106';

echo "<h1>Ziggo V2 Direct API Test</h1>";
echo "<p><strong>Test Address:</strong> {$postcode} {$number}</p>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<hr>";

// Get API key from environment or use default
$apiKey = getenv('ZIGGO_V2_API_KEY') ?: getenv('ZIGGO_API_KEY');
$baseUrl = 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2';

if (!$apiKey) {
    echo "<p style='color: red;'>✗ Missing ZIGGO_V2_API_KEY / ZIGGO_API_KEY in environment</p>";
    exit(1);
}

echo "<h2>Step 1: Test Health Check</h2>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $baseUrl . '/health',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $apiKey,
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>Status: {$statusCode}</p>";
if ($statusCode == 200) {
    echo "<p style='color: green;'>✓ Health check passed</p>";
} else {
    echo "<p style='color: red;'>✗ Health check failed</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

echo "<hr>";
echo "<h2>Step 2: Check Footprint</h2>";

$footprintUrl = $baseUrl . "/footprint/{$postcode}/{$number}";
echo "<p>URL: {$footprintUrl}</p>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $footprintUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $apiKey,
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>Status: {$statusCode}</p>";

if ($statusCode == 200) {
    echo "<p style='color: green;'>✓ Footprint check successful</p>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo htmlspecialchars($response);
    echo "</pre>";
    
    $data = json_decode($response, true);
    
    if (isset($data['data']['ADDRESSES']) && is_array($data['data']['ADDRESSES'])) {
        $addresses = $data['data']['ADDRESSES'];
        echo "<p><strong>Found " . count($addresses) . " address(es)</strong></p>";
        
        foreach ($addresses as $addr) {
            $addressId = $addr['ID'];
            
            echo "<hr>";
            echo "<h2>Step 3: Check Availability for {$addressId}</h2>";
            
            $availabilityUrl = $baseUrl . "/availability/{$addressId}";
            echo "<p>URL: {$availabilityUrl}</p>";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $availabilityUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: ' . $apiKey,
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $availResponse = curl_exec($ch);
            $availStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            echo "<p>Status: {$availStatusCode}</p>";
            
            if ($availStatusCode == 200) {
                echo "<p style='color: green;'>✓ Availability check successful</p>";
                echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
                echo htmlspecialchars($availResponse);
                echo "</pre>";
                
                $availData = json_decode($availResponse, true);
                
                if (isset($availData['data']['MAXNETWORKDOWNLOADSPEED'])) {
                    $speedGbit = floatval($availData['data']['MAXNETWORKDOWNLOADSPEED']);
                    $speedMbps = (int)($speedGbit * 1000);
                    
                    // Normalize speed
                    $speedTiers = [
                        100 => 100, 125 => 100,
                        200 => 200, 250 => 200,
                        400 => 400, 500 => 400,
                        750 => 750, 775 => 750,
                        1000 => 1000, 1100 => 1000,
                        2000 => 2000, 2200 => 2000,
                    ];
                    
                    $normalizedSpeed = $speedTiers[$speedMbps] ?? $speedMbps;
                    
                    echo "<h3 style='color: green;'>✓ Speed Found!</h3>";
                    echo "<p><strong>Raw Speed:</strong> {$speedGbit} Gbit/s = {$speedMbps} Mbps</p>";
                    echo "<p><strong>Normalized Speed:</strong> {$normalizedSpeed} Mbps</p>";
                }
            } else {
                echo "<p style='color: red;'>✗ Availability check failed</p>";
                echo "<pre>" . htmlspecialchars($availResponse) . "</pre>";
            }
        }
    }
} else {
    echo "<p style='color: red;'>✗ Footprint check failed</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

echo "<hr>";
echo "<p><small>Test: <a href='?postcode=2723AB&number=106'>2723AB 106</a> | <a href='?postcode=1011AB&number=1'>1011AB 1</a> | <a href='?postcode=3011AB&number=1'>3011AB 1</a></small></p>";
