<?php

use App\Actions\PrioriserBoiteDeTravail;
use App\Actions\TrierAnnonce;
use App\Collecte\AnnonceNormalisee;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Filament\Resources\ATrier\Pages\ListATrier;
use App\Filament\Widgets\CompteursFiles;
use App\Filament\Widgets\ElementsUrgents;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use App\Models\RegleFiltrage;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Livewire\Livewire;

function annonceATrier(string $titre, string $ville, CarbonImmutable $debut, string $id): AnnonceNormalisee
{
    return new AnnonceNormalisee(
        identifiantExterne: $id, titre: $titre, debut: $debut, heureConnue: true,
        lien: 'https://exemple.fr', lieuNom: 'Salle des fêtes', lieuVille: $ville,
    );
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 15:00', 'Europe/Paris'));
    $this->seed([ParametresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    $this->source = Source::firstWhere('code', 'factice');

    $ville = fn (string $nom, string $insee, bool $pilote, float $lat, float $lon) => Ville::create([
        'nom' => $nom, 'nom_normalise' => strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2),
        'codes_postaux' => [], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => $pilote,
    ]);
    $this->avignon = $ville('Avignon', '84007', true, 43.9493, 4.8055);
    $this->ales = $ville('Alès', '30007', false, 44.1250, 4.0819);

    $this->ceSoir = CarbonImmutable::parse('2026-10-17 20:30', 'Europe/Paris');
    $this->lieuAvecSeance = function (Ville $ville, CarbonImmutable $debut): Lieu {
        $lieu = Lieu::factory()->create(['ville_id' => $ville->id, 'position' => $ville->position]);
        Representation::factory()->create(['lieu_id' => $lieu->id, 'debut' => $debut]);

        return $lieu;
    };
    $this->aVerifier = fn (Lieu $lieu) => ElementATraiter::create([
        'file' => FileATraiter::LieuAVerifier, 'cible_type' => $lieu->getMorphClass(), 'cible_id' => $lieu->id, 'donnees' => ['motif' => 'Nouveau lieu'],
    ]);
});

it('classe par urgence : ce soir et villes pilotes d’abord', function () {
    $ceSoirPilote = ($this->aVerifier)(($this->lieuAvecSeance)($this->avignon, $this->ceSoir));
    $ceSoirAilleurs = ($this->aVerifier)(($this->lieuAvecSeance)($this->ales, $this->ceSoir));
    $semainePilote = ($this->aVerifier)(($this->lieuAvecSeance)($this->avignon, $this->ceSoir->addDays(3)));
    $plusTardAilleurs = ($this->aVerifier)(($this->lieuAvecSeance)($this->ales, $this->ceSoir->addMonth()));

    app(TrierAnnonce::class)->handle(annonceATrier('Soirée surprise', 'AVIGNON', $this->ceSoir, 'T-1'), $this->source);
    app(TrierAnnonce::class)->handle(annonceATrier('Le grand soir', 'Alès', $this->ceSoir->addDays(20), 'T-2'), $this->source);

    $spectacle = Spectacle::factory()->create();
    Representation::factory()->create(['spectacle_id' => $spectacle->id, 'lieu_id' => Lieu::factory()->create(['ville_id' => $this->avignon->id])->id, 'debut' => $this->ceSoir->addDay()]);
    $aControler = ElementATraiter::create(['file' => FileATraiter::SpectacleAControler, 'cible_type' => $spectacle->getMorphClass(), 'cible_id' => $spectacle->id, 'donnees' => []]);

    $categorie = fn (int $nb) => ElementATraiter::create(['file' => FileATraiter::AClasser, 'cible_type' => $this->source->getMorphClass(), 'cible_id' => $this->source->id, 'donnees' => ['categorie' => "Catégorie {$nb}", 'nb_annonces' => $nb]]);
    [$grosseCategorie, $petiteCategorie] = [$categorie(12), $categorie(2)];

    app(PrioriserBoiteDeTravail::class)->handle();
    $urgence = fn (ElementATraiter $e) => $e->fresh()->urgence;
    $aTrier = fn (string $titre) => ElementATraiter::where('donnees->titre', $titre)->sole();

    expect($urgence($ceSoirPilote))->toBe(3)
        ->and($urgence($ceSoirAilleurs))->toBe(2)
        ->and($urgence($semainePilote))->toBe(2)
        ->and($urgence($plusTardAilleurs))->toBe(0)
        ->and($urgence($aTrier('Soirée surprise')))->toBe(3)
        ->and($aTrier('Soirée surprise')->ville_pilote)->toBeTrue()
        ->and($urgence($aTrier('Le grand soir')))->toBe(0)
        ->and($urgence($aControler))->toBe(2)
        ->and($aControler->fresh()->echeance->toDateString())->toBe('2026-10-18')
        ->and($urgence($grosseCategorie))->toBe(1)
        ->and($urgence($petiteCategorie))->toBe(0)
        ->and($ceSoirPilote->fresh()->echeance->toDateString())->toBe('2026-10-17');
});

