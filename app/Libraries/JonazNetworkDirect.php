<?php

namespace App\Libraries;

use App\Services\SoapWebServiceSoapClient;
use Illuminate\Support\Facades\Log;

/**
 * Directe Jonaz driver voor speedCheck v2 (geen database writes).
 */
class JonazNetworkDirect
{
    private string $providerName;
    private int $speedMbps;

    public function __construct(array $config = [])
    {
        $this->providerName = (string)($config['name'] ?? 'Jonaz');
        $this->speedMbps = (int)($config['speed'] ?? 1000);
    }

    public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
    {
        try {
            $ext = trim((string)($address->extension ?? ''));
            $ext = ($ext === '') ? null : $ext;

            if ($verbose) {
                dump("Jonaz: Checking {$address->postcode} {$address->number}" . ($ext ? " {$ext}" : ''));
            }

            $response = (new SoapWebServiceSoapClient())->AvailabilityCheck3(
                $address->postcode,
                $address->number,
                $ext
            );

            if ($verbose) {
                dump('Jonaz: API Response', $response);
            }

            if ($response && isset($response->fulladdress)) {
                if (isset($response->plandate) && $response->plandate) {
                    Log::info('Jonaz plandate', [
                        'postcode' => $address->postcode,
                        'number' => $address->number,
                        'extension' => $ext,
                        'plandate' => $response->plandate,
                    ]);
                }

                if (isset($response->activeCampaign) && $response->activeCampaign) {
                    Log::info('Jonaz active campaign', [
                        'postcode' => $address->postcode,
                        'number' => $address->number,
                        'extension' => $ext,
                        'campaign' => $response->activeCampaign,
                    ]);
                }

                if ($verbose) {
                    dump('Jonaz: Coverage confirmed - ' . $this->speedMbps . ' Mbps fiber');
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
                ];
            }

            Log::info('Jonaz availability check', [
                'postcode' => $address->postcode,
                'number' => $address->number,
                'extension' => $ext,
                'available' => false,
            ]);

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
        } catch (\SoapFault $e) {
            Log::error('Jonaz SOAP error', [
                'postcode' => $address->postcode ?? null,
                'number' => $address->number ?? null,
                'extension' => $address->extension ?? null,
                'error' => $e->getMessage(),
                'faultcode' => $e->faultcode ?? null,
            ]);

            return [
                'status' => 'error',
                'message' => 'Jonaz SOAP fout: ' . $e->getMessage(),
                'data' => [],
            ];
        } catch (\Throwable $throwable) {
            Log::error('Jonaz error', [
                'postcode' => $address->postcode ?? null,
                'number' => $address->number ?? null,
                'extension' => $address->extension ?? null,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Jonaz onverwachte fout: ' . $throwable->getMessage(),
                'data' => [],
            ];
        }
    }
}
