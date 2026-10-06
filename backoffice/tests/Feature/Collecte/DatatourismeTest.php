<?php

use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurDatatourisme;
use App\Enums\StatutCollecte;
use App\Enums\TypeRepresentation;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\RepresentationsRelationManager;
use App\Models\Admin;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Database\Seeders\CorrespondancesGenresSeeder;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
    $this->seed(SourcesSeeder::class);
    $this->source = Source::firstWhere('code', 'datatourisme');
    $this->csv = file_get_contents(base_path('tests/Fixtures/datatourisme.csv'));
    $this->annonces = fn () => collect(app(ConnecteurDatatourisme::class)->lire($this->csv, $this->source))->whereInstanceOf(AnnonceNormalisee::class);
    Http::fake([
        'www.data.gouv.fr/api/1/datasets/*' => Http::response(['resources' => [
            ['title' => 'autre.csv', 'url' => 'https://static.data.gouv.fr/autre.csv', 'last_modified' => '2026-10-01T00:00:00'],
            ['title' => 'datatourisme-fma.csv', 'url' => 'https://static.data.gouv.fr/fma.csv', 'last_modified' => '2026-10-06T02:49:17.599000+00:00'],
        ]]),
        'static.data.gouv.fr/fma.csv' => Http::response($this->csv),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);
});

it('lit un jour isolé comme une annonce sans horaire', function () {
    $piece = ($this->annonces)()->first(fn (AnnonceNormalisee $a) => str_contains($a->titre, 'Le parfait manuel'));

    expect($piece->debut->format('Y-m-d'))->toBe('2026-12-10')
        ->and($piece->heureConnue)->toBeFalse()
        ->and($piece->fin)->toBeNull()
        ->and($piece->lieuVille)->toBe('Figeac')
        ->and($piece->lieuCodePostal)->toBe('46100')
        ->and($piece->categoriesSource)->toBe(['TheaterEvent'])
        ->and($piece->lien)->toStartWith('http');
});

it('lit un événement sur plusieurs jours comme une seule période', function () {
    $festival = ($this->annonces)()->filter(fn (AnnonceNormalisee $a) => $a->titre === 'Magic Show');

    expect($festival)->toHaveCount(1)
        ->and($festival->first()->debut->format('Y-m-d'))->toBe('2026-10-22')
        ->and($festival->first()->fin->format('Y-m-d'))->toBe('2026-10-25');
});

it('ignore les périodes passées, mais garde les très longues en une seule période (décision de Patrick)', function () {
    $annonces = ($this->annonces)();
    $louvre = $annonces->filter(fn (AnnonceNormalisee $a) => $a->titre === 'La Scène du Louvre-Lens'); // 2021 → 2030

    expect($louvre)->toHaveCount(1)
        ->and($louvre->first()->fin->format('Y-m-d'))->toBe('2030-07-01')
        ->and($annonces->pluck('titre'))->not->toContain('La Londe Jazz Festival'); // été 2026, passé
});

it('lit la version dans la date de mise à jour de la ressource sur data.gouv.fr', function () {
    expect(app(ConnecteurDatatourisme::class)->versionDisponible($this->source))->toBe('2026-10-06T02:49:17.599000+00:00');
});

it('collecte DATAtourisme : jours « horaire à confirmer » et périodes « du … au … »', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, CorrespondancesGenresSeeder::class]);
    Ville::create(['nom' => 'Figeac', 'nom_normalise' => 'figeac', 'code_insee' => '46102', 'departement' => '46', 'codes_postaux' => ['46100'], 'population' => 9800, 'position' => new Point(44.6086, 2.0317), 'fuseau_horaire' => 'Europe/Paris']);

    $collecte = app(ExecuterCollecte::class)->handle($this->source);
    expect($collecte->statut)->toBe(StatutCollecte::Reussie);

    $piece = Offre::where('donnees_normalisees->titre', 'like', '%Le parfait manuel%')->sole()->representation;
    expect($piece->type)->toBe(TypeRepresentation::Jour)
        ->and($piece->debut)->toBeNull()
        ->and($piece->date_locale->format('Y-m-d'))->toBe('2026-12-10')
        ->and($piece->genre->slug)->toBe('theatre'); // correspondance de départ TheaterEvent → Théâtre

    $festival = Offre::where('donnees_normalisees->titre', 'Magic Show')->sole()->representation;
    expect($festival->type)->toBe(TypeRepresentation::Periode)
        ->and($festival->date_locale->format('Y-m-d'))->toBe('2026-10-22')
        ->and($festival->date_fin->format('Y-m-d'))->toBe('2026-10-25')
        ->and(Representation::where('spectacle_id', $festival->spectacle_id)->count())->toBe(1); // une seule carte, pas trois

    // Une période en cours n'est pas « passée » : le 23/10, elle reste programmée tant qu'elle est dans le flux.
    $this->travelTo(now()->setDate(2026, 10, 23));
    Http::fake(['www.data.gouv.fr/api/1/datasets/*' => Http::response(['resources' => [['title' => 'datatourisme-fma.csv', 'url' => 'https://static.data.gouv.fr/fma.csv', 'last_modified' => 'x']]]), 'static.data.gouv.fr/fma.csv' => Http::response($this->csv)]);
    app(ExecuterCollecte::class)->handle($this->source);
    expect($festival->fresh()->statut->value)->toBe('programmee');

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    Livewire\Livewire::test(RepresentationsRelationManager::class, ['ownerRecord' => $festival->spectacle, 'pageClass' => ViewSpectacle::class])
        ->assertSee('Du 22/10/2026 au 25/10/2026');
});
