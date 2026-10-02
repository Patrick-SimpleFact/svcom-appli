<?php

use Illuminate\Support\Facades\DB;

it('affiche la page d’accueil', function () {
    $this->get('/')->assertOk();
});

it('utilise la base PostgreSQL de test', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('spettacoli_test');
});

it('dispose des extensions postgis, pg_trgm et unaccent', function () {
    $extensions = DB::table('pg_extension')->pluck('extname');

    expect($extensions)->toContain('postgis', 'pg_trgm', 'unaccent');
});

it('calcule une distance avec PostGIS', function () {
    // Avignon → Paris : environ 577 km à vol d'oiseau
    $metres = DB::scalar(
        "select ST_Distance('SRID=4326;POINT(4.8057 43.9493)'::geography, 'SRID=4326;POINT(2.3522 48.8566)'::geography)"
    );

    expect(round($metres / 1000))->toEqual(577.0);
});

it('rapproche une faute de frappe et ignore les accents', function () {
    expect((float) DB::scalar("select similarity('obsevance', 'observance')"))->toBeGreaterThan(0.3)
        ->and(DB::scalar("select unaccent('Théâtre')"))->toBe('Theatre');
});
