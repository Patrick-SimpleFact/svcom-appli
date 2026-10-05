<?php

use App\Actions\FusionnerLieux;
use App\Actions\RattacherLieu;
use App\Collecte\AnnonceNormalisee;
use App\Enums\FileATraiter;
use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\LieuSource;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\SourceFacticeSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Http;

/** Annonce d'exemple ; seuls les champs du lieu changent d'un test à l'autre. */
function annonceLieu(array $lieu, string $id = 'A-1'): AnnonceNormalisee
{
    return new AnnonceNormalisee(...[
        'identifiantExterne' => $id,
        'titre' => 'Une pièce de théâtre',
        'debut' => CarbonImmutable::parse('2026-10-17 20:30', 'Europe/Paris'),
        'heureConnue' => true,
        'lien' => 'https://exemple.fr/'.$id,
        ...$lieu,
    ]);
}

/** Réponse de la Base Adresse Nationale pour une adresse trouvée. */
function reponseBan(float $lat, float $lon, string $codeInsee = '84007', string $type = 'housenumber', float $score = 0.95): array
{
    return ['features' => [[
        'geometry' => ['type' => 'Point', 'coordinates' => [$lon, $lat]],
        'properties' => ['score' => $score, 'type' => $type, 'citycode' => $codeInsee],
    ]]];
}

function lieuxAVerifier()
{
    return ElementATraiter::where('file', FileATraiter::LieuAVerifier);
}

beforeEach(function () {
    $this->seed([SourcesSeeder::class, SourceFacticeSeeder::class]);
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->billetreduc = Source::firstWhere('code', 'billetreduc');

    $this->avignon = Ville::create([
        'nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84',
        'codes_postaux' => ['84000'], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris',
    ]);

    // Lieu du référentiel du Ministère.
    $this->cheneNoir = Lieu::create([
        'nom' => 'Théâtre du Chêne noir', 'type' => TypeLieu::Theatre, 'adresse' => '8 bis r. Sainte-Catherine', 'code_postal' => '84000',
        'ville_id' => $this->avignon->id, 'position' => new Point(43.950518, 4.809771), 'precision_position' => PrecisionPosition::Exacte,
        'ref_ministere' => 'COMP_84007_21319',
    ]);

    $this->rattacher = fn (AnnonceNormalisee $annonce, ?Source $source = null) => app(RattacherLieu::class)->handle($annonce, $source ?? $this->fnac);
});

it('rattache au référentiel un lieu au nom proche situé à moins de 200 m', function () {
    // « Chêne Noir » à ≈ 90 m du point du Ministère.
    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Chêne Noir', 'lieuVille' => 'Avignon', 'lieuLatitude' => 43.9513, 'lieuLongitude' => 4.8094]));

    expect($lieu->is($this->cheneNoir))->toBeTrue()
        ->and(Lieu::count())->toBe(1)
        ->and(lieuxAVerifier()->count())->toBe(0);
});

it('ne confond pas deux salles voisines aux noms différents', function () {
    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Théâtre des Carmes', 'lieuVille' => 'Avignon', 'lieuLatitude' => 43.9510, 'lieuLongitude' => 4.8100]));

    expect($lieu->is($this->cheneNoir))->toBeFalse()
        ->and(Lieu::count())->toBe(2);
});

it('ne rapproche pas sur un nom trop vague (« Théâtre » seul)', function () {
    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Théâtre', 'lieuVille' => 'Avignon', 'lieuLatitude' => 43.9506, 'lieuLongitude' => 4.8098]));

    expect($lieu->is($this->cheneNoir))->toBeFalse();
});

it('réunit la même salle placée à 400 m par deux sources, grâce à l’adresse', function () {
    $a = ($this->rattacher)(annonceLieu([
        'lieuNom' => 'Théâtre des Halles', 'lieuAdresse' => '22 rue du Roi René', 'lieuCodePostal' => '84000', 'lieuVille' => 'Avignon',
        'lieuLatitude' => 43.9455, 'lieuLongitude' => 4.8100,
    ]), $this->billetreduc);

    // Fnac : nom écrit autrement, adresse abrégée, position à ≈ 400 m.
    $b = ($this->rattacher)(annonceLieu([
        'lieuNom' => 'Halles (Théâtre des) - Salle Chapelle', 'lieuAdresse' => '22 r. Roi-René', 'lieuCodePostal' => '84000', 'lieuVille' => 'AVIGNON',
        'lieuLatitude' => 43.9455, 'lieuLongitude' => 4.8150,
    ]), $this->fnac);

    expect($b->is($a))->toBeTrue()
        ->and(Lieu::count())->toBe(2); // le Chêne noir + les Halles
});

it('considère les coordonnées 0,0 de la Fnac comme inconnues et géocode l’adresse', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(reponseBan(43.943678, 4.800091))]);

    $lieu = ($this->rattacher)(annonceLieu([
        'lieuNom' => 'Théâtre de l’Observance', 'lieuAdresse' => '10 rue de l’Observance', 'lieuCodePostal' => '84000', 'lieuVille' => 'Avignon',
        'lieuLatitude' => 0.0, 'lieuLongitude' => 0.0,
    ]));

    expect($lieu->precision_position)->toBe(PrecisionPosition::Adresse)
        ->and($lieu->position->latitude)->toEqualWithDelta(43.943678, 0.000001)
        ->and($lieu->ville_id)->toBe($this->avignon->id)
        ->and($lieu->type)->toBe(TypeLieu::Autre)
        ->and(lieuxAVerifier()->sole()->donnees['motif'])->toBe('Nouveau lieu, absent du référentiel');

    Http::assertSent(fn ($requete) => $requete['q'] === '10 rue de l’Observance' && $requete['postcode'] === '84000');
});

