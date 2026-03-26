<?php

namespace App\Http\Controllers;

use App\Models\SearchRequest;
use Illuminate\Http\Request;

class SearchRequestController extends Controller
{
    public function getResults(Request $request, $id)
    {
        $search = SearchRequest::find($id);
    }
}
