<?php
/**
 * Ziggo V2 API Test - Production Server Test
 * 
 * Upload this file to: /var/www/vhosts/internetvergelijk.nl/httpdocs/api/
 * Access via: https://api.internetvergelijk.nl/test_ziggo_v2.php
 * 
 * Tests both V1 and V2 API with production credentials
 */

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
// Load Laravel autoloader (go up one level from public folder)
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables (go up one level from public folder)
try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
} catch (Exception $e) {
    echo "<div class='section'><p class='error'>Could not load .env file: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}

// Configuration
$v1Url = $_ENV['ZIGGO_API_URL'] ?? getenv('ZIGGO_API_URL') ?: 'https://www.ziggo.nl/shop/api';
$v2UrlProd = $_ENV['ZIGGO_V2_API_URL'] ?? getenv('ZIGGO_V2_API_URL') ?: 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2';
$v2UrlDev = 'https://api.dev.aws.ziggo.io/v2/api/rfscom/v2';
$v2Url = $v2UrlProd; // Default to production
$apiKey = $_ENV['ZIGGO_API_KEY'] ?? getenv('ZIGGO_API_KEY') ?: 'MISSING';

$postcode = $_GET['postcode'] ?? '2723AB';
$huisnummer = $_GET['huisnummer'] ?? '106';

echo "<div class='section'>";
echo "<h2>Configuration</h2>";
echo "<pre>";
echo "V1 API URL: $v1Url\n";
echo "V2 API URL (PROD): $v2UrlProd\n";
echo "V2 API URL (DEV):  $v2UrlDev\n";
echo "Testing V2: " . ($v2Url === $v2UrlDev ? 'DEV' : 'PROD') . "\n";
echo "API Key:    " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -4) . "\n";
echo "Test:       $postcode $huisnummer\n";
echo "Server IP:  " . ($_SERVER['SERVER_ADDR'] ?? gethostbyname($_SERVER['SERVER_NAME'] ?? 'unknown')) . "\n";
echo "Client IP:  " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
echo "PHP:        " . PHP_VERSION . "\n";
echo "</pre>";
echo "<p class='warning'>⚠️ V2 PROD endpoints return 404. Try DEV? <a href='?postcode=$postcode&huisnummer=$huisnummer&env=dev' style='color: #4ec9b0;'>Test DEV Environment</a></p>";
echo "</div>";

// Check if user wants to test DEV
if (isset($_GET['env']) && $_GET['env'] === 'dev') {
    $v2Url = $v2UrlDev;
    echo "<div class='section'><p class='info'>🔧 Switched to DEV environment</p></div>";
}

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
        echo "<pre>" . htmlspecialchars(substr($body, 0, 500)) . "...</pre>";
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

// Test V2 API - Footprint (try multiple variations)
echo "<div class='section'>";
echo "<h2>3. Testing V2 API - Footprint (Multiple URL Formats)</h2>";

// Try 1: Query params (as per docs)
$urls = [
    'Query params' => $v2Url . '/footprint?postalCode=' . $postcode . '&houseNumber=' . $huisnummer,
    'Query params (postcode split)' => $v2Url . '/footprint?zipCode0=' . substr($postcode, 0, 4) . '&zipCode1=' . substr($postcode, 4, 2) . '&houseNumber=' . $huisnummer,
    'URL path (like V1)' => $v2Url . '/footprint/' . substr($postcode, 0, 4) . '/' . substr($postcode, 4, 2) . '/' . $huisnummer,
    'URL path (compact)' => $v2Url . '/footprint/' . $postcode . '/' . $huisnummer,
];

