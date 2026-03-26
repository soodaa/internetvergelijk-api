<?php
/**
 * Debug speedCheck API
 */

echo "<h1>SpeedCheck API Debug</h1>";
echo "<pre>";

// Get parameters
$postcode = $_GET['postcode'] ?? '2728AA';
$number = $_GET['nr'] ?? '3';
$extension = $_GET['nr_add'] ?? '';

echo "=== Testing Address ===\n";
echo "Postcode: {$postcode}\n";
echo "Number: {$number}\n";
echo "Extension: " . ($extension ?: '(none)') . "\n\n";

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Postcode;
use App\Models\Supplier;

// Find Ziggo supplier
$ziggo = Supplier::where('name', 'Ziggo')->first();

if (!$ziggo) {
    echo "✗ Ziggo supplier not found!\n";
    exit(1);
}

echo "=== Ziggo Supplier ===\n";
echo "ID: {$ziggo->id}\n";
echo "Name: {$ziggo->name}\n";
echo "max_download: {$ziggo->max_download}\n\n";

// Check for existing records
echo "=== Database Check ===\n";

$records = Postcode::where('postcode', $postcode)
    ->where('house_number', $number)
    ->where('house_nr_add', $extension)
    ->where('supplier_id', $ziggo->id)
    ->get();

echo "Records found: " . $records->count() . "\n\n";

if ($records->count() > 0) {
    foreach ($records as $record) {
        echo "Record ID: {$record->id}\n";
        echo "  kabel_max: {$record->kabel_max}\n";
        echo "  max_download: {$record->max_download}\n";
        echo "  updated_at: {$record->updated_at}\n";
        
        $age = now()->diffInMinutes($record->updated_at);
        echo "  Age: {$age} minutes (" . round($age/60, 1) . " hours)\n";
        
        if ($age < 720) {
            echo "  ⚠ Cache is FRESH (< 12 hours) - Will use cached data!\n";
        } else {
            echo "  ✓ Cache is OLD (> 12 hours) - Will fetch new data\n";
        }
        echo "\n";
    }
} else {
    echo "✓ No records found - Will fetch fresh data from API\n\n";
}

// Simulate what speedCheck API does
echo "=== Simulating speedCheck API ===\n\n";

$postcode_supplier = Postcode::with(['supplier' => function($query) {
    $query->select('id', 'name');
}])
    ->where('postcode', $postcode)
    ->where('house_number', $number)
    ->where('house_nr_add', $extension)
    ->get()
    ->unique('supplier_id');

echo "Total providers found: " . $postcode_supplier->count() . "\n\n";

foreach ($postcode_supplier as $pc) {
    $pc->refresh();
    
    if ($pc->supplier) {
        echo "Provider: {$pc->supplier->name}\n";
        echo "  DSL: {$pc->adsl_max}\n";
        echo "  Glasvezel: {$pc->glasvezel_max}\n";
        echo "  Kabel: {$pc->kabel_max}\n";
        echo "  Updated: {$pc->updated_at}\n\n";
    }
}

// Test URL
$apiUrl = "https://api.internetvergelijk.nl/api/speedcheck?postcode={$postcode}&nr={$number}";
if ($extension) {
    $apiUrl .= "&nr_add={$extension}";
}

echo "=== Test This URL ===\n";
echo "{$apiUrl}\n\n";

echo "=== Actions ===\n";
echo "If showing old data:\n";
echo "1. Delete records: https://api.internetvergelijk.nl/delete_old_ziggo.php\n";
echo "2. Or delete specific: Postcode::where('id', {$records->first()->id})->delete()\n";

echo "</pre>";
?>
