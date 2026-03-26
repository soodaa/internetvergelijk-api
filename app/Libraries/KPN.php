<?php

namespace App\Libraries;

use App\Support\ProviderLog;
use App\Support\ProviderTokenStore;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Psr\Http\Message\ResponseInterface;

class KPN
{

    protected $_guzzle;
    protected $baseUri;
    protected $environment;
    protected $salesChannelId;
    protected $enrichFromCatalogue;
    protected $verbose = false;
    protected float $requestTimeout;

    /**
     * Shared promise cache om dubbele requests te voorkomen (KPN, Glaspoort, Glasdraad etc.)
     * Key format: postcode:number:extension
     */
    private static array $promiseCache = [];
    private static array $promiseCacheOrder = [];
    private const PROMISE_CACHE_LIMIT = 200;

    public $name = 'kpn';

    function __construct(array $httpOptions = [])
    {
        $this->environment = strtolower(config('auth.kpn_env') ?? 'prod');
        $configuredBase = config('auth.kpn_api_base');
        $this->baseUri = rtrim($configuredBase ?? 'https://api.kpn.com', '/');
        if ($this->environment === 'acc' && ($configuredBase === null || $configuredBase === '')) {
            $this->baseUri = 'https://api.acc.kpn.com';
        }
        $this->requestTimeout = (float) env('KPN_TIMEOUT', 10);
        $salesChannel = config('auth.kpn_sales_channel_id');
        $this->salesChannelId = $salesChannel !== null && $salesChannel !== '' ? $salesChannel : 'Externe Retail';
        $this->enrichFromCatalogue = filter_var(config('auth.kpn_enrich_from_catalogue', false), FILTER_VALIDATE_BOOLEAN);

        $defaultOptions = [
            'base_uri' => $this->baseUri,
            'http_errors' => false,
            'timeout' => 6,
            'connect_timeout' => 6,
        ];

        if (!empty($httpOptions)) {
            $defaultOptions = array_replace_recursive($defaultOptions, $httpOptions);
        }

        $this->_guzzle = new Client($defaultOptions);
    }

