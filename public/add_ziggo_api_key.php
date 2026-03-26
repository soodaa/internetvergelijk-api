<?php
/**
 * Add Ziggo API Key to .env file
 * 
 * This script adds the ZIGGO_API_KEY to the .env file
 */

echo "<h1>Add Ziggo API Key to .env</h1>";
echo "<pre>";

$envFile = __DIR__ . '/../.env';
$apiKey = getenv('ZIGGO_V2_API_KEY') ?: getenv('ZIGGO_API_KEY');

if (!$apiKey) {
    echo "✗ Missing ZIGGO_V2_API_KEY / ZIGGO_API_KEY in environment.\n";
    echo "Set it in .env manually instead of hardcoding.\n";
    echo "</pre>";
    exit(1);
}

echo "=== Configuration ===\n";
echo "File: {$envFile}\n";
echo "API Key: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -10) . "\n\n";

// Check if .env exists
if (!file_exists($envFile)) {
    echo "✗ .env file not found!\n";
    exit(1);
}

// Read current .env
$envContent = file_get_contents($envFile);
if ($envContent === false) {
    echo "✗ Cannot read .env file!\n";
    exit(1);
}

echo "=== Current State ===\n";

// Check if ZIGGO_API_KEY already exists
$hasZiggoApiKey = (strpos($envContent, 'ZIGGO_API_KEY=') !== false);
$hasZiggoV2ApiKey = (strpos($envContent, 'ZIGGO_V2_API_KEY=') !== false);

echo "ZIGGO_API_KEY exists: " . ($hasZiggoApiKey ? '✓ Yes' : '✗ No') . "\n";
echo "ZIGGO_V2_API_KEY exists: " . ($hasZiggoV2ApiKey ? '✓ Yes' : '✗ No') . "\n\n";

// Backup current .env
$backupFile = $envFile . '.backup.' . date('YmdHis');
if (!copy($envFile, $backupFile)) {
    echo "✗ Cannot create backup!\n";
    exit(1);
}

echo "✓ Backup created: {$backupFile}\n\n";

// Determine what to add/update
$modified = false;
$lines = explode("\n", $envContent);
$newLines = [];

foreach ($lines as $line) {
    $trimmed = trim($line);
    
    // Update existing ZIGGO_API_KEY
    if (strpos($trimmed, 'ZIGGO_API_KEY=') === 0) {
        // Extract current value
        preg_match('/^ZIGGO_API_KEY=(.*)$/', $trimmed, $matches);
        $currentValue = $matches[1] ?? '';
        
        if ($currentValue !== $apiKey) {
            echo "Updating ZIGGO_API_KEY\n";
            echo "  Old: " . ($currentValue ?: '(empty)') . "\n";
            echo "  New: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -10) . "\n";
            $newLines[] = "ZIGGO_API_KEY={$apiKey}";
            $modified = true;
        } else {
            echo "✓ ZIGGO_API_KEY already correct\n";
            $newLines[] = $line;
        }
    }
    // Update existing ZIGGO_V2_API_KEY
    elseif (strpos($trimmed, 'ZIGGO_V2_API_KEY=') === 0) {
        preg_match('/^ZIGGO_V2_API_KEY=(.*)$/', $trimmed, $matches);
        $currentValue = $matches[1] ?? '';
        
        if ($currentValue !== $apiKey) {
            echo "Updating ZIGGO_V2_API_KEY\n";
            echo "  Old: " . ($currentValue ?: '(empty)') . "\n";
            echo "  New: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -10) . "\n";
            $newLines[] = "ZIGGO_V2_API_KEY={$apiKey}";
            $modified = true;
        } else {
            echo "✓ ZIGGO_V2_API_KEY already correct\n";
            $newLines[] = $line;
        }
    }
    else {
        $newLines[] = $line;
    }
}

// Add keys if they don't exist
if (!$hasZiggoApiKey) {
    echo "\nAdding ZIGGO_API_KEY\n";
    $newLines[] = "";
    $newLines[] = "# Ziggo API Configuration";
    $newLines[] = "ZIGGO_API_KEY={$apiKey}";
    $modified = true;
}

if (!$hasZiggoV2ApiKey) {
    echo "Adding ZIGGO_V2_API_KEY\n";
    if ($hasZiggoApiKey) {
        // Insert after ZIGGO_API_KEY
        $insertIndex = -1;
        foreach ($newLines as $i => $line) {
            if (strpos($line, 'ZIGGO_API_KEY=') !== false) {
                $insertIndex = $i + 1;
                break;
            }
        }
        if ($insertIndex > 0) {
            array_splice($newLines, $insertIndex, 0, ["ZIGGO_V2_API_KEY={$apiKey}"]);
        } else {
            $newLines[] = "ZIGGO_V2_API_KEY={$apiKey}";
        }
    } else {
        $newLines[] = "ZIGGO_V2_API_KEY={$apiKey}";
    }
    $modified = true;
}

// Add V2 URL if not present
if (strpos($envContent, 'ZIGGO_V2_API_URL=') === false) {
    echo "Adding ZIGGO_V2_API_URL\n";
    $newLines[] = "ZIGGO_V2_API_URL=https://api.prod.aws.ziggo.io/v2/api/rfscom/v2";
    $modified = true;
}

if (!$modified) {
    echo "\n✓ No changes needed!\n";
} else {
    // Write new .env
    $newContent = implode("\n", $newLines);
    
    if (file_put_contents($envFile, $newContent) === false) {
        echo "\n✗ Failed to write .env file!\n";
        echo "Restoring backup...\n";
        copy($backupFile, $envFile);
        exit(1);
    }
    
    echo "\n✓ .env file updated successfully!\n\n";
    
    echo "=== Next Steps ===\n";
    echo "1. Clear Laravel config cache: https://api.internetvergelijk.nl/clear_opcache.php\n";
    echo "2. Restart queue workers\n";
    echo "3. Test API: https://api.internetvergelijk.nl/test_ziggo_full_trace.php\n";
}

echo "\n=== Verification ===\n";
$newEnvContent = file_get_contents($envFile);
$ziggoLines = [];
foreach (explode("\n", $newEnvContent) as $line) {
    if (stripos($line, 'ZIGGO') !== false) {
        // Mask the key
        $masked = preg_replace_callback('/=(.{10})[^=]{20,}(.{10})/', function($m) {
            return "={$m[1]}...{$m[2]}";
        }, $line);
        $ziggoLines[] = $masked;
    }
}

if (empty($ziggoLines)) {
    echo "⚠ No ZIGGO configuration found!\n";
} else {
    echo "Configuration in .env:\n";
    foreach ($ziggoLines as $line) {
        echo "  {$line}\n";
    }
}

echo "</pre>";
?>
