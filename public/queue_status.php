<?php
/**
 * Queue Worker Status & Restart Instructions
 * URL: https://api.internetvergelijk.nl/queue_status.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Queue Worker Status & Restart Guide</h1>";
echo "<hr>";

echo "<h2>🚨 Important: Queue Workers Cache Code</h2>";
echo "<div style='background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107; margin: 20px 0;'>";
echo "<p><strong>The Problem:</strong></p>";
echo "<ul>";
echo "<li>Your speedCheck API uses queue jobs (background workers)</li>";
echo "<li>Queue workers load PHP code into memory when they start</li>";
echo "<li>Even if you upload new files, workers keep using OLD code from memory</li>";
echo "<li><strong>Solution: Restart queue workers after uploading new code</strong></li>";
echo "</ul>";
echo "</div>";

echo "<hr>";
echo "<h2>✅ What You've Done So Far</h2>";
echo "<ol style='font-size: 16px;'>";
echo "<li>✓ Uploaded ZiggoPostcodeCheckV2.php (verified at 08:09:48)</li>";
echo "<li>✓ Uploaded Supplier.php pointing to V2 (verified at 08:12:41)</li>";
echo "<li>✓ Direct API test shows correct 2000 Mbps</li>";
echo "<li>✓ Code simulation shows correct 2000 Mbps</li>";
echo "<li>❌ speedCheck API returns 1000 Mbps (using old queue worker code)</li>";
echo "</ol>";

echo "<hr>";
echo "<h2>🔧 Solution: Restart Queue Workers</h2>";

echo "<h3>Method 1: Using Artisan (Graceful Restart)</h3>";
echo "<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50; margin: 10px 0;'>";
echo "<p>This tells workers to finish current jobs and restart:</p>";
echo "<pre style='background: #fff; padding: 10px; border: 1px solid #ddd;'>";
echo "cd /var/www/vhosts/internetvergelijk.nl/api\n";
echo "php artisan queue:restart";
echo "</pre>";
echo "<p><small>Workers will restart themselves and load the new V2 code</small></p>";
echo "</div>";

echo "<h3>Method 2: Using Supervisor (If Installed)</h3>";
echo "<div style='background: #e3f2fd; padding: 15px; border-left: 4px solid #2196f3; margin: 10px 0;'>";
echo "<pre style='background: #fff; padding: 10px; border: 1px solid #ddd;'>";
echo "# Check if supervisor is running:\n";
echo "supervisorctl status\n\n";
echo "# Restart all workers:\n";
echo "supervisorctl restart all\n\n";
echo "# Or restart specific Laravel queue:\n";
echo "supervisorctl restart laravel-worker:*";
echo "</pre>";
echo "</div>";

echo "<h3>Method 3: Kill and Restart Manually</h3>";
echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0;'>";
echo "<pre style='background: #fff; padding: 10px; border: 1px solid #ddd;'>";
echo "# Find queue worker processes:\n";
echo "ps aux | grep 'queue:work'\n\n";
echo "# Kill them:\n";
echo "pkill -f 'queue:work'\n\n";
echo "# They should auto-restart, or manually start:\n";
echo "cd /var/www/vhosts/internetvergelijk.nl/api\n";
echo "php artisan queue:work --daemon &";
echo "</pre>";
echo "</div>";

echo "<hr>";
echo "<h2>🧪 After Restarting - Test Again</h2>";
echo "<ol>";
echo "<li>Delete the database record for test address:";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "# Connect to MySQL and run:\n";
echo "DELETE FROM postcodes WHERE postcode = '2728AA' AND house_number = '1' AND supplier_id = (SELECT id FROM suppliers WHERE name = 'Ziggo');";
echo "</pre>";
echo "</li>";
echo "<li>Test speedCheck API with fresh address:</li>";
echo "<p><a href='https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=1' target='_blank' style='display: inline-block; padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px; margin: 10px 0;'>Test speedCheck API</a></p>";
echo "<li>Expected result: <code>\"kabel\":2000</code></li>";
echo "</ol>";

echo "<hr>";
echo "<h2>📊 Quick Verification</h2>";

echo "<h3>Check Queue Jobs Table</h3>";
echo "<p>If you have access to the database, check if there are pending jobs:</p>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "SELECT * FROM jobs ORDER BY id DESC LIMIT 10;";
echo "</pre>";

echo "<h3>Check Laravel Logs</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo "tail -f /var/www/vhosts/internetvergelijk.nl/api/storage/logs/laravel.log";
echo "</pre>";

echo "<hr>";
echo "<h2>🎯 Summary</h2>";
echo "<div style='background: #e8f5e9; padding: 20px; border: 2px solid #4caf50; margin: 20px 0;'>";
echo "<p style='font-size: 18px; margin: 0;'><strong>The V2 code is correct and working!</strong></p>";
echo "<p style='margin: 10px 0 0 0;'>You just need to restart queue workers so they load the new code.</p>";
echo "<p style='margin: 10px 0 0 0;'><strong>Run:</strong> <code style='background: #fff; padding: 5px; border: 1px solid #ddd;'>php artisan queue:restart</code></p>";
echo "</div>";

echo "<hr>";
echo "<p><small>Test scripts: ";
echo "<a href='test_ziggo_v2_direct.php?postcode=2728AA&number=1'>Direct API</a> | ";
echo "<a href='test_ziggo_v2_class_debug.php?postcode=2728AA&number=1'>Class Debug</a> | ";
echo "<a href='test_which_ziggo_version.php'>Version Check</a>";
echo "</small></p>";
