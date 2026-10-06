<?php

use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurBilletReduc;
use App\Collecte\LigneIllisible;
use App\Enums\FileATraiter;
use App\Enums\StatutCollecte;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Parametre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Horizon;
use App\Support\Point;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const LISTE_FLUX_AWIN = 'https://ui.awin.com/productdata-darwin-download/publisher/1/secret/1/feedList';

/** Liste des flux Awin simulée : BilletRéduc et Fnac. */
function listeFluxAwin(string $billetreducImporte = '2026-10-05 00:15:55'): string
{
    return "Advertiser ID,Advertiser Name,Feed ID,Last Imported,No of products,URL\n"
        ."12494,Fnac Spectacles FR,23455,2026-10-05 07:30:38,107639,https://flux.awin.test/fnac.csv.gz\n"
        ."20796,BilletReduc FR,47175,{$billetreducImporte},11873,https://flux.awin.test/billetreduc.csv.gz\n";
}

beforeEach(function () {
    config(['services.awin.liste_flux' => LISTE_FLUX_AWIN]);
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
    $this->seed(SourcesSeeder::class);
    $this->source = Source::firstWhere('code', 'billetreduc');
    $this->csv = file_get_contents(base_path('tests/Fixtures/billetreduc.csv'));
    $this->lire = fn (?string $contenu = null) => collect(app(ConnecteurBilletReduc::class)->lire($contenu ?? gzencode($this->csv), $this->source));
});

it('lit chaque séance à venir comme une annonce, avec son spectacle, son lieu et son prix', function () {
    $elements = ($this->lire)();
    $annonces = $elements->whereInstanceOf(AnnonceNormalisee::class);

    $psy = $annonces->firstWhere('identifiantExterne', '397734@2026-10-16T20:00');
    expect($psy->titre)->toBe('Mon Psy Chéri')
        ->and($psy->identifiantSpectacle)->toBe('397734')
        ->and($psy->debut->format('Y-m-d H:i e'))->toBe('2026-10-16 20:00 Europe/Paris')
        ->and($psy->heureConnue)->toBeTrue()
        ->and($psy->lieuNom)->toBe("Théâtre de l'Observance - salle 2")
        ->and($psy->lieuVille)->toBe('Avignon')
        ->and($psy->lieuCodePostal)->toBe('84000')
        ->and($psy->lieuLatitude)->toBeFloat()
        ->and($psy->categoriesSource)->toBe(['Théâtre', 'Comédies pop’'])
        ->and($psy->lien)->toStartWith('https://www.awin1.com/')
        ->and($psy->prixMin)->toBeLessThanOrEqual($psy->prixMax)
        ->and($psy->complet)->toBeFalse();

    // Toutes les séances des spectacles du fichier (2 + 2 + 2 + 1 + 3 + 1 + 3 + 2 + 1) ; la ligne cassée à part.
    expect($annonces)->toHaveCount(17)
        ->and($elements->whereInstanceOf(LigneIllisible::class))->toHaveCount(1)
        ->and($annonces->pluck('identifiantExterne')->unique())->toHaveCount(17);
});

it('donne le « complet » de chaque séance et les artistes du spectacle', function () {
    $annonces = ($this->lire)()->whereInstanceOf(AnnonceNormalisee::class);

    expect($annonces->firstWhere('identifiantSpectacle', '401237')->complet)->toBeTrue()
        ->and($annonces->firstWhere('identifiantSpectacle', '400479')->artistes)->toBe(['Natacha Sardou', 'David Blanc'])
        ->and($annonces->firstWhere('identifiantSpectacle', '400479')->description)->toContain('Axel et Suzanne')
        ->and($annonces->firstWhere('identifiantSpectacle', '400479')->description)->not->toContain('<div>');
});

it('ignore les séances déjà passées', function () {
    $this->travelTo(now()->setDate(2026, 10, 12));

    $esprit = ($this->lire)()->whereInstanceOf(AnnonceNormalisee::class)->where('identifiantSpectacle', '400479');

    expect($esprit->pluck('debut')->map->format('Y-m-d')->all())->each->toBeGreaterThanOrEqual('2026-10-12');
    expect($esprit)->toHaveCount(1);
});

it('lit la version dans la colonne « Last Imported » de la liste des flux', function () {
    Http::fake([LISTE_FLUX_AWIN => Http::response(listeFluxAwin('2026-10-06 00:20:11'))]);

    expect(app(ConnecteurBilletReduc::class)->versionDisponible($this->source))->toBe('2026-10-06 00:20:11');
});

it('signale clairement une adresse de liste des flux absente', function () {
    config(['services.awin.liste_flux' => null]);

    expect(fn () => app(ConnecteurBilletReduc::class)->versionDisponible($this->source))
        ->toThrow(RuntimeException::class, 'AWIN_FEEDLIST_URL');
});

it('collecte BilletRéduc de bout en bout : tri, lieux, genres, publication', function () {
    Storage::fake('collecte');
    Http::fake([
        LISTE_FLUX_AWIN => Http::response(listeFluxAwin()),
        'flux.awin.test/billetreduc.csv.gz' => Http::response(gzencode($this->csv)),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class]);
    Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => ['84000'], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);

    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie)
        ->and($collecte->fichier_brut)->toEndWith('.csv.gz')
        ->and($collecte->nb_recus)->toBe(17)
        ->and($collecte->nb_illisibles)->toBe(1)
        ->and($collecte->nb_exclus)->toBe(2) // l'exposition « Dinosaures » : ses 2 séances
        ->and($collecte->nb_nouveaux)->toBe(Representation::count());

    // Le tri raisonne par spectacle : une seule ligne « À trier » au plus par spectacle.
    $aTrier = ElementATraiter::where('file', FileATraiter::ATrier)->pluck('donnees')->pluck('identifiant_externe');
    expect($aTrier->duplicates())->toBeEmpty();

    // L'Observance, salle 1 et salle 2 : un seul lieu.
    $observance = Offre::where('donnees_normalisees->lieu_nom', 'like', "Théâtre de l'Observance%")->pluck('lieu_id')->unique();
    expect($observance)->toHaveCount(1)
        ->and(Lieu::find($observance->first())->fuseau_horaire)->toBe('Europe/Paris');

    // Une seconde collecte identique ne republie rien.
    Http::fake([LISTE_FLUX_AWIN => Http::response(listeFluxAwin()), 'flux.awin.test/billetreduc.csv.gz' => Http::response(gzencode($this->csv))]);
    $seconde = app(ExecuterCollecte::class)->handle($this->source);
    expect([$seconde->nb_nouveaux, $seconde->nb_mis_a_jour, $seconde->nb_retires])->toBe([0, 0, 0]);
});

it('ne collecte pas les séances au-delà de l’horizon (fin du mois, N mois après aujourd’hui)', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class]);
    Parametre::firstWhere('cle', 'horizon_mois')->update(['valeur' => 1]); // le 05/10/2026 → jusqu'au 30/11/2026
    Http::fake([
        LISTE_FLUX_AWIN => Http::response(listeFluxAwin()),
        'flux.awin.test/billetreduc.csv.gz' => Http::response(gzencode($this->csv)),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);

    expect(Horizon::dateLimite()->format('Y-m-d'))->toBe('2026-11-30');

    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->nb_hors_horizon)->toBeGreaterThan(0) // « A la fin il meurt » (avril 2027), Tristan Lopin (mars 2027)…
        ->and(Offre::where('date_locale', '>', '2026-11-30')->count())->toBe(0)
        ->and(Offre::where('date_locale', '<=', '2026-11-30')->count())->toBeGreaterThan(0);
});
