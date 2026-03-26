<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Directe Glasnet driver voor speedCheck v2 (geen database writes).
 */
class GlasnetNetworkDirect
{
    private Client $client;
    private string $providerName;
    private string $expectedSupplier;
    private string $baseUrl;

    public function __construct(array $config = [])
    {
        $this->providerName = (string)($config['name'] ?? 'Glasnet');
        $this->expectedSupplier = strtolower(str_replace(' ', '', $config['supplier'] ?? $this->providerName));
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://grm.glasnet.nl/api/getServices', '/');

        $this->client = new Client([
            'http_errors' => false,
            'timeout' => 10,
            'connect_timeout' => 6,
        ]);
    }

    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        $ext = trim((string)($address->extension ?? ''));
        $ext = ($ext === '') ? null : $ext;

        $segments = [
            str_replace(' ', '', $address->postcode),
            $address->number,
        ];

        if ($ext !== null) {
            $segments[] = $ext;
        }

        $url = $this->baseUrl . '/' . implode('/', $segments);

        if ($verbose) {
            dump($this->providerName . ": Checking {$url}");
        }

        try {
            $response = $this->client->get($url);
        } catch (\Throwable $throwable) {
            Log::error($this->providerName . ' HTTP error', [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'extension' => $ext,
                'error' => $throwable->getMessage(),
            ]);

            return $this->errorResponse('Glasnet HTTP fout: ' . $throwable->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            Log::warning($this->providerName . ' API returned status ' . $response->getStatusCode(), [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'extension' => $ext,
            ]);

            return $this->errorResponse('Glasnet status ' . $response->getStatusCode());
        }

        $data = json_decode((string)$response->getBody());

        if ($verbose) {
            dump($data);
        }

        if (!$data || !isset($data->status) || $data->status === 'Unavailable') {
            return $this->noCoverageResponse();
        }

        if (isset($data->supplier)) {
            $apiSupplier = strtolower(str_replace(' ', '', $data->supplier));
            if ($apiSupplier !== $this->expectedSupplier) {
                if ($verbose) {
                    dump($this->providerName . ': supplier mismatch ' . $data->supplier);
                }
                return $this->noCoverageResponse();
            }
        }

        if (!isset($data->services->Internet) || !is_array($data->services->Internet)) {
            return $this->noCoverageResponse();
        }

        $maxSpeed = 0;

        foreach ($data->services->Internet as $product) {
            $speed = (int)($product->speed ?? 0);
            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }
        }

        if ($maxSpeed <= 0) {
            return $this->noCoverageResponse();
        }

        return [
            'status' => 'success',
            'data' => [[
                'provider' => $this->providerName,
                'download' => [
                    'dsl' => null,
                    'glasvezel' => $maxSpeed,
                    'kabel' => null,
                ],
            ]],
        ];
    }

    private function noCoverageResponse(): array
    {
        return [
            'status' => 'no_coverage',
            'data' => [[
                'provider' => $this->providerName,
                'download' => [
                    'dsl' => null,
                    'glasvezel' => null,
                    'kabel' => null,
                ],
            ]],
        ];
    }

    private function errorResponse(string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'data' => [],
        ];
    }
}
