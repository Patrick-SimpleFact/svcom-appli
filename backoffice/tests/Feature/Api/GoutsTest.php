<?php

use App\Actions\AlimenterNouveautes;
use App\Actions\ExecuterCollecte;
use App\Enums\StatutRepresentation;
use App\Models\Artiste;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Nouveaute;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Suivi;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** P07 : préférences, favoris, suivis, nouveautés (API §8, F3). « Maintenant » : 14/10/2026 à 9 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Paris'));
    $ville = fn (string $nom, string $insee, float $lat, float $lon) => Ville::create(['nom' => $nom, 'nom_normalise' => mb_strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2), 'codes_postaux' => [], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    $this->avignon = $ville('Avignon', '84007', 43.9493, 4.8055);
    $this->lille = $ville('Lille', '59350', 50.6292, 3.0573);
    $this->observance = Lieu::factory()->create(['nom' => "Théâtre de l'Observance", 'ville_id' => $this->avignon->id, 'position' => new Point(43.9355, 4.8038)]);
    $this->aLille = Lieu::factory()->create(['nom' => 'Le Spotlight', 'ville_id' => $this->lille->id, 'position' => new Point(50.63, 3.06)]);
    $this->seance = fn (Lieu $lieu, string $quand, array $attributs = []) => Representation::factory()->create(['lieu_id' => $lieu->id, 'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'), 'complet' => false, ...$attributs]);

    $this->utilisateur = Utilisateur::create(['email' => 'patrick@exemple.fr']);
    $jeton = $this->utilisateur->createToken('test')->plainTextToken;
    $this->api = fn (string $methode, string $url, array $corps = []) => $this->json($methode, $url, $corps, ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0', 'Authorization' => "Bearer {$jeton}"]);
});

it('règle les préférences ; la zone des alertes garde la commune, jamais la position', function () {
    expect(($this->api)('GET', '/v1/moi/preferences')->assertOk()->json())->toBe([
        'genres' => [], 'rayon_m' => null, 'zone_alertes' => ['ville' => null, 'rayon_km' => 30], 'alertes_actives' => true, 'rappel_jour_j' => true,
    ]);

    $theatre = Genre::firstWhere('slug', 'theatre')->id;
    $reponse = ($this->api)('PUT', '/v1/moi/preferences', ['genres' => [$theatre, 999], 'rayon_m' => 5000, 'zone_alertes' => ['lat' => 43.95, 'lon' => 4.81, 'rayon_km' => 50], 'rappel_jour_j' => false])->assertOk()->json();

    expect($reponse)->genres->toBe([$theatre])->rayon_m->toBe(5000)->rappel_jour_j->toBeFalse()
        ->and($reponse['zone_alertes'])->toBe(['ville' => ['id' => $this->avignon->id, 'nom' => 'Avignon'], 'rayon_km' => 50])
        ->and(json_encode($this->utilisateur->preferences()->first()->getAttributes()))->not->toContain('43.95');
    ($this->api)('PUT', '/v1/moi/preferences', ['zone_alertes' => ['rayon_km' => 500]])->assertStatus(422);
});

it('ajoute un favori une seule fois, et le range : ce soir, à venir, passés', function () {
    $ceSoir = ($this->seance)($this->observance, '2026-10-14 20:00');
    $plusTard = ($this->seance)($this->observance, '2026-10-20 20:00');
    $passe = ($this->seance)($this->observance, '2026-10-10 20:00');

    ($this->api)('POST', '/v1/moi/favoris', ['spectacle_id' => $plusTard->spectacle_id])->assertCreated();
    ($this->api)('POST', '/v1/moi/favoris', ['spectacle_id' => $plusTard->spectacle_id, 'representation_id' => $plusTard->id])->assertOk(); // déjà en favori : séance choisie mise à jour
    ($this->api)('POST', '/v1/moi/favoris', ['spectacle_id' => $ceSoir->spectacle_id, 'representation_id' => $ceSoir->id])->assertCreated();
    ($this->api)('POST', '/v1/moi/favoris', ['spectacle_id' => $passe->spectacle_id])->assertCreated();
    ($this->api)('POST', '/v1/moi/favoris', ['spectacle_id' => $passe->spectacle_id, 'representation_id' => $ceSoir->id])->assertStatus(422)->assertJsonPath('erreur.code', 'seance_invalide');

    $favoris = ($this->api)('GET', '/v1/moi/favoris')->json();
    expect(array_map(fn ($g) => array_column(array_column($g, 'spectacle'), 'id'), $favoris))->toBe([
        'ce_soir' => [$ceSoir->spectacle_id], 'a_venir' => [$plusTard->spectacle_id], 'passes' => [$passe->spectacle_id],
    ])->and($favoris['a_venir'][0]['seance_choisie'])->toBeTrue();

    ($this->api)('DELETE', '/v1/moi/favoris/'.$favoris['passes'][0]['id'])->assertNoContent();
    expect($this->utilisateur->favoris()->count())->toBe(2);
});

