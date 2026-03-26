<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;

/**
 * Directe HGVT driver voor speedCheck v2 (geen database writes).
 */
class HgvtNetworkDirect
{
    private Client $client;
    private string $providerName;
    /** @var array<string,true> */
    private array $projectTags = [];
    /** @var array<string,true> */
    private array $excludedProjectTags = [];
    private ?string $projectName;
    private int $speedMbps;
    private string $baseUrl;

    /**
     * Shared promise cache om dubbele HGVT requests te voorkomen bij meerdere providers.
     * Key format: baseUrl|ZIPCODE|number|extension
     *
     * @var array<string,PromiseInterface>
     */
    private static array $availabilityPromiseCache = [];
    private static array $availabilityPromiseCacheOrder = [];
    private const AVAILABILITY_PROMISE_CACHE_LIMIT = 200;

    public function __construct(array $config = [])
    {
        $this->providerName = (string)($config['name'] ?? 'HGVT');
        $this->projectTags = $this->normalizeTagSet($config['project_tags'] ?? []);

        if (isset($config['project_tag']) && $config['project_tag'] !== '') {
            $this->projectTags[strtolower((string) $config['project_tag'])] = true;
        }

        $this->excludedProjectTags = $this->normalizeTagSet($config['exclude_project_tags'] ?? []);
        $this->projectName = isset($config['project_name']) ? strtolower((string)$config['project_name']) : null;
        $this->speedMbps = (int)($config['speed'] ?? 1000);
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://b2b.trikx.nl/availability/', '/');

        $this->client = new Client([
            'http_errors' => false,
            'timeout' => 10,
            'connect_timeout' => 6,
        ]);
    }

