<?php

namespace App\Libraries;

use GuzzleHttp\Client;

use Illuminate\Http\Exceptions\HttpResponseException;

class PostcodeApi {
  private $_key;
  private $_base;

  function __construct () {
    $this->_key = env('POSTCODE_API_KEY');
    $this->_base = 'https://'.env('POSTCODE_API_BASE_URI');
    $this->_guzzle = new Client;
  }

  public function request(string $postcode, string $number) {
    try {
      $response = $this->_guzzle->request('GET', $this->_base.$postcode.'/'.$number, ['headers' => [
        'X-Api-Key' => $this->_key
        ]]);
    } catch( \GuzzleHttp\Exception\ClientException $e ) {
      return false;
    }

    if ($response->getStatusCode() == 200 ) {
      $body = json_decode($response->getBody());
      if (isset($body)){
          return $body;
      } else{
          return false;
      }
    } else {
      return false;
    }
  }
}
