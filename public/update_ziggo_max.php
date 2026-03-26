<?php
/**
 * Update Ziggo max_download in suppliers table
 * URL: https://api.internetvergelijk.nl/update_ziggo_max.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Update Ziggo Max Download</h1>";
echo "<hr>";

// Parse .env for database
$envFile = '/var/www/vhosts/internetvergelijk.nl/api/.env';
$envContent = file_get_contents($envFile);
preg_match('/DB_HOST=(.*)/', $envContent, $hostMatch);
preg_match('/DB_DATABASE=(.*)/', $envContent, $dbMatch);
preg_match('/DB_USERNAME=(.*)/', $envContent, $userMatch);
preg_match('/DB_PASSWORD=(.*)/', $envContent, $passMatch);

$host = trim($hostMatch[1] ?? 'localhost');
$dbname = trim($dbMatch[1] ?? '');
$username = trim($userMatch[1] ?? '');
$password = trim($passMatch[1] ?? '');

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Current Ziggo Supplier Settings</h2>";
    
    $stmt = $pdo->query("SELECT * FROM suppliers WHERE name = 'Ziggo'");
    $ziggo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ziggo) {
        die("<p style='color: red;'>Ziggo not found in suppliers table!</p>");
    }
    
    echo "<table style='border-collapse: collapse; width: 100%; max-width: 600px;'>";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>ID:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$ziggo['id']}</td></tr>";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Name:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$ziggo['name']}</td></tr>";
    echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>max_download:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><strong style='font-size: 18px; color: orange;'>{$ziggo['max_download']} Mbps</strong></td></tr>";
    echo "</table>";
    
    echo "<div style='background: #ffebee; padding: 20px; border-left: 4px solid #f44336; margin: 20px 0;'>";
    echo "<p><strong>🚨 FOUND THE PROBLEM!</strong></p>";
    echo "<p>The <code>suppliers</code> table has <code>max_download = 1000</code> for Ziggo.</p>";
    echo "<p>This acts as a LIMITER - even if the API returns 2000 Mbps, it gets capped at 1000!</p>";
    echo "</div>";
    
    if (!isset($_GET['confirm'])) {
        echo "<hr>";
        echo "<h2>Solution: Update max_download to 2000</h2>";
        
        echo "<p>This will allow Ziggo to support speeds up to 2000 Mbps.</p>";
        
        echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>";
        echo "<p><strong>What will be updated:</strong></p>";
        echo "<pre style='background: #fff; padding: 10px; border: 1px solid #ddd;'>";
        echo "UPDATE suppliers \n";
        echo "SET max_download = 2000 \n";
        echo "WHERE name = 'Ziggo'";
        echo "</pre>";
        echo "</div>";
        
        echo "<p><a href='?confirm=yes' style='display: inline-block; padding: 15px 30px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px; font-size: 18px;'>YES - Update to 2000 Mbps</a></p>";
        echo "<p><a href='test_actual_class.php' style='display: inline-block; padding: 10px 20px; background: #9e9e9e; color: white; text-decoration: none; border-radius: 4px;'>Cancel</a></p>";
        
    } else {
        echo "<hr>";
        echo "<h2>Updating Database...</h2>";
        
        $stmt = $pdo->prepare("UPDATE suppliers SET max_download = 2000 WHERE name = 'Ziggo'");
        $stmt->execute();
        
        echo "<p style='color: green; font-size: 20px;'><strong>✓ SUCCESS!</strong></p>";
        echo "<p>Ziggo max_download has been updated to <strong>2000 Mbps</strong></p>";
        
        // Show updated record
        $stmt = $pdo->query("SELECT * FROM suppliers WHERE name = 'Ziggo'");
        $ziggo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<h3>Updated Settings:</h3>";
        echo "<table style='border-collapse: collapse; width: 100%; max-width: 600px;'>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>max_download:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><strong style='font-size: 18px; color: green;'>{$ziggo['max_download']} Mbps</strong></td></tr>";
        echo "</table>";
        
        echo "<hr>";
        echo "<h2>🧪 Test Now</h2>";
        echo "<p>Now test the speedCheck API again. The 1000 Mbps limit has been removed!</p>";
        
        echo "<ol>";
        echo "<li>Clear cache for test address:";
        echo "<p><a href='clear_ziggo_cache.php?postcode=2728AA&number=3' style='display: inline-block; padding: 10px 20px; background: #ff9800; color: white; text-decoration: none; border-radius: 4px;'>Clear Cache for 2728AA 3</a></p>";
        echo "</li>";
        echo "<li>Test speedCheck API:";
        echo "<p><a href='https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=3' target='_blank' style='display: inline-block; padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px;'>Test speedCheck API</a></p>";
        echo "</li>";
        echo "</ol>";
        
        echo "<p><strong>Expected result:</strong> <code>\"kabel\":2000</code> 🎉</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><small><a href='test_actual_class.php'>Back to Class Test</a></small></p>";
