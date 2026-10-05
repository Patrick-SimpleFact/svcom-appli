<?php

use App\Actions\DeciderDoublon;
use App\Actions\DedoublonnerOffre;
use App\Actions\EnregistrerOffre;
use App\Actions\ExecuterCollecte;
use App\Actions\RattacherSpectacle;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\ResultatGenre;
use App\Enums\PrecisionPosition;
use App\Enums\TypeDecisionDedoublonnage;
use App\Enums\TypeLieu;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\OffresRelationManager;
use App\Models\Admin;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->billetreduc = Source::firstWhere('code', 'billetreduc');

    $lieu = fn (string $nom, float $lat, float $lon) => Lieu::create([
        'nom' => $nom, 'type' => TypeLieu::Theatre, 'position' => new Point($lat, $lon), 'precision_position' => PrecisionPosition::Exacte, 'fuseau_horaire' => 'Europe/Paris',
    ]);
    $this->avignon = $lieu('Théâtre du Chêne noir', 43.950518, 4.809771);
    $this->marseille = $lieu('Le Quai du Rire', 43.2990, 5.3850);
    $this->lyon = $lieu('Espace Gerson', 45.7640, 4.8270);

    /** La chaîne de la collecte, à partir de l'offre : enregistrement, déduplication, spectacle. */
    $this->seance = function (Source $source, string $id, string $titre, Lieu $lieu, string $jour = '2026-10-17 20:30', array $artistes = [], string $genre = 'theatre'): Offre {
        $annonce = new AnnonceNormalisee(
            identifiantExterne: $id, titre: $titre, debut: CarbonImmutable::parse($jour, 'Europe/Paris'), heureConnue: true,
            lien: "https://exemple.fr/{$id}", lieuNom: $lieu->nom, categoriesSource: ['Comédie'], artistes: $artistes,
        );
        [$offre] = app(EnregistrerOffre::class)->handle($annonce, $source, $lieu, new ResultatGenre(Genre::firstWhere('slug', $genre), false, 'Comédie', ResultatGenre::PAR_MOT));
        app(DedoublonnerOffre::class)->handle($offre);
        app(RattacherSpectacle::class)->handle($offre->fresh());

        return $offre->fresh();
    };
});

it('réunit un spectacle en tournée dans trois villes en un seul spectacle', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, '2026-10-17 20:30');
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Edmond', $this->marseille, '2026-10-20 20:00');
    $c = ($this->seance)($this->billetreduc, 'BR-3', 'EDMOND', $this->lyon, '2026-11-02 20:00');

    expect(Spectacle::count())->toBe(1)
        ->and([$a->spectacle_id, $b->spectacle_id, $c->spectacle_id])->each->toBe($a->spectacle_id)
        ->and(Spectacle::sole()->offres()->count())->toBe(3);
});

it('ne réunit jamais un titre générique entre deux lieux (« Concert »)', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Concert', $this->avignon, genre: 'concert');
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Concert', $this->marseille, genre: 'concert');
    $c = ($this->seance)($this->billetreduc, 'BR-3', 'Spectacle de Noël', $this->avignon, '2026-12-20 15:00');
    $d = ($this->seance)($this->billetreduc, 'BR-4', 'Spectacle de Noël', $this->lyon, '2026-12-20 15:00');

    expect($a->spectacle_id)->not->toBe($b->spectacle_id)
        ->and($c->spectacle_id)->not->toBe($d->spectacle_id)
        ->and(Spectacle::count())->toBe(4);
});

it('garde ensemble les dates d’un titre générique dans le même lieu', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Concert', $this->avignon, '2026-10-17 20:30', genre: 'concert');
    $b = ($this->seance)($this->billetreduc, 'BR-2', 'Concert', $this->avignon, '2026-10-18 20:30', genre: 'concert');

    expect($b->spectacle_id)->toBe($a->spectacle_id);
});