it('suit un lieu ou un artiste une seule fois, et suit le lieu conservé d’un doublon fusionné', function () {
    $artiste = Artiste::create(['nom' => 'Laura Cox', 'type' => 'personne']);
    $doublon = Lieu::factory()->create(['fusionne_dans_id' => $this->observance->id, 'masque' => true]);

    ($this->api)('POST', '/v1/moi/suivis', ['type' => 'lieu', 'id' => $this->observance->id])->assertCreated()->assertJsonPath('deja_suivi', false);
    ($this->api)('POST', '/v1/moi/suivis', ['type' => 'lieu', 'id' => $doublon->id])->assertOk()->assertJsonPath('deja_suivi', true);
    ($this->api)('POST', '/v1/moi/suivis', ['type' => 'artiste', 'id' => $artiste->id])->assertCreated()->assertJsonPath('suivi.cible.nom', 'Laura Cox');
    ($this->api)('POST', '/v1/moi/suivis', ['type' => 'lieu', 'id' => 999999])->assertNotFound();
    ($this->api)('POST', '/v1/moi/suivis', ['type' => 'ville', 'id' => 1])->assertStatus(422);

    expect(Suivi::count())->toBe(2)
        ->and(($this->api)('GET', '/v1/moi/suivis')->json('suivis.*.cible.nom'))->toEqualCanonicalizing(["Théâtre de l'Observance", 'Laura Cox']);

    $autre = Suivi::create(['utilisateur_id' => Utilisateur::create(['email' => 'autre@exemple.fr'])->id, 'type' => 'lieu', 'cible_id' => $this->aLille->id]);
    ($this->api)('DELETE', "/v1/moi/suivis/{$autre->id}")->assertNotFound(); // pas le suivi d'un autre
});

it('ajoute une nouveauté quand un lieu suivi annonce un nouveau spectacle, une seule fois, sans complet ni masqué', function () {
    $connu = ($this->seance)($this->observance, '2026-10-20 20:00');
    Suivi::create(['utilisateur_id' => $this->utilisateur->id, 'type' => 'lieu', 'cible_id' => $this->observance->id]);
    $this->travel(1)->minutes();
    $debutCollecte = now();

    $nouveau = ($this->seance)($this->observance, '2026-10-25 20:00');
    ($this->seance)($this->observance, '2026-10-26 20:00', ['spectacle_id' => $nouveau->spectacle_id]); // même spectacle : une seule nouveauté
    ($this->seance)($this->observance, '2026-10-27 20:00', ['spectacle_id' => $connu->spectacle_id]); // déjà annoncé avant : rien
    ($this->seance)($this->observance, '2026-10-28 20:00', ['complet' => true]);
    ($this->seance)($this->observance, '2026-10-29 20:00', ['statut' => StatutRepresentation::Masquee]);

    expect(app(AlimenterNouveautes::class)->apresPublication($debutCollecte))->toBe(1)
        ->and(app(AlimenterNouveautes::class)->apresPublication($debutCollecte))->toBe(0)
        ->and(Nouveaute::sole())->type->toBe(Nouveaute::NOUVEAU_SPECTACLE_LIEU)->spectacle_id->toBe($nouveau->spectacle_id);

    $nouveautes = ($this->api)('GET', '/v1/moi/nouveautes')->json();
    expect($nouveautes['non_vues'])->toBe(1)->and($nouveautes['nouveautes'][0]['representation']['lieu']['nom'])->toBe("Théâtre de l'Observance")
        ->and(($this->api)('GET', '/v1/moi/suivis')->json('suivis.0.nouveautes'))->toBe(1);

    ($this->api)('POST', '/v1/moi/nouveautes/vues')->assertJsonPath('vues', 1);
    expect(($this->api)('GET', '/v1/moi/nouveautes')->json('non_vues'))->toBe(0);
});

