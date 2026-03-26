<?php
/**
 * Debug Guzzle Header Sending
 */

echo "<h1>Guzzle Header Debug</h1>";
echo "<pre>";

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

$apiKey = env('ZIGGO_V2_API_KEY', env('ZIGGO_API_KEY'));

echo "=== Configuration ===\n";
echo "API Key from env: " . substr($apiKey, 0, 20) . "...\n";
echo "API Key length: " . strlen($apiKey) . "\n";
echo "API Key is null: " . ($apiKey === null ? 'YES' : 'NO') . "\n";
echo "API Key is empty: " . (empty($apiKey) ? 'YES' : 'NO') . "\n\n";

// Create a middleware to log requests
$container = [];
$history = Middleware::history($container);

$tapMiddleware = Middleware::mapRequest(function (RequestInterface $request) {
    echo "=== Request Being Sent ===\n";
    echo "Method: " . $request->getMethod() . "\n";
    echo "URI: " . $request->getUri() . "\n";
    echo "Headers:\n";
    foreach ($request->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            if (stripos($name, 'key') !== false) {
                $value = substr($value, 0, 20) . '...';
            }
            echo "  {$name}: {$value}\n";
        }
    }
    echo "\n";
    return $request;
});

$handlerStack = HandlerStack::create();
$handlerStack->push($tapMiddleware);
$handlerStack->push($history);

echo "=== Test 1: Headers in constructor (current implementation) ===\n";

$client1 = new Client([
    'handler' => $handlerStack,
    'base_uri' => 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2',
    'http_errors' => false,
    'timeout' => 10,
    'headers' => [
        'x-api-key' => $apiKey,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    ]
]);

try {
    $response1 = $client1->get('/footprint/2723AB/106');
    echo "Response Status: " . $response1->getStatusCode() . "\n";
    echo "Result: " . ($response1->getStatusCode() == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n\n";
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n\n";
}

// Reset container
$container = [];
$history = Middleware::history($container);
$handlerStack = HandlerStack::create();
$handlerStack->push($tapMiddleware);
$handlerStack->push($history);

echo "=== Test 2: Headers in request options ===\n";

$client2 = new Client([
    'handler' => $handlerStack,
    'base_uri' => 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2',
    'http_errors' => false,
    'timeout' => 10
]);

try {
    $response2 = $client2->get('/footprint/2723AB/106', [
        'headers' => [
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ]
    ]);
    echo "Response Status: " . $response2->getStatusCode() . "\n";
    echo "Result: " . ($response2->getStatusCode() == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n\n";
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n\n";
}

// Reset container
$container = [];
$history = Middleware::history($container);
$handlerStack = HandlerStack::create();
$handlerStack->push($tapMiddleware);
$handlerStack->push($history);

echo "=== Test 3: Headers with uppercase X-API-KEY ===\n";

$client3 = new Client([
    'handler' => $handlerStack,
    'base_uri' => 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2',
    'http_errors' => false,
    'timeout' => 10
]);

try {
    $response3 = $client3->get('/footprint/2723AB/106', [
        'headers' => [
            'X-API-KEY' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ]
    ]);
    echo "Response Status: " . $response3->getStatusCode() . "\n";
    echo "Result: " . ($response3->getStatusCode() == 200 ? "✓ SUCCESS" : "✗ FAILED") . "\n\n";
} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n\n";
}

echo "=== Diagnosis ===\n";
echo "If Test 1 fails but Test 2 succeeds:\n";
echo "  → Need to pass headers in request options, not constructor\n";
echo "\nIf all tests fail:\n";
echo "  → API key value issue (check env() return value)\n";
echo "\nIf Test 1 succeeds:\n";
echo "  → Something else is wrong with ZiggoPostcodeCheckV2\n";

echo "</pre>";
?>
