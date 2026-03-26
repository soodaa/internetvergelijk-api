<?php

namespace App\Libraries;

use GuzzleHttp\Client;

use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Postcode;
use App\Models\Supplier;
use Exception;
use Illuminate\Support\Facades\Log;

class ZiggoPromotion
{

    private $_base;
    public $name = 'ziggo';
    

    function __construct()
    {
        $this->_base = 'https://promo-api.prod.dcat.ziggo.io/v1/check';
        $this->_guzzle = new Client([
            'http_errors' => false,
            'timeout' => 6,
            'connect_timeout' => 6, 
            'headers' => [
                'x-api-key' => env('ZIGGO_API_KEY')            
            ]
        ]);
        $this->supplier = Supplier::where('name', '=', 'Ziggo')->first();

    }

    public function request($address, $result, $verbose = 0)
    {
        try {

        
            $response = $this->_guzzle->request('POST', $this->_base, [
                'timeout' => 15,
            'body'    => json_encode( [
                'postalcode' => '1234AB',
                'num' => '12',
                'ext' => 'asd'
             ] ),
        ]); 

            return $response->getBody();

            if ($response->getStatusCode() == 200) {

                $body = json_decode($response->getBody());
    
                if ($verbose) {
                    dump($body->data);
                }
    
                if (isset($body)) {

                    if (! count(get_object_vars($body->data))) {
                        return false;
                    }

                    if ($body->data->FOOTPRINT == "fZiggo" || $body->data->FOOTPRINT == "fUPC" || $body->data->EXTERNALFOOTPRINT == "") {
                        
                        collect($body->data->ADDRESSES)->where('CPRHOUSEEXTENSION', $address->extension)->each(function ($ziggoAddress) use ($result, $verbose) {

                            $url = $this->_base . '/availability/' . $ziggoAddress->ID;

                            $response = $this->_guzzle->request('GET', $url, []);
                            $availability = json_decode($response->getBody()->getContents());

                            if ($verbose) {
                                dump($availability);
                            }

                            if ($availability->data->IS_GIGA_AVAILABLE) {

                                if ($availability->data->IS_GIGA_AVAILABLE->isAvailable == true) {

                                    $result->kabel_max = 1000;
                                    $result->max_download = 1000;

                                    return;
                                }
                            }
                            if ($availability->data->IS_FAST_AVAILABLE) {

                                if ($availability->data->IS_FAST_AVAILABLE->isAvailable == true) {

                                    $result->kabel_max = 350;
                                    $result->max_download = 350;

                                    return;
                                }
                            }
                        });

                        $result->save();
    
                        return $result;
                    }
                }
            }

            return false;

        } catch (\GuzzleHttp\Exception\ClientException $e) {

            dump($e);

            throw new HttpResponseException(response()->json('Something went wrong', 500));
        } catch (Exception $e) {

            dump($e);
        }
    }
}
