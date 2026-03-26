<?php

namespace App\Services;

/**
 * @service SoapWebServiceSoapClient
 */
class SoapWebServiceSoapClient
{
    /**
     * The WSDL URI
     *
     * @var string
     */
    public static $_WsdlUri = 'http://api.jonaz.nl/SoapWebService.php?WSDL';
    /**
     * The PHP SoapClient object
     *
     * @var object
     */
    public static $_Server = null;

    /**
     * Send a SOAP request to the server
     *
     * @param string $method The method name
     * @param array $param The parameters
     * @return mixed The server response
     */
    public static function _Call($method, $param)
    {
        if (is_null(self::$_Server))
            self::$_Server = new \SoapClient(self::$_WsdlUri);
        return self::$_Server->__soapCall($method, $param);
    }

    /**
     * Availability Check of Jonaz V3
     *
     * @param string $zipcode Zipcode
     * @param integer $housenumber Housenumber
     * @param string $extension Housenumber extension
     * @return AvailabilitycheckResult Returns result of Availabilitycheck
     */
    public function AvailabilityCheck3($zipcode, $housenumber, $extension)
    {
        return self::_Call('AvailabilityCheck3', array(
            $zipcode,
            $housenumber,
            $extension
        ));
    }
}
