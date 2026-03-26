<?php
/**
 * Delete test address from database to force fresh API call
 */

echo "<h1>Delete Test Address</h1>";
echo "<pre>";

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Postcode;
use App\Models\SearchRequest;
use Illuminate\Support\Facades\Artisan;

// Optional filters
$postcode = $_GET['postcode'] ?? null;
$number = $_GET['nr'] ?? null;
$extension = $_GET['nr_add'] ?? null;
$supplier = $_GET['supplier'] ?? null;

// Normalize extension (same as providers do)
$normalizedExt = null;
if ($extension !== null) {
    $normalizedExt = trim((string)$extension);
    $normalizedExt = ($normalizedExt === '') ? null : $normalizedExt;
}

echo "=== Deleting cached addresses ===\n";
echo "Filters applied:\n";
echo "- Postcode : " . ($postcode ?? '[ALL]') . "\n";
echo "- Number   : " . ($number ?? '[ALL]') . "\n";
echo "- Ext      : " . ($normalizedExt ?? '[ALL]') . "\n";
echo "- Supplier : " . ($supplier ?? '[ALL] (by name)') . "\n\n";

$addressQuery = Postcode::query();

if ($postcode) {
    $addressQuery->where('postcode', $postcode);
}

if ($number) {
    $addressQuery->where('house_number', $number);
}

if ($extension !== null) {
    if ($normalizedExt === null) {
        $addressQuery->whereNull('house_nr_add');
    } else {
        $addressQuery->where('house_nr_add', $normalizedExt);
    }
}

if ($supplier) {
    $addressQuery->whereHas('supplier', function ($q) use ($supplier) {
        $q->where('name', $supplier);
    });
}

$srQuery = SearchRequest::query();

if ($postcode) {
    $srQuery->where('postcode', $postcode);
}

if ($number) {
    $srQuery->where('house_number', $number);
}

if ($extension !== null) {
    if ($normalizedExt === null) {
        $srQuery->whereNull('house_nr_add');
    } else {
        $srQuery->where('house_nr_add', $normalizedExt);
    }
}

$total = $addressQuery->count();
echo "Records found: {$total}\n\n";

if ($total > 0) {
    if (! $postcode && ! $number && $extension === null && ! $supplier) {
        Postcode::truncate();
        SearchRequest::truncate();
        echo "Deleted rows : all (truncate)\n";
    } else {
        $deleted = $addressQuery->delete();
        $deletedSr = $srQuery->count();
        if ($deletedSr > 0) {
            $srQuery->delete();
        }
        echo "Deleted postcodes : {$deleted}\n";
        echo "Deleted search requests : {$deletedSr}\n";
    }

    echo "✅ Cache cleared.\n\n";
} else {
    echo "✅ No records matched the filters.\n\n";
}

echo "=== Queue Workers ===\n";
echo "Sending queue:restart signal...\n";
try {
    Artisan::call('queue:restart');
    echo trim(Artisan::output()) . "\n";
    echo "✅ queue:restart call dispatched.\n\n";
} catch (\Throwable $e) {
    echo "⚠️  Unable to restart queue workers automatically: " . $e->getMessage() . "\n\n";
}

echo "</pre>";
?>
