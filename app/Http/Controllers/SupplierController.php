<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Http\Resources\ProviderCollection as SupplierResource;
use GuzzleHttp\Client;
use Route;

class SupplierController extends Controller
{
  public function get(Request $request) {
    return SupplierResource::collection(Supplier::all());
  }
}
