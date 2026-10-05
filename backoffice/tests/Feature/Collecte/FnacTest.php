<?php

use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\Connecteurs\ConnecteurFnac;
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

const LISTE_FLUX_AWIN_N02 = 'https://ui.awin.com/productdata-darwin-download/publisher/1/secret/1/feedList';

beforeEach(function () {
    config(['services.awin.liste_flux' => LISTE_FLUX_AWIN_N02]);
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
    $this->seed(SourcesSeeder::class);
    $this->fnac = Source::firstWhere('code', 'fnac');
    $this->csv = file_get_contents(base_path('tests/Fixtures/fnac.csv'));
    $this->lire = fn () => collect(app(ConnecteurFnac::class)->lire(gzencode($this->csv), $this->fnac));
});

it('lit chaque ligne comme une séance, avec date, heure, lieu et spectacle', function () {
    $elements = ($this->lire)();
    $annonces = $elements->whereInstanceOf(AnnonceNormalisee::class);

    $escroc = $annonces->firstWhere('identifiantExterne', '21733042');
    expect($escroc->titre)->toStartWith('Mon Père Cet Escroc')
        ->and($escroc->debut->format('Y-m-d H:i e'))->toBe('2026-10-07 20:00 Europe/Paris')
        ->and($escroc->heureConnue)->toBeTrue()
        ->and($escroc->lieuNom)->toBe('THEATRE MOLIERE')
        ->and($escroc->lieuVille)->toBe('BORDEAUX')
        ->and($escroc->identifiantSpectacle)->not->toBeNull()
        ->and($escroc->categoriesSource)->toContain('Comédie');

    // Annulée (« 1 - CANCELED ») et à l'étranger (Édimbourg) : pas d'annonce ; la ligne cassée à part.
    expect($annonces->pluck('identifiantExterne'))->not->toContain('19542908')
        ->and($annonces->pluck('identifiantExterne'))->not->toContain('21427211')
        ->and($annonces)->toHaveCount(8)
        ->and($elements->whereInstanceOf(LigneIllisible::class))->toHaveCount(1);
});

it('traite les coordonnées 0,0 comme inconnues et « NO_AMOUNT » comme complet', function () {
    $naim = ($this->lire)()->whereInstanceOf(AnnonceNormalisee::class)->firstWhere('identifiantExterne', '19909461');

    expect($naim->lieuLatitude)->toBeNull()
        ->and($naim->lieuLongitude)->toBeNull()
        ->and($naim->lieuAdresse)->toBe("1 PLACE DE L'EUROPE")
        ->and($naim->complet)->toBeTrue()
        ->and($naim->artistes)->toBe(['Naïm']);
});

it('publie une seule représentation et deux offres quand BilletRéduc et la Fnac vendent la même séance', function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class]);
    foreach ([['Avignon', '84007', '84', '84000', 43.9493, 4.8055], ['Bordeaux', '33063', '33', '33000', 44.8378, -0.5792]] as [$nom, $insee, $dep, $cp, $lat, $lon]) {
        Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => $dep, 'codes_postaux' => [$cp], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    }
    // Adresses Fnac sans coordonnées : la Base Adresse Nationale les place à côté de la salle connue par BilletRéduc.
    Http::fake([
        LISTE_FLUX_AWIN_N02 => Http::response("Advertiser ID,Feed ID,Last Imported,URL\n20796,47175,2026-10-05 00:15:55,https://flux.awin.test/br.csv.gz\n12494,23455,2026-10-05 07:30:38,https://flux.awin.test/fnac.csv.gz\n"),
        'flux.awin.test/br.csv.gz' => Http::response(gzencode(file_get_contents(base_path('tests/Fixtures/billetreduc_recoupement.csv')))),
        'flux.awin.test/fnac.csv.gz' => Http::response(gzencode($this->csv)),
        'data.geopf.fr/*' => Http::response(['features' => []]),
    ]);

    app(ExecuterCollecte::class)->handle(Source::firstWhere('code', 'billetreduc'));
    $collecte = app(ExecuterCollecte::class)->handle($this->fnac);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie);

    // Les cinq spectacles vendus par les deux : la séance Fnac rejoint la séance BilletRéduc.
    foreach (['21733042', '21654191', '21322565', '20162870'] as $id) {
        $fnac = Offre::where('source_id', $this->fnac->id)->firstWhere('identifiant_externe', $id);
        expect($fnac->meme_seance_que_id)->not->toBeNull("{$fnac->donnees_normalisees['titre']} devrait rejoindre la séance BilletRéduc")
            ->and($fnac->representation_id)->toBe($fnac->memeSeanceQue->representation_id)
            ->and($fnac->representation->offres()->count())->toBe(2);
    }

    // Expo et parc d'attractions : écartés ou mis de côté, jamais publiés.
    expect(Offre::where('source_id', $this->fnac->id)->whereIn('identifiant_externe', ['21097977', '20768391'])->whereNotNull('representation_id')->count())->toBe(0);
});
