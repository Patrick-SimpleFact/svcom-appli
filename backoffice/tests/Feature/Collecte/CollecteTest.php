<?php

use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Enums\StatutCollecte;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Jobs\CollecterSource;
use App\Models\Admin;
use App\Models\Collecte;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('collecte');
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, SourceFacticeSeeder::class, ReglesFiltrageSeeder::class]);
    $this->source = Source::firstWhere('code', 'factice');
    Http::fake(['data.geopf.fr/*' => Http::response(['features' => []])]);
});

it('refuse une annonce sans titre, sans date ou sans lieu', function (array $champs) {
    new AnnonceNormalisee(...[
        'identifiantExterne' => 'X', 'titre' => 'Titre', 'debut' => CarbonImmutable::now(),
        'heureConnue' => true, 'lien' => 'https://exemple.fr', 'lieuNom' => 'Théâtre', ...$champs,
    ]);
})->with([
    'sans titre' => [['titre' => ' ']],
    'sans date' => [['debut' => null]],
    'sans lieu' => [['lieuNom' => null, 'lieuAdresse' => null]],
])->throws(InvalidArgumentException::class);

it('calcule une empreinte qui change avec le contenu, pas avec la date de mise à jour', function () {
    $base = ['identifiantExterne' => 'X', 'titre' => 'Titre', 'debut' => CarbonImmutable::parse('2026-10-17 20:30'), 'heureConnue' => true, 'lien' => 'https://exemple.fr', 'lieuNom' => 'Théâtre'];

    $a = new AnnonceNormalisee(...$base);
    $b = new AnnonceNormalisee(...[...$base, 'misAJourSource' => CarbonImmutable::now()]);
    $c = new AnnonceNormalisee(...[...$base, 'complet' => true]);

    expect($a->empreinte())->toBe($b->empreinte())
        ->and($a->empreinte())->not->toBe($c->empreinte());
});

it('collecte une source : fichier brut gardé, annonces comptées, lignes illisibles à part', function () {
    $collecte = app(ExecuterCollecte::class)->handle($this->source);

    expect($collecte->statut)->toBe(StatutCollecte::Reussie)
        ->and($collecte->nb_recus)->toBe(10)
        ->and($collecte->nb_illisibles)->toBe(1)
        ->and($collecte->fin)->not->toBeNull();

    Storage::disk('collecte')->assertExists($collecte->fichier_brut);
    expect($collecte->fichier_brut)->toStartWith('factice/')
        ->and($collecte->contenuBrut())->toContain('Exemple de comédie');
});

it('transmet chaque annonce gardée à la suite de la chaîne', function () {
    $titres = [];

    app(ExecuterCollecte::class)->handle($this->source, traiterAnnonce: function (AnnonceNormalisee $annonce) use (&$titres) {
        $titres[] = $annonce->titre;
    });

    expect($titres)->toBe(['Exemple de comédie', 'Exemple de concert', 'Exemple de pièce de théâtre', 'Exemple de spectacle d’humour', 'Le Petit Prince', 'Exemple de comédie', 'Concert', 'Concert']);
});

it('marque la collecte en échec avec l’erreur, puis la laisse réessayer', function () {
    $this->source->update(['config' => ['simuler_echec' => true]]);

    expect(fn () => app(ExecuterCollecte::class)->handle($this->source, essai: 2))
        ->toThrow(RuntimeException::class, 'échec simulé');

    $collecte = Collecte::sole();
    expect($collecte->statut)->toBe(StatutCollecte::Echouee)
        ->and($collecte->essai)->toBe(2)
        ->and($collecte->erreur)->toContain('Source indisponible');
});

it('prévoit 4 essais espacés de 15 min, 30 min puis 1 h, une seule collecte à la fois par source', function () {
    $tache = new CollecterSource($this->source);

    expect($tache->tries)->toBe(4)
        ->and($tache->backoff())->toBe([900, 1800, 3600])
        ->and($tache)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($tache->uniqueId())->toBe('collecte-source-'.$this->source->id);
});

it('abandonne la collecte après le dernier essai', function () {
    $this->source->update(['config' => ['simuler_echec' => true]]);
    rescue(fn () => app(ExecuterCollecte::class)->handle($this->source, essai: 4), report: false);

    (new CollecterSource($this->source))->failed(new RuntimeException('échec'));

    expect(Collecte::sole()->statut)->toBe(StatutCollecte::Abandonnee);
});

it('lance une collecte depuis le back-office, et désactive le bouton sans connecteur', function () {
    Queue::fake();
    $this->seed(SourcesSeeder::class);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ViewSource::class, ['record' => $this->source->getKey()])
        ->callAction('lancer', ['simuler_echec' => true]);

    Queue::assertPushed(CollecterSource::class, fn ($tache) => $tache->source->is($this->source));
    expect($this->source->fresh()->config['simuler_echec'])->toBeTrue();

    Livewire::test(ViewSource::class, ['record' => Source::firstWhere('code', 'openagenda')->getKey()]) // pas encore de connecteur (N04)
        ->assertActionDisabled('lancer');

    $this->get('/admin/collectes')->assertOk();
});

it('supprime les fichiers bruts de plus de 30 jours', function () {
    $disque = Storage::disk('collecte');
    $disque->put('factice/ancien.json', '{}');
    $disque->put('factice/recent.json', '{}');
    touch($disque->path('factice/ancien.json'), now()->subDays(31)->getTimestamp());

    $this->artisan('collecte:purger-bruts')->assertSuccessful();

    $disque->assertMissing('factice/ancien.json');
    $disque->assertExists('factice/recent.json');
});
