<?php

use App\Actions\VerifierNonRegression;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Support\Point;
use App\Support\Texte;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->journee = base_path('tests/Fixtures/non-regression');
    $this->lieu = Lieu::factory()->create(['position' => new Point(43.9497, 4.8060)]);
    $this->seance = fn (string $titre, string $heure, array $attributs = []) => Representation::factory()->create([
        'spectacle_id' => Spectacle::factory()->create(['titre' => $titre])->id,
        'lieu_id' => $this->lieu->id,
        'debut' => CarbonImmutable::parse("2026-10-17 {$heure}", 'Europe/Paris'),
        ...$attributs,
    ]);
});

it('retrouve les séances du POC même si le titre du catalogue diffère, et explique les retraits', function () {
    ($this->seance)('Sacrée soirée', '21:00');
    ($this->seance)('Concert de fin de masterclass', '17:00', ['statut' => StatutRepresentation::Retiree]);

    $ville = app(VerifierNonRegression::class)->handle($this->journee)['villes'][0];

    expect($ville)->toMatchArray(['poc' => 4, 'retrouvees' => 1, 'retirees' => 1, 'absentes' => 2, 'catalogue' => 1, 'en_plus' => 0])
        ->and(array_column($ville['lignes'], 'etat'))->toBe(['retrouvee', 'retiree', 'absente', 'absente']);
});

it('ne rapproche pas deux séances trop éloignées dans la journée', function () {
    ($this->seance)('Un spectacle annulé', '15:00');

    $ville = app(VerifierNonRegression::class)->handle($this->journee)['villes'][0];

    expect($ville['lignes'][2]['etat'])->toBe('absente')
        ->and($ville['en_plus'])->toBe(1);
});

it('compte une période qui couvre la journée, quelle que soit l’heure du POC', function () {
    ($this->seance)('Les Petites Formes', '00:00', ['type' => TypeRepresentation::Periode, 'debut' => null, 'date_locale' => '2026-10-16', 'date_fin' => '2026-10-18']);

    $ville = app(VerifierNonRegression::class)->handle($this->journee)['villes'][0];

    expect($ville['lignes'][3]['etat'])->toBe('retrouvee');
});

it('ignore les représentations hors du rayon de la ville', function () {
    ($this->seance)('Sacrée soirée', '21:00', ['lieu_id' => Lieu::factory()->create(['position' => new Point(43.2965, 5.3698)])->id]);

    $ville = app(VerifierNonRegression::class)->handle($this->journee)['villes'][0];

    expect($ville['retrouvees'])->toBe(0)->and($ville['catalogue'])->toBe(0);
});

it('rapproche les titres comme le POC', function (string $poc, string $catalogue, bool $proches) {
    expect(VerifierNonRegression::titresProches($poc, Texte::normaliser($catalogue)))->toBe($proches);
})->with([
    'identiques' => ['Game Over', 'Game Over', true],
    'lieu ajouté par la source' => ["Mamouchka, l'Apprentie Sorcière - Café Théâtre de la Porte d'Italie, Avignon", "Mamouchka, l'apprentie socière", true],
    'sous-titre' => ['STARS EN DUO - STARS EN DUO - Hommage à C. Dion et F. Pagny', 'Stars en Duo', true],
    'différents' => ['Hamlet', 'Le Tout Petit Chaperon Rouge', false],
]);

it('signale par son code de retour une ville sous le seuil', function () {
    ($this->seance)('Sacrée soirée', '21:00');

    $this->artisan('collecte:non-regression', ['journee' => $this->journee, '--details' => true])
        ->expectsOutputToContain('25 % (sous le seuil)')
        ->assertFailed();

    $this->artisan('collecte:non-regression', ['journee' => $this->journee, '--seuil' => 20])->assertSuccessful();
});
