<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProviderCollection;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;

class PackageCollection extends JsonResource
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'name' => $this->name,
            'package_link' => $this->package_link,
            'supplier' => new ProviderCollection($this->supplier),
            'download' => $this->download,
            'upload' => $this->upload,
            'channels' => $this->channels,
            'channels_hd' => $this->channels_hd,
            'call_costs' => $this->call_costs,
            'price' => $this->price,
            'sale_months' => $this->sale_months,
            'price_per_year' => $this->price_per_year,
            'price_per_month' => $this->price_per_month,
            'extra' => $this->extra,
            'type' => $this->type,
            'action_text' => $this->action_text,
            'network_type' => $this->network_type,
            'modem' => $this->modem,
            'own_email' => $this->own_email,
            'webmail' => $this->webmail,
            'channels_4k' => $this->channels_4k,
            'channels_radio' => $this->channels_radio,
            'extra_tv_connections' => $this->extra_tv_connections,
            'tvkijken_app' => $this->tvkijken_app,
            'program_playback' => $this->program_playback,
            'recording' => $this->recording,
            'live_pause' => $this->live_pause,
            'missed_start' => $this->missed_start,
            'video_on_demand' => $this->video_on_demand,
            'netflix' => $this->netflix,
            'videoland' => $this->videoland,
            'disney' => $this->disney,
            'youtube' => $this->youtube,
            'call_starting_tarif' => $this->call_starting_tarif,
            'call_cost_fixed_numbers' => $this->call_cost_fixed_numbers,
            'call_cost_mobile' => $this->call_cost_mobile,
            'call_cost_mutual' => $this->call_cost_mutual,
            'two_phone_numbers' => $this->two_phone_numbers,
            'keep_number' => $this->keep_number,
            'wifiservice' => $this->wifiservice,
            'cost_mechanic' => $this->cost_mechanic,
            'transfer_service' => $this->transfer_service,
            'installation_service' => $this->installation_service,
            'reflection_period' => $this->reflection_period,
            'contract_length' => $this->contract_length,
            'oneoff_connection_costs' => $this->oneoff_connection_costs,
            'gift' => $this->gift,
            'extra_tv_packages' => $this->extra_tv_packages,
            'call_bundle_options' => $this->call_bundle_options,
            'max_boxes' => $this->max_boxes,
            'channels_list' => ChannelResource::collection($this->channels_list),
            'channels_list_hd' => ChannelResource::collection($this->channels_list_hd),
            'channels_list_4k' => ChannelResource::collection($this->channels_list_4k),
            'unlimited_nl' => $this->unlimited_nl,
            'unlimited_nl_mobile' => $this->unlimited_nl_mobile,
            'unlimited_nl_fixed' => $this->unlimited_nl_fixed
        ];
    }
}
