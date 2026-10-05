<?php

use App\Actions\EntretenirCatalogue;
use App\Actions\ExecuterCollecte;
use App\Collecte\Connecteurs\ConnecteurFactice;
use App\Collecte\RegistreConnecteurs;
use App\Enums\StatutRepresentation;
use App\Models\Admin;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Le connecteur factice, privé de certaines annonces (elles « disparaissent du flux »). */
function sansAnnonces(array $identifiants): void
{
    app()->instance(RegistreConnecteurs::class, new class($identifiants) extends RegistreConnecteurs
    {
        public function __construct(private array $retirees) {}

        public function pour(Source $source): ConnecteurFactice
        {
            return new class($this->retirees) extends ConnecteurFactice
            {
                public function __construct(private array $retirees) {}

                public function lire(string $contenuBrut, Source $source): iterable
                {
                    foreach (parent::lire($contenuBrut, $source) as $element) {
                        if (! in_array($element->identifiantExterne ?? null, $this->retirees, true)) {
                            yield $element;
                        }
                    }
                }
            };
        }
    });
}

beforeEach(function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    foreach ([['Avignon', '84007', '84', '84000', 43.9493, 4.8055], ['Marseille', '13055', '13', '13001', 43.2965, 5.3698]] as [$nom, $insee, $dep, $cp, $lat, $lon]) {
        Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => $dep, 'codes_postaux' => [$cp], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    }

    $this->factice = Source::firstWhere('code', 'factice');
    $this->bis = Source::firstWhere('code', 'factice_bis');
    $this->offre = fn (string $id) => Offre::firstWhere('identifiant_externe', $id);
    app(ExecuterCollecte::class)->handle($this->factice);
});

it('retire la représentation quand sa seule offre disparaît du flux', function () {
    $representation = ($this->offre)('F-6')->representation;

    sansAnnonces(['F-6']);
    $collecte = app(ExecuterCollecte::class)->handle($this->factice);

    expect(($this->offre)('F-6')->disparue_le)->not->toBeNull()
        ->and($representation->fresh()->statut)->toBe(StatutRepresentation::Retiree)
        ->and($collecte->nb_retires)->toBe(1);
});

it('garde la représentation tant qu’une autre billetterie la vend', function () {
    app(ExecuterCollecte::class)->handle($this->bis);
    $representation = ($this->offre)('F-1')->representation;
    expect($representation->offres()->count())->toBe(2);

    sansAnnonces(['F-1']);
    $collecte = app(ExecuterCollecte::class)->handle($this->factice);

    expect($representation->fresh()->statut)->toBe(StatutRepresentation::Programmee)
        ->and($representation->fresh()->prix_min)->toBe('16.00') // seule l'offre restante compte
        ->and($representation->fresh()->prix_max)->toBe('16.00')
        ->and($collecte->nb_retires)->toBe(0);
});

it('remet en ligne une représentation dont l’offre réapparaît', function () {
    $representation = ($this->offre)('F-6')->representation;
    sansAnnonces(['F-6']);
    app(ExecuterCollecte::class)->handle($this->factice);

    app()->forgetInstance(RegistreConnecteurs::class);
    app(ExecuterCollecte::class)->handle($this->factice);

    expect(($this->offre)('F-6')->disparue_le)->toBeNull()
        ->and($representation->fresh()->statut)->toBe(StatutRepresentation::Programmee);
});

it('ne retire pas une représentation corrigée ou annulée à la main', function () {
    $corrigee = ($this->offre)('F-6')->representation;
    $annulee = ($this->offre)('F-7')->representation;
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $corrigee->update(['prix_min' => 9]);
    $annulee->update(['statut' => StatutRepresentation::Annulee]);
    auth()->logout();

    sansAnnonces(['F-6', 'F-7']);
    app(ExecuterCollecte::class)->handle($this->factice);

    expect($corrigee->fresh()->statut)->toBe(StatutRepresentation::Programmee)
        ->and($annulee->fresh()->statut)->toBe(StatutRepresentation::Annulee);
});

it('ne touche pas une séance passée sortie du flux', function () {
    $offre = ($this->offre)('F-6');
    $offre->update(['date_locale' => today()->subDays(2)->toDateString()]);

    sansAnnonces(['F-6']);
    app(ExecuterCollecte::class)->handle($this->factice);

    expect($offre->fresh()->disparue_le)->toBeNull()
        ->and($offre->representation->fresh()->statut)->toBe(StatutRepresentation::Programmee);
});

it('garde 48 h les offres d’une source muette, puis les retire', function () {
    $representation = ($this->offre)('F-9')->representation; // dans 4 jours : encore à venir après 49 h

    $this->travel(47)->hours();
    $resultat = app(EntretenirCatalogue::class)->handle();
    expect($resultat['sources_muettes'])->toBe([])
        ->and($representation->fresh()->statut)->toBe(StatutRepresentation::Programmee);

    $this->travel(2)->hours(); // 49 h sans réponse
    $resultat = app(EntretenirCatalogue::class)->handle();
    $aVenir = Offre::where('date_locale', '>=', today()->toDateString())->count();
    expect($resultat['sources_muettes'])->toBe(['factice'])
        ->and($aVenir)->toBeGreaterThan(0)
        ->and($resultat['nb_retires'])->toBe($aVenir) // les séances passées entre-temps ne sont pas « retirées »
        ->and($representation->fresh()->statut)->toBe(StatutRepresentation::Retiree);
});

it('ne considère pas comme muette une source dont le flux n’a simplement pas changé', function () {
    $this->travel(3)->days();
    $this->factice->update(['dernier_contact_le' => now()]); // le détecteur l'a contactée : même version, pas de collecte

    expect(app(EntretenirCatalogue::class)->handle()['sources_muettes'])->toBe([]);
});

it('allège l’historique 30 jours après la séance, en gardant représentation et spectacle', function () {
    $ancienne = ($this->offre)('F-6');
    $recente = ($this->offre)('F-7');
    $ancienne->update(['date_locale' => today()->subDays(31)->toDateString()]);
    $recente->update(['date_locale' => today()->subDays(29)->toDateString()]);

    $resultat = app(EntretenirCatalogue::class)->handle();

    expect($resultat['offres_supprimees'])->toBe(1)
        ->and(Offre::find($ancienne->id))->toBeNull()
        ->and(Offre::find($recente->id))->not->toBeNull()
        ->and(Representation::find($ancienne->representation_id))->not->toBeNull()
        ->and(Spectacle::find($ancienne->spectacle_id))->not->toBeNull();
});

it('supprime les spectacles restés vides, jamais ceux de démonstration', function () {
    $vide = Spectacle::factory()->create(['demo' => false]);
    $demo = Spectacle::factory()->create(['demo' => true]);

    expect(app(EntretenirCatalogue::class)->handle()['spectacles_supprimes'])->toBe(1)
        ->and(Spectacle::find($vide->id))->toBeNull()
        ->and(Spectacle::find($demo->id))->not->toBeNull()
        ->and(($this->offre)('F-1')->spectacle)->not->toBeNull();
});

it('lance l’entretien en commande et affiche les retraits dans « Collectes »', function () {
    $this->artisan('catalogue:entretenir')->expectsOutputToContain('Sources muettes : aucune')->assertSuccessful();

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get('/admin/collectes')->assertOk()->assertSee('Retirées');
});
