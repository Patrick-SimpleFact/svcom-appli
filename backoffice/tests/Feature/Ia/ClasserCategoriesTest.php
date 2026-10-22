<?php

use App\Actions\ClasserAnnonce;
use App\Actions\EnregistrerCorrespondanceGenre;
use App\Actions\IgnorerCategorie;
use App\Collecte\AnnonceNormalisee;
use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Filament\Resources\AClasser\Pages\ListAClasser;
use App\Ia\Quota;
use App\Models\Admin;
use App\Models\AppelIa;
use App\Models\CorrespondanceGenre;
use App\Models\ElementATraiter;
use App\Models\Genre;
use App\Models\Parametre;
use App\Models\PropositionIa;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Faux Ollama : répond pour chaque catégorie reçue selon $reponses (catégorie → résultat sans id) ; absente = pas de résultat.
 *
 * @param  array<string, array<string, mixed>>  $reponses
 */
function ollamaClasse(array $reponses, float $sessionRestant = 90): void
{
    Http::fake([
        'ollama.com/api/balance' => Http::response(['included' => [
            'session' => ['remaining_percent' => $sessionRestant, 'resets_at' => now()->addHours(2)->toIso8601String()],
            'weekly' => ['remaining_percent' => 95, 'resets_at' => now()->addDays(3)->toIso8601String()],
        ], 'purchased' => ['balance_usd' => 0]]),
        'localhost:11434/api/chat' => function (Request $requete) use ($reponses) {
            $categories = json_decode($requete['messages'][1]['content'], true)['categories'];
            $resultats = collect($categories)
                ->filter(fn ($c) => isset($reponses[$c['categorie']]))
                ->map(fn ($c) => ['id' => $c['id'], ...$reponses[$c['categorie']]])
                ->values()->all();

            return Http::response(['message' => ['content' => json_encode(['resultats' => $resultats])], 'prompt_eval_count' => 900, 'eval_count' => 120]);
        },
    ]);
}

function classer(string $genre, int $confiance, bool $jeunePublic = false): array
{
    return ['decision' => 'classer', 'genre' => $genre, 'jeune_public' => $jeunePublic, 'confiance' => $confiance, 'justification' => 'Catégorie explicite.'];
}

function ignorer(int $confiance): array
{
    return ['decision' => 'ignorer', 'genre' => null, 'jeune_public' => false, 'confiance' => $confiance, 'justification' => 'Trop large.'];
}

function modeIa(string $mode): void
{
    Parametre::firstWhere('cle', 'ia_mode_a_classer')->update(['valeur' => $mode]);
}

beforeEach(function () {
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, ParametresSeeder::class, SourceFacticeSeeder::class]);
    config(['services.ollama.url' => 'http://localhost:11434', 'services.ollama.cle_api' => 'cle', 'services.ollama.url_compte' => 'https://ollama.com']);
    Cache::forget(Quota::CLE_CACHE);
    $this->source = Source::firstWhere('code', 'factice');
    $this->categorie = fn (string $categorie, int $urgence = 0) => ElementATraiter::create([
        'file' => FileATraiter::AClasser, 'cible_type' => $this->source->getMorphClass(), 'cible_id' => $this->source->id, 'urgence' => $urgence,
        'donnees' => ['categorie' => $categorie, 'nb_annonces' => 12, 'exemples' => ["Exemple {$categorie}"], 'identifiants' => []],
    ]);
});

it('ne fait rien, sans aucun appel, tant que la file est désactivée', function () {
    Http::fake();
    ($this->categorie)('Stand-up');

    $this->artisan('ia:traiter')->expectsOutputToContain('Aucune file active')->assertSuccessful();

    Http::assertNothingSent();
});

