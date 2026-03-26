<?php

/**
 * Youfone API Test Script
 * Run this on the server to verify connectivity and whitelisting.
 * Usage: php public/test_youfone.php
 */

$url = 'https://pcwcf.netherlands.youfone.services/PostcodeCheckCoverage';

$payload = [
    'Request' => [
        'HouseNr' => 78,
        'Zipcode' => '3011BN',
        'HouseNrExtension' => ''
    ]
];

echo "Testing Youfone API: $url\n";
echo "Payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// Postman uses GET with body (disableBodyPruning: true)
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'User-Agent: Internetvergelijk-API/1.0'
]);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "\nHTTP Status Code: $httpCode\n";

if ($error) {
    echo "CURL Error: $error\n";
}

if ($response) {
    echo "Response:\n";
    $decoded = json_decode($response, true);
    echo json_encode($decoded ?: $response, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "Empty response received.\n";
}
