<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Package;
use App\Http\Resources\PackageCollection as PackageResource;
use GuzzleHttp\Client;
use App\Models\Postcode;
use App\Http\Controllers\PostcodeController;
use Route;
use Carbon\Carbon;

class PackageController extends Controller
{
  public function get(Request $request) {
    $suppliers = [];
    $max_download = 0;

    $suppliers = PostcodeController::checkPostcode($request->input('postcode'), $request->input('nr'));
    $postcodes = Postcode::where('postcode', '=', $request->input('postcode'))->where('updated_at', '>', Carbon::now()->subHours(3)->toDateTimeString())->get();

    foreach($postcodes as $postcode) {
      if($postcode->max_download > $max_download) {
        $max_download = $postcode->max_download;
      }
    }
    $packages = PackageResource::collection(Package::whereIn('supplier_id', $suppliers)->where('download', '<', $max_download)->with('supplier')->get());

    return $packages;
  }
}