    /**
     * Haal snelheden op via gedeelde promise en normaliseer resultaat.
     */
    public function fetchSpeedsAsync(\stdClass $address, array $providerConfig = [], int $verbose = 0): PromiseInterface
    {
        $cacheKey = sprintf(
            '%s:%s:%s',
            strtoupper($address->postcode),
            $address->number,
            $address->extension ?? ''
        );

        $promise = $this->getCachedPromise($cacheKey);

        if ($promise !== null) {
            if ($verbose) {
                Log::debug('KPN async promise hergebruikt', ['key' => $cacheKey]);
            }
        } else {
            // Maak nieuwe promise aan voor de gedeelde async KPN-flow.
            $resultPlaceholder = new \stdClass();
            $promise = $this->getAsync($address, $resultPlaceholder, $verbose);
            $this->putPromiseInCache($cacheKey, $promise);
        }

        return $promise->then(
            function ($body) use ($providerConfig, $address) {
                if ($body === false || $body === null) {
                    return [
                        'status' => 'error',
                        'message' => 'KPN API gaf geen resultaat terug',
                        'data' => [],
                    ];
                }

                return $this->normalizeSpeeds($body, $providerConfig, $address);
            },
            function ($reason) use ($providerConfig, $address) {
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                Log::error('KPN async call fout', ProviderLog::context(
                    $providerConfig['name'] ?? 'KPN',
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
    }

    private function normalizeSpeeds($body, array $providerConfig, \stdClass $address): array
    {
        // Verzamel third-party names uit config
        $kpnThirdPartyNames = [];
        foreach (config('providers', []) as $conf) {
            if (($conf['driver'] ?? null) === 'kpn' && isset($conf['thirdparty_name'])) {
                $kpnThirdPartyNames[] = (string) $conf['thirdparty_name'];
            }
        }
        $kpnThirdPartyNames = array_values(array_unique($kpnThirdPartyNames));

        $expectedThirdParty = $providerConfig['thirdparty_name'] ?? null;

        // Check third-party match (bijv. Glaspoort vs KPN Netwerk NL)
        if ($expectedThirdParty !== null) {
            $actualThirdParty = $body->fiber_info->thirdparty_name ?? null;
            if ($actualThirdParty !== $expectedThirdParty) {
                return [
                    'status' => 'no_coverage',
                    'data' => [
                        [
                            'provider' => $providerConfig['name'],
                            'download' => [
                                'dsl' => null,
                                'glasvezel' => null,
                                'kabel' => null,
                            ],
                        ]
                    ],
                ];
            }
        }

        $speeds = [
            'dsl' => null,
            'glasvezel' => null,
            'kabel' => null,
        ];

        $actualThirdPartyInResponse = $body->fiber_info->thirdparty_name ?? null;

        $isPairbonded = false;
        $providerName = strtolower((string) ($providerConfig['name'] ?? ''));
        if (strpos($providerName, 'pairbonded') !== false || strpos($providerName, 'pair-bonded') !== false) {
            $isPairbonded = true;
        }

        if (isset($body->available_on_address->technologies) && is_array($body->available_on_address->technologies)) {
            foreach ($body->available_on_address->technologies as $technology) {
                $name = $technology->name ?? null;
                $download = $technology->download ?? null;

                if ($name === 'BONDED_COPPER' && $download !== null) {
                    if ($isPairbonded) {
                        $speeds['dsl'] = (int) $download;
                    }
                    continue;
                }

                if ($name === 'COPPER' && $download !== null) {
                    if (!$isPairbonded) {
                        $speeds['dsl'] = (int) $download;
                    }
                }

                if ($name === 'FIBER' && $download !== null) {
                    // Skip alle filters als skip_fiber_filters is ingesteld (voor KPN-all)
                    $skipFilters = $providerConfig['skip_fiber_filters'] ?? false;

                    if (!$skipFilters) {
                        // Alleen filteren als netwerk nog in aanbouw is
                        $projectStatus = $body->fiber_info->project_status ?? null;

                        if ($projectStatus === 'CONSTRUCTION') {
                            Log::info('KPN Fiber genegeerd: netwerk in aanbouw', ProviderLog::context(
                                $providerConfig['name'] ?? 'KPN',
                                $address,
                                ['project_status' => $projectStatus]
                            ));
                            continue;
                        }
                    }

                    if (!$skipFilters) {
                        $isConfiguredThirdParty = in_array($actualThirdPartyInResponse, $kpnThirdPartyNames, true);

                        // Als we KPN (hoofdmerk) checken, maar we krijgen een third-party terug die niet geconfigureerd is
                        // dan negeren we die. Maar als third-party 'KPN Netwerk NL' is, is het 'eigen' netwerk.
                        if ($expectedThirdParty === null && $actualThirdPartyInResponse !== null && $actualThirdPartyInResponse !== 'KPN Netwerk NL') {
                            if (!$isConfiguredThirdParty) {
                                Log::info('KPN third-party netwerk (nog) niet geconfigureerd', ProviderLog::context(
                                    $providerConfig['name'] ?? 'KPN',
                                    $address,
                                    ['thirdparty_name' => $actualThirdPartyInResponse]
                                ));

                                // Email alert met rate-limiting (max 1 per netwerk per 24 uur)
                                $alertCacheKey = 'kpn_network_alert_' . md5($actualThirdPartyInResponse);
                                if (!Cache::has($alertCacheKey)) {
                                    try {
                                        $recipients = env('KPN_NETWORK_ALERT_TO', 'marnix@sooda.nl');
                                        Mail::raw(
                                            "Onbekend KPN glasvezelnetwerk gedetecteerd:\n\n" .
                                            "Netwerk: {$actualThirdPartyInResponse}\n" .
                                            "Adres: {$address->postcode} {$address->number}" .
                                            ($address->extension ? " {$address->extension}" : "") . "\n\n" .
                                            "Voeg dit netwerk toe aan config/providers.php om het te ondersteunen.",
                                            fn($msg) => $msg
                                                ->to(array_map('trim', explode(',', $recipients)))
                                                ->subject("KPN: Nieuw netwerk '{$actualThirdPartyInResponse}'")
                                        );
                                        Cache::put($alertCacheKey, true, now()->addHours(24));
                                    } catch (\Throwable $mailError) {
                                        Log::warning('KPN netwerk alert mail mislukt', [
                                            'network' => $actualThirdPartyInResponse,
                                            'error' => $mailError->getMessage(),
                                        ]);
                                    }
                                }
                            }
                            continue;
                        }
                    }
                    $speeds['glasvezel'] = (int) $download;
                }
            }
        }

        if (($speeds['glasvezel'] ?? 0) <= 0) {
            $speeds['glasvezel'] = null;
        }

        if ($expectedThirdParty !== null) {
            $speeds['dsl'] = null;

            if ($speeds['glasvezel'] === null) {
                $fallbackSpeed = (int) ($providerConfig['planned_fiber_speed'] ?? 0);

                if ($fallbackSpeed > 0) {
                    $speeds['glasvezel'] = $fallbackSpeed;
                }
            }
        }

        $hasCoverage = ($speeds['dsl'] !== null)
            || ($speeds['glasvezel'] !== null)
            || ($speeds['kabel'] !== null);

        return [
            'status' => $hasCoverage ? 'success' : 'no_coverage',
            'data' => [
                [
                    'provider' => $providerConfig['name'],
                    'download' => $speeds,
                ]
            ],
        ];
    }

    public function get($address, $result, $verbose = 0, $access_token = null)
    {
        try {
            $this->verbose = (bool) $verbose;

            // Note: In V2, $result is a placeholder object and doesn't need database lookup
            // The database query has been removed as V2 doesn't use the Postcode model

            $access_token = $access_token ?? ProviderTokenStore::getValidToken('kpn');

            if ($access_token) {

                $serviceAddress = [
                    'zip_code' => $address->postcode,
                    'house_number' => $address->number,
                ];

                if ($address->extension !== null && $address->extension !== '') {
                    $serviceAddress['house_number_extension'] = $address->extension;
                }

                $body = [
                    'service_address' => $serviceAddress,
                    'order_type' => 'NEW',
                    'sales_channel_id' => $this->salesChannelId,
                ];

                if ($this->enrichFromCatalogue) {
                    $body['enrich_from_catalogue'] = true;
                }

                $response = $this->_guzzle->request('POST', '/fixed/v2/offer/', [
                    'timeout' => $this->requestTimeout,
                    'headers' => $this->buildHeaders($access_token),
                    'json' => $body,
                ]);

                $bodyContents = (string) $response->getBody();
                $decodedBody = json_decode($bodyContents, true);

                if ($this->verbose) {
                    $summary = [
                        'status' => $response->getStatusCode(),
                    ];

                    if (is_array($decodedBody)) {
                        if (isset($decodedBody['available_on_address']['technologies']) && is_array($decodedBody['available_on_address']['technologies'])) {
                            $summary['technologies'] = array_map(function ($tech) {
                                return [
                                    'name' => $tech['name'] ?? null,
                                    'download' => $tech['download'] ?? null,
                                    'upload' => $tech['upload'] ?? null,
                                ];
                            }, $decodedBody['available_on_address']['technologies']);
                        }

                        if (isset($decodedBody['bandwidth'])) {
                            $summary['bandwidth'] = $decodedBody['bandwidth'];
                        }

                        if (isset($decodedBody['subscriptions']) && is_array($decodedBody['subscriptions'])) {
                            $summary['subscriptions_count'] = count($decodedBody['subscriptions']);
                        }

                        if (isset($decodedBody['campaigns']) && is_array($decodedBody['campaigns'])) {
                            $summary['campaigns_count'] = count($decodedBody['campaigns']);
                        }

                        // Voeg third-party provider info toe aan samenvatting
                        if (isset($decodedBody['fiber_info'])) {
                            $summary['fiber_info'] = $decodedBody['fiber_info'];
                        }
                    }

                    dump('KPN API Response (samenvatting)', $summary);

                    // Toon fiber_info apart voor duidelijkheid (third-party providers zoals Glasdraad)
                    if (is_array($decodedBody) && isset($decodedBody['fiber_info'])) {
                        dump('KPN fiber_info (third-party provider)', $decodedBody['fiber_info']);
                    }

                    // Volledige response naar logbestand
                    Log::info('KPN API volledige response (zie laravel.log)', ProviderLog::context(
                        'KPN',
                        $address,
                        [
                            'status' => $response->getStatusCode(),
                            'full_response' => $decodedBody ?? $bodyContents,
                        ]
                    ));

                    dump('Volledige KPN API response is gelogd naar storage/logs/laravel.log');
                }

                if ($response->getStatusCode() === 200) {
                    return json_decode($bodyContents);
                }

                if ($response->getStatusCode() === 401) {

                    Log::error('Error KPN Response code: 401', ProviderLog::context('KPN', $address));

                    if ($this->refresh_token()) {
                        return $this->get($address, $result, $verbose);
                    }
                }

                Log::error('Error KPN Response', ProviderLog::context(
                    'KPN',
                    $address,
                    [
                        'status' => $response->getStatusCode(),
                        'body' => $decodedBody ?? $bodyContents,
                        'request_body' => $body ?? null,
                    ]
                ));

            } else {

                $access_token = $this->refresh_token();

                if ($access_token) {

                    return $this->get($address, $result, $verbose, $access_token);
                }
            }

        } catch (Exception $e) {

            Log::critical($e, ProviderLog::context('KPN', $address ?? null));
        }

        return false;
    }

    public function getAsync($address, $result, $verbose = 0, $access_token = null): PromiseInterface
    {
        try {
            $this->verbose = (bool) $verbose;

            // Note: In V2, $result is a placeholder object and doesn't need database lookup
            // The database query has been removed as V2 doesn't use the Postcode model

            $access_token = $access_token ?? ProviderTokenStore::getValidToken('kpn');

            if (!$access_token) {
                $access_token = $this->refresh_token();
                if (!$access_token) {
                    return Create::promiseFor(false);
                }
            }

            $serviceAddress = [
                'zip_code' => $address->postcode,
                'house_number' => $address->number,
            ];

            if ($address->extension !== null && $address->extension !== '') {
                $serviceAddress['house_number_extension'] = $address->extension;
            }

            $body = [
                'service_address' => $serviceAddress,
                'order_type' => 'NEW',
                'sales_channel_id' => $this->salesChannelId,
            ];

            if ($this->enrichFromCatalogue) {
                $body['enrich_from_catalogue'] = true;
            }

            return $this->_guzzle->requestAsync('POST', '/fixed/v2/offer/', [
                'timeout' => $this->requestTimeout,
                'headers' => $this->buildHeaders($access_token),
                'json' => $body,
            ])->then(
                    function (ResponseInterface $response) use ($address, $result, $body, $verbose) {
                        $bodyContents = (string) $response->getBody();
                        $decodedBody = json_decode($bodyContents, true);

                        if ($this->verbose) {
                            $summary = [
                                'status' => $response->getStatusCode(),
                            ];

                            if (is_array($decodedBody)) {
                                if (isset($decodedBody['available_on_address']['technologies']) && is_array($decodedBody['available_on_address']['technologies'])) {
                                    $summary['technologies'] = array_map(function ($tech) {
                                        return [
                                            'name' => $tech['name'] ?? null,
                                            'download' => $tech['download'] ?? null,
                                            'upload' => $tech['upload'] ?? null,
                                        ];
                                    }, $decodedBody['available_on_address']['technologies']);
                                }

                                if (isset($decodedBody['bandwidth'])) {
                                    $summary['bandwidth'] = $decodedBody['bandwidth'];
                                }

                                if (isset($decodedBody['subscriptions']) && is_array($decodedBody['subscriptions'])) {
                                    $summary['subscriptions_count'] = count($decodedBody['subscriptions']);
                                }

                                if (isset($decodedBody['campaigns']) && is_array($decodedBody['campaigns'])) {
                                    $summary['campaigns_count'] = count($decodedBody['campaigns']);
                                }

                                // Voeg third-party provider info toe aan samenvatting
                                if (isset($decodedBody['fiber_info'])) {
                                    $summary['fiber_info'] = $decodedBody['fiber_info'];
                                }
                            }

                            dump('KPN API Response (samenvatting)', $summary);

                            if (is_array($decodedBody) && isset($decodedBody['fiber_info'])) {
                                dump('KPN fiber_info (third-party provider)', $decodedBody['fiber_info']);
                            }

                            Log::info('KPN API volledige response (zie laravel.log)', [
                                'status' => $response->getStatusCode(),
                                'full_response' => $decodedBody ?? $bodyContents,
                            ]);

                            dump('Volledige KPN API response is gelogd naar storage/logs/laravel.log');
                        }

                        if ($response->getStatusCode() === 200) {
                            return json_decode($bodyContents);
                        }

                        if ($response->getStatusCode() === 401) {
                            Log::error('Error KPN Response code: 401', ProviderLog::context('KPN', $address));

                            if ($this->refresh_token()) {
                                return $this->getAsync($address, $result, $verbose);
                            }
                        }

                        Log::error('Error KPN Response', ProviderLog::context(
                            'KPN',
                            $address,
                            [
                                'status' => $response->getStatusCode(),
                                'body' => $decodedBody ?? $bodyContents,
                                'request_body' => $body ?? null,
                            ]
                        ));

                        return false;
                    },
                    function ($reason) {
                        $message = $reason instanceof RequestException ? $reason->getMessage() : (string) $reason;
                        Log::critical('KPN async exception: ' . $message);
                        return false;
                    }
                );
        } catch (Exception $e) {
            Log::critical($e);
            return Create::promiseFor(false);
        }
    }

    public function refresh_token()
    {

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        if ($this->environment === 'acc') {
            $secret = config('auth.kpn_x_secret');
            if (!empty($secret)) {
                $headers['x-secret'] = $secret;
            }
        }

        $response = $this->_guzzle->request('POST', '/oauth2/v1/token', [
            'headers' => $headers,
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => config('auth.kpn_client_id'),
                'client_secret' => config('auth.kpn_client_secret'),
            ],
        ]);

        $bodyContents = (string) $response->getBody();
        $body = json_decode($bodyContents);
        $decodedBody = json_decode($bodyContents, true);


        if ($this->verbose) {
            dump($decodedBody ?? $body ?? $bodyContents);
        }

        if ($response->getStatusCode() === 200 && isset($body->access_token)) {

            $expiresIn = isset($body->expires_in) ? (int) $body->expires_in : 3600;
            ProviderTokenStore::put('kpn', $body->access_token, $expiresIn, [
                'raw_expires_in' => $expiresIn,
            ]);

            Log::notice('New KPN access token retrieved');

            return $body->access_token;
        }

        Log::critical('Error retrieving KPN token', [
            'status' => $response->getStatusCode(),
            'body' => $decodedBody ?? $bodyContents,
        ]);

        return false;
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

    protected function buildHeaders($accessToken)
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];

        if ($this->environment === 'acc') {
            $secret = config('auth.kpn_x_secret');
            if (!empty($secret)) {
                $headers['x-secret'] = $secret;
            }
        }

        return $headers;
    }
}
