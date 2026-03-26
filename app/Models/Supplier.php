<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'logo',
        'access_token',
        'client_id',
        'client_secret',
        'max_download',
        'is_active',
        'asdl_max',
        'glasvezel_max',
        'kabel_max'
    ];

}
