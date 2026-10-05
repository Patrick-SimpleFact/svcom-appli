<?php

use App\Collecte\ComparaisonLieux;

it('reconnaît deux écritures du même lieu', function (string $a, string $b) {
    expect((new ComparaisonLieux)->nomsProches($a, $b))->toBeTrue();
})->with([
    ['Théâtre du Chêne Noir', 'Chêne noir'],
    ['Théâtre des Halles', 'THEATRE DES HALLES'],
    ['Théâtre des Halles', 'Theatre des Hales'],
    ['La Manutention', 'Manutention'],
]);

it('distingue des lieux différents', function (string $a, string $b) {
    expect((new ComparaisonLieux)->nomsProches($a, $b))->toBeFalse();
})->with([
    ['Théâtre du Chêne Noir', 'Théâtre des Carmes'],
    ['Théâtre', 'Théâtre du Chêne Noir'],
    ['Salle des fêtes', 'Salle Benoît XII'],
    ['', 'Théâtre'],
]);

it('ramène les adresses à une forme comparable', function () {
    $comparaison = new ComparaisonLieux;

    expect($comparaison->adresseNormalisee('22 r. Roi-René'))->toBe($comparaison->adresseNormalisee('22 rue du Roi René'))
        ->and($comparaison->adresseNormalisee('4 bd St-Michel'))->toBe('4 boulevard saint michel');
});
