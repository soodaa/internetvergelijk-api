<?php

/**
 * Youfone API Multi-Path Test Script
 * Run this on the server to try different path variations.
 */

$host = 'https://pcwcf.netherlands.youfone.services';
$paths = [
    '/PostcodeCheckCoverage',
    '/GetData',
    '/PostcodeCheckWcf/v3.0/service.svc/json/PostcodeCheckCoverage',
    '/PostcodeCheckWcf/v3.0/service.svc/json/GetData',
    '/service.svc/json/PostcodeCheckCoverage',
];

$payload = [
    'Request' => [
        'HouseNr' => 78,
        'Zipcode' => '3011BN',
        'HouseNrExtension' => ''
    ]
];

foreach ($paths as $path) {
    $url = $host . $path;
    echo "--- Testing Path: $path ---\n";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Internetvergelijk-API/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Status: $httpCode\n";
    if ($httpCode === 200) {
        echo "SUCCESS! Response received.\n";
        break;
    }
    echo "\n";
}
