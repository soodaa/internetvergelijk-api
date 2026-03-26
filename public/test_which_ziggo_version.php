<?php
/**
 * Check which Ziggo class is being used
 * URL: https://api.internetvergelijk.nl/test_which_ziggo_version.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Ziggo Version Check</h1>";
echo "<hr>";

// Check if files exist
echo "<h2>File Existence Check</h2>";

$files = [
    'V2' => '/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/ZiggoPostcodeCheckV2.php',
    'config/providers.php' => '/var/www/vhosts/internetvergelijk.nl/api/config/providers.php',
];

echo "<table style='border-collapse: collapse;'>";
foreach ($files as $name => $path) {
    $exists = file_exists($path);
    $color = $exists ? 'green' : 'red';
    $modified = $exists ? date('Y-m-d H:i:s', filemtime($path)) : 'N/A';
    $size = $exists ? filesize($path) . ' bytes' : 'N/A';
    
    echo "<tr>";
    echo "<td style='padding: 8px; border: 1px solid #ddd;'><strong>{$name}:</strong></td>";
    echo "<td style='padding: 8px; border: 1px solid #ddd; color: {$color};'>" . ($exists ? '✓ EXISTS' : '✗ NOT FOUND') . "</td>";
    echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$modified}</td>";
    echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$size}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
$providersFile = '/var/www/vhosts/internetvergelijk.nl/api/config/providers.php';
echo "<h2>config/providers.php Check</h2>";

if (file_exists($providersFile)) {
    $content = file_get_contents($providersFile);

    if (strpos($content, "Ziggo' => [") !== false && strpos($content, 'ZiggoPostcodeCheckV2::class') !== false) {
        echo "<p style='color: green; font-size: 16px;'><strong>✓ config/providers.php verwijst naar ZiggoPostcodeCheckV2</strong></p>";
        if (preg_match("/'Ziggo'\\s*=>\\s*\\[[^\\]]+\\]/m", $content, $matches)) {
            echo "<pre style='background: #e8f5e9; padding: 10px; border: 1px solid #4caf50;'>";
            echo htmlspecialchars($matches[0]);
            echo "</pre>";
        }
    } else {
        echo "<p style='color: orange; font-size: 16px;'><strong>⚠ Controleer dat 'Ziggo' in config/providers.php naar ZiggoPostcodeCheckV2 verwijst</strong></p>";
    }
} else {
    echo "<p style='color: red;'><strong>✗ config/providers.php niet gevonden!</strong></p>";
}

echo "<hr>";
echo "<h2>ZiggoPostcodeCheckV2.php Speed Tiers Check</h2>";

$v2File = '/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/ZiggoPostcodeCheckV2.php';
if (file_exists($v2File)) {
    $content = file_get_contents($v2File);
    
    // Check if 2000 => 2000 is in the speed tiers
    if (strpos($content, '2000 => 2000') !== false && strpos($content, '2200 => 2000') !== false) {
        echo "<p style='color: green; font-size: 16px;'><strong>✓ V2 file has correct speed tiers (includes 2000 Mbps)</strong></p>";
    } else {
        echo "<p style='color: orange; font-size: 16px;'><strong>⚠ V2 file might be outdated (missing 2000 Mbps tier)</strong></p>";
    }
    
    // Show normalizeZiggoSpeed function
    if (preg_match('/private function normalizeZiggoSpeed\(\$speed\).*?\{(.*?)\n    \}/s', $content, $matches)) {
        echo "<p><strong>normalizeZiggoSpeed() function found:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow: auto;'>";
        echo htmlspecialchars($matches[0]);
        echo "</pre>";
    }
} else {
    echo "<p style='color: red;'><strong>✗ ZiggoPostcodeCheckV2.php not found!</strong></p>";
}

echo "<hr>";
echo "<h2>PHP Opcode Cache Info</h2>";
echo "<p>Opcode cache can cause PHP to use old versions of files even after updating them.</p>";

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    echo "<p><strong>OPcache Status:</strong> " . ($status['opcache_enabled'] ? '✓ Enabled' : '✗ Disabled') . "</p>";
    
    if ($status['opcache_enabled']) {
        echo "<p style='color: orange;'><strong>⚠ OPcache is enabled.</strong> You may need to clear it after uploading files:</p>";
        echo "<pre style='background: #fff3cd; padding: 10px; border: 1px solid #ffc107;'>";
        echo "# Clear OPcache (run on server):\n";
        echo "php -r 'opcache_reset();'\n";
        echo "# Or restart PHP-FPM:\n";
        echo "service php7.4-fpm restart";
        echo "</pre>";
    }
} else {
    echo "<p>OPcache functions not available</p>";
}

echo "<hr>";
echo "<h2>Recommendations</h2>";
echo "<ol>";
echo "<li>Controleer dat <strong>config/providers.php</strong> voor 'Ziggo' naar <code>ZiggoPostcodeCheckV2::class</code> verwijst</li>";
echo "<li>Controleer dat <strong>ZiggoPostcodeCheckV2.php</strong> de 2000 Mbps speed tier bevat</li>";
echo "<li>Clear PHP opcode cache if enabled</li>";
echo "<li>Test again with a fresh postcode (not cached in database)</li>";
echo "</ol>";
