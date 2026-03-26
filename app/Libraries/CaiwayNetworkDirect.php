<?php

namespace App\Libraries;

use Illuminate\Support\Arr;

/**
 * Directe Caiway/DELTA Fiber driver voor speedCheck v2 (geen database writes).
 */
class CaiwayNetworkDirect extends CaiwayPostcodeCheck
{
    private string $providerName;

    public function __construct(array $config = [])
    {
        parent::__construct();
        $this->providerName = (string)($config['name'] ?? 'Caiway');
    }

    /**
     * Haalt snelheden rechtstreeks uit de DELTA Fiber API en retourneert het
     * uniforme speedCheck payload-formaat.
     */
    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        try {
            $token = $this->getAccessToken(false, $verbose);

            if (!$token) {
                return $this->errorResponse('Delta Fiber token kon niet worden verkregen');
            }

            $postcode = str_replace(' ', '', $address->postcode);
            $includeProducts = env('DELTA_FIBER_INCLUDE_PRODUCTS', 'true');
            $includeProducts = is_bool($includeProducts)
                ? ($includeProducts ? 'true' : 'false')
                : (strtolower((string) $includeProducts) === 'false' ? 'false' : 'true');

            $query = [
                'postcode' => $postcode,
                'huisnummer' => (string) $address->number,
                'includeProducts' => $includeProducts,
            ];

            if (!empty($address->extension)) {
                $query['toevoeging'] = (string) $address->extension;
            }

            if ($verbose) {
                dump('Delta Fiber request', $query);
            }

            $response = $this->httpClient->get('propositions', [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            if ($response->getStatusCode() === 401) {
                $token = $this->getAccessToken(true, $verbose);

                if (!$token) {
                    return $this->errorResponse('Delta Fiber token refresh mislukt na 401');
                }

                $response = $this->httpClient->get('propositions', [
                    'query' => $query,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                ]);
            }

            if ($verbose) {
                dump('Delta Fiber status: ' . $response->getStatusCode());
            }

            if (!in_array($response->getStatusCode(), [200, 204], true)) {
                if ($verbose) {
                    $errorBody = (string) $response->getBody();
                    $decodedError = json_decode($errorBody, true);
                    dump('Delta Fiber error response', [
                        'status' => $response->getStatusCode(),
                        'body' => $decodedError ?? $errorBody,
                    ]);
                }
                return $this->errorResponse('Delta Fiber onverwachte status ' . $response->getStatusCode());
            }

            if ($response->getStatusCode() === 204) {
                return $this->noCoverageResponse();
            }

            $payload = json_decode($response->getBody()->getContents(), true);

            if ($verbose) {
                dump('Delta Fiber raw response', $payload);
            }

            if (!is_array($payload)) {
                return $this->errorResponse('Delta Fiber gaf geen geldig JSON terug');
            }

            $speeds = $this->extractSpeeds($payload, $verbose);

            if (!$speeds['glasvezel'] && !$speeds['coax']) {
                return $this->noCoverageResponse();
            }

            return [
                'status' => 'success',
                'data' => [[
                    'provider' => $this->providerName,
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => $speeds['glasvezel'],
                        'kabel' => $speeds['coax'],
                    ],
                ]],
            ];
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            return $this->errorResponse('Delta Fiber HTTP fout: ' . $e->getMessage());
        } catch (\Throwable $throwable) {
            return $this->errorResponse('Delta Fiber onverwachte fout: ' . $throwable->getMessage());
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
