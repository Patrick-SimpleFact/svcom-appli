<?php

use App\Enums\PrecisionPosition;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;

/** P02 : « autour de moi » (API §3, F2). Centre : Avignon ; « maintenant » : mercredi 14/10/2026 à 19 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 19:00', 'Europe/Paris'));
    $this->centre = ['lat' => 43.9493, 'lon' => 4.8055];
    $this->avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => false]);
    // Un lieu à ≈ 500 m du centre ; $loin(km) : un lieu plus loin vers le nord.
    $this->lieu = Lieu::factory()->create(['nom' => 'Théâtre du Chêne Noir', 'position' => new Point(43.9538, 4.8055)]);
    $this->loin = fn (float $km) => Lieu::factory()->create(['position' => new Point(43.9493 + $km / 111.0, 4.8055)]);
    $this->seance = function (string $quand, array $attributs = []) {
        return Representation::factory()->create([
            'lieu_id' => $this->lieu->id,
            'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'),
            'complet' => false,
            ...$attributs,
        ]);
    };
    $this->autour = fn (array $parametres = []) => $this->getJson('/v1/representations/autour?'.http_build_query([...$this->centre, ...$parametres]), ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0']);
});

it('regroupe en une carte les séances d’un spectacle au même lieu le même soir, 0 h 30 comprise', function () {
    $spectacle = Spectacle::factory()->create(['titre' => 'Donne moi ta chance']);
    ($this->seance)('2026-10-14 21:15', ['spectacle_id' => $spectacle->id]);
    ($this->seance)('2026-10-15 00:30', ['spectacle_id' => $spectacle->id]); // compte pour ce soir

    $carte = ($this->autour)()->assertOk()->json('cartes.0');

    expect($carte['spectacle']['titre'])->toBe('Donne moi ta chance')
        ->and($carte['date_locale'])->toBe('2026-10-14')
        ->and(array_column($carte['seances'], 'debut'))->toBe(['2026-10-14T21:15:00+02:00', '2026-10-15T00:30:00+02:00'])
        ->and($carte['distance_m'])->toBeBetween(450, 550)
        ->and($carte['lieu'])->toBe(['id' => $this->lieu->id, 'nom' => 'Théâtre du Chêne Noir', 'position_approximative' => false]);
});

it('écarte une séance commencée depuis plus de 15 minutes, garde celle qui vient de commencer', function () {
    ($this->seance)('2026-10-14 18:40', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Trop tard'])->id]);
    ($this->seance)('2026-10-14 18:50', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Juste à temps'])->id]);

    expect(($this->autour)()->json('cartes.*.spectacle.titre'))->toBe(['Juste à temps']);
});

it('trie par heure puis distance, complets en fin de liste ; ou par distance', function () {
    $loin = ($this->loin)(1.5);
    ($this->seance)('2026-10-14 20:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Complet tôt'])->id, 'complet' => true]);
    ($this->seance)('2026-10-14 21:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Tard proche'])->id]);
    ($this->seance)('2026-10-14 20:30', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Tôt loin'])->id, 'lieu_id' => $loin->id]);

    expect(($this->autour)(['rayon' => 5000])->json('cartes.*.spectacle.titre'))->toBe(['Tôt loin', 'Tard proche', 'Complet tôt'])
        ->and(($this->autour)(['rayon' => 5000, 'tri' => 'distance'])->json('cartes.*.spectacle.titre'))->toBe(['Tard proche', 'Tôt loin', 'Complet tôt'])
        ->and(($this->autour)(['rayon' => 5000])->json('cartes.2.badges'))->toBe(['complet']);
});

it('élargit le rayon jusqu’à 8 représentations, ou garde le rayon fixé', function () {
    foreach (range(1, 3) as $i) {
        ($this->seance)('2026-10-14 20:00');
    }
    $a4km = ($this->loin)(4);
    foreach (range(1, 5) as $i) {
        ($this->seance)('2026-10-14 20:30', ['lieu_id' => $a4km->id]);
    }

    expect(($this->autour)()->json())->rayon_retenu_m->toBe(5000)->total->toBe(8)
        ->and(($this->autour)(['rayon' => 2000])->json())->rayon_retenu_m->toBe(2000)->total->toBe(3);
});

it('met à part les spectacles sans horaire précis : journée et période', function () {
    ($this->seance)('2026-10-14 00:00', ['type' => TypeRepresentation::Jour, 'debut' => null, 'date_locale' => '2026-10-14', 'spectacle_id' => Spectacle::factory()->create(['titre' => 'Fête du livre'])->id]);
    ($this->seance)('2026-10-14 00:00', ['type' => TypeRepresentation::Periode, 'debut' => null, 'date_locale' => '2026-10-10', 'date_fin' => '2026-10-20', 'spectacle_id' => Spectacle::factory()->create(['titre' => 'Festival'])->id]);

    $reponse = ($this->autour)()->json();

    expect($reponse['cartes'])->toBe([])
        ->and(collect($reponse['toute_la_journee'])->pluck('badges', 'spectacle.titre')->all())->toEqual(['Fête du livre' => ['horaire_a_confirmer'], 'Festival' => []])
        ->and(collect($reponse['toute_la_journee'])->firstWhere('type', 'periode')['date_fin'])->toBe('2026-10-20');
});

it('cherche le week-end du vendredi 18 h au dimanche', function () {
    ($this->seance)('2026-10-16 17:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Vendredi 17 h'])->id]);
    ($this->seance)('2026-10-16 18:30', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Vendredi soir'])->id]);
    ($this->seance)('2026-10-18 16:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Dimanche'])->id]);
    ($this->seance)('2026-10-19 20:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Lundi'])->id]);

    expect(($this->autour)(['quand' => 'week_end'])->json('cartes.*.spectacle.titre'))->toBe(['Vendredi soir', 'Dimanche'])
        ->and(($this->autour)(['quand' => 'demain'])->json('total'))->toBe(0)
        ->and(($this->autour)(['quand' => '2026-10-19'])->json('cartes.*.spectacle.titre'))->toBe(['Lundi']);
});

it('propose une autre date quand la soirée est vide, et signale une couverture faible loin des villes pilotes', function () {
    ($this->seance)('2026-10-17 20:00');

    expect(($this->autour)()->json())
        ->total->toBe(0)
        ->suggestion_date->toBe(['quand' => 'week_end', 'nombre' => 1])
        ->couverture_faible->toBeTrue();

    $this->avignon->update(['est_pilote' => true]); // ville pilote, simple soir creux : pas de bandeau
    foreach (range(1, 3) as $i) {
        ($this->seance)('2026-10-16 20:00');
    }

    expect(($this->autour)()->json('couverture_faible'))->toBeFalse();
});

it('filtre par genre, « Pour vous » et jeune public ; donne le total sans le filtre de genre s’il est vide', function () {
    [$theatre, $danse] = [Genre::firstWhere('slug', 'theatre'), Genre::firstWhere('slug', 'danse')];
    ($this->seance)('2026-10-14 20:00', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Pièce', 'genre_id' => $theatre->id])->id]);
    ($this->seance)('2026-10-14 20:30', ['spectacle_id' => Spectacle::factory()->create(['titre' => 'Marionnettes', 'genre_id' => $theatre->id, 'jeune_public' => true])->id]);

    expect(($this->autour)(['genres' => [$theatre->id]])->json('total'))->toBe(2)
        ->and(($this->autour)(['genres' => [$danse->id]])->json())->total->toBe(0)->total_tous_genres->toBe(2)
        ->and(($this->autour)(['pour_vous' => 1, 'gouts' => [$danse->id]])->json('total'))->toBe(0)
        ->and(($this->autour)(['jeune_public' => 1])->json('cartes.*.spectacle.titre'))->toBe(['Marionnettes']);
});

it('pagine par 30 cartes', function () {
    foreach (range(1, 35) as $i) {
        ($this->seance)('2026-10-14 20:00');
    }

    $page1 = ($this->autour)()->json();
    $page2 = ($this->autour)(['suivant' => $page1['suivant']])->json();

    expect(count($page1['cartes']))->toBe(30)->and($page1['total'])->toBe(35)
        ->and(count($page2['cartes']))->toBe(5)->and($page2['suivant'])->toBeNull();
});

it('ne montre pas une séance masquée ni un spectacle masqué', function () {
    ($this->seance)('2026-10-14 20:00', ['statut' => StatutRepresentation::Masquee]);
    ($this->seance)('2026-10-14 20:00', ['spectacle_id' => Spectacle::factory()->create(['masque' => true])->id]);

    expect(($this->autour)()->json('total'))->toBe(0);
});

it('cherche autour d’une ville choisie, et refuse une requête incomplète', function () {
    ($this->seance)('2026-10-14 20:00');
    $entetes = ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'];

    $this->getJson("/v1/representations/autour?ville_id={$this->avignon->id}", $entetes)->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/v1/representations/autour?ville_id=999999', $entetes)->assertNotFound()->assertJsonPath('erreur.code', 'ville_inconnue');
    $this->getJson('/v1/representations/autour', $entetes)->assertStatus(422)->assertJsonPath('erreur.code', 'donnees_invalides');
    ($this->autour)(['quand' => 'hier'])->assertStatus(422)->assertJsonPath('erreur.code', 'quand_invalide');
    ($this->autour)(['quand' => '2027-06-01'])->assertStatus(422)->assertJsonPath('erreur.code', 'date_invalide');
});

it('donne un repère par lieu pour la carte, sans les lieux à position approximative', function () {
    ($this->seance)('2026-10-14 20:00');
    ($this->seance)('2026-10-14 21:00');
    ($this->seance)('2026-10-14 20:00', ['lieu_id' => Lieu::factory()->create(['position' => new Point(43.95, 4.81), 'precision_position' => PrecisionPosition::Commune])->id]);

    $this->getJson('/v1/lieux/carte?sud=43.90&nord=44.00&ouest=4.75&est=4.85', ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'])
        ->assertOk()
        ->assertJsonCount(1, 'reperes')
        ->assertJsonPath('reperes.0.lieu_id', $this->lieu->id)
        ->assertJsonPath('reperes.0.nombre', 2);
});
