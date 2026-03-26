<?php
/**
 * Check Queue Worker Status
 */

echo "<h1>Queue Worker Status</h1>";
echo "<pre>";

echo "=== Checking Queue Workers ===\n\n";

// Method 1: Check running processes
echo "Method 1: Process list\n";
$output = [];
exec('ps aux | grep "queue:work" | grep -v grep 2>&1', $output, $returnCode);

if (!empty($output)) {
    echo "✓ Queue workers found:\n";
    foreach ($output as $line) {
        echo "  " . $line . "\n";
    }
} else {
    echo "❌ NO queue workers running!\n";
    echo "Return code: {$returnCode}\n";
}

echo "\n";

// Method 2: Check restart signal file
echo "Method 2: Restart signal file\n";
$restartFile = '/var/www/vhosts/internetvergelijk.nl/api/storage/framework/cache/data/illuminate-queue-restart';

if (file_exists($restartFile)) {
    echo "✓ Restart signal exists\n";
    echo "  Created: " . date('Y-m-d H:i:s', filemtime($restartFile)) . "\n";
    echo "  Content: " . file_get_contents($restartFile) . "\n";
} else {
    echo "⚠ No restart signal file\n";
}

echo "\n";

// Method 3: Check if cron/supervisor is configured
echo "Method 3: Supervisor config\n";
$supervisorConfig = '/etc/supervisor/conf.d/laravel-worker.conf';
if (file_exists($supervisorConfig)) {
    echo "✓ Supervisor config exists\n";
    echo file_get_contents($supervisorConfig);
} else {
    echo "⚠ No supervisor config found at {$supervisorConfig}\n";
}

echo "\n";

// Method 4: Check queue size
echo "Method 4: Queue jobs\n";

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $queueSize = \Illuminate\Support\Facades\DB::table('jobs')->count();
    echo "Jobs in queue: {$queueSize}\n";
    
    if ($queueSize > 0) {
        $jobs = \Illuminate\Support\Facades\DB::table('jobs')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        echo "\nRecent jobs:\n";
        foreach ($jobs as $job) {
            $payload = json_decode($job->payload);
            $class = $payload->displayName ?? 'Unknown';
            echo "  - {$class} (created: " . date('Y-m-d H:i:s', $job->created_at) . ")\n";
        }
    }
} catch (\Exception $e) {
    echo "⚠ Could not check jobs table: " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSIS ===\n\n";

if (empty($output)) {
    echo "❌ PROBLEM: No queue workers are running!\n\n";
    echo "The queue jobs are not being processed.\n";
    echo "speedCheck dispatches jobs to queue, but nothing processes them.\n\n";
    echo "SOLUTIONS:\n";
    echo "1. Start queue worker manually:\n";
    echo "   ssh to server, then run:\n";
    echo "   cd /var/www/vhosts/internetvergelijk.nl/api\n";
    echo "   /opt/plesk/php/7.4/bin/php artisan queue:work --daemon\n\n";
    echo "2. Or configure supervisor to auto-start workers\n\n";
    echo "3. Or use sync queue (process immediately, no workers needed)\n";
    echo "   Set QUEUE_CONNECTION=sync in .env\n";
} else {
    echo "✓ Queue workers are running\n";
    echo "But they may be using OLD code.\n\n";
    echo "Try manually killing and restarting:\n";
    echo "  killall -9 php\n";
    echo "  /opt/plesk/php/7.4/bin/php artisan queue:work --daemon &\n";
}

echo "</pre>";
?>
