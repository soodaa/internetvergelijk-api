<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

/**
 * SKP Network driver voor SpeedCheck v2.
 * 
 * SKP is een lokale provider in Pijnacker-Nootdorp die glasvezel en coax levert.
 * 
 * Flow:
 * 1. Haal city op via Glasnet API (wordt toch al aangeroepen voor EFiber/L2Fiber)
 * 2. Check of city in whitelist staat (Nootdorp, Pijnacker, Delfgauw)
 * 3. Zo ja: roep SKP API aan
 * 4. Parse connection type (glas/coax) en return snelheden
 */
class SkpNetworkDirect
{
    private Client $client;
    private string $providerName;
    private array $cities;
    private string $glasnetBaseUrl;
    private string $skpApiUrl;
    private string $skpNonce;

    // SKP snelheden per connection type
    private const SPEED_GLAS = 1000;    // 1 Gbit glasvezel
    private const SPEED_COAX = 600;     // 600 Mbit coax

    // Cache key prefix voor address lookup
    private const ADDRESS_CACHE_PREFIX = 'skp_address:';
    private const ADDRESS_CACHE_TTL = 43200; // 12 uur

    public function __construct(array $config = [])
    {
        $this->providerName = (string) ($config['name'] ?? 'SKP');
        $this->cities = array_map('strtoupper', $config['cities'] ?? ['NOOTDORP', 'PIJNACKER', 'DELFGAUW']);
        $this->glasnetBaseUrl = rtrim($config['glasnet_base_url'] ?? 'https://grm.glasnet.nl/api/getServices', '/');
        $this->skpApiUrl = $config['api_url'] ?? 'https://skpnet.nl/wp-admin/admin-ajax.php';
        $this->skpNonce = $config['nonce'] ?? env('SKP_NONCE', 'f803c3e245');

        $this->client = new Client([
            'http_errors' => false,
            'timeout' => 10,
            'connect_timeout' => 6,
        ]);
    }

    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        // Stap 1: Haal city op via Glasnet (gecached)
        $addressInfo = $this->getAddressInfo($address, $verbose);

        if ($addressInfo === null) {
            if ($verbose) {
                dump($this->providerName . ': Kon adresinfo niet ophalen via Glasnet');
            }
            return $this->noCoverageResponse();
        }

        $city = strtoupper(trim($addressInfo['city'] ?? ''));

        if ($verbose) {
            dump($this->providerName . ": City = '{$city}'");
        }

        // Stap 2: Check of city in whitelist staat
        if (!in_array($city, $this->cities, true)) {
            if ($verbose) {
                dump($this->providerName . ": City '{$city}' niet in whitelist, skip SKP API call");
            }
            return $this->noCoverageResponse();
        }

        if ($verbose) {
            dump($this->providerName . ": City '{$city}' in whitelist, calling SKP API");
        }

