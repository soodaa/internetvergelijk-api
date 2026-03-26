<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Supplier;

class Package extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'package_link',
        'supplier_id',
        'download',
        'upload',
        'call_costs',
        'price',
        'sale_months',
        'price_per_month',
        'price_per_year',
        'extra',
        'type',
        'action_text',
        'network_type',
        'modem',
        'own_email',
        'webmail',
        'channels_4k',
        'channels_radio',
        'extra_tv_connections',
        'tvkijken_app',
        'program_playback',
        'recording',
        'live_pause',
        'missed_start',
        'video_on_demand',
        'netflix',
        'videoland',
        'disney',
        'youtube',
        'call_starting_tarif',
        'call_cost_fixed_numbers',
        'call_cost_mobile',
        'call_cost_mutual',
        'two_phone_numbers',
        'keep_number',
        'wifiservice',
        'cost_mechanic',
        'transfer_service',
        'installation_service',
        'reflection_period',
        'contract_length',
        'oneoff_connection_costs',
        'gift',
        'extra_tv_packages',
        'call_bundle_options',
        'max_boxes',
        'unlimited_nl',
        'unlimited_nl_mobile',
        'unlimited_nl_fixed'
    ];

    public function supplier() {
        return $this->belongsTo('App\Models\Supplier');
    }

    public function channels_list() {
        return $this->belongsToMany(Channel::class);
    }
    
    public function channels_list_hd() {
        return $this->belongsToMany(Channel::class, 'channelhd_package');
    }

    public function channels_list_4k() {
        return $this->belongsToMany(Channel::class, 'channel4k_package');
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
