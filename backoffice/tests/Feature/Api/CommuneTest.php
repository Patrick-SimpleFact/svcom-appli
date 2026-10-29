<?php

use Database\Seeders\ParametresSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Nom de la commune d'une position, quand le téléphone ne le donne pas (Android) : commune qui contient le point (geo.api.gouv.fr). */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->entetes = ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'];
});

it('donne la commune qui contient le point, position arrondie à ~100 m', function () {
    Http::fake(['geo.api.gouv.fr/*' => Http::response([['nom' => 'Paris']])]);

    $this->getJson('/v1/commune?lat=48.832213&lon=2.287512', $this->entetes)->assertOk()->assertExactJson(['commune' => 'Paris']);

    Http::assertSent(fn (Request $r) => $r['lat'] == 48.832 && $r['lon'] == 2.288 && $r['fields'] === 'nom');
});

it('répond null hors d’une commune ou si le service est en panne', function () {
    Http::fake(['geo.api.gouv.fr/*' => Http::sequence()->push([])->push('panne', 503)->whenEmpty(fn () => throw new RuntimeException('réseau'))]);

    foreach (range(1, 3) as $essai) {
        $this->getJson('/v1/commune?lat=46&lon=-3', $this->entetes)->assertOk()->assertExactJson(['commune' => null]);
    }
});

it('exige une position valide', function () {
    $this->getJson('/v1/commune?lat=95&lon=2', $this->entetes)->assertUnprocessable();
});
