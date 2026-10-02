<?php

use App\Support\CodeInsee;

it('ramène un arrondissement à sa commune', function (string $code, string $commune) {
    expect(CodeInsee::commune($code))->toBe($commune);
})->with([
    ['75101', '75056'],
    ['75109', '75056'],
    ['75120', '75056'],
    ['69381', '69123'],
    ['13201', '13055'],
    ['13216', '13055'],
    ['84007', '84007'],
    ['75056', '75056'],
]);