it('compte une séance de 1 h du matin dans la soirée de la veille', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 01:30', 'Europe/Paris'));
    $element = ($this->aVerifier)(($this->lieuAvecSeance)($this->avignon, CarbonImmutable::parse('2026-10-18 02:00', 'Europe/Paris')));

    app(PrioriserBoiteDeTravail::class)->handle();

    expect($element->fresh()->urgence)->toBe(3);
});

it('affiche la boîte de travail en page d’accueil, avec les compteurs et les éléments urgents', function () {
    ($this->aVerifier)(($this->lieuAvecSeance)($this->avignon, $this->ceSoir))->cible->update(['nom' => 'Théâtre du Chêne Noir']);
    app(TrierAnnonce::class)->handle(annonceATrier('Le grand soir', 'Alès', $this->ceSoir->addDays(20), 'T-2'), $this->source);
    app(PrioriserBoiteDeTravail::class)->handle();
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin')->assertOk()->assertSee('Boîte de travail');

    Livewire::test(CompteursFiles::class)
        ->assertSee('Lieux à vérifier')
        ->assertSee('1 urgent(s), dont 1 ce soir en ville pilote')
        ->assertSee('À trier');

    // Par défaut, seulement l'urgent ; le filtre montre le reste.
    Livewire::test(ElementsUrgents::class)
        ->assertSee('Théâtre du Chêne Noir')
        ->assertSee('Ce soir')
        ->assertDontSee('Le grand soir')
        ->filterTable('urgence', '0')
        ->assertSee('Le grand soir');
});

it('applique tout de suite la décision prise depuis la boîte de travail', function () {
    app(TrierAnnonce::class)->handle(annonceATrier('Soirée surprise', 'Avignon', $this->ceSoir, 'T-1'), $this->source);
    $lieu = ($this->aVerifier)(($this->lieuAvecSeance)($this->avignon, $this->ceSoir));
    app(PrioriserBoiteDeTravail::class)->handle();
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $annonce = ElementATraiter::where('file', FileATraiter::ATrier)->sole();

    Livewire::test(ElementsUrgents::class)
        ->assertTableActionVisible('garder', $annonce)
        ->assertTableActionHidden('verifie', $annonce)
        ->callTableAction('garder', $annonce)
        ->callTableAction('verifie', $lieu);

    expect($annonce->fresh())->statut->toBe(StatutElement::Traite)->decision->toBe(['issue' => 'garde'])
        ->and($lieu->fresh()->statut)->toBe(StatutElement::Traite);
});

it('rattache un lieu à vérifier à un lieu existant : ses séances y passent tout de suite', function () {
    $conserve = Lieu::factory()->create(['nom' => 'Théâtre des Halles', 'ville_id' => $this->avignon->id, 'position' => new Point(43.9466, 4.8090)]);
    $element = ($this->aVerifier)(($this->lieuAvecSeance)($this->ales, $this->ceSoir));
    $doublon = $element->cible;
    $representation = Representation::where('lieu_id', $doublon->id)->sole();
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ElementsUrgents::class)
        ->filterTable('urgence', '0')
        ->callTableAction('rattacher', $element, ['conserve_id' => $conserve->id])
        ->assertHasNoTableActionErrors();

    expect($doublon->fresh()->fusionne_dans_id)->toBe($conserve->id)
        ->and($representation->fresh())->lieu_id->toBe($conserve->id)->ville_id->toBe($this->avignon->id)
        ->and($representation->fresh()->position->latitude)->toEqualWithDelta(43.9466, 0.0001)
        ->and($element->fresh())->statut->toBe(StatutElement::Traite)->decision->toBe(['fusionne_dans' => $conserve->id]);
});

it('ajoute un mot de tri depuis une annonce à trier', function () {
    app(TrierAnnonce::class)->handle(annonceATrier('Soirée karaoké', 'Avignon', $this->ceSoir, 'T-1'), $this->source);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListATrier::class)
        ->callTableAction('ajouterMot', ElementATraiter::where('file', FileATraiter::ATrier)->sole(), ['type' => 'exclure', 'mot' => 'Karaoké'])
        ->assertHasNoTableActionErrors();

    expect(RegleFiltrage::where('type', 'exclure')->where('mot', 'karaoke')->exists())->toBeTrue();
});
