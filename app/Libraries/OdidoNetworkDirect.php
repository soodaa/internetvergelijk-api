<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Direct Odido (T-Mobile) provider voor SpeedCheck v2.
 * - Geen database interacties
 * - Geeft resultaten terug als array (status + download speeds)
 * - Ondersteunt fiber varianten (WBA, GOP, OPF, DFN, GPT) en DSL
 */
class OdidoNetworkDirect
{
    private static array $promiseCache = [];
    private static array $promiseCacheOrder = [];
    private static array $promiseRefCount = [];
    private const PROMISE_CACHE_LIMIT = 200;

    private Client $client;
    private string $variant;
    private string $providerName;
    private string $technology; // fiber | dsl
    private ?int $cap;
    private string $baseUrl;

    /**
     * @param array $config Must contain: variant, name, technology (fiber/dsl)
     */
    public function __construct(array $config, array $httpOptions = [])
    {
        $this->variant = strtoupper((string)($config['variant'] ?? ''));
        $this->providerName = (string)($config['name'] ?? 'Odido');
        $this->technology = strtolower((string)($config['technology'] ?? 'fiber'));
        $this->cap = $config['cap'] ?? config("odido.caps.{$this->variant}");

        if ($this->variant === '') {
            throw new \InvalidArgumentException('OdidoNetworkDirect: variant is verplicht.');
        }

        $base = env('TMOBILE_API_URL', 'https://internetvergelijk.nl');
        $this->baseUrl = rtrim($base, '/');

        $clientOptions = [
            'timeout' => 10,
            'connect_timeout' => 6,
        ];

        if (!empty($httpOptions)) {
            $clientOptions = array_replace_recursive($clientOptions, $httpOptions);
        }

        $this->client = new Client($clientOptions);
    }

