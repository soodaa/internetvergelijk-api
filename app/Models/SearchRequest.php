<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchRequest extends Model
{
    public function done()
    {
        return $this->open_requests == 0;
    }
}
