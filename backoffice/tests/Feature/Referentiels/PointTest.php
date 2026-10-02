<?php

use App\Support\Point;
use Illuminate\Support\Facades\DB;

it('relit exactement une position enregistrée par PostGIS', function () {
    $avignon = new Point(43.9416, 4.8333);

    $hex = DB::scalar('select ?::geography', [$avignon->versEwkt()]);
    $relu = Point::depuisEwkb($hex);

    expect($relu->latitude)->toEqualWithDelta(43.9416, 0.000001)
        ->and($relu->longitude)->toEqualWithDelta(4.8333, 0.000001);
});

it('refuse une position impossible', function () {
    new Point(120, 4);
})->throws(InvalidArgumentException::class);

it('lit aussi le texte EWKT', function () {
    $point = Point::depuisEwkt('SRID=4326;POINT(4.8333000 43.9416000)');

    expect($point->latitude)->toBe(43.9416)->and($point->longitude)->toBe(4.8333);
});
