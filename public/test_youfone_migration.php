<?php

/**
 * Youfone API Migration Test Script
 * Compares old vs new host connectivity from this server.
 */

$payload = [
    'Request' => [
        'HouseNr' => 78,
        'Zipcode' => '3011BN',
        'HouseNrExtension' => ''
    ]
];

$tests = [
    'New Host (Flattened)' => 'https://pcwcf.netherlands.youfone.services/PostcodeCheckCoverage',
    'New Host (Old Path)' => 'https://pcwcf.netherlands.youfone.services/PostcodeCheckWcf/v3.0/service.svc/json/PostcodeCheckCoverage',
    'Old Host (Classic)' => 'https://provisioning.youfone.nl/PostcodeCheckWcf/v3.0/service.svc/json/PostcodeCheckCoverage',
];

foreach ($tests as $name => $url) {
    echo "=== Testing $name ===\n";
    echo "URL: $url\n";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Try both methods if one fails
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Status: $httpCode\n";
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        echo "Response Structure: " . (isset($data['d']) ? "WCF Style (.d)" : "Flat JSON") . "\n";
        echo "Data preview: " . substr($response, 0, 100) . "...\n";
    }
    echo "\n";
}
