<?php
/**
 * Check Laravel Environment Configuration for Ziggo API
 */

echo "<h1>Ziggo API Configuration Check</h1>";
echo "<pre>";

// Try to load .env file directly
$envFile = __DIR__ . '/../.env';

echo "=== Environment File ===\n";
echo "Path: {$envFile}\n";
echo "Exists: " . (file_exists($envFile) ? "✓ Yes" : "✗ No") . "\n";

if (file_exists($envFile)) {
    echo "Readable: " . (is_readable($envFile) ? "✓ Yes" : "✗ No") . "\n";
    echo "Modified: " . date('Y-m-d H:i:s', filemtime($envFile)) . "\n";
    echo "\n";
    
    // Parse .env file
    echo "=== Ziggo Configuration ===\n";
    $envContent = file_get_contents($envFile);
    $lines = explode("\n", $envContent);
    
    $ziggoVars = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        if (strpos($line, 'ZIGGO') !== false || strpos($line, 'API_KEY') !== false) {
            $ziggoVars[] = $line;
        }
    }
    
    if (empty($ziggoVars)) {
        echo "⚠ No ZIGGO configuration found in .env\n";
    } else {
        foreach ($ziggoVars as $var) {
            // Mask sensitive values
            if (preg_match('/^([^=]+)=(.*)$/', $var, $matches)) {
                $key = $matches[1];
                $value = $matches[2];
                
                if (strpos($key, 'KEY') !== false && strlen($value) > 20) {
                    $masked = substr($value, 0, 10) . '...' . substr($value, -10);
                    echo "{$key}={$masked}\n";
                } else {
                    echo "{$key}={$value}\n";
                }
            }
        }
    }
}

echo "\n=== Laravel Environment (via env() helper) ===\n";

// Bootstrap Laravel to access env()
try {
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
    
    $envVars = [
        'ZIGGO_API_KEY',
        'ZIGGO_V2_API_KEY',
        'ZIGGO_V2_API_URL',
    ];
    
    foreach ($envVars as $var) {
        $value = env($var);
        if ($value) {
            if (strpos($var, 'KEY') !== false && strlen($value) > 20) {
                $masked = substr($value, 0, 10) . '...' . substr($value, -10);
                echo "{$var}={$masked}\n";
            } else {
                echo "{$var}={$value}\n";
            }
        } else {
            echo "{$var}=(not set)\n";
        }
    }
    
    echo "\n=== ZiggoPostcodeCheckV2 Configuration ===\n";
    
    // Create instance and check what it's using
    $ziggo = new \App\Libraries\ZiggoPostcodeCheckV2();
    
    // Use reflection to access private properties
    $reflection = new ReflectionClass($ziggo);
    
    $baseProperty = $reflection->getProperty('_base');
    $baseProperty->setAccessible(true);
    $baseUrl = $baseProperty->getValue($ziggo);
    
    echo "Base URL: {$baseUrl}\n";
    
    // Check Guzzle configuration
    $guzzleProperty = $reflection->getProperty('_guzzle');
    $guzzleProperty->setAccessible(true);
    $guzzle = $guzzleProperty->getValue($ziggo);
    
    $guzzleConfig = $guzzle->getConfig();
    echo "Guzzle base_uri: " . ($guzzleConfig['base_uri'] ?? 'not set') . "\n";
    
    if (isset($guzzleConfig['headers'])) {
        echo "\nGuzzle Headers:\n";
        foreach ($guzzleConfig['headers'] as $header => $value) {
            if (stripos($header, 'key') !== false && strlen($value) > 20) {
                $masked = substr($value, 0, 10) . '...' . substr($value, -10);
                echo "  {$header}: {$masked}\n";
            } else {
                echo "  {$header}: {$value}\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error loading Laravel: " . $e->getMessage() . "\n";
}

echo "\n=== Diagnosis ===\n";
echo "If ZIGGO_V2_API_KEY is not set, it will fall back to ZIGGO_API_KEY\n";
echo "If both are not set, API calls will fail with 403 Forbidden\n";
echo "\nExpected configuration:\n";
echo "ZIGGO_API_KEY=<ZIGGO_API_KEY>\n";
echo "ZIGGO_V2_API_URL=https://api.prod.aws.ziggo.io/v2/api/rfscom/v2\n";

echo "</pre>";
?>
