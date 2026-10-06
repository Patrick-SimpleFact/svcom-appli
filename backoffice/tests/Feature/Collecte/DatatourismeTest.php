<?php

use App\Actions\DedoublonnerOffre;
use App\Actions\EnregistrerOffre;
use App\Actions\ExecuterCollecte;
use App\Actions\PublierSource;
use App\Actions\RattacherSpectacle;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurDatatourisme;
use App\Collecte\ResultatGenre;
use App\Enums\StatutCollecte;
use App\Enums\TypeRepresentation;
use App\Models\Admin;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\CorrespondancesGenresSeeder;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.datatourisme.cle' => 'cle-de-test']);
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(6, 0));
    $this->seed(SourcesSeeder::class);
    $this->source = Source::firstWhere('code', 'datatourisme');
    $this->brut = file_get_contents(base_path('tests/Fixtures/datatourisme_api.jsonl'));
    [$page1, $page2] = array_map(fn ($l) => json_decode($l, true), explode("\n", $this->brut));
    $this->annonces = fn () => collect(app(ConnecteurDatatourisme::class)->lire($this->brut, $this->source))->whereInstanceOf(AnnonceNormalisee::class);
    $this->fauxApi = fn () => Http::fake([
        'api.datatourisme.fr/v1/entertainmentAndEvent?page=2*' => Http::response(['objects' => $page2, 'meta' => ['next' => null]]),
        'api.datatourisme.fr/v1/entertainmentAndEvent*' => Http::response(['objects' => $page1, 'meta' => ['next' => 'https://api.datatourisme.fr/v1/entertainmentAndEvent?page=2&crs=suite']]),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);
});

it('lit une séance à l’heure, avec son prix, son lien de réservation et sa ville', function () {
    $piece = ($this->annonces)()->first(fn (AnnonceNormalisee $a) => str_contains($a->titre, 'Le parfait manuel'));

    expect($piece->debut->format('Y-m-d H:i e'))->toBe('2026-12-10 19:00 Europe/Paris')
        ->and($piece->heureConnue)->toBeTrue()
        ->and($piece->prixMin)->toBe(8.0)
        ->and($piece->prixMax)->toBe(16.0)
        ->and($piece->lien)->toContain('mapado.com')            // billetterie de l'Astrolabe
        ->and($piece->lieuVille)->toBe('Figeac')
        ->and($piece->lieuCodePostal)->toBe('46100')
        ->and($piece->categoriesSource)->toContain('TheaterEvent');
});

it('donne une séance par soir à un spectacle sur plusieurs jours avec heure, et une période sans heure', function () {
    $colocs = ($this->annonces)()->filter(fn (AnnonceNormalisee $a) => $a->titre === 'Les colocs');
    $fete = ($this->annonces)()->filter(fn (AnnonceNormalisee $a) => $a->titre === 'Fête du sol vivant');

    expect($colocs->map(fn ($a) => $a->debut->format('d/m H:i'))->values()->all())->toBe(['23/02 21:00', '24/02 21:00'])
        ->and($fete)->toHaveCount(1)
        ->and($fete->first()->heureConnue)->toBeFalse()
        ->and($fete->first()->fin->format('Y-m-d'))->toBe('2026-10-18');
});

it('sépare le nom de la salle de son adresse', function () {
    $lieux = ($this->annonces)()->map(fn (AnnonceNormalisee $a) => [$a->lieuNom, $a->lieuAdresse]);

    expect($lieux->every(fn ($l) => $l[0] === null || ! preg_match('/^\d/', $l[0])))->toBeTrue()
        ->and($lieux->filter(fn ($l) => $l[0] !== null))->not->toBeEmpty();
});

it('télécharge toutes les pages, avec la clé dans l’en-tête et le filtre « spectacles à venir »', function () {
    ($this->fauxApi)();

    $brut = app(ConnecteurDatatourisme::class)->telecharger($this->source);

    expect(explode("\n", $brut))->toHaveCount(2);
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-Key', 'cle-de-test')
        && str_contains(urldecode($r->url()), 'type[in]=ShowEvent,TheaterEvent')
        && str_contains(urldecode($r->url()), 'takesPlaceAt.endDate[gte]=2026-10-06'));
});

it('signale clairement une clé absente, et se collecte une fois par jour tôt le matin', function () {
    config(['services.datatourisme.cle' => null]);
    $connecteur = app(ConnecteurDatatourisme::class);

    expect(fn () => $connecteur->telecharger($this->source))->toThrow(RuntimeException::class, 'DATATOURISME_API_KEY')
        ->and($connecteur->intervalleHeures())->toBe(24)
        ->and($connecteur->plageHoraire())->toBe([5, 9]);
});

it('collecte par l’API, et une séance de l’ancien export « horaire à confirmer » prend l’heure', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, CorrespondancesGenresSeeder::class]);
    $figeac = Ville::create(['nom' => 'Figeac', 'nom_normalise' => 'figeac', 'code_insee' => '46102', 'departement' => '46', 'codes_postaux' => ['46100'], 'population' => 9800, 'position' => new Point(44.6086, 2.0317), 'fuseau_horaire' => 'Europe/Paris']);

    // Ce qu'avait publié l'export data.gouv.fr (N03) : le même spectacle, sans heure, sous un autre identifiant.
    $lieu = Lieu::create(['nom' => '2 bd Pasteur', 'type' => 'autre', 'adresse' => '2 bd Pasteur', 'code_postal' => '46100', 'ville_id' => $figeac->id, 'position' => new Point(44.60873, 2.02583), 'precision_position' => 'exacte', 'fuseau_horaire' => 'Europe/Paris']);
    $ancienne = new AnnonceNormalisee('b83f2ceb@2026-12-10', 'Théâtre à Figeac : Le parfait manuel (à l\'usage des futurs dictateurs)', CarbonImmutable::parse('2026-12-10', 'Europe/Paris'), false, 'https://www.astrolabe-grand-figeac.fr', lieuAdresse: '2 bd Pasteur', lieuCodePostal: '46100', lieuVille: 'Figeac', identifiantSpectacle: 'b83f2ceb');
    [$offre] = app(EnregistrerOffre::class)->handle($ancienne, $this->source, $lieu, new ResultatGenre(Genre::firstWhere('slug', 'theatre'), false, null, ResultatGenre::PAR_MOT));
    app(DedoublonnerOffre::class)->handle($offre);
    app(RattacherSpectacle::class)->handle($offre->fresh());
    app(PublierSource::class)->handle($this->source, null, [$offre->id]);
    $representation = $offre->fresh()->representation;
    expect($representation->type)->toBe(TypeRepresentation::Jour);

    ($this->fauxApi)();
    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie)
        ->and($representation->fresh()->type)->toBe(TypeRepresentation::Seance)
        ->and($representation->fresh()->debut->setTimezone('Europe/Paris')->format('H:i'))->toBe('19:00')
        ->and($representation->fresh()->prix_min)->toBe('8.00')
        ->and($offre->fresh()->disparue_le)->not->toBeNull() // l'ancienne offre de l'export a disparu…
        ->and(Representation::whereDate('date_locale', '2026-12-10')->whereHas('ville', fn ($q) => $q->where('nom', 'Figeac'))->count())->toBe(1); // …sans doublon

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get('/admin/spectacles')->assertOk();
});
