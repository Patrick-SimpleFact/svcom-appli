<?php

use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Illuminate\Support\Facades\DB;

/** W05a : page d'accueil du site. « Maintenant » : mercredi 14/10/2026 à 18 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', 'Europe/Paris'));
    $ville = fn (string $nom, string $insee, float $lat, float $lon, bool $pilote) => Ville::create(['nom' => $nom, 'nom_normalise' => mb_strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2), 'codes_postaux' => [], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => $pilote]);
    $this->paris = $ville('Paris', '75056', 48.8566, 2.3522, true);
    $this->avignon = $ville('Avignon', '84007', 43.9493, 4.8055, true);
    $this->petite = $ville('Courthézon', '84039', 44.0870, 4.8840, true);

    $seance = fn (Ville $v, string $titre, string $genre, string $heure) => Representation::factory()->create([
        'spectacle_id' => Spectacle::factory()->create(['titre' => $titre, 'genre_id' => Genre::firstWhere('slug', $genre)->id])->id,
        'lieu_id' => Lieu::factory()->create(['nom' => "Salle {$titre}", 'ville_id' => $v->id, 'position' => $v->position])->id,
        'debut' => CarbonImmutable::parse("2026-10-14 {$heure}", 'Europe/Paris'), 'complet' => false,
    ]);
    $seance($this->paris, 'Pièce parisienne', 'theatre', '20:00');
    $seance($this->paris, 'Soirée DJ', 'autres', '19:00');
    foreach (['Concert A' => '20:30', 'Concert B' => '20:45', 'Humour C' => '21:00', 'Danse D' => '21:30'] as $titre => $heure) {
        $seance($this->paris, $titre, 'theatre', $heure);
    }
    $seance($this->avignon, 'Donne moi ta chance', 'theatre', '21:15');

    foreach ([[$this->paris, 18000, 600], [$this->avignon, 200, 11], [$this->petite, 3, 0]] as [$v, $trente, $soir]) {
        DB::table('couvertures')->insert(['jour' => '2026-10-14', 'ville_id' => $v->id, 'ce_soir' => $soir, 'week_end' => 0, 'trente_jours' => $trente, 'spectacles' => 0, 'mesuree_le' => now()]);
    }
});

it('montre les spectacles de ce soir (Paris par défaut), les villes pilotes fournies et le bloc théâtres', function () {
    $page = $this->get('/')->assertOk()
        ->assertSee('Ce soir,<br>près de vous.', false)
        ->assertSee('Pièce parisienne')->assertSee('Exemple à Paris')
        ->assertSee('/s/piece-parisienne-', false)
        ->assertSee('18 000')->assertSee('Avignon')->assertDontSee('Courthézon') // moins de 50 représentations : pas montrée
        ->assertSee('Vous êtes un théâtre ou une billetterie ?')->assertSee('Bientôt sur l’App Store et Google Play')
        ->assertDontSee('fonts.googleapis.com');

    // Plus de 5 spectacles : les vrais genres passent avant « Autres », affichés dans l'ordre des heures.
    $page->assertDontSee('Soirée DJ')->assertSeeInOrder(['Pièce parisienne', 'Concert A', 'Danse D']);
});

it('recalcule l’aperçu autour de la position donnée, sans la mettre dans l’adresse', function () {
    $this->post('/accueil/autour', ['lat' => 43.95, 'lon' => 4.81])->assertOk()
        ->assertSee('Avignon')->assertSee('Donne moi ta chance')->assertSee('Autour de votre position')->assertDontSee('Pièce parisienne');
    $this->get('/accueil/autour')->assertStatus(405);
});
