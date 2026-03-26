<?php

/**
 * Test script voor raw Odido (T-Mobile) API responses
 * 
 * Gebruik:
 * /opt/plesk/php/7.4/bin/php test_odido_raw.php <postcode> <nummer> [toevoeging]
 * 
 * Voorbeeld:
 * /opt/plesk/php/7.4/bin/php test_odido_raw.php 1241LP 56
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use GuzzleHttp\Client;

if ($argc < 3) {
    echo "Gebruik: php test_odido_raw.php <postcode> <nummer> [toevoeging]\n";
    echo "Voorbeeld: php test_odido_raw.php 1241LP 56\n";
    exit(1);
}

$postcode = str_replace(' ', '', strtoupper($argv[1]));
$number = $argv[2];
$extension = $argv[3] ?? '';

echo "=== Odido Raw API Test ===\n";
echo "Postcode: {$postcode}\n";
echo "Nummer: {$number}\n";
echo "Toevoeging: " . ($extension ?: '(leeg)') . "\n\n";

$apiUrl = env('ODIDO_COVERAGE_API_URL', 'https://vispcoveragecheckapi.glasoperator.nl/Generic201402/CoverageCheck.svc/urljson');
$clientId = env('ODIDO_CLIENT_ID', 'internetvergelijk.vispcoverage');
$clientSecret = env('ODIDO_CLIENT_SECRET');

if (!$clientSecret) {
    echo "ERROR: ODIDO_CLIENT_SECRET niet geconfigureerd in .env\n";
    exit(1);
}

// Build URL met credentials in query string
$url = $apiUrl . '/CheckFiberCoverage?' . http_build_query([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'ispId' => 'VDF',
    'postalCode' => $postcode,
    'houseNumber' => $number,
    'houseNumberAddition' => $extension,
]);

echo "API URL: {$url}\n\n";

$client = new Client([
    'timeout' => 10,
    'connect_timeout' => 6,
]);

try {
    echo "Versturen van request...\n\n";
    
    $response = $client->get($url);
    
    echo "Status Code: " . $response->getStatusCode() . "\n\n";
    
    $body = (string)$response->getBody();
    $decoded = json_decode($body, true);
    
    echo "=== RAW JSON RESPONSE ===\n";
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    echo "=== RESPONSE ANALYSE ===\n";
    
    if (is_array($decoded) && !empty($decoded)) {
        echo "Aantal entries: " . count($decoded) . "\n\n";
        
        foreach ($decoded as $index => $entry) {
            echo "--- Entry #" . ($index + 1) . " ---\n";
            
            if (isset($entry['Address'])) {
                $addr = $entry['Address'];
                echo "Adres: " . ($addr['Street'] ?? 'N/A') . " " . ($addr['HouseNumber'] ?? 'N/A');
                if (!empty($addr['HouseNumberAddition'] ?? '')) {
                    echo " " . $addr['HouseNumberAddition'];
                }
                echo "\n";
                echo "Postcode: " . ($addr['PostalCode'] ?? 'N/A') . "\n";
            }
            
            if (isset($entry['State'])) {
                echo "State: " . $entry['State'] . "\n";
            }
            
            if (isset($entry['ExpectedMaximumAvailableBandwidth'])) {
                $bandwidthKbps = (int)$entry['ExpectedMaximumAvailableBandwidth'];
                $bandwidthMbps = (int)($bandwidthKbps / 1000);
                echo "ExpectedMaximumAvailableBandwidth: {$bandwidthKbps} kbps ({$bandwidthMbps} Mbps)\n";
            }
            
            if (isset($entry['Packages']) && is_array($entry['Packages'])) {
                echo "Packages gevonden: " . count($entry['Packages']) . "\n";
                
                $variants = [];
                foreach ($entry['Packages'] as $pkg) {
                    $name = $pkg['Name'] ?? '';
                    if (preg_match('/(WBA|GOP|OPF|DFN|GPT|DSL)$/i', $name, $matches)) {
                        $variants[] = $matches[1];
                    }
                }
                
                if (!empty($variants)) {
                    echo "Gevonden varianten: " . implode(', ', array_unique($variants)) . "\n";
                }
                
                echo "Eerste 5 packages:\n";
                foreach (array_slice($entry['Packages'], 0, 5) as $pkg) {
                    $name = $pkg['Name'] ?? 'N/A';
                    $down = $pkg['DownloadSpeed'] ?? 'N/A';
                    $up = $pkg['UploadSpeed'] ?? 'N/A';
                    echo "  - {$name}: {$down}↓ / {$up}↑ Mbps\n";
                }
            }
            
            echo "\n";
        }
    } else {
        echo "Geen data gevonden in response\n";
    }
    
} catch (\GuzzleHttp\Exception\ConnectException $e) {
    echo "ERROR: Verbindingsfout\n";
    echo $e->getMessage() . "\n";
    exit(1);
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

