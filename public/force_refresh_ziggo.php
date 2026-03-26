<?php
/**
 * Force refresh Ziggo postcode in database
 * URL: https://api.internetvergelijk.nl/force_refresh_ziggo.php?postcode=2728AA&number=1
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$postcode = $_GET['postcode'] ?? '2728AA';
$number = $_GET['number'] ?? '1';

echo "<h1>Force Refresh Ziggo Postcode</h1>";
echo "<p><strong>Address:</strong> {$postcode} {$number}</p>";
echo "<hr>";

// Database connection (adjust credentials if needed)
$host = 'localhost';
$dbname = 'internetvergelijknl_internetvergelijk';
$username = 'internetvergelijknl_internetvergelijk';
$password = 'Br3SXUsvvA85';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Step 1: Find Ziggo supplier_id</h2>";
    $stmt = $pdo->query("SELECT id, name FROM suppliers WHERE name = 'Ziggo'");
    $ziggo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ziggo) {
        die("<p style='color: red;'>Ziggo supplier not found in database!</p>");
    }
    
    echo "<p>Ziggo supplier_id: <strong>{$ziggo['id']}</strong></p>";
    
    echo "<hr>";
    echo "<h2>Step 2: Check existing record</h2>";
    
    $stmt = $pdo->prepare("SELECT * FROM postcodes WHERE postcode = ? AND house_number = ? AND supplier_id = ?");
    $stmt->execute([$postcode, $number, $ziggo['id']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        echo "<p style='color: orange;'>⚠ Record exists in database:</p>";
        echo "<pre style='background: #fff3cd; padding: 10px; border: 1px solid #ffc107;'>";
        echo "ID: {$existing['id']}\n";
        echo "kabel_max: {$existing['kabel_max']} Mbps\n";
        echo "max_download: {$existing['max_download']} Mbps\n";
        echo "updated_at: {$existing['updated_at']}\n";
        echo "</pre>";
        
        echo "<hr>";
        echo "<h2>Step 3: Delete old record to force fresh check</h2>";
        
        if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
            $stmt = $pdo->prepare("DELETE FROM postcodes WHERE id = ?");
            $stmt->execute([$existing['id']]);
            
            echo "<p style='color: green;'><strong>✓ Deleted record #{$existing['id']}</strong></p>";
            echo "<p>Now test the speedCheck API again - it should create a new record with V2 data:</p>";
            echo "<p><a href='https://api.internetvergelijk.nl/api/speedcheck?postcode={$postcode}&nr={$number}' target='_blank' style='padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px;'>Test speedCheck API</a></p>";
        } else {
            echo "<p><strong>⚠ Delete this record to force a fresh V2 check?</strong></p>";
            echo "<p><a href='?postcode={$postcode}&number={$number}&confirm=yes' style='padding: 10px 20px; background: #f44336; color: white; text-decoration: none; border-radius: 4px;'>YES - Delete and Refresh</a></p>";
            echo "<p><small>This will permanently delete the old record. The speedCheck API will create a new one with V2 data.</small></p>";
        }
    } else {
        echo "<p style='color: green;'>✓ No existing record found - speedCheck API should create a new one with V2 data</p>";
        echo "<p><a href='https://api.internetvergelijk.nl/api/speedcheck?postcode={$postcode}&nr={$number}' target='_blank' style='padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px;'>Test speedCheck API</a></p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>You may need to adjust the database credentials in this script.</p>";
}

echo "<hr>";
echo "<h2>Alternative: Check Queue Workers</h2>";
echo "<p>If deleting doesn't help, the issue might be with queue workers using old code.</p>";
echo "<p>Queue workers need to be restarted after code changes:</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "# SSH into server and run:\n";
echo "cd /var/www/vhosts/internetvergelijk.nl/api\n";
echo "php artisan queue:restart\n";
echo "\n";
echo "# Or if using supervisor:\n";
echo "supervisorctl restart all\n";
echo "</pre>";
