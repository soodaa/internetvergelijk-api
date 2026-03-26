<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Feed extends Model
{

    /*
    |--------------------------------------------------------------------------
    | GLOBAL VARIABLES
    |--------------------------------------------------------------------------
    */

    protected $table = 'feeds';
    protected $primaryKey = 'id';
    protected $guarded = ['id'];
    protected $fillable = [
        'name',
        'link',
        'delay',
        'supplier_id',
        'package_name',
        'package_link',
        'supplier',
        'download',
        'upload',
        'channels',
        'channels_hd',
        'call_costs',
        'price',
        'sale_months',
        'price_per_month',
        'price_per_year',
        'fetched_at',
        'file'
    ];
    // protected $hidden = [];
    // protected $dates = [];

    /*
    |--------------------------------------------------------------------------
    | FUNCTIONS
    |--------------------------------------------------------------------------
    */

    public function setFileAttribute($value): void
    {
        if ($value instanceof UploadedFile) {
            $path = $value->store('', ['disk' => 'local']);
            $this->attributes['file'] = $path;
            return;
        }

        $this->attributes['file'] = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function supplier()
    {
        return $this->belongsTo('App\Models\Supplier');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | MUTATORS
    |--------------------------------------------------------------------------
    */
}