it('prévient d’une nouvelle date d’un artiste suivi dans la zone des alertes seulement, et respecte les alertes coupées', function () {
    $artiste = Artiste::create(['nom' => 'Laura Cox', 'type' => 'personne']);
    Suivi::create(['utilisateur_id' => $this->utilisateur->id, 'type' => 'artiste', 'cible_id' => $artiste->id]);
    $this->utilisateur->preferences()->create(['zone_alertes_ville_id' => $this->avignon->id, 'zone_alertes_rayon_km' => 30]);
    $debut = now();
    $this->travel(1)->minutes();
    $concert = Spectacle::factory()->create(['titre' => 'Laura Cox']);
    $concert->artistes()->attach($artiste->id);
    $avignon = ($this->seance)($this->observance, '2026-10-25 20:00', ['spectacle_id' => $concert->id]);
    ($this->seance)($this->aLille, '2026-10-26 20:00', ['spectacle_id' => $concert->id]); // hors zone

    app(AlimenterNouveautes::class)->apresPublication($debut);
    expect(Nouveaute::pluck('representation_id')->all())->toBe([$avignon->id]);

    Nouveaute::query()->delete();
    $this->utilisateur->preferences()->update(['alertes_actives' => false]);
    app(AlimenterNouveautes::class)->apresPublication($debut);
    expect(Nouveaute::count())->toBe(0);
});

it('ajoute le rappel du jour J pour la séance choisie d’un favori, une fois', function () {
    $ceSoir = ($this->seance)($this->observance, '2026-10-14 20:00');
    ($this->api)('POST', '/v1/moi/favoris', ['spectacle_id' => $ceSoir->spectacle_id, 'representation_id' => $ceSoir->id]);

    $this->artisan('nouveautes:rappels')->expectsOutputToContain('1 rappel')->assertSuccessful();
    $this->artisan('nouveautes:rappels')->expectsOutputToContain('0 rappel');

    expect(Nouveaute::sole()->type)->toBe(Nouveaute::RAPPEL_JOUR_J);
});

it('remplit « Pour vous » d’un compte avec ses genres, ses lieux et ses artistes suivis', function () {
    [$theatre, $danse] = [Genre::firstWhere('slug', 'theatre'), Genre::firstWhere('slug', 'danse')];
    $this->utilisateur->preferences()->create(['genres' => [$theatre->id]]);
    Suivi::create(['utilisateur_id' => $this->utilisateur->id, 'type' => 'lieu', 'cible_id' => $this->observance->id]);
    ($this->seance)($this->observance, '2026-10-14 20:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Danse au lieu suivi', 'genre_id' => $danse->id])->id]);
    ($this->seance)(Lieu::factory()->create(['position' => new Point(43.95, 4.81)]), '2026-10-14 20:30', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Théâtre ailleurs', 'genre_id' => $theatre->id])->id]);
    ($this->seance)(Lieu::factory()->create(['position' => new Point(43.95, 4.81)]), '2026-10-14 21:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Danse ailleurs', 'genre_id' => $danse->id])->id]);

    expect(($this->api)('GET', '/v1/representations/autour?lat=43.9493&lon=4.8055&pour_vous=1&rayon=10000')->json('cartes.*.spectacle.titre'))->toBe(['Danse au lieu suivi', 'Théâtre ailleurs']);
});

it('exige un compte', function () {
    $this->getJson('/v1/moi/favoris', ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'])->assertUnauthorized();
});

it('alimente la file des nouveautés à la fin de chaque collecte réussie', function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([MotsGenresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    $this->mock(AlimenterNouveautes::class)->shouldReceive('apresPublication')->once();

    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice'));
});
