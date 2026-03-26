#!/usr/bin/env php
<?php

/**
 * Standalone Ziggo V2 API Test (no Laravel dependencies)
 * 
 * Tests the new Ziggo V2 API directly with your credentials
 */

// Ziggo V2 API Configuration
$apiUrl = 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2';
$apiKey = '1mtvdLy0HLKUv2tZ0WCp3PZCIj1IIiGK';  // Same key as V1

// Test address
$postcode = '2723AB';
$huisnummer = 106;

echo "\n=== Ziggo V2 API Standalone Test ===\n";
echo "Postcode: $postcode $huisnummer\n";
echo "API URL: $apiUrl\n\n";

// Initialize Guzzle
require_once __DIR__ . '/vendor/autoload.php';

$client = new \GuzzleHttp\Client([
    'base_uri' => 'https://api.prod.aws.ziggo.io', // Base only, no /v2/api/rfscom/v2
    'timeout' => 10,
    'verify' => false, // Accept self-signed certificates
]);

// Test 1: Health Check
echo "1. Testing Health Endpoint...\n";
try {
    $response = $client->get($apiUrl . '/health', [
        'headers' => ['x-api-key' => $apiKey]  // lowercase
    ]);
    $healthData = json_decode($response->getBody(), true);
    echo "   ✓ Health: " . $response->getStatusCode() . " OK\n";
    echo "   Response: " . json_encode($healthData, JSON_PRETTY_PRINT) . "\n\n";
} catch (\Exception $e) {
    echo "   ✗ Health failed: " . $e->getMessage() . "\n\n";
}

// Test 2: Footprint (Coverage Check)
echo "2. Testing Footprint Endpoint...\n";
try {
    $response = $client->post($apiUrl . '/footprint', [
        'headers' => [
            'x-api-key' => $apiKey,  // lowercase
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'postcode' => $postcode,
            'huisnummer' => (int)$huisnummer,
        ]
    ]);
    
    $footprintData = json_decode($response->getBody(), true);
    echo "   ✓ Footprint: " . $response->getStatusCode() . " OK\n";
    echo "   Full Response:\n";
    echo json_encode($footprintData, JSON_PRETTY_PRINT) . "\n\n";
    
    // Extract coverage info
    if (isset($footprintData['footprint']) && $footprintData['footprint']) {
        echo "   📍 COVERAGE: YES\n";
    } else {
        echo "   📍 COVERAGE: NO\n";
    }
} catch (\Exception $e) {
    echo "   ✗ Footprint failed: " . $e->getMessage() . "\n\n";
}

// Test 3: Availability (Speed Check)
echo "\n3. Testing Availability Endpoint...\n";
try {
    $response = $client->post($apiUrl . '/availability', [
        'headers' => [
            'x-api-key' => $apiKey,  // lowercase
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'postcode' => $postcode,
            'huisnummer' => (int)$huisnummer,
        ]
    ]);
    
    $availabilityData = json_decode($response->getBody(), true);
    echo "   ✓ Availability: " . $response->getStatusCode() . " OK\n";
    echo "   Full Response:\n";
    echo json_encode($availabilityData, JSON_PRETTY_PRINT) . "\n\n";
    
    // Try to extract speed
    echo "   🔍 Searching for speed information...\n";
    
    // Method 1: Look for speed field
    if (isset($availabilityData['speed'])) {
        echo "   Found speed field: " . $availabilityData['speed'] . " Mbps\n";
    }
    
    // Method 2: Look in downloadSpeed
    if (isset($availabilityData['downloadSpeed'])) {
        echo "   Found downloadSpeed: " . $availabilityData['downloadSpeed'] . " Mbps\n";
    }
    
    // Method 3: Look in maxSpeed
    if (isset($availabilityData['maxSpeed'])) {
        echo "   Found maxSpeed: " . $availabilityData['maxSpeed'] . " Mbps\n";
    }
    
    // Method 4: Look in products array
    if (isset($availabilityData['products']) && is_array($availabilityData['products'])) {
        echo "   Found " . count($availabilityData['products']) . " products:\n";
        foreach ($availabilityData['products'] as $product) {
            if (isset($product['speed'])) {
                echo "     - " . $product['speed'] . " Mbps\n";
            } else {
                echo "     - " . json_encode($product) . "\n";
            }
        }
    }
    
} catch (\Exception $e) {
    echo "   ✗ Availability failed: " . $e->getMessage() . "\n";
    if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
        echo "   Response Body: " . $e->getResponse()->getBody() . "\n";
    }
    echo "\n";
}

// Test 4: Address Endpoint (if exists)
echo "\n4. Testing Address Endpoint...\n";
try {
    $response = $client->post($apiUrl . '/address', [
        'headers' => [
            'x-api-key' => $apiKey,  // lowercase
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'postcode' => $postcode,
            'huisnummer' => (int)$huisnummer,
        ]
    ]);
    
    $addressData = json_decode($response->getBody(), true);
    echo "   ✓ Address: " . $response->getStatusCode() . " OK\n";
    echo "   Response: " . json_encode($addressData, JSON_PRETTY_PRINT) . "\n\n";
} catch (\Exception $e) {
    echo "   ✗ Address endpoint might not exist: " . $e->getMessage() . "\n\n";
}

echo "\n=== Test Complete ===\n\n";
