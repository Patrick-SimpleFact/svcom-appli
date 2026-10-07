<?php

use App\Api\Fiches;
use App\Models\Artiste;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;

/** P04 : fiches spectacle, autres dates, pages lieu et artiste (API §5, F5). « Maintenant » : 14/10/2026 à 10 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class, SourcesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Paris'));
    $this->source = fn (string $code) => Source::firstWhere('code', $code);
    $avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 1, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    $this->observance = Lieu::factory()->create(['nom' => "Théâtre de l'Observance", 'ville_id' => $avignon->id, 'position' => new Point(43.9355, 4.8038)]);
    $this->marseille = Lieu::factory()->create(['nom' => 'Le Quai du Rire', 'position' => new Point(43.2965, 5.3698)]);
    $this->spectacle = Spectacle::factory()->create(['titre' => 'Donne moi ta chance', 'description' => '<p>Ils sont trois.</p><p>Un &eacute;t&eacute; <b>fou</b>.</p>']);
    $this->seance = fn (Lieu $lieu, string $quand, array $attributs = []) => Representation::factory()->create([
        'spectacle_id' => $this->spectacle->id, 'lieu_id' => $lieu->id, 'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'), 'complet' => false, ...$attributs,
    ]);
    $this->offre = fn (Representation $r, string $source, ?float $prix, array $attributs = []) => Offre::create([
        'source_id' => ($this->source)($source)->id, 'identifiant_externe' => $source.'-'.$r->id.'-'.uniqid(), 'representation_id' => $r->id,
        'donnees_normalisees' => ['titre' => 'x'], 'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'lien' => "https://{$source}.example/billet",
        'prix_min' => $prix, 'complet' => false, ...$attributs,
    ]);
    $this->get = fn (string $url) => $this->getJson($url, ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0']);
});

it('trie les billetteries : celles qui vendent des billets, puis places, prix, affiliation', function () {
    $r = ($this->seance)($this->observance, '2026-10-17 19:30');
    ($this->offre)($r, 'openagenda', null);                         // organisateur : en dernier
    ($this->offre)($r, 'fnac', 20);                                  // affilié mais plus cher
    ($this->offre)($r, 'ticketmaster', 15);                          // même prix, non affilié
    ($this->offre)($r, 'billetreduc', 15);                           // même prix, affilié → recommandée
    ($this->offre)($r, 'billetreduc', 9, ['complet' => true]);       // le moins cher mais complet
    ($this->offre)($r, 'fnac', 5, ['disparue_le' => now()]);         // plus en vente : absente

    $billetteries = ($this->get)("/v1/spectacles/{$this->spectacle->id}")->assertOk()->json('seances.0.billetteries');

    expect(array_map(fn ($b) => [$b['source'], $b['prix_min'], $b['complet']], $billetteries))->toEqual([
        ['BilletRéduc', 15.0, false], ['Ticketmaster', 15.0, false], ['Fnac Spectacles', 20.0, false], ['BilletRéduc', 9.0, true], ['OpenAgenda', null, false],
    ])
        ->and(array_column($billetteries, 'recommandee'))->toBe([true, false, false, false, false])
        ->and($billetteries[4]['billetterie'])->toBeFalse()
        ->and($billetteries[0]['lien_sortie'])->toContain('/sortie/'.$billetteries[0]['offre_id'].'?a=');
});

it('ouvre la fiche sur la séance demandée, sinon la prochaine, ou la plus proche avec une position', function () {
    $prochaine = ($this->seance)($this->marseille, '2026-10-15 20:00');
    $avignon1 = ($this->seance)($this->observance, '2026-10-17 19:30');
    ($this->seance)($this->observance, '2026-10-17 21:15');
    ($this->seance)($this->observance, '2026-10-12 20:00'); // passée

    $fiche = ($this->get)("/v1/spectacles/{$this->spectacle->id}?representation={$avignon1->id}")->json();
    expect($fiche)->jour->toBe('2026-10-17')->autres_dates->toBe(1)
        ->and($fiche['lieu']['nom'])->toBe("Théâtre de l'Observance")
        ->and(array_column($fiche['seances'], 'debut'))->toBe(['2026-10-17T19:30:00+02:00', '2026-10-17T21:15:00+02:00'])
        ->and($fiche['description'])->toBe("Ils sont trois.\n\nUn été fou.")
        ->and(($this->get)("/v1/spectacles/{$this->spectacle->id}")->json('seances.0.representation_id'))->toBe($prochaine->id)
        ->and(($this->get)("/v1/spectacles/{$this->spectacle->id}?lat=43.95&lon=4.80")->json())
        ->lieu->distance_m->toBeLessThan(2000)->jour->toBe('2026-10-17');
});

it('dit qu’un spectacle est terminé, et ne montre pas un spectacle masqué', function () {
    ($this->seance)($this->observance, '2026-10-12 20:00');

    expect(($this->get)("/v1/spectacles/{$this->spectacle->id}")->json())->termine->toBeTrue()->seances->toBe([]);

    $this->spectacle->update(['masque' => true]);
    ($this->get)("/v1/spectacles/{$this->spectacle->id}")->assertNotFound()->assertJsonPath('erreur.code', 'introuvable');
});

it('liste les autres dates par lieu, le plus proche d’abord', function () {
    ($this->seance)($this->marseille, '2026-10-15 20:00');
    ($this->seance)($this->observance, '2026-10-17 19:30');
    ($this->seance)($this->observance, '2026-10-18 19:30');

    $lieux = ($this->get)("/v1/spectacles/{$this->spectacle->id}/representations?lat=43.95&lon=4.80")->json('lieux');

    expect(array_column(array_column($lieux, 'lieu'), 'nom'))->toBe(["Théâtre de l'Observance", 'Le Quai du Rire'])
        ->and(array_column($lieux, 'dates'))->toBe([2, 1])
        ->and(($this->get)("/v1/spectacles/{$this->spectacle->id}/representations")->json('lieux.0.lieu.nom'))->toBe('Le Quai du Rire'); // sans position : la date la plus proche
});

it('donne la page d’un lieu avec ses dates à venir, celle du lieu conservé pour un doublon fusionné', function () {
    ($this->seance)($this->observance, '2026-10-17 19:30');
    $doublon = Lieu::factory()->create(['nom' => 'Observance salle 1', 'fusionne_dans_id' => $this->observance->id, 'masque' => true]);

    expect(($this->get)("/v1/lieux/{$this->observance->id}")->assertOk()->json())
        ->nom->toBe("Théâtre de l'Observance")->ville->toBe('Avignon')->total->toBe(1)
        ->and(($this->get)("/v1/lieux/{$doublon->id}")->json('id'))->toBe($this->observance->id);

    $this->observance->update(['masque' => true]);
    ($this->get)("/v1/lieux/{$this->observance->id}")->assertNotFound();
});

it('donne la page d’un artiste avec ses dates à venir', function () {
    $artiste = Artiste::create(['nom' => 'Laura Cox', 'type' => 'personne']);
    $this->spectacle->artistes()->attach($artiste->id, ['role' => 'principal']);
    ($this->seance)($this->observance, '2026-10-17 19:30');

    expect(($this->get)("/v1/artistes/{$artiste->id}")->json())->nom->toBe('Laura Cox')->total->toBe(1)
        ->and(($this->get)("/v1/spectacles/{$this->spectacle->id}")->json('artistes'))->toBe([['id' => $artiste->id, 'nom' => 'Laura Cox', 'role' => 'principal']]);
    ($this->get)('/v1/artistes/999999')->assertNotFound();
});

it('nettoie les descriptions sans effacer les chevrons qui ne sont pas des balises', function (?string $brut, ?string $propre) {
    expect(Fiches::nettoyer($brut))->toBe($propre);
})->with([
    'balises et entités' => ['<p>Un <b>été</b>&nbsp;fou</p><br/>Suite', "Un été fou\n\nSuite"],
    'retour à la ligne' => ['Ligne 1<br>Ligne 2', "Ligne 1\nLigne 2"],
    'chevrons de texte' => ["Nova Materia\n< Post-punk – Paris >", "Nova Materia\n< Post-punk – Paris >"],
    'vide' => ['<p> </p>', null],
    'absente' => [null, null],
]);