foreach ($urls as $method => $footprintUrl) {
    echo "<h3>Trying: $method</h3>";
    echo "<p class='info'>URL: $footprintUrl</p>";
    
    try {
        $response = $v2Client->get($footprintUrl, [
            'headers' => ['x-api-key' => $apiKey]
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        
        if ($statusCode == 200) {
            $data = json_decode($body, true);
            echo "<p class='success'>✓ SUCCESS! Status: $statusCode</p>";
            echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
            
            if (isset($data['inFootprint'])) {
                echo "<p class='success'>📍 In Footprint: " . ($data['inFootprint'] ? 'YES' : 'NO') . "</p>";
            }
            if (isset($data['connectionType'])) {
                echo "<p class='success'>Connection Type: " . $data['connectionType'] . "</p>";
            }
            break; // Stop trying after success
        } else {
            echo "<p class='warning'>✗ Failed - Status: $statusCode</p>";
            echo "<pre>" . substr($body, 0, 200) . "...</pre>";
        }
    } catch (Exception $e) {
        echo "<p class='warning'>✗ Error: " . htmlspecialchars(substr($e->getMessage(), 0, 200)) . "</p>";
    }
    echo "<hr>";
}

echo "</div>";

// Test V2 API - Availability (try multiple formats)
echo "<div class='section'>";
echo "<h2>4. Testing V2 API - Availability (Speed) - Multiple Formats</h2>";

// Try different URL formats
$availUrls = [
    'Query params' => $v2Url . '/availability?postalCode=' . $postcode . '&houseNumber=' . $huisnummer,
    'URL path (compact)' => $v2Url . '/availability/' . $postcode . $huisnummer,
    'URL path (with PAID from footprint)' => $v2Url . '/availability/PAID-131.224.940', // From footprint response
    'URL path (with ID from footprint)' => $v2Url . '/availability/2723AB106', // From footprint response
];

foreach ($availUrls as $method => $availabilityUrl) {
    echo "<h3>Trying: $method</h3>";
    echo "<p class='info'>URL: $availabilityUrl</p>";
    
    try {
        $response = $v2Client->get($availabilityUrl, [
            'headers' => ['x-api-key' => $apiKey]
        ]);
        
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        
        if ($statusCode == 200) {
            $data = json_decode($body, true);
            echo "<p class='success'>✓ SUCCESS! Status: $statusCode</p>";
            echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "</pre>";
            
            // Try to find speed information (V2 uses Gbit/s!)
            echo "<p class='info'>🔍 Speed Information:</p>";
            echo "<ul>";
            
            if (isset($data['data']['MAXNETWORKDOWNLOADSPEED'])) {
                $speedMbps = $data['data']['MAXNETWORKDOWNLOADSPEED'] * 1000;
                echo "<li class='success'>Download Speed: " . $speedMbps . " Mbps (" . $data['data']['MAXNETWORKDOWNLOADSPEED'] . " Gbit/s)</li>";
            }
            
            if (isset($data['data']['MAXNETWORKUPLOADSPEED'])) {
                $speedMbps = $data['data']['MAXNETWORKUPLOADSPEED'] * 1000;
                echo "<li class='success'>Upload Speed: " . $speedMbps . " Mbps (" . $data['data']['MAXNETWORKUPLOADSPEED'] . " Gbit/s)</li>";
            }
            
            if (isset($data['data']['CONNECTIONTYPE'])) {
                echo "<li class='success'>Connection Type: " . $data['data']['CONNECTIONTYPE'] . "</li>";
            }
            
            if (isset($data['data']['NETWORKTECHNOLOGY'])) {
                echo "<li class='success'>Network Technology: " . $data['data']['NETWORKTECHNOLOGY'] . "</li>";
            }
            
            if (isset($data['data']['IS_INTERNET_AVAILABLE'])) {
                echo "<li class='success'>Internet Available: " . ($data['data']['IS_INTERNET_AVAILABLE'] ? 'YES' : 'NO') . "</li>";
            }
            
            if (isset($data['data']['LINESTATUS'])) {
                echo "<li class='info'>Line Status: " . $data['data']['LINESTATUS'] . "</li>";
            }
            
            echo "</ul>";
            break; // Stop after success
        } else {
            echo "<p class='warning'>✗ Failed - Status: $statusCode</p>";
            echo "<pre>" . substr($body, 0, 200) . "...</pre>";
        }
    } catch (Exception $e) {
        echo "<p class='warning'>✗ Error: " . htmlspecialchars(substr($e->getMessage(), 0, 200)) . "</p>";
    }
    echo "<hr>";
}

echo "</div>";

// Summary
echo "<div class='section'>";
echo "<h2>Summary</h2>";
echo "<p>Test completed at: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>You can test other addresses by adding URL parameters:</p>";
echo "<pre>?postcode=1234AB&huisnummer=1</pre>";
echo "</div>";
?>

</body>
</html>
