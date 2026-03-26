<?php
/**
 * Tijdelijk script om speedcheck cache te clearen
 * Gebruik: /opt/plesk/php/7.4/bin/php clear_speedcheck_cache.php
 * Uitvoeren vanuit de api directory
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

try {
    $redis = Cache::getRedis()->connection();
    
    // Haal alle speedcheck keys op
    $keys = $redis->keys('speedcheck:*');
    
    if (empty($keys)) {
        echo "Geen speedcheck cache keys gevonden.\n";
        exit(0);
    }
    
    echo "Gevonden " . count($keys) . " speedcheck cache keys.\n";
    
    // Verwijder alle keys
    foreach ($keys as $key) {
        $redis->del($key);
    }
    
    echo "Alle speedcheck cache is geleegd.\n";
    
} catch (\Exception $e) {
    echo "Fout bij clearen van cache: " . $e->getMessage() . "\n";
    exit(1);
}

