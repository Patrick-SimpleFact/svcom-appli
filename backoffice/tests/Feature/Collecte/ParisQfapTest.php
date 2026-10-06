<?php

use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurParisQfap;
use App\Enums\StatutCollecte;
use App\Enums\TypeRepresentation;
use App\Models\Offre;
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
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(20, 0));
    $this->seed(SourcesSeeder::class);
    $this->source = Source::firstWhere('code', 'paris_qfap');
    $this->brut = file_get_contents(base_path('tests/Fixtures/paris_qfap.json'));
    $this->annonces = fn () => collect(app(ConnecteurParisQfap::class)->lire($this->brut, $this->source))->whereInstanceOf(AnnonceNormalisee::class);
});

it('lit chaque horaire comme une séance, avec son prix et son lien de réservation', function () {
    $lac = ($this->annonces)()->filter(fn (AnnonceNormalisee $a) => $a->titre === 'Lac artificiel');

    expect($lac)->not->toBeEmpty()
        ->and($lac->first()->debut->format('Y-m-d H:i e'))->toBe('2026-10-07 19:30 Europe/Paris')
        ->and($lac->first()->heureConnue)->toBeTrue()
        ->and($lac->first()->prixMin)->toBe(8.0)    // « De 8 à 20 euros »
        ->and($lac->first()->prixMax)->toBe(20.0)
        ->and($lac->first()->lien)->toContain('mapado.com')
        ->and($lac->first()->lieuVille)->toBe('Paris')
        ->and($lac->first()->categoriesSource)->toContain('Théâtre');
});

it('lit les prix écrits « 22€ » et la gratuité', function () {
    $memoire = ($this->annonces)()->first(fn (AnnonceNormalisee $a) => str_contains($a->titre, 'Mémoire de fille'));
    $juno = ($this->annonces)()->first(fn (AnnonceNormalisee $a) => str_contains($a->titre, 'Juno'));

    expect($memoire->prixMax)->toBe(22.0)
        ->and($memoire->prixMin)->toBeLessThan(22.0)
        ->and($juno->gratuit)->toBeTrue()
        ->and($juno->categoriesSource)->toBe(['Concert', 'Festival']);
});

it('fait une période d’un événement sans horaire sur plusieurs jours', function () {
    $kiosque = ($this->annonces)()->filter(fn (AnnonceNormalisee $a) => str_contains($a->titre, 'Kiosque en Fête'));

    expect($kiosque)->toHaveCount(1)
        ->and($kiosque->first()->heureConnue)->toBeFalse()
        ->and($kiosque->first()->fin->format('Y-m-d'))->toBe('2026-10-26');
});

it('lit la version dans la date de mise à jour du jeu de données', function () {
    Http::fake(['opendata.paris.fr/*' => Http::response(['metas' => ['default' => ['data_processed' => '2026-10-06T18:15:12+00:00']]])]);

    expect(app(ConnecteurParisQfap::class)->versionDisponible($this->source))->toBe('2026-10-06T18:15:12+00:00');
});

it('collecte Que faire à Paris : le sport et les ateliers sont écartés, le théâtre publié', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, CorrespondancesGenresSeeder::class]);
    Ville::create(['nom' => 'Paris', 'nom_normalise' => 'paris', 'code_insee' => '75056', 'departement' => '75', 'codes_postaux' => ['75001', '75018'], 'population' => 2100000, 'position' => new Point(48.8566, 2.3522), 'fuseau_horaire' => 'Europe/Paris']);
    Http::fake(['opendata.paris.fr/*' => Http::response($this->brut), 'data.geopf.fr/*' => Http::response(['features' => []])]);

    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie)
        ->and(Offre::where('donnees_normalisees->titre', 'like', 'Paris Sportives%')->count())->toBe(0)
        ->and(Offre::where('donnees_normalisees->titre', 'Lac artificiel')->first()->representation->type)->toBe(TypeRepresentation::Seance)
        ->and(Offre::where('donnees_normalisees->titre', 'Lac artificiel')->first()->representation->genre->slug)->toBe('theatre');
});
