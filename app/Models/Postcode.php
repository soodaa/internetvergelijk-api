<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;

class Postcode extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'postcode',
        'supplier_id',
        'updated_at',
        'max_download',
        'house_nr_add',
        'house_number',
        'adsl_max',
        'glasvezel_max'
    ];

    public function supplier()
    {
        return $this->hasOne(
            'App\Models\Supplier',
            'id',
            'supplier_id'
        );
    }

    public function getSupplierName() {
        $supplier = Supplier::where('id', $this->supplier_id)->first();
        if($supplier) {
            return $supplier->name;
        } else {
            return '';
        }
    }
}
