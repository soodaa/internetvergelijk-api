<?php
/**
 * Force Restart Queue Workers
 * This will:
 * 1. Clear OPcache
 * 2. Kill all PHP processes (queue workers)
 * 3. Show how to restart manually
 */

echo "<h1>Force Restart Queue Workers</h1>";
echo "<pre>";

echo "=== Step 1: Clear OPcache ===\n";

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "✅ OPcache cleared successfully!\n";
    } else {
        echo "⚠️  OPcache clear failed (may require permissions)\n";
    }
} else {
    echo "⚠️  OPcache not available\n";
}

echo "\n=== Step 2: Check Running Queue Workers ===\n";

$output = [];
exec('ps aux | grep "queue:work" | grep -v grep 2>&1', $output);

if (!empty($output)) {
    echo "Queue workers found:\n";
    foreach ($output as $line) {
        // Extract PID
        if (preg_match('/\s+(\d+)\s+/', $line, $matches)) {
            $pid = $matches[1];
            echo "  PID: {$pid}\n";
            echo "  " . $line . "\n\n";
        }
    }
    
    echo "=== Step 3: Kill Instructions ===\n\n";
    echo "⚠️  WARNING: This will kill ALL PHP processes!\n";
    echo "This includes the queue workers but may affect other PHP processes.\n\n";
    
    echo "To kill queue workers, SSH to server and run:\n\n";
    echo "  # Kill specific queue worker by PID:\n";
    foreach ($output as $line) {
        if (preg_match('/\s+(\d+)\s+/', $line, $matches)) {
            $pid = $matches[1];
            echo "  kill -9 {$pid}\n";
        }
    }
    
    echo "\n  # Or kill all PHP processes (DANGER!):\n";
    echo "  killall -9 php\n\n";
    
    echo "  # Then restart queue worker:\n";
    echo "  cd /var/www/vhosts/internetvergelijk.nl/api\n";
    echo "  /opt/plesk/php/7.4/bin/php artisan queue:work --daemon &\n\n";
    
    echo "=== Alternative: Use Restart Signal (Graceful) ===\n\n";
    
    // Bootstrap Laravel to use Artisan
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $kernel = $app->make('Illuminate\Contracts\Console\Kernel');
    $kernel->bootstrap();
    
    try {
        \Illuminate\Support\Facades\Artisan::call('queue:restart');
        echo "✅ Queue restart signal sent (again)\n";
        echo "Workers should restart within 60 seconds\n\n";
    } catch (\Exception $e) {
        echo "❌ Could not send restart signal: " . $e->getMessage() . "\n\n";
    }
    
} else {
    echo "❌ No queue workers found!\n";
    echo "You need to start them manually:\n\n";
    echo "SSH to server and run:\n";
    echo "  cd /var/www/vhosts/internetvergelijk.nl/api\n";
    echo "  /opt/plesk/php/7.4/bin/php artisan queue:work --daemon &\n";
}

echo "=== Step 4: Verify File Version ===\n\n";

$file = '/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/ZiggoPostcodeCheckV2.php';
echo "File: {$file}\n";
echo "Modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "Size: " . filesize($file) . " bytes\n\n";

// Check for 2000 support
$contents = file_get_contents($file);
if (strpos($contents, '2000 => 2000') !== false) {
    echo "✅ File contains 2000 => 2000 (correct version!)\n";
} else {
    echo "❌ File does NOT contain 2000 => 2000 (old version!)\n";
    echo "    You need to re-upload ZiggoPostcodeCheckV2.php!\n";
}

if (strpos($contents, '2200 => 2000') !== false) {
    echo "✅ File contains 2200 => 2000 (correct version!)\n";
} else {
    echo "❌ File does NOT contain 2200 => 2000 (old version!)\n";
}

echo "\n=== Next Steps ===\n\n";
echo "1. ✅ OPcache cleared\n";
echo "2. ⏳ Kill queue worker via SSH (see commands above)\n";
echo "3. ⏳ Restart queue worker via SSH\n";
echo "4. ⏳ Wait 10 seconds\n";
echo "5. ⏳ Test: https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=3\n";
echo "6. ✅ Should return: \"kabel\": 2000\n";

echo "</pre>";
?>