it('réunit le même titre de deux sources seulement s’ils partagent un artiste', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Le Prénom', $this->avignon, artistes: ['Patrick Bruel']);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Le Prénom', $this->marseille, '2026-10-20 20:00', artistes: ['PATRICK BRUEL', 'Valérie Benguigui']);
    $c = ($this->seance)($this->fnac, 'FN-2', 'Le Prénom', $this->lyon, '2026-11-02 20:00', artistes: ['Une autre troupe']);
    $bordeaux = Lieu::create(['nom' => 'Théâtre Femina', 'type' => TypeLieu::Theatre, 'position' => new Point(44.8410, -0.5760), 'precision_position' => PrecisionPosition::Exacte]);
    $d = ($this->seance)(Source::firstWhere('code', 'ticketmaster'), 'TM-1', 'Le Prénom', $bordeaux, '2026-11-03 20:00');

    expect($b->spectacle_id)->toBe($a->spectacle_id)
        ->and($c->fresh()->spectacle_id)->toBe($a->spectacle_id) // même titre et même source que B (Fnac)
        ->and($d->spectacle_id)->not->toBe($a->spectacle_id);    // autre source, autre lieu, aucun artiste en commun
});

it('réunit le même titre dans le même lieu, même vendu par deux sources à des dates différentes', function () {
    $vendredi = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, '2026-10-16 20:30');
    $samedi = ($this->seance)($this->fnac, 'FN-1', 'Edmond', $this->avignon, '2026-10-17 20:30');
    $ailleurs = ($this->seance)($this->fnac, 'FN-2', 'Les Fourberies de Scapin', $this->avignon, '2026-10-18 20:30');

    expect($samedi->meme_seance_que_id)->toBeNull() // pas la même séance…
        ->and($samedi->spectacle_id)->toBe($vendredi->spectacle_id) // …mais le même spectacle
        ->and($ailleurs->spectacle_id)->not->toBe($vendredi->spectacle_id);
});

it('donne le même spectacle à une séance vendue par deux billetteries', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon);
    $b = ($this->seance)($this->fnac, 'FN-1', 'Edmond de Alexis Michalik', $this->avignon);

    expect($b->meme_seance_que_id)->toBe($a->id)
        ->and($b->spectacle_id)->toBe($a->spectacle_id);
});

it('aligne le spectacle quand deux séances sont fusionnées à la main', function () {
    $a = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon, '2026-10-17 20:30');
    $b = ($this->seance)($this->fnac, 'FN-1', 'La pièce aux 5 Molières', $this->avignon, '2026-10-17 20:30');
    expect($b->spectacle_id)->not->toBe($a->spectacle_id);

    app(DeciderDoublon::class)->handle($a, $b, TypeDecisionDedoublonnage::Fusionner);

    expect($b->fresh()->spectacle_id)->toBe($a->spectacle_id);
});

it('crée le spectacle avec le titre, le genre et la classification de la séance', function () {
    $offre = ($this->seance)($this->billetreduc, 'BR-1', 'Edmond', $this->avignon);

    expect($offre->spectacle->only(['titre', 'classification_fine', 'jeune_public', 'demo']))
        ->toBe(['titre' => 'Edmond', 'classification_fine' => 'Comédie', 'jeune_public' => false, 'demo' => false])
        ->and($offre->spectacle->genre->slug)->toBe('theatre')
        ->and($offre->spectacle->champs_verrouilles)->toBe([]);
});

it('rattache les séances des sources factices et les montre sur la fiche du spectacle', function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([MotsGenresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    foreach ([['Avignon', '84007', '84', '84000', 43.9493, 4.8055], ['Marseille', '13055', '13', '13001', 43.2965, 5.3698]] as [$nom, $insee, $dep, $cp, $lat, $lon]) {
        Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => $dep, 'codes_postaux' => [$cp], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    }

    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice'));
    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'factice_bis'));

    $offre = fn (string $id) => Offre::firstWhere('identifiant_externe', $id);
    $comedie = $offre('F-1')->spectacle;

    expect($offre('F-9')->spectacle_id)->toBe($comedie->id)          // tournée à Marseille
        ->and($offre('B-1')->spectacle_id)->toBe($comedie->id)       // même séance, autre billetterie
        ->and($offre('F-10')->spectacle_id)->not->toBe($offre('F-11')->spectacle_id) // « Concert » dans deux lieux
        ->and($comedie->offres()->count())->toBe(3);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get('/admin/spectacles')->assertOk()->assertSee('Exemple de comédie');
    Livewire::test(OffresRelationManager::class, ['ownerRecord' => $comedie, 'pageClass' => ViewSpectacle::class])
        ->assertOk()
        ->assertSee('Le Quai du Rire')
        ->assertSee('Démonstration bis (factice)');
});
