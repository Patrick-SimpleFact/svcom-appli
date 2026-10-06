<?php

use App\Actions\DeciderDoublon;
use App\Actions\EnregistrerCorrespondanceGenre;
use App\Actions\ExecuterCollecte;
use App\Actions\PublierSource;
use App\Collecte\Connecteurs\ConnecteurFactice;
use App\Collecte\RegistreConnecteurs;
use App\Enums\StatutCollecte;
use App\Enums\StatutRepresentation;
use App\Enums\TypeDecisionDedoublonnage;
use App\Enums\TypeRepresentation;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\OffresRelationManager;
use App\Filament\Resources\Spectacles\RelationManagers\RepresentationsRelationManager;
use App\Models\Admin;
use App\Models\Collecte;
use App\Models\Genre;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Ville;
use App\Support\Point;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('collecte');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    foreach ([['Avignon', '84007', '84', '84000', 43.9493, 4.8055], ['Marseille', '13055', '13', '13001', 43.2965, 5.3698]] as [$nom, $insee, $dep, $cp, $lat, $lon]) {
        Ville::create(['nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => $dep, 'codes_postaux' => [$cp], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    }

    $this->factice = Source::firstWhere('code', 'factice');
    $this->bis = Source::firstWhere('code', 'factice_bis');
    // La seconde billetterie de démonstration est moins fiable : ses horaires ne doivent pas l'emporter.
    $this->bis->update(['fiabilite' => ['horaire' => 'faible', 'prix' => 'faible', 'complet' => 'faible', 'description' => 'faible', 'lieu' => 'faible']]);
    $this->factice->update(['fiabilite' => ['horaire' => 'elevee', 'prix' => 'elevee', 'complet' => 'elevee', 'description' => 'moyenne', 'lieu' => 'moyenne']]);

    $this->collecter = fn (Source $source) => app(ExecuterCollecte::class)->handle($source);
    // Republie les offres déjà en base, sans relire le flux (qui remettrait ses propres valeurs).
    $this->republier = fn (Source $source) => app(PublierSource::class)->handle($source, null, Offre::where('source_id', $source->id)->pluck('id')->all());
    $this->offre = fn (string $id) => Offre::firstWhere('identifiant_externe', $id);
});

it('publie une représentation par séance, sous son spectacle, en fin de collecte', function () {
    $collecte = ($this->collecter)($this->factice);

    expect(Representation::count())->toBe(8) // 8 annonces gardées, toutes des séances différentes
        ->and($collecte->nb_nouveaux)->toBe(8)
        ->and($collecte->nb_mis_a_jour)->toBe(0);

    $comedie = ($this->offre)('F-1')->representation;
    expect($comedie->spectacle_id)->toBe(($this->offre)('F-1')->spectacle_id)
        ->and($comedie->type)->toBe(TypeRepresentation::Seance)
        ->and($comedie->statut)->toBe(StatutRepresentation::Programmee)
        ->and($comedie->prix_min)->toBe('18.00')
        ->and($comedie->lieu->nom)->toBe('Théâtre du Chêne noir')
        ->and($comedie->genre->slug)->toBe('theatre');
});

it('publie une seule représentation et deux offres quand deux billetteries vendent la séance', function () {
    ($this->collecter)($this->factice);
    $collecte = ($this->collecter)($this->bis);

    $f1 = ($this->offre)('F-1');
    $b1 = ($this->offre)('B-1');
    $representation = $f1->representation;

    expect($b1->representation_id)->toBe($representation->id)
        ->and($representation->offres()->count())->toBe(2)
        ->and($representation->prix_min)->toBe('16.00') // le moins cher des deux
        ->and($representation->prix_max)->toBe('18.00')
        // 20:30 (source fiable) et non 20:45 (source moins fiable)
        ->and($representation->debut->setTimezone('Europe/Paris')->format('H:i'))->toBe('20:30')
        ->and($collecte->nb_nouveaux)->toBe(2) // la pièce à 21:15 (séance séparée) et le conte
        ->and($collecte->nb_mis_a_jour)->toBe(2); // la comédie (prix minimum 16 €) et le concert (prix maximum 14 €)
});

it('ne marque une séance complète que si toutes les billetteries le sont', function () {
    ($this->collecter)($this->factice);
    ($this->collecter)($this->bis);
    $representation = ($this->offre)('F-1')->representation;

    ($this->offre)('B-1')->update(['complet' => true]);
    ($this->republier)($this->bis);
    expect($representation->fresh()->complet)->toBeFalse();

    ($this->offre)('F-1')->update(['complet' => true]);
    ($this->republier)($this->bis);
    expect($representation->fresh()->complet)->toBeTrue();
});

it('ne change rien au catalogue si la collecte échoue en route', function () {
    ($this->collecter)($this->factice);
    $avant = Representation::orderBy('id')->get(['id', 'debut', 'prix_min'])->toArray();

    // La lecture échoue après quelques annonces (fichier tronqué).
    $this->factice->update(['config' => ['simuler_echec' => false, 'jeu' => 'tronque']]);
    $this->mock(RegistreConnecteurs::class, function ($mock) {
        $mock->shouldReceive('pour')->andReturn(new class extends ConnecteurFactice
        {
            public function lire(string $contenuBrut, Source $source): iterable
            {
                $n = 0;
                foreach (parent::lire($contenuBrut, $source) as $element) {
                    if (++$n === 3) {
                        throw new RuntimeException('Fichier tronqué');
                    }
                    yield $element;
                }
            }
        });
    });

    expect(fn () => app(ExecuterCollecte::class)->handle($this->factice))->toThrow(RuntimeException::class);
    expect(Collecte::latest('id')->first()->statut)->toBe(StatutCollecte::Echouee)
        ->and(Representation::orderBy('id')->get(['id', 'debut', 'prix_min'])->toArray())->toBe($avant);
});

it('n’écrase jamais une heure corrigée à la main, ni une séance masquée', function () {
    ($this->collecter)($this->factice);
    $representation = ($this->offre)('F-1')->representation;
    $corrigee = $representation->debut->setTimezone('Europe/Paris')->setTime(21, 0);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $representation->update(['debut' => $corrigee, 'statut' => StatutRepresentation::Masquee]);
    auth()->logout();

    expect($representation->fresh()->champs_verrouilles)->toContain('debut');

    ($this->offre)('F-1')->update(['prix_min' => 22]);
    ($this->collecter)($this->factice);

    $apres = $representation->fresh();
    expect($apres->debut->setTimezone('Europe/Paris')->format('H:i'))->toBe('21:00')
        ->and($apres->statut)->toBe(StatutRepresentation::Masquee)
        ->and($apres->prix_min)->toBe('18.00'); // la collecte a remis le prix du flux : non verrouillé
});

it('publie une séance sans heure comme « horaire à confirmer » à sa date', function () {
    ($this->collecter)($this->factice);
    $offre = ($this->offre)('F-1');
    $offre->update(['heure_connue' => false]);

    ($this->republier)($this->factice);

    $representation = $offre->fresh()->representation;
    expect($representation->type)->toBe(TypeRepresentation::Jour)
        ->and($representation->debut)->toBeNull()
        ->and($representation->date_locale->format('Y-m-d'))->toBe($offre->date_locale->format('Y-m-d'));
});

it('donne une nouvelle représentation à une séance séparée à la main', function () {
    ($this->collecter)($this->factice);
    ($this->collecter)($this->bis);
    $f1 = ($this->offre)('F-1');
    $b1 = ($this->offre)('B-1');

    app(DeciderDoublon::class)->handle($f1, $b1, TypeDecisionDedoublonnage::Separer);
    ($this->collecter)($this->bis);

    expect($f1->fresh()->representation_id)->not->toBe($b1->fresh()->representation_id)
        ->and($f1->fresh()->representation->offres()->pluck('identifiant_externe')->all())->toBe(['F-1']);
});

it('reclasse le spectacle publié quand sa catégorie est classée (K05 en vrai)', function () {
    ($this->collecter)($this->factice);
    ($this->collecter)($this->bis);
    $conte = ($this->offre)('B-12')->representation;
    expect($conte->genre->slug)->toBe('autres');

    app(EnregistrerCorrespondanceGenre::class)->handle($this->bis, 'Conte musical', Genre::firstWhere('slug', 'comedie-musicale-cabaret'), jeunePublic: true);

    expect($conte->fresh()->genre->slug)->toBe('comedie-musicale-cabaret')
        ->and($conte->spectacle->fresh()->jeune_public)->toBeTrue();
});

it('affiche les compteurs de publication dans l’écran Collectes', function () {
    ($this->collecter)($this->factice);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/collectes')->assertOk()->assertSee('Représentations nouvelles');
});

it('publie au passage suivant les séances préparées par une collecte interrompue avant sa publication', function () {
    ($this->collecter)($this->factice);
    Offre::query()->update(['representation_id' => null]); // comme si la publication n'avait jamais eu lieu
    Representation::query()->delete();

    $collecte = ($this->collecter)($this->factice); // flux identique : aucune offre n'a changé

    expect($collecte->nb_nouveaux)->toBe(8)
        ->and(Offre::where('source_id', $this->factice->id)->whereNull('representation_id')->count())->toBe(0);
});

it('garde la salle donnée par la billetterie sur la représentation', function () {
    ($this->collecter)($this->factice);
    $offre = ($this->offre)('F-1');
    $offre->update(['donnees_normalisees' => [...$offre->donnees_normalisees, 'lieu_nom' => 'Théâtre du Chêne noir - salle Léo Ferré']]);

    ($this->republier)($this->factice);

    expect($offre->fresh()->representation->salle)->toBe('Salle Léo Ferré')
        ->and(($this->offre)('F-2')->representation->salle)->toBeNull();
});

it('donne à un spectacle classé « Autres » le genre qu’une autre de ses sources sait donner', function () {
    ($this->collecter)($this->factice);
    $offre = ($this->offre)('F-1');
    $spectacle = $offre->spectacle;
    $spectacle->update(['genre_id' => Genre::firstWhere('slug', 'autres')->id]); // la 1re source ne savait pas classer
    $offre->update(['genre_id' => Genre::firstWhere('slug', 'humour')->id]);   // une autre source (ou une correspondance) sait

    ($this->republier)($this->factice);

    // Le genre d'une de ses autres sources (ici Humour ou Théâtre, selon la séance republiée en dernier) : plus « Autres ».
    expect($spectacle->fresh()->genre->slug)->toBeIn(['humour', 'theatre'])
        ->and($offre->fresh()->representation->genre_id)->toBe($spectacle->fresh()->genre_id)
        ->and($spectacle->fresh()->champs_verrouilles)->toBe([]);
});

it('affiche les prix et le lien de réservation de chaque billetterie dans le back-office', function () {
    ($this->collecter)($this->factice);
    ($this->collecter)($this->bis);
    $representation = ($this->offre)('F-1')->representation;
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire\Livewire::test(RepresentationsRelationManager::class, ['ownerRecord' => $representation->spectacle, 'pageClass' => ViewSpectacle::class])
        ->assertSee('16 – 18 €');
    Livewire\Livewire::test(OffresRelationManager::class, ['ownerRecord' => $representation->spectacle, 'pageClass' => ViewSpectacle::class])
        ->assertSee('18 €')
        ->assertSee('Réserver ↗')
        ->assertSeeHtml('href="https://exemple.fr/1"');
    expect(RepresentationsRelationManager::prix(null, null, true))->toBe('Gratuit')
        ->and(RepresentationsRelationManager::prix('8.50', '8.50'))->toBe('8,50 €');
});
