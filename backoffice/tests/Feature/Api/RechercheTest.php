<?php

use App\Enums\TypeRepresentation;
use App\Models\Artiste;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Recherche;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;

/** P03 : propositions et recherche (API §4, F4). « Maintenant » : mercredi 14/10/2026 à 10 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Paris'));
    $ville = fn (string $nom, string $insee, int $population, float $lat, float $lon, bool $pilote = false) => Ville::create(['nom' => $nom, 'nom_normalise' => mb_strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2), 'codes_postaux' => [], 'population' => $population, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => $pilote]);
    $this->avignon = $ville('Avignon', '84007', 92188, 43.9493, 4.8055, true);
    $this->lauris = $ville('Lauris', '84065', 3900, 43.7469, 5.3134);
    $this->observance = Lieu::factory()->create(['nom' => "Théâtre de l'Observance", 'ville_id' => $this->avignon->id, 'position' => new Point(43.9355, 4.8038)]);
    $this->laurette = Lieu::factory()->create(['nom' => 'Laurette Théâtre', 'ville_id' => $this->avignon->id, 'position' => new Point(43.9480, 4.8090)]);
    $this->seance = fn (Lieu $lieu, string $quand, array $spectacle = [], array $attributs = []) => Representation::factory()->create([
        'lieu_id' => $lieu->id, 'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'), 'complet' => false, 'gratuit' => false, 'prix_min' => 18,
        'spectacle_id' => Spectacle::factory()->create(['titre' => 'Spectacle', 'genre_id' => Genre::firstWhere('slug', 'theatre')->id, ...$spectacle])->id, ...$attributs, // genre fixé : la fabrique le tire au hasard
    ]);
    $this->entetes = ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'];
    $this->chercher = fn (array $p = []) => $this->getJson('/v1/recherche?'.http_build_query($p), $this->entetes);
    $this->titres = fn (array $reponse) => collect($reponse['jours'])->flatMap(fn ($j) => array_column(array_column($j['cartes'], 'spectacle'), 'titre'))->all();
});

it('propose pendant la frappe, par type, malgré une faute, sans les spectacles sans date à venir', function () {
    ($this->seance)($this->laurette, '2026-10-17 19:00', ['titre' => 'Lesbien Tomber']);
    Spectacle::factory()->create(['titre' => 'Laurier passé']); // sans date : pas proposé
    ($this->seance)($this->observance, '2026-10-17 19:30', ['titre' => 'Donne moi ta chance']);

    $laur = $this->getJson('/v1/recherche/propositions?q=Laur', $this->entetes)->assertOk()->json();
    expect(array_column($laur['lieux'], 'nom'))->toBe(['Laurette Théâtre'])
        ->and($laur['lieux'][0]['sous_titre'])->toBe('Avignon')
        ->and(array_column($laur['villes'], 'nom'))->toBe(['Lauris'])
        ->and($laur['spectacles'])->toBe([]);

    expect($this->getJson('/v1/recherche/propositions?q=obsevance', $this->entetes)->json('lieux.0.nom'))->toBe("Théâtre de l'Observance")
        ->and($this->getJson('/v1/recherche/propositions?q=donne%20moi', $this->entetes)->json('spectacles.0'))
        ->toMatchArray(['titre' => 'Donne moi ta chance', 'sous_titre' => "Théâtre de l'Observance, Avignon"])
        ->and($this->getJson('/v1/recherche/propositions?q=l', $this->entetes)->json())->toBe(['spectacles' => [], 'artistes' => [], 'lieux' => [], 'villes' => []]);
});

it('propose un artiste avec ses dates à venir', function () {
    $artiste = Artiste::create(['nom' => 'Laura Cox', 'type' => 'personne']);
    $representation = ($this->seance)($this->laurette, '2026-10-20 20:00', ['titre' => 'Concert']);
    $representation->spectacle->artistes()->attach($artiste->id, ['role' => 'principal']);

    expect($this->getJson('/v1/recherche/propositions?q=laura', $this->entetes)->json('artistes'))
        ->toBe([['id' => $artiste->id, 'nom' => 'Laura Cox', 'sous_titre' => '1 date à venir']]);
});

it('exige chaque mot du texte, une faute tolérée par mot', function () {
    ($this->seance)($this->laurette, '2026-10-17 19:00', ['titre' => 'Lesbien Tomber']);
    ($this->seance)(Lieu::factory()->create(['nom' => 'Théâtre du Balcon', 'position' => new Point(43.95, 4.80)]), '2026-10-17 20:00', ['titre' => 'Hamlet']);

    expect(($this->titres)(($this->chercher)(['q' => 'laurete theatre'])->json()))->toBe(['Lesbien Tomber']); // pas tous les théâtres
});

it('trouve par titre, par lieu, par ville et par artiste, cartes groupées par jour', function () {
    ($this->seance)($this->observance, '2026-10-17 19:30', ['titre' => 'Donne moi ta chance']);
    ($this->seance)($this->observance, '2026-10-17 21:15', ['titre' => 'Autre pièce']);
    ($this->seance)($this->laurette, '2026-10-18 15:00', ['titre' => 'Mamouchka']);

    expect(($this->titres)(($this->chercher)(['q' => 'donne moi ta chanse'])->json()))->toBe(['Donne moi ta chance'])
        ->and(($this->titres)(($this->chercher)(['q' => 'obsevance'])->json()))->toBe(['Donne moi ta chance', 'Autre pièce'])
        ->and(($this->chercher)(['q' => 'Avignon'])->json())->total->toBe(3)
        ->and(($this->chercher)(['q' => 'Avignon'])->json('jours.*.date'))->toBe(['2026-10-17', '2026-10-18']);
});

it('filtre par période, genre, moment, disponibilité et lieu', function () {
    $danse = Genre::firstWhere('slug', 'danse');
    ($this->seance)($this->observance, '2026-10-17 11:00', ['titre' => 'Matinée']);
    ($this->seance)($this->observance, '2026-10-17 20:00', ['titre' => 'Soirée', 'genre_id' => $danse->id]);
    ($this->seance)($this->observance, '2026-10-18 00:30', ['titre' => 'Après minuit']); // soirée du 17
    ($this->seance)($this->observance, '2026-10-17 20:30', ['titre' => 'Complet'], ['complet' => true]);
    ($this->seance)($this->observance, '2026-11-20 20:00', ['titre' => 'En novembre']);

    expect(($this->titres)(($this->chercher)(['du' => '2026-10-17', 'au' => '2026-10-17', 'moment' => 'soiree'])->json()))->toBe(['Soirée', 'Après minuit', 'Complet'])
        ->and(($this->titres)(($this->chercher)(['moment' => 'matinee'])->json()))->toBe(['Matinée'])
        ->and(($this->titres)(($this->chercher)(['genres' => [$danse->id]])->json()))->toBe(['Soirée'])
        ->and(($this->chercher)(['masquer_complets' => 1])->json('total'))->toBe(4)
        ->and(($this->chercher)(['du' => '2026-11-01'])->json('total'))->toBe(1)
        ->and(($this->chercher)(['lat' => 43.9493, 'lon' => 4.8055, 'rayon' => 2000])->json('total'))->toBe(5)
        ->and(($this->chercher)(['lat' => 48.85, 'lon' => 2.35])->json('total'))->toBe(0);
});

it('masque les spectacles sans prix connu quand un filtre de prix est actif, et les compte', function () {
    ($this->seance)($this->observance, '2026-10-17 20:00', ['titre' => 'À 12 €'], ['prix_min' => 12]);
    ($this->seance)($this->observance, '2026-10-17 20:00', ['titre' => 'À 25 €'], ['prix_min' => 25]);
    ($this->seance)($this->observance, '2026-10-17 20:00', ['titre' => 'Gratuit'], ['prix_min' => null, 'gratuit' => true]);
    ($this->seance)($this->observance, '2026-10-17 20:00', ['titre' => 'Prix inconnu'], ['prix_min' => null]);

    $moinsDe15 = ($this->chercher)(['prix_max' => 15])->json();
    expect(($this->titres)($moinsDe15))->toEqualCanonicalizing(['À 12 €', 'Gratuit'])
        ->and($moinsDe15['sans_prix_masques'])->toBe(1)
        ->and(($this->titres)(($this->chercher)(['gratuit' => 1])->json()))->toBe(['Gratuit'])
        ->and(($this->titres)(($this->chercher)(['tri' => 'prix'])->json()))->toBe(['Gratuit', 'À 12 €', 'À 25 €', 'Prix inconnu'])
        ->and(($this->chercher)()->json('sans_prix_masques'))->toBe(0);
});

it('range au premier jour cherché une période commencée avant', function () {
    ($this->seance)($this->observance, '2026-10-14 00:00', ['titre' => 'Exposition-spectacle'], ['type' => TypeRepresentation::Periode, 'debut' => null, 'date_locale' => '2026-09-01', 'date_fin' => '2026-12-31']);

    expect(($this->chercher)()->json('jours.0.date'))->toBe('2026-10-14')
        ->and(($this->chercher)(['du' => '2026-11-02'])->json('jours.0'))->date->toBe('2026-11-02');
});

it('sans résultat : propose une correction, puis d’élargir le rayon ou la période', function () {
    ($this->seance)($this->laurette, '2026-10-17 19:00', ['titre' => "La confession d'un enfant du siècle"]);
    ($this->seance)($this->laurette, '2026-11-05 19:00', ['titre' => 'Plus tard']);

    expect(($this->chercher)(['q' => 'zzzzqqq'])->json())->total->toBe(0)->correction->toBeNull()
        ->and(($this->chercher)(['q' => 'confesion', 'lat' => 43.80, 'lon' => 4.80, 'rayon' => 5000])->json('elargir'))->toBe(['rayon_m' => 50000, 'total' => 1])
        ->and(($this->chercher)(['q' => 'plus tard', 'du' => '2026-10-20', 'au' => '2026-10-25'])->json('elargir'))->toBe(['au' => '2026-11-19', 'total' => 1])
        ->and(($this->chercher)(['q' => 'loraite theatre'])->json('correction'))->toBe('Laurette Théâtre') // trop loin pour la recherche, assez proche pour la correction
        ->and(($this->chercher)(['q' => 'laurete theatre', 'lat' => 48.85, 'lon' => 2.35])->json('correction'))->toBeNull(); // le texte trouve : c'est le lieu qui ne donne rien
});

it('journalise chaque recherche sans identifiant d’appareil, une fois par recherche', function () {
    ($this->seance)($this->observance, '2026-10-17 20:00', ['titre' => 'Donne moi ta chance']);

    $premiere = ($this->chercher)(['q' => 'Donne moi', 'ville_id' => $this->avignon->id, 'genres' => [1]])->json();
    ($this->chercher)(['q' => 'introuvable']);

    expect(Recherche::count())->toBe(2)
        ->and(Recherche::first()->only(['texte', 'ville_id', 'nb_resultats']))->toBe(['texte' => 'Donne moi', 'ville_id' => $this->avignon->id, 'nb_resultats' => $premiere['total']])
        ->and(Recherche::first()->filtres)->toMatchArray(['genres' => [1]])
        ->and(json_encode(Recherche::all()))->not->toContain('appareil-de-test');
});

it('pagine par 30 cartes', function () {
    foreach (range(1, 35) as $i) {
        ($this->seance)($this->observance, '2026-10-17 20:00');
    }

    $page1 = ($this->chercher)()->json();
    $page2 = ($this->chercher)(['suivant' => $page1['suivant']])->json();

    expect($page1['total'])->toBe(35)->and(collect($page1['jours'])->sum(fn ($j) => count($j['cartes'])))->toBe(30)
        ->and(collect($page2['jours'])->sum(fn ($j) => count($j['cartes'])))->toBe(5)->and($page2['suivant'])->toBeNull()
        ->and(Recherche::count())->toBe(1);
});