it('en observation, propose sans rien modifier', function () {
    modeIa('observation');
    $standUp = ($this->categorie)('Stand-up', urgence: 2);
    $large = ($this->categorie)('Spectacles');
    ollamaClasse(['Stand-up' => classer('humour', 95), 'Spectacles' => ignorer(88)]);

    $this->artisan('ia:traiter')->expectsOutputToContain('À classer : 2 proposition(s)')->assertSuccessful();

    expect($standUp->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and(CorrespondanceGenre::count())->toBe(0)
        ->and($standUp->propositionIa->proposition)->toEqual(['decision' => 'classer', 'genre' => 'humour', 'jeune_public' => false])
        ->and($standUp->propositionIa->confiance)->toBe(95)
        ->and($large->propositionIa->proposition)->toBe(['decision' => 'ignorer'])
        ->and(AppelIa::sole()->only(['tache', 'nb_elements', 'jetons_entree']))->toBe(['tache' => 'a_classer', 'nb_elements' => 2, 'jetons_entree' => 900]);

    // Le modèle a reçu la catégorie, ses exemples et la liste des genres ; un deuxième passage ne redemande pas.
    Http::assertSent(fn (Request $r) => str_contains((string) $r->url(), '/api/chat')
        && str_contains($r['messages'][1]['content'], 'Exemple Stand-up') && str_contains($r['messages'][0]['content'], 'cirque-magie'));
    $this->artisan('ia:traiter')->expectsOutputToContain('plus rien à proposer');
    expect(AppelIa::count())->toBe(1);
});

it('en mode actif, applique ce dont il est sûr et laisse le reste à décider', function () {
    modeIa('actif');
    $standUp = ($this->categorie)('Stand-up');
    $large = ($this->categorie)('Spectacles');
    $douteux = ($this->categorie)('Seul en scène');
    ollamaClasse(['Stand-up' => classer('humour', 95), 'Spectacles' => ignorer(92), 'Seul en scène' => classer('theatre', 60)]);

    $this->artisan('ia:traiter')->expectsOutputToContain('3 proposition(s), 2 appliquée(s)');

    expect($standUp->fresh()->statut)->toBe(StatutElement::Traite)
        ->and(CorrespondanceGenre::where('categorie_source', 'Stand-up')->sole()->genre->slug)->toBe('humour')
        ->and($large->fresh()->decision)->toBe(['ignoree' => true])
        ->and($douteux->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and($douteux->propositionIa->statut)->toBe(StatutPropositionIa::Proposee)
        ->and(PropositionIa::where('statut', StatutPropositionIa::Appliquee)->count())->toBe(2);
});

it('n’envoie rien quand le forfait est sous la part réservée', function () {
    modeIa('observation');
    ($this->categorie)('Stand-up');
    ollamaClasse(['Stand-up' => classer('humour', 95)], sessionRestant: 30);

    $this->artisan('ia:traiter')->expectsOutputToContain('Arrêt : Forfait sous la part réservée');

    Http::assertNotSent(fn (Request $r) => str_contains((string) $r->url(), '/api/chat'));
    expect(PropositionIa::count())->toBe(0);
});

it('note « sans réponse » un élément oublié ou hors cadre, et ne le redemande qu’après 24 h', function () {
    modeIa('observation');
    $oubliee = ($this->categorie)('Oubliée');
    $horsCadre = ($this->categorie)('Hors cadre');
    ollamaClasse(['Hors cadre' => classer('opera-rock', 90)]);

    $this->artisan('ia:traiter')->expectsOutputToContain('2 sans réponse');

    expect($oubliee->propositionsIa()->sole()->statut)->toBe(StatutPropositionIa::Echec)
        ->and($horsCadre->propositionsIa()->sole()->proposition['erreur'])->toContain('hors cadre');

    $this->artisan('ia:traiter')->expectsOutputToContain('plus rien à proposer');
    $this->travel(25)->hours();
    Cache::forget(Quota::CLE_CACHE);
    $this->artisan('ia:traiter')->expectsOutputToContain('2 sans réponse');
});

it('s’arrête sans rien noter si Ollama est injoignable', function () {
    modeIa('observation');
    ($this->categorie)('Stand-up');
    Http::fake([
        'ollama.com/api/balance' => Http::response(['included' => ['session' => ['remaining_percent' => 90, 'resets_at' => now()->addHour()->toIso8601String()]], 'purchased' => ['balance_usd' => 0]]),
        'localhost:11434/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    $this->artisan('ia:traiter')->expectsOutputToContain('Arrêt : Appel à Ollama impossible');

    expect(PropositionIa::count())->toBe(0);
});

it('rapproche les propositions des décisions prises à la main entre-temps', function () {
    modeIa('observation');
    $pareil = ($this->categorie)('Stand-up');
    $autrement = ($this->categorie)('Seul en scène');
    ollamaClasse(['Stand-up' => classer('humour', 95), 'Seul en scène' => classer('theatre', 80)]);
    $this->artisan('ia:traiter');

    app(EnregistrerCorrespondanceGenre::class)->handle($this->source, 'Stand-up', Genre::firstWhere('slug', 'humour'));
    app(EnregistrerCorrespondanceGenre::class)->handle($this->source, 'Seul en scène', Genre::firstWhere('slug', 'humour'));
    Cache::forget(Quota::CLE_CACHE);
    $this->artisan('ia:traiter')->expectsOutputToContain('2 proposition(s) rapprochée(s)');

    expect($pareil->propositionsIa()->sole()->statut)->toBe(StatutPropositionIa::Appliquee)
        ->and($autrement->propositionsIa()->sole()->statut)->toBe(StatutPropositionIa::Refusee);
});

it('ne remet pas dans la file une catégorie ignorée', function () {
    $large = ($this->categorie)('Spectacles');
    app(IgnorerCategorie::class)->handle($large);

    app(ClasserAnnonce::class)->handle(new AnnonceNormalisee(
        identifiantExterne: 'X-1', titre: 'Soirée mystère', debut: CarbonImmutable::now()->addDay(), heureConnue: true,
        lien: 'https://exemple.fr', lieuNom: 'Salle', categoriesSource: ['Spectacles', 'Nouvelle catégorie'],
    ), $this->source);

    expect(ElementATraiter::where('file', FileATraiter::AClasser)->where('statut', StatutElement::EnAttente)->pluck('donnees')->pluck('categorie')->all())
        ->toBe(['Nouvelle catégorie']);
});

it('affiche la proposition dans la file et l’applique sur « Accepter », une à une ou par sélection', function () {
    modeIa('observation');
    $standUp = ($this->categorie)('Stand-up');
    $enfants = ($this->categorie)('Spectacles enfants');
    $large = ($this->categorie)('Spectacles');
    ollamaClasse(['Stand-up' => classer('humour', 95), 'Spectacles enfants' => classer('theatre', 85, jeunePublic: true), 'Spectacles' => ignorer(90)]);
    $this->artisan('ia:traiter');
    $this->actingAs($admin = Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListAClasser::class)
        ->assertSee('Humour (95 %)')
        ->assertSee('Théâtre · Jeune public (85 %)')
        ->assertSee('Ignorer (trop large) (90 %)')
        ->callTableAction('accepterIa', $standUp)
        ->callTableBulkAction('accepterIaSelection', [$enfants, $large]);

    $correspondance = CorrespondanceGenre::where('categorie_source', 'Spectacles enfants')->sole();
    expect(CorrespondanceGenre::where('categorie_source', 'Stand-up')->sole()->genre->slug)->toBe('humour')
        ->and([$correspondance->genre->slug, $correspondance->jeune_public])->toBe(['theatre', true])
        ->and($large->fresh()->decision)->toBe(['ignoree' => true])
        ->and($standUp->fresh()->traite_par)->toBe($admin->id)
        ->and(PropositionIa::where('statut', StatutPropositionIa::Appliquee)->count())->toBe(3);
});

it('ignore une catégorie à la main', function () {
    $large = ($this->categorie)('Spectacles');
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListAClasser::class)->callTableAction('ignorer', $large);

    expect($large->fresh()->statut)->toBe(StatutElement::Traite)
        ->and(CorrespondanceGenre::count())->toBe(0);
});

it('compare plusieurs modèles sans enregistrer de proposition', function () {
    ($this->categorie)('Stand-up');
    ollamaClasse(['Stand-up' => classer('humour', 95)]);

    $this->artisan('ia:comparer', ['modeles' => ['gemma4:cloud', 'gpt-oss:120b-cloud']])
        ->expectsOutputToContain('Humour (95)')
        ->expectsOutputToContain('Aucune proposition enregistrée')
        ->assertSuccessful();

    expect(PropositionIa::count())->toBe(0)
        ->and(AppelIa::pluck('modele')->all())->toBe(['gemma4:cloud', 'gpt-oss:120b-cloud']);
});
