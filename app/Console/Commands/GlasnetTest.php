<?php

namespace App\Console\Commands;

use App\Http\Controllers\PostcodeController;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Test alle Glasnet-based providers met één postcode
 * 
 * Glasnet API serves meerdere providers via dezelfde backend:
 * - L2Fiber (EFiber)
 * - HSLnet
 * - GlaswebVenray
 * - BreedbandBuitengebiedRucphen
 * - Rekam
 */
class GlasnetTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glasnet:test {postcode} {number} {extension?} {--raw : Toon rauwe API response}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test alle Glasnet providers met één postcode/huisnummer';

    /**
     * Glasnet provider mapping
     */
    protected $glasnetProviders = [
        'L2Fiber' => 'L2Fiber',
        'HSLnet' => 'HSLnet',
        'GlaswebVenray' => 'Glasweb Venray',
        'BreedbandBuitengebiedRucphen' => 'Breedband Buitengebied Rucphen',
    ];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $postcode = $this->argument('postcode');
        $number = $this->argument('number');
        $extension = $this->argument('extension') ?? null;
        $showRaw = $this->option('raw');

        $this->info("╔════════════════════════════════════════════════════════════════════════");
        $this->info("║ Glasnet Providers Test" . ($showRaw ? " (RAW MODE)" : ""));
        $this->info("║ Postcode: {$postcode}, Huisnummer: {$number}" . ($extension ? ", Toevoeging: {$extension}" : ""));
        $this->info("╚════════════════════════════════════════════════════════════════════════");
        $this->line("");

        $results = [];

        /** @var PostcodeController $controller */
        $controller = app(PostcodeController::class);

        foreach ($this->glasnetProviders as $provider => $description) {
            $this->info("→ Testing {$provider} ({$description})...");
            
            try {
                $address = $this->makeAddress($postcode, $number, $extension);
                $result = $controller->runConfiguredProvider($address, $provider, $showRaw ? 1 : 0, true);

                if (($result['status'] ?? '') === 'error') {
                    $message = $result['message'] ?? 'Onbekende fout';
                    $this->line("  <fg=red>✗ Error:</> {$message}");
                    $results[$provider] = [
                        'status' => 'error',
                        'error' => $message,
                    ];
                    $this->line("");
                    continue;
                }

                $payload = $result['data'] ?? [];
                $download = Arr::get($payload, '0.download', []);
                $speed = $download['glasvezel'] ?? null;

                if ($speed !== null && $speed > 0) {
                    $this->line("  <fg=green>✓ Success!</> Glasvezel: {$speed} Mbps");
                    $results[$provider] = [
                        'status' => 'success',
                        'speed' => $speed,
                    ];
                } else {
                    $this->line("  <fg=yellow>○ No coverage</> - Niet beschikbaar op dit adres");
                    $results[$provider] = [
                        'status' => 'no_coverage',
                        'speed' => 0
                    ];
                }
            } catch (\Exception $e) {
                $this->line("  <fg=red>✗ Error:</> " . $e->getMessage());
                $results[$provider] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }

            $this->line("");
        }

        // Summary
        $this->info("╔════════════════════════════════════════════════════════════════════════");
        $this->info("║ SAMENVATTING");
        $this->info("╚════════════════════════════════════════════════════════════════════════");
        
        $successCount = 0;
        $noCoverageCount = 0;
        $errorCount = 0;

        foreach ($results as $provider => $result) {
            if ($result['status'] === 'success') {
                $successCount++;
                $this->line("<fg=green>✓ {$provider}:</> {$result['speed']} Mbps");
            } elseif ($result['status'] === 'no_coverage') {
                $noCoverageCount++;
                $this->line("<fg=yellow>○ {$provider}:</> Geen dekking");
            } else {
                $errorCount++;
                $this->line("<fg=red>✗ {$provider}:</> Error");
            }
        }

        $this->line("");
        $this->info("Totaal: " . count($this->glasnetProviders) . " providers getest");
        $this->line("  <fg=green>✓ Success:</> {$successCount}");
        $this->line("  <fg=yellow>○ No coverage:</> {$noCoverageCount}");
        $this->line("  <fg=red>✗ Errors:</> {$errorCount}");

        return 0;
    }

    private function makeAddress(string $postcode, $number, ?string $extension = null): \stdClass
    {
        $address = new \stdClass();
        $address->postcode = strtoupper(str_replace(' ', '', $postcode));
        $address->number = is_numeric($number) ? (int) $number : $number;
        $extension = $extension !== null ? trim($extension) : null;
        $address->extension = $extension === '' ? null : $extension;

        return $address;
    }
}

