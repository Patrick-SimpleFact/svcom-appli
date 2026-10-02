<?php

use App\Support\Texte;

it('normalise un texte : minuscules, sans accents ni ponctuation', function (string $brut, string $attendu) {
    expect(Texte::normaliser($brut))->toBe($attendu);
})->with([
    ['Théâtre de l’Observance', 'theatre de l observance'],
    ['  Saint-Denis  ', 'saint denis'],
    ['Les c*ns', 'les c ns'],
    ['« Spin & Spells »', 'spin spells'],
    ['Pointe-à-Pitre', 'pointe a pitre'],
]);
