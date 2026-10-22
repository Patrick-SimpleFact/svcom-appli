<?php

use App\Actions\ExecuterCollecte;
use App\Actions\SortirElementsPasses;
use App\Actions\TrierAnnonce;
use App\Collecte\AnnonceNormalisee;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\ElementATraiter;
use App\Models\Offre;
use App\Models\Parametre;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    foreach ([['Avignon', '84007', '84', '84000', 43.9493, 4.8055], ['Marseille', '13055', '13', '13001', 43.2965, 5.3698]] as [$nom, $insee, $dep, $cp, $lat, $lon]) {
        Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => $dep, 'codes_postaux' => [$cp], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    }

    $this->source = Source::firstWhere('code', 'factice');
    app(ExecuterCollecte::class)->handle($this->source);
    $this->offre = fn (string $id) => Offre::firstWhere('identifiant_externe', $id);
    $this->passee = fn (Offre $offre) => $offre->update(['date_locale' => today()->subDays(2)->toDateString(), 'date_fin' => null]);

    $this->paire = fn (FileATraiter $file, Offre $a, Offre $b) => ElementATraiter::create([
        'file' => $file, 'cible_type' => $b->getMorphClass(), 'cible_id' => $b->id,
        'donnees' => ['offre_a_id' => $a->id, 'offre_b_id' => $b->id, 'ecart_minutes' => 45, 'ressemblance' => 0.75],
    ]);
    $this->aTrier = function (string $id, CarbonImmutable $debut): ElementATraiter {
        app(TrierAnnonce::class)->handle(new AnnonceNormalisee(
            identifiantExterne: $id, titre: "Soirée {$id}", debut: $debut, heureConnue: true, lien: 'https://exemple.fr', lieuNom: 'Salle des fêtes', lieuVille: 'Avignon',
        ), $this->source);

        return ElementATraiter::where('file', FileATraiter::ATrier)->where('donnees->identifiant_externe', $id)->sole();
    };
    $this->aClasser = fn (string $categorie) => ElementATraiter::create([
        'file' => FileATraiter::AClasser, 'cible_type' => $this->source->getMorphClass(), 'cible_id' => $this->source->id,
        'donnees' => ['categorie' => $categorie, 'nb_annonces' => 1, 'exemples' => [], 'identifiants' => []],
    ]);
    $this->categorie = fn (Offre $offre, string $categorie) => $offre->update(['donnees_normalisees' => [...$offre->donnees_normalisees, 'categories_source' => [$categorie]]]);
});

it('sort les paires de séances toutes deux passées, garde celles dont une séance est à venir', function () {
    [$a, $b, $c] = [($this->offre)('F-1'), ($this->offre)('F-6'), ($this->offre)('F-7')];
    ($this->passee)($a);
    ($this->passee)($b);
    $passee = ($this->paire)(FileATraiter::DoublonProbable, $a, $b);
    $fusionPassee = ($this->paire)(FileATraiter::FusionAControler, $a, $b);
    $aVenir = ($this->paire)(FileATraiter::DoublonProbable, $a, $c);

    $resultat = app(SortirElementsPasses::class)->handle();

    expect($resultat[FileATraiter::DoublonProbable->value])->toBe(1)
        ->and($resultat[FileATraiter::FusionAControler->value])->toBe(1)
        ->and($passee->fresh()->statut)->toBe(StatutElement::Traite)
        ->and($passee->fresh()->decision)->toBe(['sans_objet' => 'séances passées'])
        ->and($fusionPassee->fresh()->statut)->toBe(StatutElement::Traite)
        ->and($aVenir->fresh()->statut)->toBe(StatutElement::EnAttente);
});

it('garde une paire dont une séance court encore sur une période', function () {
    [$a, $b] = [($this->offre)('F-1'), ($this->offre)('F-6')];
    ($this->passee)($a);
    $b->update(['date_locale' => today()->subDays(5)->toDateString(), 'date_fin' => today()->addDays(5)->toDateString()]);
    $paire = ($this->paire)(FileATraiter::DoublonProbable, $a, $b);

    app(SortirElementsPasses::class)->handle();

    expect($paire->fresh()->statut)->toBe(StatutElement::EnAttente);
});

