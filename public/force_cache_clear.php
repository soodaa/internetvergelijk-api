<?php
/**
 * Force Clear All Laravel Caches
 */

echo "<h1>Force Clear All Caches</h1>";
echo "<pre>";

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

echo "=== Clearing Laravel Caches ===\n\n";

// 1. Clear config cache
echo "1. Config Cache:\n";
try {
    Artisan::call('config:clear');
    echo "   ✓ Cleared\n";
    echo "   Output: " . Artisan::output() . "\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 2. Clear route cache
echo "\n2. Route Cache:\n";
try {
    Artisan::call('route:clear');
    echo "   ✓ Cleared\n";
    echo "   Output: " . Artisan::output() . "\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 3. Clear view cache
echo "\n3. View Cache:\n";
try {
    Artisan::call('view:clear');
    echo "   ✓ Cleared\n";
    echo "   Output: " . Artisan::output() . "\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 4. Clear application cache
echo "\n4. Application Cache:\n";
try {
    Artisan::call('cache:clear');
    echo "   ✓ Cleared\n";
    echo "   Output: " . Artisan::output() . "\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 5. Queue restart
echo "\n5. Queue Restart Signal:\n";
try {
    Artisan::call('queue:restart');
    echo "   ✓ Signal sent\n";
    echo "   Output: " . Artisan::output() . "\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}

// 6. Clear OPcache
echo "\n6. OPcache:\n";
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "   ✓ Cleared\n";
    } else {
        echo "   ✗ Failed to clear\n";
    }
} else {
    echo "   ⚠ Not available\n";
}

echo "\n=== Verify Configuration ===\n\n";

// Test env() function
echo "Environment Variables:\n";
echo "  ZIGGO_API_KEY: " . (env('ZIGGO_API_KEY') ? 'Set (' . substr(env('ZIGGO_API_KEY'), 0, 10) . '...)' : 'NOT SET') . "\n";
echo "  ZIGGO_V2_API_KEY: " . (env('ZIGGO_V2_API_KEY') ? 'Set (' . substr(env('ZIGGO_V2_API_KEY'), 0, 10) . '...)' : 'NOT SET') . "\n";
echo "  ZIGGO_V2_API_URL: " . (env('ZIGGO_V2_API_URL') ?: 'NOT SET') . "\n";

// Test config() function
echo "\nConfig Cache Status:\n";
$configCached = app()->configurationIsCached();
echo "  Configuration cached: " . ($configCached ? 'Yes (need to rebuild)' : 'No (reading from .env)') . "\n";

if ($configCached) {
    echo "\n⚠ Config is cached! Rebuilding...\n";
    try {
        Artisan::call('config:cache');
        echo "✓ Config cache rebuilt\n";
    } catch (Exception $e) {
        echo "✗ Failed to rebuild: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Test API Access ===\n\n";

// Try to instantiate ZiggoPostcodeCheckV2
try {
    $ziggo = new \App\Libraries\ZiggoPostcodeCheckV2();
    echo "✓ ZiggoPostcodeCheckV2 instantiated successfully\n";
    
    // Test a simple API call
    $testPostcode = '2723AB';
    $testNumber = '106';
    
    echo "\nTesting API call to {$testPostcode} {$testNumber}...\n";
    
    $address = new stdClass();
    $address->postcode = $testPostcode;
    $address->number = $testNumber;
    $address->extension = '';
    
    $result = new \App\Models\Postcode();
    $result->postcode = $testPostcode;
    $result->house_number = $testNumber;
    $result->house_nr_add = '';
    $result->supplier_id = 4;
    $result->max_download = 0;
    
    ob_start();
    $apiResult = $ziggo->request($address, $result, 1);
    $output = ob_get_clean();
    
    if ($apiResult) {
        echo "✓ API call succeeded!\n";
        echo "  kabel_max: {$result->kabel_max}\n";
        echo "  max_download: {$result->max_download}\n";
    } else {
        echo "✗ API call failed\n";
        echo "\nDebug output:\n{$output}\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== Summary ===\n";
echo "All caches cleared and configuration reloaded.\n";
echo "If API calls still fail with 403, check:\n";
echo "1. API key is correct in .env file\n";
echo "2. Server IP is whitelisted with Ziggo\n";
echo "3. API key hasn't expired\n";

echo "</pre>";
?>
