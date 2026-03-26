<?php
/**
 * Delete old Ziggo cache records
 */

echo "<h1>Delete Old Ziggo Cache</h1>";
echo "<pre>";

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Postcode;
use Illuminate\Support\Facades\DB;

echo "=== Current Ziggo Records ===\n\n";

// Find all Ziggo records (supplier_id = 4)
$records = Postcode::where('supplier_id', 4)
    ->orderBy('updated_at', 'desc')
    ->get();

echo "Total Ziggo records: " . $records->count() . "\n\n";

// Group by postcode
$grouped = $records->groupBy('postcode');

echo "Unique postcodes: " . $grouped->count() . "\n\n";

// Show sample records
echo "Sample records (first 10):\n";
foreach ($records->take(10) as $record) {
    echo sprintf(
        "ID: %d | %s %s%s | kabel: %d | max: %d | updated: %s\n",
        $record->id,
        $record->postcode,
        $record->house_number,
        $record->house_nr_add ? "-{$record->house_nr_add}" : "",
        $record->kabel_max,
        $record->max_download,
        $record->updated_at
    );
}

echo "\n=== Records with kabel_max < 2000 ===\n\n";

$oldRecords = Postcode::where('supplier_id', 4)
    ->where('kabel_max', '<', 2000)
    ->get();

echo "Count: " . $oldRecords->count() . "\n\n";

if ($oldRecords->count() > 0) {
    echo "First 20 old records:\n";
    foreach ($oldRecords->take(20) as $record) {
        echo sprintf(
            "ID: %d | %s %s%s | kabel: %d | updated: %s\n",
            $record->id,
            $record->postcode,
            $record->house_number,
            $record->house_nr_add ? "-{$record->house_nr_add}" : "",
            $record->kabel_max,
            $record->updated_at
        );
    }
}

echo "\n=== ACTION: Delete old records? ===\n\n";

if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    
    echo "Deleting records with kabel_max < 2000...\n";
    
    $deleted = Postcode::where('supplier_id', 4)
        ->where('kabel_max', '<', 2000)
        ->delete();
    
    echo "✓ Deleted {$deleted} records\n\n";
    
    echo "Remaining Ziggo records: " . Postcode::where('supplier_id', 4)->count() . "\n";
    
    echo "\n✓✓✓ Done! ✓✓✓\n\n";
    echo "Now test the API again:\n";
    echo "https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=1\n";
    
} else {
    
    echo "To DELETE all old Ziggo records (kabel_max < 2000), add ?confirm=yes to the URL:\n\n";
    echo "<a href='?confirm=yes' style='color: red; font-weight: bold;'>CLICK HERE TO DELETE OLD RECORDS</a>\n\n";
    echo "Or visit: " . $_SERVER['REQUEST_URI'] . "?confirm=yes\n\n";
    echo "⚠ This will delete {$oldRecords->count()} records!\n";
    
}

echo "\n=== Alternative: Delete ALL Ziggo records ===\n\n";

if (isset($_GET['confirm']) && $_GET['confirm'] === 'all') {
    
    echo "Deleting ALL Ziggo records...\n";
    
    $deleted = Postcode::where('supplier_id', 4)->delete();
    
    echo "✓ Deleted {$deleted} records\n\n";
    echo "✓✓✓ All Ziggo cache cleared! ✓✓✓\n\n";
    echo "Now test with a fresh address - it will fetch new data from V2 API:\n";
    echo "https://api.internetvergelijk.nl/api/speedcheck?postcode=2723AB&nr=106\n";
    
} else {
    echo "To DELETE ALL Ziggo records and start fresh, visit:\n";
    echo "<a href='?confirm=all' style='color: red; font-weight: bold;'>DELETE ALL ZIGGO CACHE</a>\n\n";
    echo "Or: " . $_SERVER['REQUEST_URI'] . "?confirm=all\n\n";
    echo "⚠ This will delete ALL {$records->count()} Ziggo records!\n";
}

echo "</pre>";
?>
