<?php

namespace App\Services;

/**
 * Struct FullAddress
 *
 * @pw_element string $street Street
 * @pw_element int $housenumber Housenumber
 * @pw_element string $extension Housenumber extension
 * @pw_element string $zipcode Zipcode
 * @pw_element string $city City
 * @pw_complex FullAddress
 */
class FullAddress
{
    /**
     * Street
     *
     * @var string
     */
    public $street;
    /**
     * Housenumber
     *
     * @var int
     */
    public $housenumber;
    /**
     * Housenumber extension
     *
     * @var string
     */
    public $extension;
    /**
     * Zipcode
     *
     * @var string
     */
    public $zipcode;
    /**
     * City
     *
     * @var string
     */
    public $city;
}
