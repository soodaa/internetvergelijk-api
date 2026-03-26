<?php

namespace App\Console\Commands;

use App\Http\Controllers\PostcodeController;
use Illuminate\Console\Command;

class SpeedcheckTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'speedcheck:test
        {postcode : Postcode (bijv. 3765AT)}
        {number : Huisnummer}
        {extension? : Optionele huisnummer toevoeging}
        {--provider= : Provider key uit config/providers.php (laat leeg voor alle providers)}
        {--raw : Toon extra logging (verbose modus)}
        {--fresh : Sla cache over voor deze call}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test SpeedCheck v2 provider(s) op basis van config/providers.php. Gebruik --provider=KPN voor één provider, zonder --provider test je alle providers.';

    public function handle(): int
    {
        $providerArgument = $this->option('provider');
        $providers = config('providers', []);

        if (empty($providers)) {
            $this->error('Geen providers gevonden in config/providers.php');
            return self::FAILURE;
        }

        $postcode = strtoupper(str_replace(' ', '', (string) $this->argument('postcode')));
        $number = $this->argument('number');
        $extension = $this->argument('extension');
        $extension = $extension !== null ? trim((string) $extension) : null;
        $extension = $extension === '' ? null : $extension;

        if ($postcode === '' || $number === null || $number === '') {
            $this->error('Postcode en huisnummer zijn verplicht.');
            return self::FAILURE;
        }

        $address = new \stdClass();
        $address->postcode = $postcode;
        $address->number = is_numeric($number) ? (int) $number : $number;
        $address->extension = $extension;

        $verbose = $this->option('raw') ? 1 : 0;
        $useCache = !$this->option('fresh');

        // Test alle providers als geen provider is opgegeven of "all"
        if (empty($providerArgument) || strtolower($providerArgument) === 'all') {
            return $this->testAllProviders($address, $providers, $verbose, $useCache);
        }

        // Test één specifieke provider
        $providerKey = $this->resolveProviderKey($providerArgument, $providers);

        if ($providerKey === null) {
            $this->error("Provider '{$providerArgument}' bestaat niet in config/providers.php (gebruik sleutel of naam).");
            return self::FAILURE;
        }

        $providerConfig = $providers[$providerKey];

        if (empty($providerConfig['active'])) {
            $this->warn("Let op: provider '{$providerConfig['name']}' staat inactief in config/providers.php.");
        }

        try {
            /** @var PostcodeController $controller */
            $controller = app(PostcodeController::class);
            $result = $controller->runConfiguredProvider($address, $providerKey, $verbose, $useCache);
        } catch (\Throwable $throwable) {
            $this->error('Fout tijdens uitvoeren van provider: ' . $throwable->getMessage());
            return self::FAILURE;
        }

        $meta = $result['meta'] ?? [];
        $fromCache = (bool) ($meta['from_cache'] ?? false);
        $durationMs = $meta['duration_ms'] ?? null;
        $status = $result['status'] ?? 'onbekend';
        $message = $result['message'] ?? null;
        $data = $result['data'] ?? [];

        $this->info(sprintf(
            "Provider: %s (%s) | Status: %s%s%s",
            $providerConfig['name'] ?? $providerKey,
            $providerKey,
            $status,
            $fromCache ? ' [cache]' : '',
            $durationMs !== null ? sprintf(' | Duur: %d ms', $durationMs) : ''
        ));

        if ($message !== null) {
            $this->line('<fg=yellow>Message:</> ' . $message);
        }

        if (empty($data)) {
            $this->warn('Geen data in response.');
        } else {
            $this->line('');
            $this->line('Response payload:');
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function testAllProviders(\stdClass $address, array $providers, int $verbose, bool $useCache): int
    {
        $this->info("╔════════════════════════════════════════════════════════════════════════");
        $this->info("║ SpeedCheck v2 - Test alle providers");
        $this->info("║ Postcode: {$address->postcode}, Huisnummer: {$address->number}" . ($address->extension ? ", Toevoeging: {$address->extension}" : ""));
        $this->info("╚════════════════════════════════════════════════════════════════════════");
        $this->line("");

        /** @var PostcodeController $controller */
        $controller = app(PostcodeController::class);

        $results = [];
        $totalStartTime = microtime(true);

        foreach ($providers as $providerKey => $providerConfig) {
            if (empty($providerConfig['active'])) {
                continue;
            }

            $providerName = $providerConfig['name'] ?? $providerKey;
            $this->line("→ Testing {$providerName}...");

            $startTime = microtime(true);
            try {
                $result = $controller->runConfiguredProvider($address, $providerKey, $verbose, $useCache);
                $duration = (microtime(true) - $startTime) * 1000;

                $meta = $result['meta'] ?? [];
                $fromCache = (bool) ($meta['from_cache'] ?? false);
                $status = $result['status'] ?? 'unknown';
                $message = $result['message'] ?? null;
                $hasData = !empty($result['data'] ?? []);

                $results[$providerKey] = [
                    'name' => $providerName,
                    'status' => $status,
                    'duration_ms' => round($duration, 2),
                    'from_cache' => $fromCache,
                    'has_data' => $hasData,
                    'message' => $message,
                ];

                $statusColor = 'white';
                if ($status === 'success') {
                    $statusColor = 'green';
                } elseif ($status === 'no_coverage') {
                    $statusColor = 'yellow';
                } elseif ($status === 'error') {
                    $statusColor = 'red';
                }

                $cacheIndicator = $fromCache ? ' [cache]' : '';
                $this->line(sprintf(
                    "  <fg=%s>%s</> %s%s",
                    $statusColor,
                    $status === 'success' ? '✓' : ($status === 'no_coverage' ? '○' : '✗'),
                    round($duration, 2) . ' ms' . $cacheIndicator,
                    $message ? " - {$message}" : ''
                ));
            } catch (\Throwable $throwable) {
                $duration = (microtime(true) - $startTime) * 1000;
                $results[$providerKey] = [
                    'name' => $providerName,
                    'status' => 'error',
                    'duration_ms' => round($duration, 2),
                    'from_cache' => false,
                    'has_data' => false,
                    'message' => $throwable->getMessage(),
                ];
                $this->line(sprintf("  <fg=red>✗</> %s ms - %s", round($duration, 2), $throwable->getMessage()));
            }
            $this->line("");
        }

        $totalDuration = (microtime(true) - $totalStartTime) * 1000;

        // Toon samenvatting tabel
        $this->info("╔════════════════════════════════════════════════════════════════════════");
        $this->info("║ SAMENVATTING");
        $this->info("╚════════════════════════════════════════════════════════════════════════");
        $this->line("");

        $headers = ['Provider', 'Status', 'Duur (ms)', 'Cache', 'Data'];
        $rows = [];

        foreach ($results as $result) {
            $statusIcon = '?';
            if ($result['status'] === 'success') {
                $statusIcon = '✓';
            } elseif ($result['status'] === 'no_coverage') {
                $statusIcon = '○';
            } elseif ($result['status'] === 'error') {
                $statusIcon = '✗';
            }

            $rows[] = [
                $result['name'],
                $statusIcon . ' ' . $result['status'],
                number_format($result['duration_ms'], 2),
                $result['from_cache'] ? '✓' : '-',
                $result['has_data'] ? '✓' : '-',
            ];
        }

        $this->table($headers, $rows);
        $this->line("");

        // Statistieken
        $successCount = count(array_filter($results, function($r) { return $r['status'] === 'success'; }));
        $errorCount = count(array_filter($results, function($r) { return $r['status'] === 'error'; }));
        $noCoverageCount = count(array_filter($results, function($r) { return $r['status'] === 'no_coverage'; }));
        $cacheCount = count(array_filter($results, function($r) { return $r['from_cache']; }));

        $durations = array_column($results, 'duration_ms');
        $avgDuration = count($durations) > 0 ? array_sum($durations) / count($durations) : 0;
        $maxDuration = count($durations) > 0 ? max($durations) : 0;
        $minDuration = count($durations) > 0 ? min($durations) : 0;

        $this->info("Statistieken:");
        $this->line("  Totaal providers getest: " . count($results));
        $this->line("  <fg=green>✓ Success:</> {$successCount}");
        $this->line("  <fg=yellow>○ Geen dekking:</> {$noCoverageCount}");
        $this->line("  <fg=red>✗ Errors:</> {$errorCount}");
        $this->line("  Cache hits: {$cacheCount}");
        $this->line("");
        $this->line("Responstijden:");
        $this->line("  Totaal: " . number_format($totalDuration, 2) . " ms");
        $this->line("  Gemiddeld: " . number_format($avgDuration, 2) . " ms");
        $this->line("  Snelste: " . number_format($minDuration, 2) . " ms");
        $this->line("  Traagste: " . number_format($maxDuration, 2) . " ms");

        return self::SUCCESS;
    }

    private function resolveProviderKey(string $argument, array $providers): ?string
    {
        foreach ($providers as $key => $config) {
            if (strcasecmp($key, $argument) === 0) {
                return $key;
            }

            $name = (string) ($config['name'] ?? '');
            if ($name !== '' && strcasecmp($name, $argument) === 0) {
                return $key;
            }
        }

        return null;
    }
}


