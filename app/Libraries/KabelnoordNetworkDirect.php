<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Kabelnoord Network driver voor SpeedCheck v2.
 * 
 * Kabelnoord levert glasvezel in Noord-Nederland (Friesland/Groningen).
 * 
 * Flow:
 * 1. Fetch HTML pagina van netwerkkabelnoord.nl/jouw-netwerk
 * 2. Parse HTML om beschikbaarheid te bepalen
 * 3. Return snelheden op basis van status
 */
class KabelnoordNetworkDirect
{
    private Client $client;
    private string $providerName;
    private string $apiUrl;

    private const SPEED_FIBER = 1000; // 1 Gbit glasvezel

    public function __construct(array $config = [])
    {
        $this->providerName = (string) ($config['name'] ?? 'Kabelnoord');
        $this->apiUrl = $config['api_url'] ?? 'https://www.netwerkkabelnoord.nl/jouw-netwerk';

        $this->client = new Client([
            'http_errors' => false,
            'timeout' => 15,
            'connect_timeout' => 6,
        ]);
    }

    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        $postcode = str_replace(' ', '', $address->postcode);
        $number = $address->number;
        $addition = trim((string) ($address->extension ?? ''));

        $url = $this->apiUrl . '?' . http_build_query([
            'zipcode' => $postcode,
            'housenumber' => $number,
            'addition' => $addition,
        ]);

        if ($verbose) {
            dump($this->providerName . ": Fetching from {$url}");
        }

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => 'Mozilla/5.0 (compatible; InternetvergelijkBot/1.0)',
                ],
            ]);
        } catch (\Throwable $throwable) {
            Log::error($this->providerName . ' API call failed', [
                'postcode' => $postcode,
                'number' => $number,
                'error' => $throwable->getMessage(),
            ]);
            return $this->errorResponse('Kabelnoord niet bereikbaar: ' . $throwable->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            Log::warning($this->providerName . ' API returned status ' . $response->getStatusCode());
            return $this->errorResponse('Kabelnoord status ' . $response->getStatusCode());
        }

        $html = (string) $response->getBody();

        if ($verbose) {
            dump($this->providerName . ': Response length = ' . strlen($html) . ' bytes');
        }

        // Parse HTML voor beschikbaarheid
        $availability = $this->checkAvailability($html);

        if ($verbose) {
            dump($this->providerName . ': Availability', $availability);
        }

        if (!$availability['available']) {
            return $this->noCoverageResponse($availability['status']);
        }

        // Glasvezel beschikbaar
        return [
            'status' => 'success',
            'data' => [
                [
                    'provider' => $this->providerName,
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => self::SPEED_FIBER,
                        'kabel' => null,
                    ],
                ]
            ],
        ];
    }

    /**
     * Check beschikbaarheid in HTML response.
     */
    private function checkAvailability(string $html): array
    {
        // Glasvezel aanwezig
        if (strpos($html, 'Op dit adres is een glasvezelaansluiting van Netwerk Kabelnoord aanwezig') !== false) {
            return ['available' => true, 'status' => 'connected'];
        }

        // Nog niet beschikbaar
        if (strpos($html, 'Op dit adres is nu nog geen glasvezelaansluiting') !== false) {
            return ['available' => false, 'status' => 'not_available'];
        }

        // In aanbouw
        if (strpos($html, 'wordt momenteel aangelegd') !== false || strpos($html, 'bouwt') !== false) {
            return ['available' => false, 'status' => 'construction'];
        }

        // Onbekend
        return ['available' => false, 'status' => 'unknown'];
    }

    private function noCoverageResponse(string $status = 'no_coverage'): array
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
                    'network_status' => $status,
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
