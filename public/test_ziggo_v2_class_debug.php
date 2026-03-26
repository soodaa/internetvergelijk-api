<?php
/**
 * Debug Ziggo V2 - Direct Class Test
 * URL: https://api.internetvergelijk.nl/test_ziggo_v2_class_debug.php?postcode=2723AB&number=106
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$postcode = $_GET['postcode'] ?? '2723AB';
$number = $_GET['number'] ?? '106';

echo "<h1>Ziggo V2 Class Debug</h1>";
echo "<p><strong>Test Address:</strong> {$postcode} {$number}</p>";
echo "<hr>";

// Simulate the V2 class behavior manually
$apiKey = getenv('ZIGGO_V2_API_KEY') ?: getenv('ZIGGO_API_KEY');
$baseUrl = 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2';

if (!$apiKey) {
    die("<p style='color: red;'>Missing ZIGGO_V2_API_KEY / ZIGGO_API_KEY in environment</p>");
}

echo "<h2>Step 1: Footprint API Call</h2>";
$footprintUrl = "{$baseUrl}/footprint/{$postcode}/{$number}";
echo "<p>URL: {$footprintUrl}</p>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $footprintUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $apiKey,
        'Accept: application/json'
    ]
]);

$footprintResponse = curl_exec($ch);
$footprintStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>Status: {$footprintStatus}</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo htmlspecialchars($footprintResponse);
echo "</pre>";

$footprintData = json_decode($footprintResponse, true);

if (!isset($footprintData['data']['ADDRESSES'])) {
    die("<p style='color: red;'>No addresses found in footprint response</p>");
}

$addressId = $footprintData['data']['ADDRESSES'][0]['ID'];
echo "<p><strong>Address ID:</strong> {$addressId}</p>";

echo "<hr>";
echo "<h2>Step 2: Availability API Call</h2>";
$availabilityUrl = "{$baseUrl}/availability/{$addressId}";
echo "<p>URL: {$availabilityUrl}</p>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $availabilityUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $apiKey,
        'Accept: application/json'
    ]
]);

$availabilityResponse = curl_exec($ch);
$availabilityStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>Status: {$availabilityStatus}</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo htmlspecialchars($availabilityResponse);
echo "</pre>";

$availabilityData = json_decode($availabilityResponse, true);

if (!isset($availabilityData['data']['MAXNETWORKDOWNLOADSPEED'])) {
    die("<p style='color: orange;'>No speed data found in availability response</p>");
}

echo "<hr>";
echo "<h2>Step 3: Parse Speed (simulating ZiggoPostcodeCheckV2::parseSpeed)</h2>";

$speedGbit = floatval($availabilityData['data']['MAXNETWORKDOWNLOADSPEED']);
$speedMbps = (int)($speedGbit * 1000);

echo "<p><strong>Raw Speed (Gbit/s):</strong> {$speedGbit}</p>";
echo "<p><strong>Converted to Mbps:</strong> {$speedMbps} Mbps</p>";

echo "<hr>";
echo "<h2>Step 4: Normalize Speed (simulating ZiggoPostcodeCheckV2::normalizeZiggoSpeed)</h2>";

$speedTiers = [
    100 => 100,
    125 => 100,
    200 => 200,
    250 => 200,
    400 => 400,
    500 => 400,
    750 => 750,
    775 => 750,
    1000 => 1000,
    1100 => 1000,
    2000 => 2000,
    2200 => 2000,
];

echo "<p><strong>Speed Tiers Map:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
print_r($speedTiers);
echo "</pre>";

echo "<p><strong>Looking up {$speedMbps} in speed tiers...</strong></p>";

if (isset($speedTiers[$speedMbps])) {
    $normalizedSpeed = $speedTiers[$speedMbps];
    echo "<p style='color: green;'><strong>✓ Found in map: {$speedMbps} → {$normalizedSpeed} Mbps</strong></p>";
} else {
    $normalizedSpeed = $speedMbps;
    echo "<p style='color: orange;'><strong>⚠ Not in map, using original: {$normalizedSpeed} Mbps</strong></p>";
}

echo "<hr>";
echo "<h2>Step 5: Final Result</h2>";
echo "<table style='border-collapse: collapse; width: 100%; max-width: 600px;'>";
echo "<tr><td style='padding: 12px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Raw API Speed:</strong></td><td style='padding: 12px; border: 1px solid #ddd;'>{$speedGbit} Gbit/s ({$speedMbps} Mbps)</td></tr>";
echo "<tr><td style='padding: 12px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Normalized Speed:</strong></td><td style='padding: 12px; border: 1px solid #ddd;'><strong style='font-size: 18px; color: " . ($normalizedSpeed == 2000 ? 'green' : 'orange') . ";'>{$normalizedSpeed} Mbps</strong></td></tr>";
echo "</table>";

if ($normalizedSpeed == 2000) {
    echo "<p style='color: green; font-size: 16px;'><strong>✓ CORRECT!</strong> The code should save 2000 Mbps to kabel_max</p>";
} else {
    echo "<p style='color: red; font-size: 16px;'><strong>✗ PROBLEM!</strong> Expected 2000 Mbps but got {$normalizedSpeed} Mbps</p>";
}

echo "<hr>";
echo "<p><strong>Conclusion:</strong> If the normalized speed above is correct (2000 Mbps) but the speedCheck API returns 1000 Mbps, then the problem is likely:</p>";
echo "<ul>";
echo "<li>The ZiggoPostcodeCheckV2 class on the server is not the updated version</li>";
echo "<li>OR the Supplier.php still points to V1 instead of V2</li>";
echo "<li>OR there's a caching issue with the old value</li>";
echo "</ul>";

echo "<hr>";
echo "<p><small>Test: <a href='?postcode=2723AB&number=106'>2723AB 106</a> | <a href='?postcode=2725DN&number=25'>2725DN 25</a></small></p>";
