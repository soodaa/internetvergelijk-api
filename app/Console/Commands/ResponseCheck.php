<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResponseCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'response:check';

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
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $client = new Client([
            'timeout' => 15,
            'connect_timeout' => 6,
            'http_errors' => false,
        ]);

        $token = env('API_TOKEN');

        if (!$token) {
            Log::error('response:check aborted: API_TOKEN ontbreekt in .env');
            return self::FAILURE;
        }
        
        try {
            $response = $client->get('https://api.internetvergelijk.nl/speedCheck', [
                'query' => [
                    'postcode' => '2724RJ',
                    'nr' => '37',
                    'api_token' => $token,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() >= 400) {
                Log::warning('response:check non-200 status', [
                    'status' => $response->getStatusCode(),
                ]);
                return self::FAILURE;
            }
        } catch (GuzzleException $e) {
            $safeMessage = preg_replace('/(api_token=)[^&\\s]+/i', '$1[REDACTED]', $e->getMessage()) ?? $e->getMessage();

            Log::critical('response:check exception', [
                'error' => $safeMessage,
            ]);

            mail('marnix@sooda.nl', 'Internetvergelijk API check failed', $safeMessage);
            return self::FAILURE;
        }
        
        return self::SUCCESS;
    }
}
