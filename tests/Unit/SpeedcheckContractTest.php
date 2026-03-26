<?php

namespace App\Libraries {
    class StubSuccessProvider
    {
        public function __construct(array $config = [])
        {
        }

        public function fetchSpeeds(\stdClass $address, int $verbose = 0): array
        {
            return [
                'status' => 'success',
                'data' => [[
                    'provider' => 'Stub',
                    'download' => [
                        'dsl' => null,
                        'glasvezel' => 500,
                        'kabel' => null,
                    ],
                ]],
            ];
        }
    }
}

namespace Tests\Unit;

use App\Http\Controllers\PostcodeController;
use App\Support\ProviderCircuitBreaker;
use Tests\TestCase;

class FakeCircuitBreaker extends ProviderCircuitBreaker
{
    public function __construct()
    {
        // no-op to avoid Redis usage
    }

    public function isOpen(string $providerKey): bool
    {
        return false;
    }

    public function remainingOpenSeconds(string $providerKey): int
    {
        return 0;
    }

    public function recordFailure(string $providerKey): void
    {
        // no-op
    }

    public function recordSuccess(string $providerKey): void
    {
        // no-op
    }

    public function currentFailures(string $providerKey): int
    {
        return 0;
    }
}

class SpeedcheckContractTest extends TestCase
{
    public function testRunConfiguredProviderReturnsNormalizedContract(): void
    {
        $controller = new PostcodeController(new FakeCircuitBreaker());

        config([
            'providers' => [
                'Stub' => [
                    'class' => \App\Libraries\StubSuccessProvider::class,
                    'driver' => 'stub',
                    'name' => 'Stub',
                    'active' => true,
                    'queue' => 'default',
                    'cache_ttl' => 120,
                ],
            ],
        ]);

        $address = new \stdClass();
        $address->postcode = '1234AB';
        $address->number = 1;
        $address->extension = null;

        $result = $controller->runConfiguredProvider($address, 'Stub', 0, false);

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('download', $result['data'][0]);
        $this->assertSame(500, $result['data'][0]['download']['glasvezel']);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('duration_ms', $result['meta']);
        $this->assertFalse($result['meta']['from_cache']);
        $this->assertArrayHasKey('circuit', $result['meta']);
        $this->assertArrayHasKey('state', $result['meta']['circuit']);
        $this->assertSame('closed', $result['meta']['circuit']['state']);
    }
}
