<?php

namespace App\Http\Controllers;

use App\Services\HealthStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonitoringController extends Controller
{
    private HealthStatusService $healthStatusService;

    public function __construct(HealthStatusService $healthStatusService)
    {
        $this->healthStatusService = $healthStatusService;
    }

    public function health(Request $request)
    {
        $report = null;
        $generatedAt = null;

        if ($request->boolean('run')) {
            $report = $this->healthStatusService->evaluate();
            $generatedAt = now();
        }

        return view('monitoring.health', [
            'report' => $report,
            'generatedAt' => $generatedAt,
            'hasRun' => $report !== null,
        ]);
    }

    public function lookup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'postcode' => ['required', 'string', 'regex:/^[0-9]{4}[A-Za-z]{2}$/'],
            'number' => ['required'],
            'extension' => ['nullable', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return response($validator->errors()->first(), 422);
        }

        $address = new \stdClass();
        $address->postcode = strtoupper(str_replace(' ', '', $request->input('postcode')));
        $address->number = $request->input('number');
        $address->extension = $request->input('extension') !== '' ? $request->input('extension') : null;

        $providers = config('providers', []);
        $controller = app(\App\Http\Controllers\PostcodeController::class);
        $results = [];

        foreach ($providers as $providerKey => $providerConfig) {
            if (empty($providerConfig['active'])) {
                continue;
            }

            try {
                $result = $controller->runConfiguredProvider($address, $providerKey, 0, false);
            } catch (\Throwable $throwable) {
                $results[] = [
                    'provider' => $providerConfig['name'] ?? $providerKey,
                    'status' => 'error',
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => null,
                        'kabel' => null,
                    ],
                    'meta' => [
                        'duration_ms' => null,
                    ],
                    'message' => $throwable->getMessage(),
                ];
                continue;
            }

            $download = $result['data'][0]['download'] ?? [
                'dsl' => null,
                'glasvezel' => null,
                'kabel' => null,
            ];

            $results[] = [
                'provider' => $providerConfig['name'] ?? $providerKey,
                'status' => $result['status'] ?? 'error',
                'download' => $download,
                'meta' => $result['meta'] ?? [],
                'message' => $result['message'] ?? null,
            ];
        }

        return response()->json([
            'providers' => $results,
        ]);
    }
}

