<?php
/**
 * Clear Ziggo Cache (Update timestamp to force refresh)
 * URL: https://api.internetvergelijk.nl/clear_ziggo_cache.php?postcode=2728AA&number=1
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$postcode = $_GET['postcode'] ?? '2728AA';
$number = $_GET['number'] ?? '1';

echo "<h1>Clear Ziggo Cache</h1>";
echo "<p><strong>Address:</strong> {$postcode} {$number}</p>";
echo "<hr>";

// Find Laravel's .env file to get DB credentials
$envFile = '/var/www/vhosts/internetvergelijk.nl/api/.env';

if (!file_exists($envFile)) {
    die("<p style='color: red;'>Cannot find .env file at: {$envFile}</p>");
}

// Parse .env file
$envContent = file_get_contents($envFile);
preg_match('/DB_HOST=(.*)/', $envContent, $hostMatch);
preg_match('/DB_DATABASE=(.*)/', $envContent, $dbMatch);
preg_match('/DB_USERNAME=(.*)/', $envContent, $userMatch);
preg_match('/DB_PASSWORD=(.*)/', $envContent, $passMatch);

$host = $hostMatch[1] ?? 'localhost';
$dbname = $dbMatch[1] ?? '';
$username = $userMatch[1] ?? '';
$password = $passMatch[1] ?? '';

echo "<h2>Database Connection</h2>";
echo "<p>Host: <code>{$host}</code></p>";
echo "<p>Database: <code>{$dbname}</code></p>";

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color: green;'>✓ Connected to database</p>";
    
    echo "<hr>";
    echo "<h2>Find Ziggo Records</h2>";
    
    // Get Ziggo supplier ID
    $stmt = $pdo->query("SELECT id FROM suppliers WHERE name = 'Ziggo'");
    $ziggo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ziggo) {
        die("<p style='color: red;'>Ziggo supplier not found in database!</p>");
    }
    
    $supplierId = $ziggo['id'];
    echo "<p>Ziggo supplier_id: <strong>{$supplierId}</strong></p>";
    
    // Find the postcode record
    $stmt = $pdo->prepare("
        SELECT id, postcode, house_number, kabel_max, max_download, updated_at, created_at 
        FROM postcodes 
        WHERE postcode = ? AND house_number = ? AND supplier_id = ?
    ");
    $stmt->execute([$postcode, $number, $supplierId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        echo "<h3>Found Record:</h3>";
        echo "<table style='border-collapse: collapse; width: 100%; max-width: 600px;'>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>ID:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$record['id']}</td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Postcode:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$record['postcode']}</td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>House Number:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$record['house_number']}</td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>kabel_max:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'><strong>{$record['kabel_max']} Mbps</strong></td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>max_download:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$record['max_download']} Mbps</td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Updated:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$record['updated_at']}</td></tr>";
        echo "<tr><td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Created:</strong></td><td style='padding: 8px; border: 1px solid #ddd;'>{$record['created_at']}</td></tr>";
        echo "</table>";
        
        // Check if within 12 hour cache window
        $updatedTime = strtotime($record['updated_at']);
        $now = time();
        $hoursSince = ($now - $updatedTime) / 3600;
        
        echo "<p><strong>Cache Status:</strong> Updated {$hoursSince} hours ago</p>";
        
        if ($hoursSince < 12) {
            echo "<p style='color: orange;'>⚠ Within 12-hour cache window - will use cached value</p>";
        } else {
            echo "<p style='color: green;'>✓ Outside 12-hour cache window - will refresh on next check</p>";
        }
        
        echo "<hr>";
        echo "<h2>Clear Cache Options</h2>";
        
        if (!isset($_GET['action'])) {
            echo "<h3>Option 1: Set updated_at to old date (Force Refresh)</h3>";
            echo "<p>This tricks the cache into thinking the record is old, forcing a fresh API check.</p>";
            echo "<p><a href='?postcode={$postcode}&number={$number}&action=reset_timestamp' style='display: inline-block; padding: 10px 20px; background: #ff9800; color: white; text-decoration: none; border-radius: 4px;'>Reset Timestamp</a></p>";
            
            echo "<h3>Option 2: Delete Record (Complete Removal)</h3>";
            echo "<p>This completely removes the record, forcing a fresh check.</p>";
            echo "<p><a href='?postcode={$postcode}&number={$number}&action=delete' style='display: inline-block; padding: 10px 20px; background: #f44336; color: white; text-decoration: none; border-radius: 4px;'>Delete Record</a></p>";
            
        } elseif ($_GET['action'] === 'reset_timestamp') {
            // Set updated_at to 13 hours ago
            $stmt = $pdo->prepare("
                UPDATE postcodes 
                SET updated_at = DATE_SUB(NOW(), INTERVAL 13 HOUR)
                WHERE id = ?
            ");
            $stmt->execute([$record['id']]);
            
            echo "<p style='color: green; font-size: 18px;'><strong>✓ Timestamp Reset!</strong></p>";
            echo "<p>The record's updated_at has been set to 13 hours ago.</p>";
            echo "<p>Next speedCheck API call will trigger a fresh V2 API check.</p>";
            
        } elseif ($_GET['action'] === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM postcodes WHERE id = ?");
            $stmt->execute([$record['id']]);
            
            echo "<p style='color: green; font-size: 18px;'><strong>✓ Record Deleted!</strong></p>";
            echo "<p>The old record has been removed from the database.</p>";
            echo "<p>Next speedCheck API call will create a new record with V2 data.</p>";
        }
        
    } else {
        echo "<p style='color: green;'><strong>✓ No cached record found</strong></p>";
        echo "<p>The next speedCheck API call will create a fresh record using V2 API.</p>";
    }
    
    echo "<hr>";
    echo "<h2>🧪 Test Now</h2>";
    echo "<p><a href='https://api.internetvergelijk.nl/api/speedcheck?postcode={$postcode}&nr={$number}' target='_blank' style='display: inline-block; padding: 15px 30px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px; font-size: 18px;'>Test speedCheck API</a></p>";
    echo "<p><strong>Expected result:</strong> <code>\"kabel\":2000</code></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><small>";
echo "<a href='?postcode=2728AA&number=1'>2728AA 1</a> | ";
echo "<a href='?postcode=2723AB&number=106'>2723AB 106</a> | ";
echo "<a href='queue_status.php'>Queue Status</a>";
echo "</small></p>";
