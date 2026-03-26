<?php
/**
 * Compare Direct cURL vs Guzzle Headers
 */

echo "<h1>Header Comparison Test</h1>";
echo "<pre>";

$apiKey = getenv('ZIGGO_V2_API_KEY') ?: getenv('ZIGGO_API_KEY');
$testUrl = 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/footprint/2723AB/106';

if (!$apiKey) {
    echo "ERROR: Missing ZIGGO_V2_API_KEY / ZIGGO_API_KEY in environment.\n";
    echo "</pre>";
    exit(1);
}

echo "=== Test 1: Direct cURL (lowercase x-api-key) ===\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $testUrl,
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

echo "Status: {$statusCode}\n";
echo "Result: " . ($statusCode == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n";
if ($statusCode != 200) {
    echo "Response: " . substr($response, 0, 200) . "\n";
}

echo "\n=== Test 2: Direct cURL (uppercase X-API-KEY) ===\n";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $testUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-API-KEY: ' . $apiKey,
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: {$statusCode}\n";
echo "Result: " . ($statusCode == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n";
if ($statusCode != 200) {
    echo "Response: " . substr($response, 0, 200) . "\n";
}

echo "\n=== Test 3: Guzzle with env() ===\n";

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Environment variables:\n";
echo "  ZIGGO_API_KEY: " . (env('ZIGGO_API_KEY') ? substr(env('ZIGGO_API_KEY'), 0, 20) . '...' : 'NOT SET') . "\n";
echo "  ZIGGO_V2_API_KEY: " . (env('ZIGGO_V2_API_KEY') ? substr(env('ZIGGO_V2_API_KEY'), 0, 20) . '...' : 'NOT SET') . "\n";

$keyToUse = env('ZIGGO_V2_API_KEY', env('ZIGGO_API_KEY'));
echo "  Key being used: " . ($keyToUse ? substr($keyToUse, 0, 20) . '...' : 'NULL/EMPTY') . "\n";
echo "  Key available: " . ($keyToUse ? '✓ YES' : '✗ NO') . "\n";

echo "\nGuzzle request:\n";

use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2',
    'http_errors' => false,
    'timeout' => 10,
    'headers' => [
        'x-api-key' => $keyToUse,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    ]
]);

try {
    $response = $client->get('/footprint/2723AB/106');
    $statusCode = $response->getStatusCode();
    
    echo "Status: {$statusCode}\n";
    echo "Result: " . ($statusCode == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n";
    
    if ($statusCode != 200) {
        echo "Response: " . substr($response->getBody(), 0, 200) . "\n";
    }
    
    // Show actual headers sent
    echo "\nRequest headers sent:\n";
    $request = $response->getRequest();
    foreach ($request->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            if (stripos($name, 'key') !== false) {
                $value = substr($value, 0, 20) . '...';
            }
            echo "  {$name}: {$value}\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
}

echo "\n=== Test 4: ZiggoPostcodeCheckV2 Class ===\n";

try {
    $ziggo = new \App\Libraries\ZiggoPostcodeCheckV2();
    
    // Use reflection to check the Guzzle config
    $reflection = new ReflectionClass($ziggo);
    $guzzleProperty = $reflection->getProperty('_guzzle');
    $guzzleProperty->setAccessible(true);
    $guzzleClient = $guzzleProperty->getValue($ziggo);
    
    $config = $guzzleClient->getConfig();
    echo "Guzzle configuration:\n";
    echo "  base_uri: " . ($config['base_uri'] ?? 'not set') . "\n";
    echo "  timeout: " . ($config['timeout'] ?? 'not set') . "\n";
    
    if (isset($config['headers'])) {
        echo "  Headers:\n";
        foreach ($config['headers'] as $name => $value) {
            if (stripos($name, 'key') !== false) {
                $displayValue = $value ? substr($value, 0, 20) . '...' : 'NULL/EMPTY';
            } else {
                $displayValue = $value;
            }
            echo "    {$name}: {$displayValue}\n";
        }
    }
    
    // Try a test call
    echo "\nTest API call via class:\n";
    $response = $guzzleClient->get('/footprint/2723AB/106');
    $statusCode = $response->getStatusCode();
    
    echo "Status: {$statusCode}\n";
    echo "Result: " . ($statusCode == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Diagnosis ===\n";
echo "If Test 1/2 succeed but Test 3/4 fail:\n";
echo "  → env() is returning NULL or wrong value\n";
echo "  → Check .env file and clear config cache\n";
echo "\nIf all tests fail:\n";
echo "  → Server IP not whitelisted OR API key revoked\n";
echo "\nIf all tests succeed:\n";
echo "  → Problem is elsewhere (database, queue workers, etc.)\n";

echo "</pre>";
?>
