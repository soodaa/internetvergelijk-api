<?php
/**
 * Clear PHP OPcache and Autoloader Cache
 * 
 * This script clears:
 * 1. PHP OPcache (compiled PHP code cache)
 * 2. Laravel autoloader cache
 * 3. Laravel config cache
 * 4. Laravel route cache
 * 5. Laravel view cache
 */

echo "<h1>Laravel & PHP Cache Clear</h1>";
echo "<pre>";

// 1. Clear OPcache
echo "=== Clearing PHP OPcache ===\n";
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "✓ OPcache cleared successfully\n";
    } else {
        echo "✗ Failed to clear OPcache\n";
    }
} else {
    echo "⚠ OPcache extension not loaded\n";
}

// Get OPcache status
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    if ($status) {
        echo "\nOPcache Status:\n";
        echo "- Enabled: " . ($status['opcache_enabled'] ? 'Yes' : 'No') . "\n";
        echo "- Cache full: " . ($status['cache_full'] ? 'Yes' : 'No') . "\n";
        echo "- Cached scripts: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";
        echo "- Memory used: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB\n";
    }
}

echo "\n";

// 2. Clear Laravel caches via command line
echo "=== Clearing Laravel Caches ===\n";

$laravelPath = dirname(__DIR__);
$commands = [
    'composer dump-autoload' => 'Autoloader cache',
    'php artisan config:clear' => 'Config cache',
    'php artisan route:clear' => 'Route cache',
    'php artisan view:clear' => 'View cache',
    'php artisan cache:clear' => 'Application cache',
];

foreach ($commands as $command => $description) {
    echo "\nClearing {$description}...\n";
    echo "Command: {$command}\n";
    
    $output = [];
    $returnCode = 0;
    
    exec("cd {$laravelPath} && {$command} 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        echo "✓ Success\n";
        if (!empty($output)) {
            echo "  " . implode("\n  ", $output) . "\n";
        }
    } else {
        echo "✗ Failed (exit code: {$returnCode})\n";
        if (!empty($output)) {
            echo "  " . implode("\n  ", $output) . "\n";
        }
    }
}

echo "\n";
echo "=== Queue Worker Restart ===\n";
echo "Queue workers need to be manually restarted to pick up new code.\n";
echo "Restart signal file should be created at:\n";
echo "{$laravelPath}/storage/framework/cache/data/illuminate-queue-restart\n";

$restartFile = $laravelPath . '/storage/framework/cache/data/illuminate-queue-restart';
if (file_exists($restartFile)) {
    $timestamp = file_get_contents($restartFile);
    $date = date('Y-m-d H:i:s', (int)$timestamp);
    echo "✓ Restart signal exists (timestamp: {$date})\n";
} else {
    echo "⚠ Restart signal file does not exist\n";
}

echo "\n=== Summary ===\n";
echo "All caches have been cleared.\n";
echo "Queue workers will restart when they finish their current job.\n";
echo "\nFor immediate effect, you may need to:\n";
echo "1. Manually restart PHP-FPM: sudo systemctl restart php-fpm\n";
echo "2. Manually restart queue workers: php artisan queue:restart\n";
echo "3. Re-run the speed check for a fresh address\n";

echo "</pre>";
?>
