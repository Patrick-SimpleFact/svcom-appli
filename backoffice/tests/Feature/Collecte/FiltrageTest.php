<?php

use App\Actions\ExecuterCollecte;
use App\Actions\TrierAnnonce;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\FiltreSpectacleVivant;
use App\Enums\FileATraiter;
use App\Enums\IssueFiltrage;
use App\Enums\StatutElement;
use App\Enums\TypeRegleFiltrage;
use App\Filament\Resources\ReglesFiltrage\Pages\ManageReglesFiltrage;
use App\Models\Admin;
use App\Models\ElementATraiter;
use App\Models\RegleFiltrage;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, ReglesFiltrageSeeder::class, SourceFacticeSeeder::class]);
    $this->source = Source::firstWhere('code', 'factice');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
});

function annonce(string $titre, array $categories = [], ?string $description = null, string $id = 'A-1'): AnnonceNormalisee
{
    return new AnnonceNormalisee(
        identifiantExterne: $id, titre: $titre, debut: CarbonImmutable::parse('2026-10-17 20:30'), heureConnue: true,
        lien: 'https://exemple.fr', lieuNom: 'Salle des fêtes', categoriesSource: $categories, description: $description,
    );
}

it('exclut les faux positifs connus du POC', function (AnnonceNormalisee $annonce) {
    expect((new FiltreSpectacleVivant)->evaluer($annonce)->issue)->toBe(IssueFiltrage::Exclu);
})->with([
    'Fête de la Science' => fn () => annonce('Fête de la Science : spectacles et ateliers pour toute la famille', description: 'Animations, ateliers et conférences.'),
    'lecture en bibliothèque' => fn () => annonce('Lectures en bibliothèque : contes d’automne'),
    'Parcours de l’Art' => fn () => annonce('Parcours de l’Art 2026', description: 'Art contemporain, performances et spectacles dans la ville.'),
    'exposition' => fn () => annonce('Exposition de photographies'),
    'visite guidée d’un théâtre' => fn () => annonce('Visite guidée du Théâtre antique'),
]);

it('garde le spectacle vivant, d’après la catégorie de la source ou le titre', function (AnnonceNormalisee $annonce) {
    expect((new FiltreSpectacleVivant)->evaluer($annonce)->issue)->toBe(IssueFiltrage::Garde);
})->with([
    'catégorie BilletRéduc' => fn () => annonce('Edmond', ['Théâtre']),
    'catégorie Ticketmaster' => fn () => annonce('Les Misérables', ['Arts & Theatre', 'Musical']),
    'type DATAtourisme' => fn () => annonce('La nuit des rois', ['TheaterEvent']),
    'titre seul' => fn () => annonce('Concert de jazz au jardin'),
    'pluriel' => fn () => annonce('Festival des marionnettes'),
]);

it('met de côté une annonce sans indice', function () {
    $resultat = (new FiltreSpectacleVivant)->evaluer(annonce('Edmond'));

    expect($resultat->issue)->toBe(IssueFiltrage::ATrier)
        ->and($resultat->score)->toBe(0);
});

it('explique le score par les mots trouvés', function () {
    expect((new FiltreSpectacleVivant)->evaluer(annonce('Atelier théâtre', ['Théâtre']))->motifs)
        ->toBe(['+3 categorie « theatre »', '+2 titre « theatre »', '−3 titre « atelier »']);
});

it('ignore un mot désactivé', function () {
    RegleFiltrage::where('mot', 'exposition')->update(['actif' => false]);

    expect((new FiltreSpectacleVivant)->evaluer(annonce('Exposition de photographies'))->issue)->toBe(IssueFiltrage::ATrier);
});

it('range une annonce douteuse dans la file « À trier », une seule fois', function () {
    $trier = app(TrierAnnonce::class);
    $trier->handle(annonce('Edmond'), $this->source);
    $trier->handle(annonce('Edmond'), $this->source);

    $element = ElementATraiter::sole();
    expect($element->file)->toBe(FileATraiter::ATrier)
        ->and($element->statut)->toBe(StatutElement::EnAttente)
        ->and($element->cible->is($this->source))->toBeTrue()
        ->and($element->donnees['titre'])->toBe('Edmond');
});

it('respecte la décision prise dans la file pour cette annonce', function () {
    app(TrierAnnonce::class)->handle(annonce('Edmond'), $this->source);
    ElementATraiter::sole()->update(['statut' => StatutElement::Traite, 'decision' => ['issue' => 'garde']]);

    expect(app(TrierAnnonce::class)->handle(annonce('Edmond'), $this->source))->toBe(IssueFiltrage::Garde);
});

it('sort de la file une annonce qu’un nouveau mot rend évidente', function () {
    app(TrierAnnonce::class)->handle(annonce('Soirée surprise'), $this->source);
    RegleFiltrage::create(['type' => TypeRegleFiltrage::Exclure, 'mot' => 'surprise']);

    expect(app(TrierAnnonce::class)->handle(annonce('Soirée surprise'), $this->source))->toBe(IssueFiltrage::Exclu)
        ->and(ElementATraiter::sole()->statut)->toBe(StatutElement::Traite)
        ->and(ElementATraiter::sole()->decision['automatique'])->toBeTrue();
});

it('compte gardées, exclues et à trier sans bloquer la collecte', function () {
    Storage::fake('collecte');
    $transmises = [];

    $collecte = app(ExecuterCollecte::class)->handle($this->source, traiterAnnonce: function (AnnonceNormalisee $annonce) use (&$transmises) {
        $transmises[] = $annonce->titre;
    });

    expect($collecte->only(['nb_recus', 'nb_retenus', 'nb_exclus', 'nb_a_trier']))
        ->toBe(['nb_recus' => 7, 'nb_retenus' => 5, 'nb_exclus' => 1, 'nb_a_trier' => 1])
        ->and($transmises)->toBe(['Exemple de comédie', 'Exemple de concert', 'Exemple de pièce de théâtre', 'Exemple de spectacle d’humour', 'Le Petit Prince'])
        ->and(ElementATraiter::where('file', FileATraiter::ATrier)->sole()->donnees['titre'])->toBe('Soirée surprise');
});

it('ajoute un mot depuis le back-office, écrit sans accents ni majuscules', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ManageReglesFiltrage::class)
        ->callAction(CreateAction::class, ['type' => 'exclure', 'mot' => '  Vide-Dressing '])
        ->assertHasNoActionErrors();

    expect(RegleFiltrage::where('mot', 'vide dressing')->where('type', TypeRegleFiltrage::Exclure)->exists())->toBeTrue();
});

it('refuse un mot déjà présent dans la liste', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ManageReglesFiltrage::class)
        ->callAction(CreateAction::class, ['type' => 'exclure', 'mot' => 'Bibliothèque'])
        ->assertHasActionErrors(['mot']);
});

it('affiche les écrans « Mots de tri » et « À trier »', function () {
    app(TrierAnnonce::class)->handle(annonce('Edmond'), $this->source);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/mots-de-tri')->assertOk()->assertSee('bibliotheque');
    $this->get('/admin/a-trier')->assertOk()->assertSee('Edmond')->assertSee('Démonstration (factice)');
});
