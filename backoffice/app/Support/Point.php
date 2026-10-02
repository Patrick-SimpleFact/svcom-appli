<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Position géographique (degrés, WGS 84).
 */
final readonly class Point
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException("Position impossible : {$latitude}, {$longitude}");
        }
    }

    /** Texte compris par PostGIS : SRID=4326;POINT(longitude latitude). */
    public function versEwkt(): string
    {
        return sprintf('SRID=4326;POINT(%.7F %.7F)', $this->longitude, $this->latitude);
    }

    /** Lit la valeur renvoyée par PostgreSQL (EWKB en hexadécimal). */
    public static function depuisEwkb(string $hex): self
    {
        $octets = hex2bin($hex);
        $petitBoutiste = ord($octets[0]) === 1;
        $entier = $petitBoutiste ? 'V' : 'N';
        $type = unpack($entier, substr($octets, 1, 4))[1];
        $decalage = ($type & 0x20000000) ? 9 : 5; // SRID présent ou non

        $doubles = unpack($petitBoutiste ? 'e2' : 'E2', substr($octets, $decalage, 16));

        return new self(latitude: $doubles[2], longitude: $doubles[1]);
    }
}