it('sort les annonces à trier dont la séance est passée ; elles reviennent si la source les publie encore', function () {
    $passee = ($this->aTrier)('T-1', CarbonImmutable::now('Europe/Paris')->subDays(2));
    $aVenir = ($this->aTrier)('T-2', CarbonImmutable::now('Europe/Paris')->addDays(3));

    expect(app(SortirElementsPasses::class)->handle()[FileATraiter::ATrier->value])->toBe(1)
        ->and($passee->fresh()->statut)->toBe(StatutElement::Traite)
        ->and($aVenir->fresh()->statut)->toBe(StatutElement::EnAttente);

    // « Sans objet » n'est pas une décision : si l'annonce est encore au programme, elle revient à trier.
    ($this->aTrier)('T-1', CarbonImmutable::now('Europe/Paris')->addDays(1));
    expect($passee->fresh()->statut)->toBe(StatutElement::EnAttente);
});

it('sort les catégories à classer sans séance à venir chez leur source', function () {
    ($this->categorie)(($this->offre)('F-6'), 'Catégorie vivante');
    $passee = ($this->offre)('F-7');
    ($this->categorie)($passee, 'Catégorie éteinte');
    ($this->passee)($passee);
    $disparue = ($this->offre)('F-9');
    ($this->categorie)($disparue, 'Catégorie disparue');
    $disparue->update(['disparue_le' => now()]);

    [$vivante, $eteinte, $sansOffre] = [($this->aClasser)('Catégorie vivante'), ($this->aClasser)('Catégorie éteinte'), ($this->aClasser)('Catégorie disparue')];

    expect(app(SortirElementsPasses::class)->handle()[FileATraiter::AClasser->value])->toBe(2)
        ->and($vivante->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and($eteinte->fresh()->statut)->toBe(StatutElement::Traite)
        ->and($sansOffre->fresh()->statut)->toBe(StatutElement::Traite);
});

it('sort les spectacles à contrôler sans séance à venir, garde ceux qui se jouent encore', function () {
    [$a, $b] = [($this->offre)('F-1'), ($this->offre)('F-6')];
    $controle = fn (Offre $o) => ElementATraiter::create(['file' => FileATraiter::SpectacleAControler, 'cible_type' => $o->spectacle->getMorphClass(),
        'cible_id' => $o->spectacle_id, 'donnees' => ['titre' => $o->spectacle->titre, 'motif' => 'titre seul']]);
    Offre::where('spectacle_id', $a->spectacle_id)->get()->each(fn (Offre $o) => ($this->passee)($o));
    [$fini, $enCours] = [$controle($a), $controle($b)];

    app(SortirElementsPasses::class)->handle();

    expect($fini->fresh()->statut)->toBe(StatutElement::Traite)
        ->and($fini->fresh()->decision)->toBe(['sans_objet' => 'séances passées'])
        ->and($enCours->fresh()->statut)->toBe(StatutElement::EnAttente);
});

it('est planifiée chaque jour à l’heure réglée (4 h de Paris par défaut)', function () {
    $evenement = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'boite:sortir-passes'));
    $passeA = function (string $heure) use ($evenement): bool {
        $this->travelTo(CarbonImmutable::parse("2026-10-23 {$heure}", 'Europe/Paris'));

        return $evenement->isDue(app()) && $evenement->filtersPass(app());
    };

    expect($evenement)->not->toBeNull()
        ->and($passeA('04:00'))->toBeTrue()
        ->and($passeA('04:01'))->toBeFalse()
        ->and($passeA('05:30'))->toBeFalse();

    Parametre::firstWhere('cle', 'boite_heure_sortie_passes')->update(['valeur' => '05:30']);

    expect($passeA('05:30'))->toBeTrue()
        ->and($passeA('04:00'))->toBeFalse();
});

it('ne touche ni aux autres files ni aux éléments déjà traités', function () {
    [$a, $b] = [($this->offre)('F-1'), ($this->offre)('F-6')];
    ($this->passee)($a);
    ($this->passee)($b);
    $dejaTraite = ($this->paire)(FileATraiter::DoublonProbable, $a, $b);
    $dejaTraite->update(['statut' => StatutElement::Traite, 'decision' => ['type' => 'separer']]);

    app(SortirElementsPasses::class)->handle();

    expect($dejaTraite->fresh()->decision)->toBe(['type' => 'separer']);
});

it('compte sans rien modifier en simulation', function () {
    [$a, $b] = [($this->offre)('F-1'), ($this->offre)('F-6')];
    ($this->passee)($a);
    ($this->passee)($b);
    $paire = ($this->paire)(FileATraiter::DoublonProbable, $a, $b);

    $this->artisan('boite:sortir-passes', ['--simulation' => true])
        ->expectsOutputToContain('Simulation : rien n’a été modifié.')
        ->assertSuccessful();

    expect($paire->fresh()->statut)->toBe(StatutElement::EnAttente);

    $this->artisan('boite:sortir-passes')->assertSuccessful();
    expect($paire->fresh()->statut)->toBe(StatutElement::Traite);
});