    /**
     * Haal snelheden rechtstreeks op.
     * Alle varianten (inclusief DSL) worden via de fiber API opgehaald,
     * omdat packages zoals INTA100DSL ook in de fiber response zitten.
     */
    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        // Alle varianten (inclusief DSL) gebruiken de fiber API
        // omdat packages zoals INTA100DSL ook in de fiber response voorkomen
        return $this->fetchFiberSpeeds($address, $verbose);
    }

    public function fetchSpeedsAsync(\stdClass $address, int $verbose = 0): PromiseInterface
    {
        if (!method_exists($this, 'requestFiberCoverageAsync')) {
            return Create::promiseFor($this->fetchSpeeds($address, $verbose));
        }

        return $this->requestFiberCoverageAsync($address, $verbose)->then(
            function ($coverage) use ($address, $verbose) {
                if (!$coverage) {
                    return $this->noCoverageResponse();
                }

                $speed = $this->parseFiberSpeed($coverage, $verbose);

                if ($speed <= 0) {
                    return $this->noCoverageResponse();
                }

                $speed = $this->applyCap($speed);

                if ($verbose) {
                    Log::info("Odido {$this->variant}: normalized speed {$speed} Mbps");
                }

                $download = [
                    'dsl' => null,
                    'glasvezel' => null,
                    'kabel' => null,
                ];

                if ($this->technology === 'dsl') {
                    $download['dsl'] = $speed;
                } else {
                    $download['glasvezel'] = $speed;
                }

                return [
                    'status' => 'success',
                    'data' => [[
                        'provider' => $this->providerName,
                        'download' => $download,
                    ]],
                ];
            },
            function ($reason) {
                $message = $reason instanceof RequestException ? $reason->getMessage() : (string) $reason;
                Log::error('Odido coverage async error', [
                    'provider' => $this->providerName,
                    'error' => $message,
                ]);

                return $this->errorResponse('Odido coverage fout: ' . $message);
            }
        );
    }

    private function fetchFiberSpeeds(\stdClass $address, int $verbose): array
    {
        $coverage = $this->requestFiberCoverage($address, $verbose);

        if (!$coverage) {
            return $this->noCoverageResponse();
        }

        $speed = $this->parseFiberSpeed($coverage, $verbose);

        if ($speed <= 0) {
            return $this->noCoverageResponse();
        }

        $speed = $this->applyCap($speed);

        if ($verbose) {
            Log::info("Odido {$this->variant}: normalized speed {$speed} Mbps");
        }

        // Bepaal welk veld op basis van technology (dsl vs fiber)
        $download = [
            'dsl' => null,
            'glasvezel' => null,
            'kabel' => null,
        ];

        if ($this->technology === 'dsl') {
            $download['dsl'] = $speed;
        } else {
            $download['glasvezel'] = $speed;
        }

        return [
            'status' => 'success',
            'data' => [[
                'provider' => $this->providerName,
                'download' => $download,
            ]],
        ];
    }

    private function fetchDslSpeeds(\stdClass $address, int $verbose): array
    {
        try {
            $response = $this->client->get($this->baseUrl . '/tmobile_dsl.php', [
                'query' => [
                    'postalCode' => $address->postcode,
                    'houseNumber' => $address->number,
                    'houseNumberAddition' => $address->extension ?? '',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return $this->errorResponse('Odido DSL API gaf status ' . $response->getStatusCode());
            }

            $payload = json_decode((string)$response->getBody());

            if (!is_array($payload) || empty($payload[0]) || empty($payload[0]->State)) {
                return $this->noCoverageResponse();
            }

            $entry = $payload[0];
            $bandwidthKbps = (int)($entry->ExpectedMaximumAvailableBandwidth ?? 0);
            $speed = (int)($bandwidthKbps / 1000);

            if ($verbose) {
                Log::info("Odido DSL raw bandwidth: {$bandwidthKbps} kbps ({$speed} Mbps)");
            }

            $speed = $this->applyCap($speed);

            return [
                'status' => $speed > 0 ? 'success' : 'no_coverage',
                'data' => [[
                    'provider' => $this->providerName,
                    'download' => [
                        'dsl' => $speed > 0 ? $speed : null,
                        'glasvezel' => null,
                        'kabel' => null,
                    ],
                ]],
            ];

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Odido DSL API connection error', [
                'provider' => $this->providerName,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Odido DSL API niet bereikbaar');
        } catch (\Throwable $throwable) {
            Log::error('Odido DSL API fout', [
                'provider' => $this->providerName,
                'error' => $throwable->getMessage(),
            ]);
            return $this->errorResponse('Fout bij ophalen Odido DSL gegevens');
        }
    }

    private function requestFiberCoverage(\stdClass $address, int $verbose)
    {
        // Standaard: Direct naar Odido API (zonder proxy)
        // Proxy bestanden op hoofddomein zijn niet meer nodig omdat IP whitelisting werkt op api.internetvergelijk.nl
        $useDirectApi = env('ODIDO_DIRECT_API', true);
        
        if ($useDirectApi) {
            return $this->requestFiberCoverageDirect($address, $verbose);
        }
        
        // Fallback: Via proxy op hoofddomein (voor backwards compatibility)
        try {
            $response = $this->client->get($this->baseUrl . '/tmobile_fiber.php', [
                'query' => [
                    'postalCode' => $address->postcode,
                    'houseNumber' => $address->number,
                    'houseNumberAddition' => $address->extension ?? '',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $body = json_decode((string)$response->getBody(), true);

            if (empty($body) || !is_array($body)) {
                return false;
            }

            $extension = $address->extension;
            $matches = [];

            foreach ($body as $entry) {
                if (!isset($entry['Address'])) {
                    continue;
                }
                $addition = $entry['Address']['HouseNumberAddition'] ?? '';

                if ($extension === null || $extension === '') {
                    if ($addition === null || $addition === '') {
                        $matches[] = $entry;
                    }
                } else {
                    if (strcasecmp(trim((string)$addition), trim((string)$extension)) === 0) {
                        $matches[] = $entry;
                    }
                }
            }

            return $matches[0] ?? $body[0];

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Odido fiber API connection error', [
                'provider' => $this->providerName,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $throwable) {
            Log::error('Odido fiber API fout', [
                'provider' => $this->providerName,
                'error' => $throwable->getMessage(),
            ]);
            return false;
        }
    }

    public function requestFiberCoverageAsync(\stdClass $address, int $verbose): PromiseInterface
    {
        $cacheKey = sprintf('%s-%s-%s', $address->postcode, $address->number, $address->extension ?? '');

        if (isset(self::$promiseCache[$cacheKey])) {
            // Verhoog reference counter wanneer promise wordt hergebruikt
            self::$promiseRefCount[$cacheKey] = (self::$promiseRefCount[$cacheKey] ?? 0) + 1;
            
            if ($verbose) {
                Log::info("Odido async coverage promise hergebruikt", [
                    'key' => $cacheKey,
                    'ref_count' => self::$promiseRefCount[$cacheKey]
                ]);
            }
            return self::$promiseCache[$cacheKey];
        }

        $useDirectApi = env('ODIDO_DIRECT_API', true);

        if ($useDirectApi) {
            $promise = $this->requestFiberCoverageDirectAsync($address, $verbose);
        } else {
            $promise = Create::promiseFor($this->requestFiberCoverage($address, $verbose));
        }

        // Initialiseer reference counter
        self::$promiseRefCount[$cacheKey] = 1;
        
        $promise = $this->wrapPromiseWithCleanup($promise, $cacheKey);
        $this->putPromiseInCache($cacheKey, $promise);

        return $promise;
    }

    /**
     * Direct API call naar Odido (zonder proxy) - voor whitelisting test
     */
    private function requestFiberCoverageDirect(\stdClass $address, int $verbose)
    {
        try {
            $apiUrl = env('ODIDO_COVERAGE_API_URL', 'https://vispcoveragecheckapi.glasoperator.nl/Generic201402/CoverageCheck.svc/urljson');
            $clientId = env('ODIDO_CLIENT_ID', 'internetvergelijk.vispcoverage');
            $clientSecret = env('ODIDO_CLIENT_SECRET');
            
            if (!$clientSecret) {
                Log::error('Odido direct API: ODIDO_CLIENT_SECRET niet geconfigureerd');
                return false;
            }
            
            // Build URL met credentials in query string (zoals proxy doet)
            $url = $apiUrl . '/CheckFiberCoverage?' . http_build_query([
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
                'ispId' => 'VDF',
                'postalCode' => str_replace(' ', '', $address->postcode),
                'houseNumber' => $address->number,
                'houseNumberAddition' => $address->extension ?? '',
            ]);
            
            if ($verbose) {
                Log::info("Odido direct API request: GET {$url}");
            }
            
            $response = $this->client->get($url);
            
            if ($verbose) {
                Log::info('Odido direct API status', ['status' => $response->getStatusCode()]);
            }
            
            if ($response->getStatusCode() !== 200) {
                Log::error('Odido direct API error', [
                    'status' => $response->getStatusCode(),
                    'body' => (string)$response->getBody(),
                ]);
                return false;
            }
            
            $body = json_decode((string)$response->getBody(), true);
            
            if (empty($body) || !is_array($body)) {
                if ($verbose) {
                    Log::info('Odido direct API: Lege of ongeldige response', ['body' => $body]);
                }
                return false;
            }
            
            if ($verbose) {
                Log::info('Odido direct API response', ['response' => $body]);
            }
            
            // Address matching (zelfde logica als proxy versie)
            $extension = $address->extension;
            $matches = [];
            
            foreach ($body as $entry) {
                if (!isset($entry['Address'])) {
                    continue;
                }
                $addition = $entry['Address']['HouseNumberAddition'] ?? '';
                
                if ($extension === null || $extension === '') {
                    if ($addition === null || $addition === '') {
                        $matches[] = $entry;
                    }
                } else {
                    if (strcasecmp(trim((string)$addition), trim((string)$extension)) === 0) {
                        $matches[] = $entry;
                    }
                }
            }
            
            Log::info('Odido direct API success', [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'matches_found' => count($matches),
            ]);
            
            return $matches[0] ?? $body[0];
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Odido direct API connection error', [
                'provider' => $this->providerName,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $throwable) {
            Log::error('Odido direct API fout', [
                'provider' => $this->providerName,
                'error' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);
            return false;
        }
    }

    private function requestFiberCoverageDirectAsync(\stdClass $address, int $verbose): PromiseInterface
    {
        $apiUrl = env('ODIDO_COVERAGE_API_URL', 'https://vispcoveragecheckapi.glasoperator.nl/Generic201402/CoverageCheck.svc/urljson');
        $clientId = env('ODIDO_CLIENT_ID', 'internetvergelijk.vispcoverage');
        $clientSecret = env('ODIDO_CLIENT_SECRET');

        if (!$clientSecret) {
            Log::error('Odido direct API: ODIDO_CLIENT_SECRET niet geconfigureerd');

            return Create::promiseFor(false);
        }

        $url = $apiUrl . '/CheckFiberCoverage?' . http_build_query([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'ispId' => 'VDF',
            'postalCode' => str_replace(' ', '', $address->postcode),
            'houseNumber' => $address->number,
            'houseNumberAddition' => $address->extension ?? '',
        ]);

        if ($verbose) {
            Log::info("Odido direct API request: GET {$url}");
        }

        return $this->client->getAsync($url)->then(
            function (ResponseInterface $response) use ($address, $verbose) {
                if ($verbose) {
                    Log::info('Odido direct API status', ['status' => $response->getStatusCode()]);
                }

                if ($response->getStatusCode() !== 200) {
                    Log::error('Odido direct API error', [
                        'status' => $response->getStatusCode(),
                        'body' => (string) $response->getBody(),
                    ]);
                    return false;
                }

                $body = json_decode((string) $response->getBody(), true);

                if (empty($body) || !is_array($body)) {
                    if ($verbose) {
                        Log::info('Odido direct API: Lege of ongeldige response', ['body' => $body]);
                    }
                    return false;
                }

                $extension = $address->extension;
                $matches = [];

                foreach ($body as $entry) {
                    if (!isset($entry['Address'])) {
                        continue;
                    }
                    $addition = $entry['Address']['HouseNumberAddition'] ?? '';

                    if ($extension === null || $extension === '') {
                        if ($addition === null || $addition === '') {
                            $matches[] = $entry;
                        }
                    } else {
                        if (strcasecmp(trim((string) $addition), trim((string) $extension)) === 0) {
                            $matches[] = $entry;
                        }
                    }
                }

                return $matches[0] ?? $body[0];
            },
            function ($reason) {
                $message = $reason instanceof RequestException ? $reason->getMessage() : (string) $reason;
                Log::error('Odido direct API async error', ['error' => $message]);
                return false;
            }
        );
    }

    private function parseFiberSpeed(array $data, int $verbose): int
    {
        $packages = $data['Packages'] ?? [];
        $variant = $this->variant;
        $packageSpeeds = [];

        foreach ($packages as $package) {
            if (preg_match('/INTA(\d+)' . preg_quote($variant, '/') . '/', $package, $matches)) {
                $speed = (int)$matches[1];
                $packageSpeeds[] = $speed;

                if ($verbose) {
                    Log::info("Odido {$variant}: package {$package} => {$speed} Mbps");
                }
            }
        }

        if (empty($packageSpeeds)) {
            return 0;
        }

        $maxSpeed = max($packageSpeeds);

        Log::info("Odido {$variant} parsed", [
            'packages_found' => count($packageSpeeds),
            'speeds' => $packageSpeeds,
            'maxSpeed_from_packages' => $maxSpeed,
        ]);

        return $maxSpeed;
    }

    private function applyCap(int $speed): int
    {
        if ($this->cap === null || $this->cap <= 0) {
            return $speed;
        }

        if ($speed > $this->cap) {
            Log::info("Odido {$this->variant} cap applied", [
                'original' => $speed,
                'capped' => $this->cap,
            ]);
        }

        return min($speed, $this->cap);
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

    private function putPromiseInCache(string $key, PromiseInterface $promise): void
    {
        if (count(self::$promiseCacheOrder) >= self::PROMISE_CACHE_LIMIT) {
            $oldest = array_shift(self::$promiseCacheOrder);
            if ($oldest !== null) {
                unset(self::$promiseCache[$oldest]);
                unset(self::$promiseRefCount[$oldest]);
            }
        }

        self::$promiseCache[$key] = $promise;
        self::$promiseCacheOrder[] = $key;
    }

    private function dropCachedPromise(string $key): void
    {
        // Verlaag reference counter
        if (isset(self::$promiseRefCount[$key])) {
            self::$promiseRefCount[$key]--;
            
            // Verwijder alleen als geen andere varianten deze promise nog gebruiken
            if (self::$promiseRefCount[$key] <= 0) {
                unset(self::$promiseCache[$key]);
                unset(self::$promiseRefCount[$key]);
                self::$promiseCacheOrder = array_values(array_filter(
                    self::$promiseCacheOrder,
                    static fn ($entry) => $entry !== $key
                ));
            }
        } else {
            // Fallback: verwijder direct als ref count niet bestaat
            unset(self::$promiseCache[$key]);
            self::$promiseCacheOrder = array_values(array_filter(
                self::$promiseCacheOrder,
                static fn ($entry) => $entry !== $key
            ));
        }
    }

    private function wrapPromiseWithCleanup(PromiseInterface $promise, string $key): PromiseInterface
    {
        return $promise->then(
            function ($value) use ($key) {
                // Success: verwijder alleen als alle references klaar zijn
                $this->dropCachedPromise($key);
                return $value;
            },
            function ($reason) use ($key) {
                // Rejection: verwijder direct uit cache zodat nieuwe requests een fresh promise krijgen
                // Dit voorkomt dat rejected promises oneindig gecached blijven
                $this->forceRemoveCachedPromise($key);
                return Create::rejectionFor($reason);
            }
        );
    }
    
    private function forceRemoveCachedPromise(string $key): void
    {
        // Verwijder rejected promise direct uit cache, ongeacht reference counter
        // Dit zorgt ervoor dat nieuwe requests een nieuwe promise maken in plaats van
        // de rejected promise te hergebruiken
        unset(self::$promiseCache[$key]);
        unset(self::$promiseRefCount[$key]);
        self::$promiseCacheOrder = array_values(array_filter(
            self::$promiseCacheOrder,
            static fn ($entry) => $entry !== $key
        ));
        
        Log::debug('Odido rejected promise verwijderd uit cache', ['key' => $key]);
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
