<?php

namespace App\Libraries;

use App\Support\ProviderTokenStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class CaiwayPostcodeCheck
{
    /**
     * HTTP client for the propositions endpoint.
     *
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * HTTP client for OAuth token retrieval.
     *
     * @var \GuzzleHttp\Client
     */
    protected $authClient;

    /**
     * Constructor wires dependencies and prepares HTTP clients.
     */
    public function __construct()
    {
        $baseUrl = rtrim(env('DELTA_FIBER_API_URL', 'https://affiliate-api.dfn.nl/api'), '/') . '/';

        $this->httpClient = new Client([
            'base_uri' => $baseUrl,
            'http_errors' => false,
            'timeout' => (float) env('DELTA_FIBER_TIMEOUT', 10),
            'connect_timeout' => (float) env('DELTA_FIBER_CONNECT_TIMEOUT', 6),
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $this->authClient = new Client([
            'http_errors' => false,
            'timeout' => (float) env('DELTA_FIBER_TIMEOUT', 10),
        ]);
    }

    // Note: request() method removed - speedcheck gebruikt CaiwayNetworkDirect::fetchSpeeds().

    /**
     * Retrieve a bearer token, optionally forcing a refresh.
     */
    protected function getAccessToken(bool $forceRefresh = false, int $verbose = 0): ?string
    {
        if (! $forceRefresh) {
            $cachedToken = ProviderTokenStore::getValidToken('delta-fiber');
            if ($cachedToken) {
                if ($verbose) {
                    $entry = ProviderTokenStore::get('delta-fiber');
                    $minutesLeft = null;
                    if ($entry && isset($entry['expires_at'])) {
                        $minutesLeft = round(($entry['expires_at'] - now()->getTimestamp()) / 60);
                    }
                    dump('Delta Fiber: using cached token' . ($minutesLeft !== null ? ' (expires in ' . $minutesLeft . ' min)' : ''));
                }
                return $cachedToken;
            }
        }

        if ($verbose) {
            dump('Delta Fiber: acquiring new access token...');
        }

        $tokenUrl = env('DELTA_FIBER_TOKEN_URL');
        $clientId = env('DELTA_FIBER_CLIENT_ID');
        $clientSecret = env('DELTA_FIBER_CLIENT_SECRET');
        $scope = env('DELTA_FIBER_SCOPE');
        $grantType = env('DELTA_FIBER_GRANT_TYPE', 'password');
        $username = env('DELTA_FIBER_USERNAME');
        $password = env('DELTA_FIBER_PASSWORD');

        if ($tokenUrl && preg_match('#/authorize/?$#i', $tokenUrl)) {
            $tokenUrl = preg_replace('#/authorize/?$#i', '/token', $tokenUrl);
            if ($verbose) {
                dump('Delta Fiber: corrected token URL to use /token endpoint', $tokenUrl);
            }
        }

        $hasMandatoryConfig = $tokenUrl && $clientId && $clientSecret && $scope;

        if ($grantType === 'client_credentials') {
            if (! $hasMandatoryConfig) {
                Log::error('Delta Fiber configuration incomplete (client_credentials requires token URL, client id/secret, scope)');
                if ($verbose) {
                    dump('Delta Fiber config incomplete for client_credentials grant');
                }
                return null;
            }
        } else {
            if (! ($hasMandatoryConfig && $username && $password)) {
                Log::error('Delta Fiber configuration incomplete (password grant requires username/password)');
                if ($verbose) {
                    dump('Delta Fiber config incomplete for password grant');
                }
            return null;
            }
        }

        try {
            $formParams = array_filter([
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $scope,
                    'grant_type' => $grantType,
                'username' => $grantType === 'password' ? $username : null,
                'password' => $grantType === 'password' ? $password : null,
                ], static function ($value) {
                    return $value !== null && $value !== '';
            });

            $response = $this->authClient->post($tokenUrl, [
                'form_params' => $formParams,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                Log::error('Delta Fiber token request failed', [
                    'status' => $response->getStatusCode(),
                    'body' => $errorBody,
                ]);
                if ($verbose) {
                    dump('Delta Fiber token request failed', [
                        'status' => $response->getStatusCode(),
                        'body' => $errorBody,
                ]);
                }
                return null;
            }

            $rawBody = $response->getBody()->getContents();

            if ($verbose) {
                dump('Delta Fiber token raw response', $rawBody);
            }

            $body = json_decode($rawBody, true);

            if (! is_array($body) || empty($body['access_token'])) {
                Log::error('Delta Fiber token response missing access_token');
                if ($verbose) {
                    dump('Delta Fiber token response missing access_token', $body);
                }
                return null;
            }

            $expiresIn = (int) Arr::get($body, 'expires_in', 3600);
            $token = $body['access_token'];
            ProviderTokenStore::put('delta-fiber', $token, max(60, $expiresIn), [
                'raw_expires_in' => $expiresIn,
            ]);

            if ($verbose) {
                dump(sprintf(
                    'Delta Fiber: token acquired successfully (expires in %d min)',
                    round($expiresIn / 60)
                ));
            }

            return $token;
        } catch (GuzzleException $e) {
            Log::error('Delta Fiber token HTTP error: ' . $e->getMessage());
            if ($verbose) {
                dump('Delta Fiber token HTTP error: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('Delta Fiber token unexpected exception: ' . $e->getMessage());
            if ($verbose) {
                dump('Delta Fiber token unexpected exception: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Convert API payload to Mbps values for glasvezel/coax.
     *
     * @param  array  $payload
     * @param  int    $verbose
     * @return array{glasvezel:int|null, coax:int|null}
     */
    protected function extractSpeeds(array $payload, int $verbose = 0): array
    {
        $glasvezel = null;
        $coax = null;

        foreach ($payload as $brand) {
            if (! isset($brand['connections']) || ! is_array($brand['connections'])) {
                continue;
            }

            foreach ($brand['connections'] as $connection) {
                if (! isset($connection['connectionType'])) {
                    continue;
                }

                if (isset($connection['connectionStatus']) && strtolower($connection['connectionStatus']) !== 'beschikbaar') {
                    continue;
                }

                $speed = $this->convertSpeedToMbps(Arr::get($connection, 'maxConnectionSpeed'));

                if ($verbose && $speed) {
                    dump(sprintf(
                        'Delta Fiber connection %s (%s) -> %s Mbps',
                        $connection['connectionType'],
                        $brand['brand'] ?? 'unknown',
                        $speed
                    ));
                }

                if (! $speed) {
                    continue;
                }

                $type = strtolower($connection['connectionType']);

                if ($type === 'glasvezel') {
                    $glasvezel = max($glasvezel ?? 0, $speed);
                } elseif ($type === 'coax') {
                    $coax = max($coax ?? 0, $speed);
                }
            }
        }

        return [
            'glasvezel' => $glasvezel ? (int) $glasvezel : null,
            'coax' => $coax ? (int) $coax : null,
        ];
    }

    /**
     * Helper to convert the maxConnectionSpeed structure to Mbps.
     *
     * @param  array|null  $speed
     */
    protected function convertSpeedToMbps($speed): ?int
    {
        if (! is_array($speed)) {
            return null;
        }

        $download = Arr::get($speed, 'downloadSpeed');
        $unit = strtoupper(Arr::get($speed, 'downloadSpeedUnit', 'MBPS'));

        if ($download === null) {
            return null;
        }

        $download = (float) $download;

        if ($unit === 'GBPS') {
            return (int) round($download * 1000);
        }

        if ($unit === 'MBPS') {
            return (int) round($download);
        }

        if ($unit === 'KBPS') {
            return (int) round($download / 1000);
        }

        // Fallback: assume value already in Mbps.
        return (int) round($download);
    }
}
