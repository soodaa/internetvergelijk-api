<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Glob;

class HealthMailLog extends Command
{
    protected $signature = 'health:mail-log {--days=7 : Number of days back to include} {--dry-run : Only show what would be mailed}';

    protected $description = 'Mail the healthcheck log for the past N days';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days <= 0) {
            $this->error('Days must be >= 1');
            return self::FAILURE;
        }

        $logPaths = $this->collectLogFiles($days);

        if (empty($logPaths)) {
            $this->info('No healthcheck logs found for selected period.');
            return self::SUCCESS;
        }

        $subject = sprintf('[Healthcheck] Logoverzicht (%s dagen)', $days);
        $body = $this->buildMailBody($logPaths);

        if ($this->option('dry-run')) {
            $this->line('Dry run - zou mailen naar marnix@sooda.nl');
            $this->line($body);
            $this->line('Bijlagen:');
            foreach ($logPaths as $path) {
                $this->line(' - ' . $path);
            }
            return self::SUCCESS;
        }

        $recipients = explode(',', env('HEALTHCHECK_MAIL_TO', 'marnix@sooda.nl'));

        Mail::raw($body, function ($message) use ($subject, $logPaths, $recipients) {
            $message->subject($subject);
            foreach ($recipients as $recipient) {
                $message->to(trim($recipient));
            }

            foreach ($logPaths as $path) {
                $message->attach($path);
            }
        });

        $this->info(sprintf('Healthcheck log verstuurd (%d bijlagen).', count($logPaths)));
        return self::SUCCESS;
    }

    private function collectLogFiles(int $days): array
    {
        $storagePath = storage_path('logs');
        $files = glob($storagePath . DIRECTORY_SEPARATOR . 'healthcheck*.log*');

        $cutoff = now()->subDays($days)->startOfDay();
        $matches = [];

        foreach ($files as $file) {
            $filename = basename($file);

            if ($filename === 'healthcheck.log') {
                $matches[] = $file;
                continue;
            }

            // Expect format healthcheck-YYYY-MM-DD.log
            if (preg_match('/healthcheck-(\d{4}-\d{2}-\d{2})\.log/', $filename, $match)) {
                $date = \Carbon\Carbon::createFromFormat('Y-m-d', $match[1])->startOfDay();
                if ($date >= $cutoff) {
                    $matches[] = $file;
                }
            }
        }

        sort($matches);

        return $matches;
    }

    private function buildMailBody(array $logPaths): string
    {
        $lines = [
            'Healthcheck log over de periode: ' . now()->toDateTimeString(),
            sprintf('Aantal bestanden: %d', count($logPaths)),
            '',
            'Bijgevoegd:',
        ];

        foreach ($logPaths as $path) {
            $lines[] = '- ' . basename($path);
        }

        return implode(PHP_EOL, $lines);
    }
}

