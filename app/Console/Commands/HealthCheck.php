<?php

namespace App\Console\Commands;

use App\Services\HealthStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'health:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private HealthStatusService $healthStatusService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $report = $this->healthStatusService->evaluate();

        foreach ($report['providers'] as $providerKey => $provider) {
            if ($provider['status'] === 'ok') {
                $this->info($providerKey . ' passed');
                continue;
            }

            foreach ($provider['messages'] as $message) {
                $this->warn($providerKey . ' ' . $message);
            }

            if (empty($provider['messages'])) {
                $this->warn($providerKey . ' returned non-ok status');
            }

            if ($provider['status'] === 'error') {
                Log::error('HealthCheck provider failure', [
                    'provider' => $providerKey,
                    'messages' => $provider['messages'],
                ]);
            }
        }

        foreach ($report['tokens'] as $token) {
            if ($token['status'] === 'ok') {
                $this->info($token['message']);
            } else {
                $this->warn($token['message']);
            }
        }

        $failed = $report['failed_providers'];
        $tokenWarnings = $report['token_warnings'];

        if (!empty($failed) || !empty($tokenWarnings)) {
            $subject = 'Internetvergelijk.nl API Problem Detected';
            $message = 'API issues gedetecteerd:' . PHP_EOL;

            if (!empty($failed)) {
                $message .= '- Providers: ' . json_encode($failed) . PHP_EOL;
            }

            if (!empty($tokenWarnings)) {
                $message .= '- Tokens: ' . implode('; ', $tokenWarnings) . PHP_EOL;
            }

            mail('marnix@sooda.nl', $subject, $message);
        }

        return empty($failed) && empty($tokenWarnings) ? self::SUCCESS : self::FAILURE;
    }
}
