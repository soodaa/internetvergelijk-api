#!/usr/bin/env php
<?php

/**
 * Test Ziggo V1 API to verify the API key works
 */

$apiUrl = 'https://www.ziggo.nl/shop/api';
$apiKey = '1mtvdLy0HLKUv2tZ0WCp3PZCIj1IIiGK';

$postcode = '2723AB';
$huisnummer = 106;

echo "\n=== Testing Ziggo V1 API ===\n";
echo "URL: $apiUrl/footprint/2723/AB/$huisnummer\n\n";

require_once __DIR__ . '/vendor/autoload.php';

$client = new \GuzzleHttp\Client([
    'http_errors' => false,
    'timeout' => 6,
    'headers' => [
        'x-api-key' => $apiKey
    ]
]);

$url = $apiUrl . '/footprint/2723/AB/' . $huisnummer;
$response = $client->get($url);

echo "Status: " . $response->getStatusCode() . "\n";
echo "Response:\n";
echo $response->getBody() . "\n\n";

if ($response->getStatusCode() == 200) {
    $body = json_decode($response->getBody());
    echo "✓ V1 API KEY WORKS!\n";
    echo "Footprint: " . ($body->data->FOOTPRINT ?? 'unknown') . "\n";
} else {
    echo "✗ V1 API KEY DOES NOT WORK\n";
}

echo "\n";
