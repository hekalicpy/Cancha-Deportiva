<?php
/** Location helper used by notifications and the public location endpoint. */
final class VenueLocation
{
    public static function details(): array
    {
        $venue = 'Arena 7 - Sede Colegio Melitón Carvajal';
        $address = 'Av. Prol. Iquitos 2139, Lince 15046, Lima, Perú';
        // Coordinates from the venue marker (!3d/!4d), not the map viewport.
        // Verified against this Google Maps place listing on 2026-10-11.
        $latitude = -12.084917;
        $longitude = -77.0282236;
        $destination = $latitude . ',' . $longitude;
        $placeUrl = 'https://www.google.com/maps/place/Arena+7+-+Sede+Colegio+Melit%C3%B3n+Carvajal/'
            . '@-12.084917,-77.0282236,17z/data=!3m1!4b1!4m6!3m5!1s0x9105c98ab2a3da01:0x7766de8c0d081607'
            . '!8m2!3d-12.084917!4d-77.0282236!16s%2Fg%2F11h3g74xl0';
        return [
            'nombre' => 'Arena 7',
            'local' => $venue,
            'direccion' => $address,
            'latitud' => $latitude,
            'longitud' => $longitude,
            'mapa' => $placeUrl,
            'como_llegar' => 'https://www.google.com/maps/dir/?' . http_build_query(
                ['api' => 1, 'destination' => $destination], '', '&', PHP_QUERY_RFC3986
            ),
        ];
    }
}
