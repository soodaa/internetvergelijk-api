<?php
/**
 * Ziggo V2 API Test - Upload to production server
 * 
 * Access via: https://api.internetvergelijk.nl/test_ziggo_v2.php
 * 
 * Tests both V1 and V2 API with production credentials
 */

// Prevent unauthorized access (remove this line to make it public)
// die('Access denied');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Ziggo API Test</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .success { color: #4ec9b0; }
        .error { color: #f48771; }
        .warning { color: #ce9178; }
        .info { color: #569cd6; }
        pre { background: #252526; padding: 15px; border-radius: 5px; overflow-x: auto; }
        h2 { color: #4ec9b0; border-bottom: 2px solid #4ec9b0; padding-bottom: 5px; }
        .section { margin: 20px 0; padding: 15px; background: #2d2d30; border-radius: 5px; }
    </style>
</head>
<body>
<h1>🔍 Ziggo API Test (Production Server)</h1>

<?php
// Load Laravel autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Configuration
$v1Url = $_ENV['ZIGGO_API_URL'] ?? 'https://www.ziggo.nl/shop/api';
$v2Url = $_ENV['ZIGGO_V2_API_URL'] ?? 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2';
$apiKey = $_ENV['ZIGGO_API_KEY'] ?? 'MISSING';

$postcode = $_GET['postcode'] ?? '2723AB';
$huisnummer = $_GET['huisnummer'] ?? '106';

echo "<div class='section'>";
echo "<h2>Configuration</h2>";
echo "<pre>";
echo "V1 API URL: $v1Url\n";
echo "V2 API URL: $v2Url\n";
echo "API Key:    " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -4) . "\n";
echo "Test:       $postcode $huisnummer\n";
echo "Server IP:  " . ($_SERVER['SERVER_ADDR'] ?? 'unknown') . "\n";
echo "Client IP:  " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
echo "</pre>";
echo "</div>";

// Test V1 API
echo "<div class='section'>";
echo "<h2>1. Testing V1 API (Current Production)</h2>";

$client = new \GuzzleHttp\Client([
    'http_errors' => false,
    'timeout' => 6,
    'headers' => ['x-api-key' => $apiKey]
]);

$url = $v1Url . '/footprint/' . substr($postcode, 0, 4) . '/' . substr($postcode, 4, 2) . '/' . $huisnummer;
echo "<p class='info'>URL: $url</p>";

try {
    $response = $client->get($url);
    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    if ($statusCode == 200) {
        $data = json_decode($body, true);
        echo "<p class='success'>✓ V1 API Works! Status: $statusCode</p>";
        echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
        
        // Extract speed if available
        if (isset($data['data']['FOOTPRINT'])) {
            echo "<p class='success'>Footprint: " . $data['data']['FOOTPRINT'] . "</p>";
        }
    } else {
        echo "<p class='error'>✗ V1 API Failed - Status: $statusCode</p>";
        echo "<pre>$body</pre>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ V1 API Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Test V2 API - Health
echo "<div class='section'>";
echo "<h2>2. Testing V2 API - Health Check</h2>";

$v2Client = new \GuzzleHttp\Client([
    'http_errors' => false,
    'timeout' => 10,
    'verify' => false,
]);

$healthUrl = $v2Url . '/health';
echo "<p class='info'>URL: $healthUrl</p>";

try {
    $response = $v2Client->get($healthUrl, [
        'headers' => ['x-api-key' => $apiKey]
    ]);
    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    if ($statusCode == 200) {
        echo "<p class='success'>✓ V2 Health OK - Status: $statusCode</p>";
    } else {
        echo "<p class='error'>✗ V2 Health Failed - Status: $statusCode</p>";
    }
    echo "<pre>$body</pre>";
} catch (Exception $e) {
    echo "<p class='error'>✗ V2 Health Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Test V2 API - Footprint
echo "<div class='section'>";
echo "<h2>3. Testing V2 API - Footprint (Coverage)</h2>";

$footprintUrl = $v2Url . '/footprint';
echo "<p class='info'>URL: $footprintUrl</p>";
echo "<p class='info'>Payload: {\"postcode\": \"$postcode\", \"huisnummer\": $huisnummer}</p>";

try {
    $response = $v2Client->post($footprintUrl, [
        'headers' => [
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'postcode' => $postcode,
            'huisnummer' => (int)$huisnummer,
        ]
    ]);
    
    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    if ($statusCode == 200) {
        $data = json_decode($body, true);
        echo "<p class='success'>✓ V2 Footprint OK - Status: $statusCode</p>";
        echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
        
        if (isset($data['footprint']) && $data['footprint']) {
            echo "<p class='success'>📍 Coverage: YES</p>";
        } else {
            echo "<p class='warning'>📍 Coverage: NO</p>";
        }
    } else {
        echo "<p class='error'>✗ V2 Footprint Failed - Status: $statusCode</p>";
        echo "<pre>$body</pre>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ V2 Footprint Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Test V2 API - Availability
echo "<div class='section'>";
echo "<h2>4. Testing V2 API - Availability (Speed)</h2>";

$availabilityUrl = $v2Url . '/availability';
echo "<p class='info'>URL: $availabilityUrl</p>";
echo "<p class='info'>Payload: {\"postcode\": \"$postcode\", \"huisnummer\": $huisnummer}</p>";

try {
    $response = $v2Client->post($availabilityUrl, [
        'headers' => [
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'postcode' => $postcode,
            'huisnummer' => (int)$huisnummer,
        ]
    ]);
    
    $statusCode = $response->getStatusCode();
    $body = $response->getBody()->getContents();
    
    if ($statusCode == 200) {
        $data = json_decode($body, true);
        echo "<p class='success'>✓ V2 Availability OK - Status: $statusCode</p>";
        echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
        
        // Try to find speed information
        echo "<p class='info'>🔍 Searching for speed information...</p>";
        echo "<ul>";
        
        if (isset($data['speed'])) {
            echo "<li class='success'>Found 'speed': " . $data['speed'] . " Mbps</li>";
        }
        if (isset($data['downloadSpeed'])) {
            echo "<li class='success'>Found 'downloadSpeed': " . $data['downloadSpeed'] . " Mbps</li>";
        }
        if (isset($data['maxSpeed'])) {
            echo "<li class='success'>Found 'maxSpeed': " . $data['maxSpeed'] . " Mbps</li>";
        }
        if (isset($data['maxDownloadSpeed'])) {
            echo "<li class='success'>Found 'maxDownloadSpeed': " . $data['maxDownloadSpeed'] . " Mbps</li>";
        }
        if (isset($data['products']) && is_array($data['products'])) {
            echo "<li class='success'>Found " . count($data['products']) . " products</li>";
            foreach ($data['products'] as $idx => $product) {
                if (isset($product['speed'])) {
                    echo "<li class='success'>  Product $idx: " . $product['speed'] . " Mbps</li>";
                }
            }
        }
        
        echo "</ul>";
    } else {
        echo "<p class='error'>✗ V2 Availability Failed - Status: $statusCode</p>";
        echo "<pre>$body</pre>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ V2 Availability Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Summary
echo "<div class='section'>";
echo "<h2>Summary</h2>";
echo "<p>Test completed at: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>You can test other addresses: <code>?postcode=1234AB&huisnummer=1</code></p>";
echo "</div>";
?>

</body>
</html>
