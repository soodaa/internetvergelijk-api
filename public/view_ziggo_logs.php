<?php
/**
 * View Ziggo V2 Logs in Real-Time
 */

echo "<h1>Ziggo V2 Real-Time Logs</h1>";
echo "<style>
    .trace { background: #e3f2fd; padding: 10px; margin: 5px 0; border-left: 4px solid #2196f3; }
    .parse { background: #fff3e0; padding: 10px; margin: 5px 0; border-left: 4px solid #ff9800; }
    .normalize { background: #f3e5f5; padding: 10px; margin: 5px 0; border-left: 4px solid #9c27b0; }
    .saved { background: #e8f5e9; padding: 10px; margin: 5px 0; border-left: 4px solid #4caf50; }
    .error { background: #ffebee; padding: 10px; margin: 5px 0; border-left: 4px solid #f44336; }
    pre { margin: 0; font-family: monospace; }
</style>";

$logFile = '/var/www/vhosts/internetvergelijk.nl/api/storage/logs/laravel.log';

// Get only recent lines (last 500)
$lines = [];
if (file_exists($logFile)) {
    $handle = fopen($logFile, 'r');
    if ($handle) {
        // Get file size and start from near the end
        fseek($handle, -50000, SEEK_END);
        while (($line = fgets($handle)) !== false) {
            $lines[] = $line;
        }
        fclose($handle);
        
        // Keep only last 500 lines
        $lines = array_slice($lines, -500);
    }
}

echo "<p><strong>Showing last " . count($lines) . " log lines</strong></p>";
echo "<p><a href='?refresh=1' style='padding: 10px 20px; background: #2196f3; color: white; text-decoration: none; border-radius: 4px;'>Refresh Logs</a></p>";

echo "<hr>";

// Parse and display Ziggo V2 related logs
$foundZiggo = false;

foreach ($lines as $line) {
    // Look for Ziggo V2 TRACE logs
    if (strpos($line, 'Ziggo V2 TRACE') !== false) {
        $foundZiggo = true;
        echo "<div class='trace'>";
        echo "<strong>🔍 TRACE (Before Save)</strong><br>";
        echo "<pre>" . htmlspecialchars($line) . "</pre>";
        echo "</div>";
    }
    
    // Look for Ziggo V2 PARSE logs
    if (strpos($line, 'Ziggo V2 PARSE') !== false) {
        $foundZiggo = true;
        echo "<div class='parse'>";
        echo "<strong>📊 PARSE (Raw API Data)</strong><br>";
        echo "<pre>" . htmlspecialchars($line) . "</pre>";
        echo "</div>";
    }
    
    // Look for Ziggo V2 NORMALIZE logs
    if (strpos($line, 'Ziggo V2 NORMALIZE') !== false) {
        $foundZiggo = true;
        echo "<div class='normalize'>";
        echo "<strong>🔄 NORMALIZE (Speed Mapping)</strong><br>";
        echo "<pre>" . htmlspecialchars($line) . "</pre>";
        echo "</div>";
    }
    
    // Look for Ziggo V2 SAVED logs
    if (strpos($line, 'Ziggo V2 SAVED') !== false) {
        $foundZiggo = true;
        echo "<div class='saved'>";
        echo "<strong>✅ SAVED (After Database Write)</strong><br>";
        echo "<pre>" . htmlspecialchars($line) . "</pre>";
        echo "</div>";
    }
    
    // Look for Ziggo V2 errors
    if (strpos($line, 'Ziggo V2') !== false && strpos($line, 'Error') !== false) {
        $foundZiggo = true;
        echo "<div class='error'>";
        echo "<strong>❌ ERROR</strong><br>";
        echo "<pre>" . htmlspecialchars($line) . "</pre>";
        echo "</div>";
    }
}

if (!$foundZiggo) {
    echo "<p style='background: #fff3cd; padding: 20px; border: 2px solid #ffc107;'>";
    echo "<strong>⚠️ No Ziggo V2 logs found yet</strong><br><br>";
    echo "This means either:<br>";
    echo "1. Queue workers haven't processed any Ziggo requests yet<br>";
    echo "2. The new code with logging hasn't been uploaded yet<br>";
    echo "3. Queue workers are still using old code (need restart)<br><br>";
    echo "Try:<br>";
    echo "1. Upload the new ZiggoPostcodeCheckV2.php with logging<br>";
    echo "2. Restart queue workers<br>";
    echo "3. Delete test address and request again<br>";
    echo "4. Wait 10 seconds and refresh this page<br>";
    echo "</p>";
}

echo "<hr>";
echo "<h2>Next Steps</h2>";
echo "<ol>";
echo "<li><strong>Upload</strong> the new ZiggoPostcodeCheckV2.php with logging</li>";
echo "<li><strong>Restart workers:</strong> <code>kill -9 [PID]</code> then start new one</li>";
echo "<li><strong>Clear OPcache:</strong> <a href='clear_opcache.php' target='_blank'>clear_opcache.php</a></li>";
echo "<li><strong>Delete test data:</strong> <a href='delete_test_address.php?postcode=2728AA&nr=3' target='_blank'>delete_test_address.php</a></li>";
echo "<li><strong>Request speedCheck:</strong> Wait 10 seconds, then check</li>";
echo "<li><strong>Refresh this page</strong> to see the trace logs</li>";
echo "</ol>";

echo "<p><strong>This will show EXACTLY where 1000 comes from!</strong></p>";
?>
