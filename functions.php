<?php
function get_met_alerts($lat, $lon) {
    $url = "https://api.met.no/weatherapi/metalerts/1.1/.json?lat=$lat&lon=$lon";
    $context = stream_context_create(["http" => ["header" => "User-Agent: Vaervakt.no-Project patrick@vaervakt.no"]]);
    $response = @file_get_contents($url, false, $context);
    return $response ? json_decode($response, true) : null;
}

function get_met_forecast($lat, $lon) {
    $url = "https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=$lat&lon=$lon";
    $context = stream_context_create(["http" => ["header" => "User-Agent: Vaervakt.no-Project patrick@vaervakt.no"]]);
    $response = @file_get_contents($url, false, $context);
    return $response ? json_decode($response, true) : null;
}
?>