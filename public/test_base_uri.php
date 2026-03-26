<?php
/**
 * Test Guzzle base_uri behavior
 */

echo "<h1>Guzzle base_uri Behavior Test</h1>";
echo "<pre>";

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

echo "=== Guzzle base_uri Path Handling ===\n\n";

echo "Rule: When request path starts with '/', it REPLACES the base_uri path\n";
echo "Rule: When request path does NOT start with '/', it APPENDS to base_uri\n\n";

$tests = [
    [
        'base_uri' => 'https://api.example.com/v2/api/rfscom/v2',
        'request' => '/footprint/2723AB/106',
        'expected' => 'https://api.example.com/footprint/2723AB/106',
        'note' => 'Leading / replaces path'
    ],
    [
        'base_uri' => 'https://api.example.com/v2/api/rfscom/v2/',
        'request' => '/footprint/2723AB/106',
        'expected' => 'https://api.example.com/footprint/2723AB/106',
        'note' => 'Trailing / on base_uri does not help'
    ],
    [
        'base_uri' => 'https://api.example.com/v2/api/rfscom/v2/',
        'request' => 'footprint/2723AB/106',
        'expected' => 'https://api.example.com/v2/api/rfscom/v2/footprint/2723AB/106',
        'note' => 'No leading / appends correctly'
    ],
];

foreach ($tests as $i => $test) {
    $num = $i + 1;
    echo "Test {$num}: {$test['note']}\n";
    echo "  base_uri: {$test['base_uri']}\n";
    echo "  request: {$test['request']}\n";
    echo "  expected: {$test['expected']}\n";
    
    $client = new Client(['base_uri' => $test['base_uri']]);
    $uri = \GuzzleHttp\Psr7\Uri::resolve(
        \GuzzleHttp\Psr7\Uri::fromParts(parse_url($test['base_uri'])),
        new \GuzzleHttp\Psr7\Uri($test['request'])
    );
    
    echo "  actual: {$uri}\n";
    echo "  match: " . ($uri == $test['expected'] ? '✓ YES' : '✗ NO') . "\n\n";
}

echo "=== Solution ===\n";
echo "To fix ZiggoPostcodeCheckV2.php:\n\n";
echo "Option 1: Remove leading slash from requests\n";
echo "  Change: \$this->_guzzle->get('/footprint/...')\n";
echo "  To:     \$this->_guzzle->get('footprint/...')\n\n";

echo "Option 2: Don't use base_uri with paths\n";
echo "  Change base_uri to: 'https://api.prod.aws.ziggo.io/'\n";
echo "  And use full paths: '/v2/api/rfscom/v2/footprint/...'\n\n";

echo "Option 3: Build full URLs\n";
echo "  Don't use base_uri at all, build complete URLs\n\n";

echo "Recommended: Option 1 (simplest fix)\n";

echo "</pre>";
?>