        // Stap 3: Roep SKP API aan
        return $this->callSkpApi($address, $verbose);
    }

    /**
     * Haal adresinfo op via Glasnet API (met caching).
     * Dit wordt toch al aangeroepen voor EFiber/L2Fiber checks.
     */
    private function getAddressInfo(\stdClass $address, int $verbose): ?array
    {
        $cacheKey = self::ADDRESS_CACHE_PREFIX . $address->postcode . ':' . $address->number . ':' . ($address->extension ?? '');

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if ($verbose) {
                dump($this->providerName . ': Address info from cache');
            }
            return $cached;
        }

        // Build Glasnet URL
        $ext = trim((string) ($address->extension ?? ''));
        $ext = ($ext === '') ? null : $ext;

        $segments = [
            str_replace(' ', '', $address->postcode),
            $address->number,
        ];

        if ($ext !== null) {
            $segments[] = $ext;
        }

        $url = $this->glasnetBaseUrl . '/' . implode('/', $segments);

        if ($verbose) {
            dump($this->providerName . ": Fetching address from Glasnet: {$url}");
        }

        try {
            $response = $this->client->get($url);
        } catch (\Throwable $throwable) {
            Log::warning($this->providerName . ' Glasnet address lookup failed', [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'error' => $throwable->getMessage(),
            ]);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = json_decode((string) $response->getBody());

        if (!$data || !isset($data->address)) {
            return null;
        }

        $addressInfo = [
            'city' => $data->address->city ?? null,
            'street' => $data->address->street ?? null,
            'postcode' => $data->address->postcode ?? null,
        ];

        // Cache voor 12 uur
        Cache::put($cacheKey, $addressInfo, self::ADDRESS_CACHE_TTL);

        return $addressInfo;
    }

    /**
     * Roep SKP WordPress API aan.
     */
    private function callSkpApi(\stdClass $address, int $verbose): array
    {
        $params = [
            'action' => 'gfpcnlgetaddress',
            'nonce' => $this->skpNonce,
            'form_id' => '23',
            'field_id' => '83',
            'postcode' => str_replace(' ', '', $address->postcode),
            'huisnummer' => $address->number,
            'toevoeging' => $address->extension ?? '',
            '_' => (string) round(microtime(true) * 1000),
        ];

        $url = $this->skpApiUrl . '?' . http_build_query($params);

        if ($verbose) {
            dump($this->providerName . ": Calling SKP API: {$url}");
        }

        try {
            $response = $this->client->get($url);
        } catch (\Throwable $throwable) {
            Log::error($this->providerName . ' SKP API call failed', [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'error' => $throwable->getMessage(),
            ]);
            return $this->errorResponse('SKP API niet bereikbaar: ' . $throwable->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            Log::warning($this->providerName . ' SKP API returned status ' . $response->getStatusCode());
            return $this->errorResponse('SKP API status ' . $response->getStatusCode());
        }

        $body = (string) $response->getBody();

        if ($verbose) {
            dump($this->providerName . ': SKP API raw response', $body);
        }

        // SKP returns "0" when nonce is invalid or address not found
        if ($body === '0' || $body === '') {
            Log::warning($this->providerName . ' SKP API returned empty/0 (nonce might be invalid)', [
                'postcode' => $address->postcode,
                'number' => $address->number,
            ]);
            return $this->noCoverageResponse();
        }

        $data = json_decode($body);

        if ($verbose) {
            dump($this->providerName . ': SKP API decoded response', $data);
        }

        if (!$data || !isset($data->message->adres)) {
            return $this->noCoverageResponse();
        }

        $adres = $data->message->adres;

        // Check of adres gevonden en approved is
        if (empty($adres->found) || empty($adres->approved)) {
            if ($verbose) {
                dump($this->providerName . ': Address not found or not approved by SKP');
            }
            return $this->noCoverageResponse();
        }

        // Parse connection type
        $connection = strtolower(trim($adres->connection ?? ''));

        if ($verbose) {
            dump($this->providerName . ": Connection type = '{$connection}'");
        }

        $speeds = [
            'dsl' => null,
            'glasvezel' => null,
            'kabel' => null,
        ];

        // Map connection type to speeds
        if (str_contains($connection, 'glas')) {
            $speeds['glasvezel'] = self::SPEED_GLAS;
        }

        if (str_contains($connection, 'coax')) {
            $speeds['kabel'] = self::SPEED_COAX;
        }

        // Als beide (glas en coax) beschikbaar zijn
        if ($connection === 'glas_coax' || $connection === 'glascoax') {
            $speeds['glasvezel'] = self::SPEED_GLAS;
            $speeds['kabel'] = self::SPEED_COAX;
        }

        // Check of we iets gevonden hebben
        if ($speeds['glasvezel'] === null && $speeds['kabel'] === null) {
            if ($verbose) {
                dump($this->providerName . ": Unknown connection type '{$connection}'");
            }
            Log::warning($this->providerName . ' Unknown connection type', [
                'connection' => $connection,
                'postcode' => $address->postcode,
            ]);
            return $this->noCoverageResponse();
        }

        return [
            'status' => 'success',
            'data' => [
                [
                    'provider' => $this->providerName,
                    'download' => $speeds,
                ]
            ],
        ];
    }

    private function noCoverageResponse(): array
    {
        return [
            'status' => 'no_coverage',
            'data' => [
                [
                    'provider' => $this->providerName,
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => null,
                        'kabel' => null,
                    ],
                ]
            ],
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