it('ignore une position hors de France ou loin de sa commune (latitude et longitude inversées)', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);

    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Salle Benoît XII', 'lieuVille' => 'Avignon', 'lieuLatitude' => 4.8094, 'lieuLongitude' => 43.9513]));

    expect($lieu->precision_position)->toBe(PrecisionPosition::Commune);
});

it('place un lieu introuvable au centre de sa commune, « position approximative », à vérifier', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);

    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Salle des fêtes', 'lieuAdresse' => 'quelque part', 'lieuVille' => 'Avignon']));

    expect($lieu->precision_position)->toBe(PrecisionPosition::Commune)
        ->and($lieu->position->latitude)->toEqualWithDelta(43.9493, 0.000001)
        ->and(lieuxAVerifier()->sole()->only(['priorite', 'cible_id']))->toBe(['priorite' => 2, 'cible_id' => $lieu->id]);
});

it('n’utilise pas un résultat trop vague du géocodage (commune seule)', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(reponseBan(43.95, 4.81, type: 'municipality'))]);

    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'La Factory', 'lieuAdresse' => 'Avignon', 'lieuVille' => 'Avignon']));

    expect($lieu->precision_position)->toBe(PrecisionPosition::Commune);
});

it('réutilise le lieu déjà vu chez la source, sans nouveau géocodage', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(reponseBan(43.943678, 4.800091))]);
    $observance = ['lieuNom' => 'Théâtre de l’Observance', 'lieuAdresse' => '10 rue de l’Observance', 'lieuCodePostal' => '84000', 'lieuVille' => 'Avignon'];

    $premier = ($this->rattacher)(annonceLieu($observance, 'A-1'));
    $second = app(RattacherLieu::class)->handle(annonceLieu($observance, 'A-2'), $this->fnac); // nouvelle collecte

    expect($second->is($premier))->toBeTrue()
        ->and(LieuSource::count())->toBe(1)
        ->and(lieuxAVerifier()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('suit une fusion faite dans le back-office', function () {
    $doublon = ($this->rattacher)(annonceLieu(['lieuNom' => 'Chêne noir – salle Léo Ferré', 'lieuVille' => 'Avignon', 'lieuLatitude' => 43.9600, 'lieuLongitude' => 4.8200]));
    app(FusionnerLieux::class)->handle($doublon, $this->cheneNoir);

    $lieu = app(RattacherLieu::class)->handle(annonceLieu(['lieuNom' => 'Chêne noir – salle Léo Ferré', 'lieuVille' => 'Avignon', 'lieuLatitude' => 43.9600, 'lieuLongitude' => 4.8200]), $this->fnac);

    expect($lieu->is($this->cheneNoir))->toBeTrue();
});

it('sans position, rattache par le nom dans la même commune mais demande une vérification', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);

    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Théâtre du Chêne Noir', 'lieuVille' => 'Avignon']));

    expect($lieu->is($this->cheneNoir))->toBeTrue()
        ->and(lieuxAVerifier()->sole()->donnees['motif'])->toBe('Rattaché par le nom seulement (position inconnue)');
});

it('complète un lieu reconnu sans écraser une correction faite à la main', function () {
    $this->cheneNoir->update(['adresse' => null, 'code_postal' => null, 'champs_verrouilles' => ['adresse']]);

    ($this->rattacher)(annonceLieu(['lieuNom' => 'Chêne Noir', 'lieuAdresse' => '8 bis rue Sainte-Catherine', 'lieuCodePostal' => '84000', 'lieuVille' => 'Avignon', 'lieuLatitude' => 43.9506, 'lieuLongitude' => 4.8098]));

    expect($this->cheneNoir->fresh()->only(['adresse', 'code_postal']))->toBe(['adresse' => null, 'code_postal' => '84000'])
        ->and($this->cheneNoir->fresh()->precision_position)->toBe(PrecisionPosition::Exacte);
});

it('donne au nouveau lieu le fuseau horaire de sa commune (La Réunion)', function () {
    Ville::create([
        'nom' => 'Saint-Denis', 'nom_normalise' => 'saint denis', 'code_insee' => '93066', 'departement' => '93',
        'codes_postaux' => ['93200'], 'population' => 113000, 'position' => new Point(48.9356, 2.3539), 'fuseau_horaire' => 'Europe/Paris',
    ]);
    $reunion = Ville::create([
        'nom' => 'Saint-Denis', 'nom_normalise' => 'saint denis', 'code_insee' => '97411', 'departement' => '974',
        'codes_postaux' => ['97400'], 'population' => 153000, 'position' => new Point(-20.8823, 55.4504), 'fuseau_horaire' => 'Indian/Reunion',
    ]);

    $lieu = ($this->rattacher)(annonceLieu(['lieuNom' => 'Théâtre Champ Fleuri', 'lieuCodePostal' => '97400', 'lieuVille' => 'Saint-Denis', 'lieuLatitude' => -20.8890, 'lieuLongitude' => 55.4590]));

    expect($lieu->ville_id)->toBe($reunion->id)
        ->and($lieu->fuseau_horaire)->toBe('Indian/Reunion')
        ->and($lieu->precision_position)->toBe(PrecisionPosition::Exacte);
});

it('affiche la file « Lieux à vérifier » dans le back-office', function () {
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    ($this->rattacher)(annonceLieu(['lieuNom' => 'Salle des fêtes', 'lieuVille' => 'Avignon']));
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/lieux-a-verifier')->assertOk()
        ->assertSee('Salle des fêtes')
        ->assertSee('Position approximative (centre de la commune)')
        ->assertSee('Fnac Spectacles');
});