    public function fetchSpeedsAsync(\stdClass $address, array $providerConfig = [], int $verbose = 0): PromiseInterface
    {
        if (empty($this->projectTags) && $this->projectName === null) {
            Log::error($this->providerName . ': HGVT providerconfig mist project_tags/project_name (catch-all is niet toegestaan)', [
                'driver' => 'hgvt',
                'provider' => $this->providerName,
            ]);

            return Create::promiseFor($this->errorResponse('HGVT configuratiefout: project_tags ontbreekt'));
        }

        $ext = trim((string)($address->extension ?? ''));
        $ext = ($ext === '') ? null : $ext;

        $params = [
            'version' => 4,
            'zipcode' => $address->postcode,
            'housenumber' => $address->number,
        ];

        if ($ext) {
            $params['housenumberext'] = $ext;
        }

        $url = $this->baseUrl . '?' . http_build_query($params);

        if ($verbose) {
            Log::info($this->providerName . ": Checking {$url}");
        }

        return $this->getAvailabilityPromise($url, $this->baseUrl, $address->postcode, (string) $address->number, $ext, $verbose)
            ->then(function (array $payload) use ($address, $ext, $verbose) {
                if (($payload['ok'] ?? false) !== true) {
                    return $this->errorResponse((string) ($payload['error'] ?? 'HGVT onbekende fout'));
                }

                $data = $payload['data'] ?? null;
                if (!$data || !is_object($data)) {
                    return $this->noCoverageResponse();
                }

                if (isset($data->response->error) && $data->response->error) {
                    return $this->noCoverageResponse();
                }

                if (!isset($data->response->projects) || !is_array($data->response->projects)) {
                    return $this->noCoverageResponse();
                }

                $available = false;
                $matchedTag = null;
                $projectCount = count($data->response->projects);

                foreach ($data->response->projects as $project) {
                    $state = (int)($project->state ?? 0);
                    $tag = strtolower((string)($project->project_tag ?? ''));
                    $name = strtolower((string)($project->project_name ?? ''));
                    $room = trim((string)($project->room ?? ''));

                    if ($tag !== '' && isset($this->excludedProjectTags[$tag])) {
                        continue;
                    }

                    if (!empty($this->projectTags)) {
                        if ($tag === '' || !isset($this->projectTags[$tag])) {
                            continue;
                        }
                    }

                    if ($this->projectName && $name !== $this->projectName) {
                        continue;
                    }

                    // Veiligheid: als HGVT alleen room-specifieke projecten teruggeeft en de gebruiker geen toevoeging invult,
                    // voorkom false-positives (drukke sites -> veel verkeerde "dekking" meldingen).
                    if ($ext === null && $room !== '') {
                        continue;
                    }

                    if ($verbose) {
                        Log::info($this->providerName . ": Project '{$project->project_name}' - Tag: {$tag} - State: {$state} - Room: {$room} - Technology: " . ($project->technology ?? 'N/A'));
                    }

                    if ($state >= 10 && $state < 40) {
                        $available = true;
                        $matchedTag = $tag !== '' ? $tag : null;
                        break;
                    }
                }

                if (!$available) {
                    return $this->noCoverageResponse();
                }

                return [
                    'status' => 'success',
                    'data' => [[
                        'provider' => $this->providerName,
                        'download' => [
                            'dsl' => null,
                            'glasvezel' => $this->speedMbps,
                            'kabel' => null,
                        ],
                    ]],
                    'meta' => [
                        'hgvt' => [
                            'projects_returned' => $projectCount,
                            'matched_project_tag' => $matchedTag,
                        ],
                    ],
                ];
            });
    }

    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        try {
            return $this->fetchSpeedsAsync($address, [], $verbose)->wait();
        } catch (\Throwable $throwable) {
            return $this->errorResponse('HGVT HTTP fout: ' . $throwable->getMessage());
        }
    }

    private function normalizeTagSet($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag !== '') {
                $out[$tag] = true;
            }
        }
        return $out;
    }

    private function getAvailabilityPromise(
        string $url,
        string $baseUrl,
        string $postcode,
        string $number,
        ?string $extension,
        int $verbose
    ): PromiseInterface {
        $cacheKey = sprintf('%s|%s|%s|%s',
            $baseUrl,
            strtoupper(trim($postcode)),
            trim($number),
            $extension ?? ''
        );

        $promise = $this->getCachedAvailabilityPromise($cacheKey);
        if ($promise !== null) {
            if ($verbose) {
                Log::debug($this->providerName . ': HGVT async promise hergebruikt', ['key' => $cacheKey]);
            }
            return $promise;
        }

        $promise = $this->client->getAsync($url)->then(
            function ($response) use ($postcode, $number, $extension, $verbose) {
                $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;

                if ($status !== 200) {
                    Log::warning($this->providerName . ' API returned status ' . $status, [
                        'postcode' => $postcode,
                        'number' => $number,
                        'extension' => $extension,
                    ]);

                    return [
                        'ok' => false,
                        'error' => 'HGVT status ' . $status,
                        'status' => $status,
                        'data' => null,
                    ];
                }

                $decoded = json_decode((string) $response->getBody());

                if ($verbose) {
                    Log::info($this->providerName . ': Raw API Response', ['response' => $decoded]);
                }

                if (!$decoded) {
                    return [
                        'ok' => false,
                        'error' => 'HGVT ongeldige JSON response',
                        'status' => $status,
                        'data' => null,
                    ];
                }

                return [
                    'ok' => true,
                    'error' => null,
                    'status' => $status,
                    'data' => $decoded,
                ];
            },
            function ($reason) use ($postcode, $number, $extension) {
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                Log::error($this->providerName . ' HTTP error', [
                    'postcode' => $postcode,
                    'number' => $number,
                    'extension' => $extension,
                    'error' => $message,
                ]);

                return [
                    'ok' => false,
                    'error' => 'HGVT HTTP fout: ' . $message,
                    'status' => 0,
                    'data' => null,
                ];
            }
        );

        $this->putAvailabilityPromiseInCache($cacheKey, $promise);
        return $promise;
    }

    private function getCachedAvailabilityPromise(string $key): ?PromiseInterface
    {
        $promise = self::$availabilityPromiseCache[$key] ?? null;
        return $promise instanceof PromiseInterface ? $promise : null;
    }

    private function putAvailabilityPromiseInCache(string $key, PromiseInterface $promise): void
    {
        if (isset(self::$availabilityPromiseCache[$key])) {
            return;
        }

        self::$availabilityPromiseCache[$key] = $promise;
        self::$availabilityPromiseCacheOrder[] = $key;

        if (count(self::$availabilityPromiseCacheOrder) <= self::AVAILABILITY_PROMISE_CACHE_LIMIT) {
            return;
        }

        $oldestKey = array_shift(self::$availabilityPromiseCacheOrder);
        if ($oldestKey !== null) {
            unset(self::$availabilityPromiseCache[$oldestKey]);
        }
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
