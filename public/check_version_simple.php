<?php
/**
 * Simple version check - No Composer required
 */

echo "<h1>ZiggoPostcodeCheckV2 - Version Check (Simple)</h1>";
echo "<pre>";

$file = __DIR__ . '/../app/Libraries/ZiggoPostcodeCheckV2.php';

echo "File: {$file}\n";
echo "Exists: " . (file_exists($file) ? "✓ Yes" : "✗ No") . "\n";

if (!file_exists($file)) {
    echo "\n✗ File not found!\n";
    exit(1);
}

echo "Modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "Size: " . filesize($file) . " bytes\n\n";

// Read file content
$content = file_get_contents($file);

echo "=== Checking for URL fix ===\n\n";

// Check for old code (leading slash)
$hasOldFootprint = strpos($content, '"/footprint/{') !== false;
$hasOldAvailability = strpos($content, '"/availability/{') !== false;

// Check for new code (no leading slash)
$hasNewFootprint = strpos($content, '"footprint/{') !== false;
$hasNewAvailability = strpos($content, '"availability/{') !== false;

// Check for trailing slash fix
$hasRtrimFix = strpos($content, "rtrim(\$baseUrl, '/') . '/'") !== false;

echo "OLD CODE (with leading /):\n";
echo "  \"/footprint/{\": " . ($hasOldFootprint ? "✗ FOUND (BAD)" : "✓ NOT FOUND (GOOD)") . "\n";
echo "  \"/availability/{\": " . ($hasOldAvailability ? "✗ FOUND (BAD)" : "✓ NOT FOUND (GOOD)") . "\n\n";

echo "NEW CODE (no leading /):\n";
echo "  \"footprint/{\": " . ($hasNewFootprint ? "✓ FOUND (GOOD)" : "✗ NOT FOUND (BAD)") . "\n";
echo "  \"availability/{\": " . ($hasNewAvailability ? "✓ FOUND (GOOD)" : "✗ NOT FOUND (BAD)") . "\n\n";

echo "TRAILING SLASH FIX:\n";
echo "  rtrim(\$baseUrl): " . ($hasRtrimFix ? "✓ FOUND (GOOD)" : "✗ NOT FOUND (BAD)") . "\n\n";

// Overall status
if (!$hasOldFootprint && !$hasOldAvailability && $hasNewFootprint && $hasNewAvailability && $hasRtrimFix) {
    echo "═══════════════════════════════════════\n";
    echo "✓✓✓ FILE IS CORRECTLY UPDATED! ✓✓✓\n";
    echo "═══════════════════════════════════════\n\n";
    
    echo "Next steps:\n";
    echo "1. Clear cache: https://api.internetvergelijk.nl/clear_opcache.php\n";
    echo "2. Test API: https://api.internetvergelijk.nl/test_ziggo_full_trace.php\n";
    echo "3. Test endpoint: https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=1\n";
    
} else {
    echo "═══════════════════════════════════════\n";
    echo "✗✗✗ FILE NOT YET UPDATED ✗✗✗\n";
    echo "═══════════════════════════════════════\n\n";
    
    echo "Issues found:\n";
    if ($hasOldFootprint) echo "  - Still has \"/footprint/{\"\n";
    if ($hasOldAvailability) echo "  - Still has \"/availability/{\"\n";
    if (!$hasNewFootprint) echo "  - Missing \"footprint/{\"\n";
    if (!$hasNewAvailability) echo "  - Missing \"availability/{\"\n";
    if (!$hasRtrimFix) echo "  - Missing rtrim fix\n";
    
    echo "\nPlease upload the corrected version.\n";
}

echo "\n=== Expected file size: ~11,500 bytes ===\n";
echo "Your file: " . filesize($file) . " bytes\n";

if (filesize($file) < 10000) {
    echo "⚠ Warning: File seems too small!\n";
} elseif (filesize($file) > 15000) {
    echo "⚠ Warning: File seems too large!\n";
} else {
    echo "✓ File size looks reasonable\n";
}

echo "</pre>";
?>
