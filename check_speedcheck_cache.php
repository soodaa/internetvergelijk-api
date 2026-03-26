<?php
/**
 * Script om te checken of speedcheck cache leeg is
 * Gebruik: /opt/plesk/php/7.4/bin/php check_speedcheck_cache.php
 * Uitvoeren vanuit de api directory
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Redis;

try {
    // Gebruik de 'cache' Redis connection (zoals geconfigureerd in config/database.php)
    $redis = Redis::connection('cache');
    
    echo "=== SpeedCheck Cache Status ===\n\n";
    
    // Probeer verschillende patterns (met en zonder prefix)
    $prefix = config('cache.prefix', 'laravel_cache');
    $patterns = [
        $prefix . ':speedcheck:*',  // Met Laravel cache prefix
        'speedcheck:*',              // Zonder prefix (direct)
    ];
    
    $allKeys = [];
    foreach ($patterns as $pattern) {
        $foundKeys = $redis->keys($pattern);
        if (!empty($foundKeys)) {
            $allKeys = array_merge($allKeys, $foundKeys);
        }
    }
    
    // Verwijder duplicaten
    $allKeys = array_unique($allKeys);
    
    if (empty($allKeys)) {
        echo "✅ Cache is LEEG - geen speedcheck keys gevonden.\n";
        echo "\n(Gecheckt op patterns: " . implode(', ', $patterns) . ")\n";
        exit(0);
    }
    
    echo "❌ Cache is NIET leeg - gevonden " . count($allKeys) . " speedcheck cache key(s):\n\n";
    
    // Toon eerste 10 keys als voorbeeld
    $sampleKeys = array_slice($allKeys, 0, 10);
    foreach ($sampleKeys as $key) {
        echo "  - $key\n";
    }
    
    if (count($allKeys) > 10) {
        echo "  ... en nog " . (count($allKeys) - 10) . " meer\n";
    }
    
    echo "\nOm cache te clearen:\n";
    echo "  /opt/plesk/php/7.4/bin/php artisan cache:clear\n";
    
    exit(1);
    
} catch (\Exception $e) {
    echo "❌ Fout bij checken van cache: " . $e->getMessage() . "\n";
    exit(1);
}

