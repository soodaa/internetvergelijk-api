<?php

$curl = curl_init();

$postalCode = $_GET['postalCode'];
$houseNumber = $_GET['houseNumber'];
$houseNumberAddition = $_GET['houseNumberAddition'];

$url = 'https://vispcoveragecheckapi.glasoperator.nl/Generic201402/CoverageCheck.svc/urljson/CheckDslCoverage?clientId=internetvergelijk.vispcoverage&clientSecret=07wrqQJZjx8TdJ77vMkEknP0TYhB&ispId=VDF&postalCode=' . $postalCode .'&houseNumber=' . $houseNumber . ($houseNumberAddition ? '&houseNumberAddition=' . $houseNumberAddition : '');

curl_setopt_array($curl, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
));
curl_setopt($curl, CURLOPT_VERBOSE, true);


$response = curl_exec($curl);
curl_close($curl);
echo $response;
