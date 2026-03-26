<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proxy endpoint that keeps API token server-side and forwards speedCheck requests.
 */
class SpeedcheckProxyController extends Controller
{
    private PostcodeController $postcodeController;
    private Client $client;
    private string $mode;
    private float $timeout;
    private float $connectTimeout;
    private string $upstreamUrl;

    public function __construct(PostcodeController $postcodeController)
    {
        $this->postcodeController = $postcodeController;
        $this->mode = strtolower((string) env('SPEEDCHECK_PROXY_MODE', 'internal'));
        $this->timeout = (float) env('SPEEDCHECK_PROXY_TIMEOUT', 15.0);
        $this->connectTimeout = (float) env('SPEEDCHECK_PROXY_CONNECT_TIMEOUT', 6.0);
        $this->upstreamUrl = (string) env('SPEEDCHECK_PROXY_UPSTREAM_URL', 'https://api.internetvergelijk.nl/speedCheck');

        $this->client = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => false,
        ]);
    }

    public function proxy(Request $request)
    {
        $start = microtime(true);

        $token = env('API_TOKEN');
        if (!$token) {
            return response()->json([
                'error' => 1,
                'error_message' => 'API token ontbreekt in serverconfiguratie',
            ], 500);
        }

        $postcode = trim((string) $request->query('postcode', ''));
        $number = trim((string) $request->query('nr', ''));
        $extension = $request->query('nr_add');
        $extension = $extension !== null ? trim((string) $extension) : null;
        $extension = $extension === '' ? null : $extension;
        $fresh = $request->boolean('fresh', false);
        $verbose = $request->boolean('verbose', false);

        if ($postcode === '' || $number === '') {
            return response()->json([
                'error' => 1,
                'error_message' => 'Postcode en huisnummer zijn verplicht',
            ], 422);
        }

        $internalQuery = [
            'postcode' => $postcode,
            'nr' => $number,
        ];

        if ($extension !== null) {
            $internalQuery['nr_add'] = $extension;
        }
        if ($fresh) {
            $internalQuery['fresh'] = 1;
        }
        if ($verbose) {
            $internalQuery['verbose'] = 1;
        }

        $durationMs = 0;

        try {
            if ($this->mode !== 'http') {
                $internalRequest = Request::create('/speedCheck', 'GET', $internalQuery);
                $internalResponse = $this->postcodeController->speedCheck($internalRequest);

                $status = method_exists($internalResponse, 'getStatusCode') ? $internalResponse->getStatusCode() : 200;
                $durationMs = (int) round((microtime(true) - $start) * 1000);

                Log::info('Speedcheck proxy succesvol', [
                    'mode' => $this->mode,
                    'postcode' => $postcode,
                    'nr' => $number,
                    'nr_add' => $extension,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                ]);

                return $internalResponse;
            }

            $upstreamQuery = $internalQuery;
            $upstreamQuery['api_token'] = $token;

            $response = $this->client->request('GET', $this->upstreamUrl, [
                'query' => $upstreamQuery,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $contentType = $response->getHeaderLine('Content-Type') ?: 'application/json';

            Log::info('Speedcheck proxy succesvol', [
                'mode' => $this->mode,
                'postcode' => $postcode,
                'nr' => $number,
                'nr_add' => $extension,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);

            return response($body, $status)->header('Content-Type', $contentType);
        } catch (Throwable $throwable) {
            $durationMs = $durationMs ?: (int) round((microtime(true) - $start) * 1000);

            $safeError = $this->redactSensitive($throwable->getMessage());
            Log::error('Speedcheck proxy fout', [
                'mode' => $this->mode,
                'postcode' => $postcode,
                'nr' => $number,
                'nr_add' => $extension,
                'status' => 502,
                'duration_ms' => $durationMs,
                'error' => $safeError,
            ]);

            return response()->json([
                'error' => 1,
                'error_message' => 'Upstream speedCheck niet bereikbaar',
            ], 502);
        }
    }

    private function redactSensitive(string $message): string
    {
        $message = preg_replace('/(api_token=)[^&\\s]+/i', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/(Authorization:\\s*Bearer\\s+)[^\\s]+/i', '$1[REDACTED]', $message) ?? $message;

        return $message;
    }
}
