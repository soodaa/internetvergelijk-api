<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class ZiggoV2Debug extends Command
{
    protected $signature = 'ziggo:debug {postcode} {number} {extension?}';
    protected $description = 'Debug Ziggo V2 API - Show raw responses';

    public function handle()
    {
        $postcode = $this->argument('postcode');
        $number = $this->argument('number');
        $extension = $this->argument('extension');

        $baseUrl = env('ZIGGO_V2_API_URL', 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2');
        $apiKey = env('ZIGGO_V2_API_KEY', env('ZIGGO_API_KEY'));

        $this->info("=== Ziggo V2 API Debug ===");
        $this->line("Base URL: {$baseUrl}");
        $this->line("API Key: " . substr($apiKey, 0, 10) . "...");
        $this->line("Address: {$postcode} {$number} {$extension}");
        $this->line("");

        $guzzle = new Client([
            'http_errors' => false,
            'timeout' => 10,
            'headers' => [
                'X-Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);

        // Test 1: Health Check
        $this->info("--- 1. Health Check ---");
        try {
            $response = $guzzle->request('GET', $baseUrl . '/health');
            $this->line("Status: " . $response->getStatusCode());
            $this->line("Body: " . $response->getBody());
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        $this->line("");

        // Test 2: Footprint Check
        $this->info("--- 2. Footprint Check ---");
        $params = [
            'postalCode' => str_replace(' ', '', $postcode),
            'houseNumber' => (int) $number
        ];
        if ($extension) {
            $params['houseNumberExtension'] = $extension;
        }

        $this->line("Request: " . json_encode($params, JSON_PRETTY_PRINT));
        
        try {
            $response = $guzzle->request('POST', $baseUrl . '/footprint', [
                'json' => $params
            ]);
            
            $this->line("Status: " . $response->getStatusCode());
            $this->line("Headers: " . json_encode($response->getHeaders(), JSON_PRETTY_PRINT));
            
            $body = json_decode($response->getBody(), true);
            $this->line("Response: " . json_encode($body, JSON_PRETTY_PRINT));

            // Analyze structure
            if (isset($body['data'])) {
                $this->line("");
                $this->info("📊 Data Structure Analysis:");
                $this->analyzeStructure($body['data'], '  ');
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        $this->line("");

        // Test 3: Availability Check
        $this->info("--- 3. Availability Check ---");
        try {
            $response = $guzzle->request('POST', $baseUrl . '/availability', [
                'json' => $params
            ]);
            
            $this->line("Status: " . $response->getStatusCode());
            
            $body = json_decode($response->getBody(), true);
            $this->line("Response: " . json_encode($body, JSON_PRETTY_PRINT));

            // Analyze structure
            if (isset($body['data'])) {
                $this->line("");
                $this->info("📊 Data Structure Analysis:");
                $this->analyzeStructure($body['data'], '  ');
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        $this->line("");

        // Test 4: Address Validation
        $this->info("--- 4. Address Validation ---");
        try {
            $response = $guzzle->request('POST', $baseUrl . '/address', [
                'json' => $params
            ]);
            
            $this->line("Status: " . $response->getStatusCode());
            
            $body = json_decode($response->getBody(), true);
            $this->line("Response: " . json_encode($body, JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }

        $this->line("");
        $this->info("=== Debug Complete ===");
        $this->line("");
        $this->warn("💡 TIP: Use this output to update parseSpeed() method in ZiggoPostcodeCheckV2.php");

        return 0;
    }

    private function analyzeStructure($data, $indent = '')
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $this->line("{$indent}{$key}: " . gettype($value) . " (" . count((array)$value) . " items)");
                    if (count((array)$value) > 0) {
                        $this->analyzeStructure($value, $indent . '  ');
                    }
                } else {
                    $type = gettype($value);
                    $preview = is_string($value) ? substr($value, 0, 50) : $value;
                    $this->line("{$indent}{$key}: {$type} = {$preview}");
                }
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $this->line("{$indent}{$key}: " . gettype($value) . " (" . count((array)$value) . " items)");
                    $this->analyzeStructure($value, $indent . '  ');
                } else {
                    $type = gettype($value);
                    $preview = is_string($value) ? substr($value, 0, 50) : $value;
                    $this->line("{$indent}{$key}: {$type} = {$preview}");
                }
            }
        }
    }
}
