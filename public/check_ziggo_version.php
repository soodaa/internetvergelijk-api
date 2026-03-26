<?php
/**
 * Test if file upload worked - Version 2 with URL fix
 */

echo "<h1>ZiggoPostcodeCheckV2 - Version Check</h1>";
echo "<pre>";

require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$file = __DIR__ . '/../../app/Libraries/ZiggoPostcodeCheckV2.php';

echo "File: {$file}\n";
echo "Exists: " . (file_exists($file) ? "✓ Yes" : "✗ No") . "\n";
echo "Modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "Size: " . filesize($file) . " bytes\n\n";

// Check if the fix is present
$content = file_get_contents($file);

echo "=== Checking for URL fix ===\n";
$hasLeadingSlashFootprint = strpos($content, '"/footprint/') !== false;
$hasNoLeadingSlashFootprint = strpos($content, '"footprint/') !== false;

echo "Has OLD code (\"/footprint/\"): " . ($hasLeadingSlashFootprint ? "✗ YES (NOT FIXED)" : "✓ NO") . "\n";
echo "Has NEW code (\"footprint/\"): " . ($hasNoLeadingSlashFootprint ? "✓ YES (FIXED)" : "✗ NO") . "\n\n";

$hasLeadingSlashAvail = strpos($content, '"/availability/') !== false;
$hasNoLeadingSlashAvail = strpos($content, '"availability/') !== false;

echo "Has OLD code (\"/availability/\"): " . ($hasLeadingSlashAvail ? "✗ YES (NOT FIXED)" : "✓ NO") . "\n";
echo "Has NEW code (\"availability/\"): " . ($hasNoLeadingSlashAvail ? "✓ YES (FIXED)" : "✗ NO") . "\n\n";

// Check base_uri handling
$hasRtrim = strpos($content, 'rtrim($baseUrl') !== false;
echo "Has trailing slash fix (rtrim): " . ($hasRtrim ? "✓ YES" : "✗ NO") . "\n\n";

if ($hasNoLeadingSlashFootprint && $hasNoLeadingSlashAvail && $hasRtrim) {
    echo "✓✓✓ FILE IS UPDATED WITH FIX! ✓✓✓\n\n";
    
    // Test instantiation
    echo "=== Testing Instantiation ===\n";
    try {
        $ziggo = new \App\Libraries\ZiggoPostcodeCheckV2();
        echo "✓ Class instantiated successfully\n";
        
        // Check the base_uri value
        $reflection = new ReflectionClass($ziggo);
        $baseProperty = $reflection->getProperty('_base');
        $baseProperty->setAccessible(true);
        $baseUrl = $baseProperty->getValue($ziggo);
        
        echo "base_uri: {$baseUrl}\n";
        echo "Has trailing slash: " . (substr($baseUrl, -1) === '/' ? "✓ YES" : "✗ NO") . "\n";
        
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗✗✗ FILE NOT UPDATED YET ✗✗✗\n";
    echo "Please upload the fixed version.\n";
}

echo "</pre>";
?>
