<?php

use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurTicketmaster;
use App\Collecte\LigneIllisible;
use App\Enums\StatutCollecte;
use App\Models\Offre;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const FICHIER_TICKETMASTER = 'https://s3.amazonaws.com/feeds/20261006/EVENTS_RAW-FR-f974dd37-2026-10-06_142710.json.gz';

beforeEach(function () {
    config(['services.ticketmaster.cle' => 'cle-de-test']);
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(16, 0));
    $this->seed(SourcesSeeder::class);
    $this->source = Source::firstWhere('code', 'ticketmaster');
    $this->flux = gzencode(file_get_contents(base_path('tests/Fixtures/ticketmaster.json')));
    $this->lire = fn () => collect(app(ConnecteurTicketmaster::class)->lire($this->flux, $this->source));
});

it('lit le flux national événement par événement, sans les annulés', function () {
    $elements = ($this->lire)();
    $annonces = $elements->whereInstanceOf(AnnonceNormalisee::class);

    expect($elements->whereInstanceOf(LigneIllisible::class))->toBeEmpty() // accolades dans les textes : bien lues
        ->and($annonces)->toHaveCount(6)                                     // 8 événements, dont 2 annulés
        ->and($annonces->pluck('titre'))->not->toContain('PUTAIN DE RENAUD !');

    $lopin = $annonces->firstWhere('identifiantExterne', 'ZkyMmBwZ1A7kF0v');
    expect($lopin->debut->format('Y-m-d H:i e'))->toBe('2027-03-13 20:30 Europe/Paris')
        ->and($lopin->heureConnue)->toBeTrue()
        ->and($lopin->lieuVille)->toStartWith('Avignon')
        ->and($lopin->artistes)->toHaveCount(1)
        ->and($lopin->categoriesSource)->toContain('Arts & Theatre')
        ->and($lopin->lien)->toStartWith('https://www.ticketmaster.fr/');
});

it('regroupe pour le tri les séances d’un même spectacle dans une même salle', function () {
    $laroque = ($this->lire)()->whereInstanceOf(AnnonceNormalisee::class)->filter(fn ($a) => str_starts_with($a->titre, 'MICHELE LAROQUE'));

    expect($laroque)->toHaveCount(2)
        ->and($laroque->pluck('identifiantSpectacle')->unique())->toHaveCount(1);
});

it('lit la version dans le nom du fichier vers lequel le flux redirige', function () {
    Http::fake(['app.ticketmaster.com/*' => Http::response('', 303, ['Location' => FICHIER_TICKETMASTER])]);

    expect(app(ConnecteurTicketmaster::class)->versionDisponible($this->source))->toBe('EVENTS_RAW-FR-f974dd37-2026-10-06_142710.json.gz');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'apikey=cle-de-test') && str_contains($r->url(), 'countryCode=FR'));
});

it('signale clairement une clé absente', function () {
    config(['services.ticketmaster.cle' => null]);

    expect(fn () => app(ConnecteurTicketmaster::class)->versionDisponible($this->source))->toThrow(RuntimeException::class, 'TICKETMASTER_CONSUMER_KEY');
});

it('fusionne la séance Ticketmaster avec la même séance vendue par BilletRéduc', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class]);
    Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => ['84000'], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    config(['services.awin.liste_flux' => 'https://ui.awin.com/liste']);
    Http::fake([
        'ui.awin.com/liste' => Http::response("Advertiser ID,Feed ID,Last Imported,URL\n20796,47175,2026-10-06 00:15:55,https://flux.awin.test/br.csv.gz\n"),
        'flux.awin.test/br.csv.gz' => Http::response(gzencode(file_get_contents(base_path('tests/Fixtures/billetreduc.csv')))),
        'app.ticketmaster.com/*' => Http::response($this->flux),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);

    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'billetreduc'));
    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie);

    $tm = Offre::where('source_id', $this->source->id)->firstWhere('identifiant_externe', 'ZkyMmBwZ1A7kF0v');
    $br = Offre::where('donnees_normalisees->titre', 'like', 'Tristan Lopin%')->where('source_id', '!=', $this->source->id)->first();
    expect($tm->representation_id)->toBe($br->representation_id)
        ->and($tm->representation->offres()->count())->toBe(2);
});
