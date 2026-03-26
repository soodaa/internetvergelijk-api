<?php
/**
 * Restart Queue Workers via Web
 * URL: https://api.internetvergelijk.nl/restart_queue.php?confirm=yes
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Queue Worker Restart</h1>";
echo "<hr>";

if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    echo "<div style='background: #fff3cd; padding: 20px; border: 2px solid #ffc107; margin: 20px 0;'>";
    echo "<h2>⚠️ Warning</h2>";
    echo "<p>This will restart all queue workers, causing them to reload PHP code.</p>";
    echo "<p>Current jobs will be finished, then workers will restart with new code.</p>";
    echo "</div>";
    
    echo "<p><a href='?confirm=yes' style='display: inline-block; padding: 15px 30px; background: #f44336; color: white; text-decoration: none; border-radius: 4px; font-size: 18px;'>YES - Restart Queue Workers</a></p>";
    echo "<p><a href='queue_status.php' style='display: inline-block; padding: 10px 20px; background: #9e9e9e; color: white; text-decoration: none; border-radius: 4px;'>Cancel</a></p>";
    
} else {
    echo "<h2>Attempting to restart queue workers...</h2>";
    
    // Method 1: Try Artisan command
    echo "<h3>Method 1: Artisan queue:restart</h3>";
    
    $output = [];
    $returnCode = 0;
    
    chdir('/var/www/vhosts/internetvergelijk.nl/api');
    exec('php artisan queue:restart 2>&1', $output, $returnCode);
    
    if ($returnCode === 0) {
        echo "<p style='color: green;'><strong>✓ Success!</strong></p>";
        echo "<pre style='background: #e8f5e9; padding: 10px; border: 1px solid #4caf50;'>";
        echo htmlspecialchars(implode("\n", $output));
        echo "</pre>";
    } else {
        echo "<p style='color: orange;'><strong>⚠ Command failed or no output</strong></p>";
        echo "<pre style='background: #fff3cd; padding: 10px; border: 1px solid #ffc107;'>";
        echo htmlspecialchars(implode("\n", $output));
        echo "\nReturn code: {$returnCode}";
        echo "</pre>";
    }
    
    // Method 2: Create restart signal file
    echo "<hr>";
    echo "<h3>Method 2: Create Restart Signal File</h3>";
    echo "<p>Laravel queue workers check for a 'restart' file. Creating it will signal them to restart.</p>";
    
    $restartFile = '/var/www/vhosts/internetvergelijk.nl/api/storage/framework/cache/data/illuminate-queue-restart';
    
    try {
        // Create the directory if it doesn't exist
        $dir = dirname($restartFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Write the restart signal
        file_put_contents($restartFile, time());
        
        echo "<p style='color: green;'><strong>✓ Restart signal file created!</strong></p>";
        echo "<p>File: <code>{$restartFile}</code></p>";
        echo "<p>Workers will detect this file and restart themselves.</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'><strong>✗ Failed to create restart file</strong></p>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Method 3: Check for supervisor
    echo "<hr>";
    echo "<h3>Method 3: Check Supervisor</h3>";
    
    exec('which supervisorctl 2>&1', $output, $returnCode);
    
    if ($returnCode === 0 && !empty($output)) {
        echo "<p style='color: green;'>✓ Supervisor found at: " . htmlspecialchars($output[0]) . "</p>";
        
        $output = [];
        exec('supervisorctl status 2>&1', $output, $returnCode);
        
        echo "<p><strong>Supervisor Status:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
        echo htmlspecialchars(implode("\n", $output));
        echo "</pre>";
        
        echo "<p><strong>To restart supervisor workers, run on server:</strong></p>";
        echo "<pre style='background: #e3f2fd; padding: 10px; border: 1px solid #2196f3;'>supervisorctl restart all</pre>";
    } else {
        echo "<p style='color: orange;'>⚠ Supervisor not found or not accessible</p>";
    }
    
    echo "<hr>";
    echo "<h2>✅ Next Steps</h2>";
    echo "<ol>";
    echo "<li>Wait 10-30 seconds for workers to detect restart signal</li>";
    echo "<li>Delete the test postcode from database (or wait 12+ hours for cache to expire)</li>";
    echo "<li>Test speedCheck API again:</li>";
    echo "</ol>";
    
    echo "<p><a href='https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=1' target='_blank' style='display: inline-block; padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 4px; margin: 10px 0;'>Test speedCheck API</a></p>";
    
    echo "<p><strong>Expected result:</strong> <code>\"kabel\":2000</code></p>";
}

echo "<hr>";
echo "<p><small><a href='queue_status.php'>Back to Queue Status</a> | <a href='test_which_ziggo_version.php'>Version Check</a></small></p>";
