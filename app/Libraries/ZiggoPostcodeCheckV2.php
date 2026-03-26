<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use Exception;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Ziggo V2 API Postcode Checker
 *
 * Expected speeds (kabel_max):
 * - 100 Mbps  (Lite)
 * - 200 Mbps  (Start)
 * - 400 Mbps  (XXL)
 * - 750 Mbps  (Complete/Max oudere pakketten)
 * - 1000 Mbps (Giga/Elite)
 *
 * New speeds vanaf maart 2025 worden genormaliseerd naar bovenstaande waarden
 * voor consistentie in de database.
 */
class ZiggoPostcodeCheckV2
{
    private const PROMISE_CACHE_LIMIT = 200;
    private static array $promiseCache = [];
    private static array $promiseCacheOrder = [];

    private $_base;
    private $_guzzle;

    function __construct(array $httpOptions = [])
    {
        // Ziggo V2 API Base URL
        // Dev: https://api.dev.aws.ziggo.io/v2/api/rfscom/v2
        // Prod: https://api.prod.aws.ziggo.io/v2/api/rfscom/v2
        $baseUrl = env('ZIGGO_V2_API_URL', 'https://api.prod.aws.ziggo.io/v2/api/rfscom/v2');

        // Ensure trailing slash for proper Guzzle base_uri behavior
        $this->_base = rtrim($baseUrl, '/') . '/';

        $defaultOptions = [
            'base_uri' => $this->_base,
            'http_errors' => false,
            'timeout' => 10,
            'connect_timeout' => 6,
            'headers' => [
                'x-api-key' => env('ZIGGO_V2_API_KEY', env('ZIGGO_API_KEY')),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];

        if (!empty($httpOptions)) {
            $defaultOptions = array_replace_recursive($defaultOptions, $httpOptions);
        }

        $this->_guzzle = new Client($defaultOptions);
    }

    /**
     * Haal snelheden op via gechainde promises (Footprint -> Availability).
     */
    public function fetchSpeedsAsync(\stdClass $address, int $verbose = 0): PromiseInterface
    {
        $cacheKey = $this->buildPromiseCacheKey($address);
        $cached = $this->getCachedPromise($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $postcode = str_replace(' ', '', $address->postcode);
        $housenumber = $address->number;

        $promise = $this->checkFootprintAsync($postcode, $housenumber, $verbose)->then(
            function ($footprintData) use ($address, $verbose) {
                if (!$footprintData) {
                    return $this->noCoverageResponse();
                }

                if (!isset($footprintData['ADDRESSES']) || !is_array($footprintData['ADDRESSES'])) {
                    return $this->noCoverageResponse();
                }

                $availabilityPromises = [];

                foreach ($footprintData['ADDRESSES'] as $ziggoAddress) {
                    $addressId = $ziggoAddress['ID'] ?? null;
                    if ($addressId === null) {
                        continue;
                    }

                    if ($address->extension && isset($ziggoAddress['HOUSEEXTENSION']) && $ziggoAddress['HOUSEEXTENSION'] !== $address->extension) {
                        continue;
                    }

                    $availabilityPromises[] = $this->checkAvailabilityAsync($addressId, $verbose)->then(
                        function ($availabilityData) use ($verbose) {
                            if (!$availabilityData) {
                                return null;
                            }

                            $connectionType = $this->getConnectionType($availabilityData);
                            $speed = $this->parseSpeed($availabilityData, $verbose);

                            return [
                                'speed' => $speed,
                                'type' => $connectionType,
                            ];
                        }
                    );
                }

                if (empty($availabilityPromises)) {
                    return $this->noCoverageResponse();
                }

                return Utils::all($availabilityPromises)->then(
                    function ($results) use ($address, $verbose) {
                        $maxSpeed = 0;
                        $bestType = null;

                        foreach ($results as $res) {
                            if ($res && $res['speed'] > $maxSpeed) {
                                $maxSpeed = $res['speed'];
                                $bestType = $res['type'];
                            }
                        }

                        if ($maxSpeed > 0) {
                            $speeds = [
                                'dsl' => null,
                                'glasvezel' => null,
                                'kabel' => null,
                            ];

                            if ($bestType === 'FTTH') {
                                $speeds['glasvezel'] = (int) $maxSpeed;
                            } else {
                                $speeds['kabel'] = (int) $maxSpeed;
                            }

                            if ($verbose) {
                                Log::info('Ziggo speeds resolved', [
                                    'postcode' => $address->postcode,
                                    'max_speed' => $maxSpeed,
                                    'type' => $bestType
                                ]);
                            }

                            return [
                                'status' => 'success',
                                'data' => [[
                                    'provider' => 'Ziggo',
                                    'download' => $speeds,
                                ]],
                            ];
                        }

                        return $this->noCoverageResponse();
                    }
                );
            },
            function ($reason) use ($address) {
                $message = $reason instanceof RequestException ? $reason->getMessage() : (string) $reason;
                Log::error('Ziggo fetchSpeedsAsync error', ProviderLog::context(
                    'Ziggo',
                    $address,
                    ['error' => $message]
                ));

                return [
                    'status' => 'error',
                    'message' => $message,
                    'data' => [],
                ];
            }
        );

        $promise = $this->wrapPromiseWithCleanup($promise, $cacheKey);
        $this->putPromiseInCache($cacheKey, $promise);

        return $promise;
    }

    private function noCoverageResponse(): array
    {
        return [
            'status' => 'no_coverage',
            'data' => [[
                'provider' => 'Ziggo',
                'download' => [
                    'dsl' => null,
                    'glasvezel' => null,
                    'kabel' => null,
                ],
            ]],
        ];
    }

    /**
     * Check footprint (coverage area)
     * V2 uses: GET /footprint/{postcode}/{housenumber}
     *
     * @param string $postcode Complete postcode (e.g., "2723AB")
     * @param int $housenumber
     * @param int $verbose
     * @return array|false Returns array with ADDRESSES on success, false on failure
     */
    public function checkFootprint($postcode, $housenumber, $verbose = 0)
    {
        try {
            // V2 API uses URL path, not query params or JSON body
            // NOTE: No leading slash - Guzzle appends to base_uri
            $url = "footprint/{$postcode}/{$housenumber}";

            if ($verbose) {
                dump("Ziggo V2 Footprint Request: GET {$this->_base}/{$url}");
            }

            $response = $this->_guzzle->get($url);

            if ($verbose) {
                dump('Ziggo V2 Footprint Status:', $response->getStatusCode());
            }

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $body = json_decode($response->getBody(), true);

            // V2 wraps response in 'data' key
            if (!isset($body['data'])) {
                return false;
            }

            $data = $body['data'];

            // Allow new footprint identifiers (coax + FTTH)
            $footprint = $data['FOOTPRINT'] ?? null;
            $allowedFootprints = [
                'fZiggo',
                'fUPC',
                'fZiggoFTTH',
                'fZiggo_FTTH',
                'fZiggoFtth',
                'fZiggoFiber',
                'fZiggoFibre',
                'fZiggoFTTB',
                'FTTH',
            ];

            if ($footprint && !in_array($footprint, $allowedFootprints, true)) {
                Log::debug('Ziggo V2 unexpected footprint type', [
                    'postcode' => $postcode,
                    'housenumber' => $housenumber,
                    'footprint' => $footprint,
                ]);
            }

            // Check if we have addresses with IDs
            if (empty($data['ADDRESSES']) || !is_array($data['ADDRESSES'])) {
                return false;
            }

            return $data;
        } catch (Exception $e) {
            Log::error('Ziggo V2 Footprint Error: ' . $e->getMessage());
            return false;
        }
    }

    public function checkFootprintAsync($postcode, $housenumber, $verbose = 0): PromiseInterface
    {
        $url = "footprint/{$postcode}/{$housenumber}";

        if ($verbose) {
            dump("Ziggo V2 Footprint Request: GET {$this->_base}/{$url}");
        }

        return $this->_guzzle->getAsync($url)->then(
            function (ResponseInterface $response) use ($verbose, $postcode, $housenumber) {
                if ($verbose) {
                    dump('Ziggo V2 Footprint Status:', $response->getStatusCode());
                }

                if ($response->getStatusCode() !== 200) {
                    return false;
                }

                $body = json_decode((string) $response->getBody(), true);

                if (!isset($body['data'])) {
                    return false;
                }

                $data = $body['data'];

                if (empty($data['ADDRESSES']) || !is_array($data['ADDRESSES'])) {
                    return false;
                }

                return $data;
            },
            function ($reason) use ($postcode, $housenumber) {
                $message = $reason instanceof RequestException ? $reason->getMessage() : (string) $reason;
                Log::error('Ziggo V2 Footprint async error', [
                    'postcode' => $postcode,
                    'housenumber' => $housenumber,
                    'error' => $message,
                ]);
                return false;
            }
        );
    }

    /**
     * Check availability - get detailed product availability
     * V2 uses: GET /availability/{addressID}
     *
     * @param string $addressId Address ID from footprint response (e.g., "2723AB106")
     * @param int $verbose
     * @return array|false Returns availability data on success, false on failure
     */
    public function checkAvailability($addressId, $verbose = 0)
    {
        try {
            // V2 API uses URL path with address ID
            // NOTE: No leading slash - Guzzle appends to base_uri
            $url = "availability/{$addressId}";

            if ($verbose) {
                dump("Ziggo V2 Availability Request: GET {$this->_base}/{$url}");
            }

            $response = $this->_guzzle->get($url);

            if ($verbose) {
                dump('Ziggo V2 Availability Status:', $response->getStatusCode());
            }

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $body = json_decode($response->getBody(), true);

            // V2 wraps response in 'data' key
            if (!isset($body['data'])) {
                return false;
            }

            $data = $body['data'];

            // V2 API doesn't have PRODUCTS array - speeds are directly in data
            // Check if we have internet availability or speed data
            $hasInternet = isset($data['IS_INTERNET_AVAILABLE']['isAvailable']) && $data['IS_INTERNET_AVAILABLE']['isAvailable'];
            $hasSpeed = isset($data['MAXNETWORKDOWNLOADSPEED']) && !empty($data['MAXNETWORKDOWNLOADSPEED']);

            if (!$hasInternet && !$hasSpeed) {
                return false;
            }

            return $data;
        } catch (Exception $e) {
            Log::error('Ziggo V2 Availability Error: ' . $e->getMessage());
            return false;
        }
    }

    public function checkAvailabilityAsync($addressId, $verbose = 0): PromiseInterface
    {
        $url = "availability/{$addressId}";

        if ($verbose) {
            dump("Ziggo V2 Availability Request: GET {$this->_base}/{$url}");
        }

        return $this->_guzzle->getAsync($url)->then(
            function (ResponseInterface $response) use ($verbose, $addressId) {
                if ($verbose) {
                    dump("Ziggo V2 Availability Status ({$addressId}):", $response->getStatusCode());
                }

                if ($response->getStatusCode() !== 200) {
                    return false;
                }

                $body = json_decode((string) $response->getBody(), true);

                if (!isset($body['data'])) {
                    return false;
                }

                $data = $body['data'];

                // V2 API doesn't have PRODUCTS array - speeds are directly in data
                // Check if we have internet availability or speed data
                $hasInternet = isset($data['IS_INTERNET_AVAILABLE']['isAvailable']) && $data['IS_INTERNET_AVAILABLE']['isAvailable'];
                $hasSpeed = isset($data['MAXNETWORKDOWNLOADSPEED']) && !empty($data['MAXNETWORKDOWNLOADSPEED']);

                if (!$hasInternet && !$hasSpeed) {
                    return false;
                }

                return $data;
            },
            function ($reason) use ($addressId) {
                $message = $reason instanceof RequestException ? $reason->getMessage() : (string) $reason;
                Log::error('Ziggo V2 Availability async error', [
                    'address_id' => $addressId,
                    'error' => $message,
                ]);
                return false;
            }
        );
    }

    /**
     * Parse speed from availability data
     * V2 returns speeds as strings in Gbit/s format (e.g., "2.2" = 2200 Mbps)
     *
     * Known Ziggo speed tiers:
     * - Lite: 100/125 Mbps
     * - Start: 200/250 Mbps
     * - XXL: 400/500 Mbps
     * - Complete/Max: 750/775 Mbps
     * - Giga/Elite: 1000/1100 Mbps
     *
     * @param array $data - Availability response data (V2 uses UPPERCASE field names)
     * @param int $verbose
     * @return int - Speed in Mbps
     */
    public function parseSpeed($data, $verbose = 0)
    {
        $maxSpeed = 0;

        // V2 API returns speeds as strings in Gbit/s
        // MAXNETWORKDOWNLOADSPEED: "2.2" = 2200 Mbps (2.2 Gbit/s)
        // MAXNETWORKUPLOADSPEED: "0.16" = 160 Mbps (0.16 Gbit/s)

        if (isset($data['MAXNETWORKDOWNLOADSPEED'])) {
            // Convert Gbit/s string to Mbps integer
            $speedGbit = floatval($data['MAXNETWORKDOWNLOADSPEED']);
            $maxSpeed = (int)($speedGbit * 1000);

            // TRACE: Log raw parsing
            Log::debug("Ziggo V2 PARSE", [
                'speedGbit' => $speedGbit,
                'maxSpeed_mbps' => $maxSpeed
            ]);

            if ($verbose) {
                dump("Ziggo V2: Raw speed = {$speedGbit} Gbit/s = {$maxSpeed} Mbps");
            }
        }

        // Normalize to standard Ziggo tiers
        if ($maxSpeed > 0) {
            $maxSpeed = $this->normalizeZiggoSpeed($maxSpeed);

            if ($verbose) {
                dump("Ziggo V2: Normalized speed = {$maxSpeed} Mbps");
            }
        }

        return $maxSpeed;
    }

    /**
     * Normalize Ziggo speeds to standard tiers
     * Maps new speed tiers to old tiers for consistency
     *
     * @param int $speed - Raw speed in Mbps
     * @return int - Normalized speed
     */
    private function normalizeZiggoSpeed($speed)
    {
        // Known Ziggo speed tiers (old → new)
        $speedTiers = [
            100 => 100,   // Lite (old)
            125 => 100,   // Lite (new) → map to 100 for consistency
            200 => 200,   // Start (old)
            250 => 200,   // Start (new) → map to 200
            400 => 400,   // XXL (old)
            500 => 400,   // XXL (new) → map to 400
            750 => 750,   // Complete/Max (old)
            775 => 750,   // Complete/Max (new) → map to 750
            1000 => 1000, // Giga/Elite (old)
            1100 => 1000, // Giga/Elite (new) → map to 1000
            2000 => 2000, // 2 Gbit tier (2024/2025)
            2200 => 2000, // 2 Gbit tier (new) → map to 2000
        ];

        $normalized = $speedTiers[$speed] ?? $speed;

        // TRACE: Log normalization
        if ($speed !== $normalized) {
            Log::debug("Ziggo V2 NORMALIZE", [
                'original' => $speed,
                'normalized' => $normalized
            ]);
        }

        // Return mapped speed or original if not in tiers
        return $normalized;
    }

    /**
     * Get connection type from availability data
     *
     * @param array $data - Availability response data
     * @return string|null - 'FTTH', 'COAX', or null
     */
    public function getConnectionType($data)
    {
        return isset($data['CONNECTIONTYPE']) ? strtoupper($data['CONNECTIONTYPE']) : null;
    }

    private function buildPromiseCacheKey(\stdClass $address): string
    {
        return sprintf(
            '%s:%s:%s',
            strtoupper($address->postcode ?? ''),
            $address->number ?? '',
            $address->extension ?? ''
        );
    }

    private function getCachedPromise(string $key): ?PromiseInterface
    {
        return self::$promiseCache[$key] ?? null;
    }

    private function putPromiseInCache(string $key, PromiseInterface $promise): void
    {
        if (count(self::$promiseCacheOrder) >= self::PROMISE_CACHE_LIMIT) {
            $oldest = array_shift(self::$promiseCacheOrder);
            if ($oldest !== null) {
                unset(self::$promiseCache[$oldest]);
            }
        }

        self::$promiseCache[$key] = $promise;
        self::$promiseCacheOrder[] = $key;
    }

    private function dropCachedPromise(string $key): void
    {
        unset(self::$promiseCache[$key]);
        self::$promiseCacheOrder = array_values(array_filter(
            self::$promiseCacheOrder,
            static fn ($entry) => $entry !== $key
        ));
    }

    private function wrapPromiseWithCleanup(PromiseInterface $promise, string $key): PromiseInterface
    {
        return $promise->then(
            function ($value) use ($key) {
                $this->dropCachedPromise($key);
                return $value;
            },
            function ($reason) use ($key) {
                $this->dropCachedPromise($key);
                return Create::rejectionFor($reason);
            }
        );
    }

    /**
     * Health check for API
     *
     * @return bool
     */
    public function healthCheck()
    {
        try {
            // NOTE: No leading slash - Guzzle appends to base_uri
            $endpoint = 'health';

            $response = $this->_guzzle->request('GET', $endpoint);

            return $response->getStatusCode() == 200;

        } catch (Exception $e) {
            Log::error("Ziggo V2 Health Check Failed: " . $e->getMessage());
            return false;
        }
    }
}
