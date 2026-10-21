<?php

use App\Enums\TypeParametre;
use App\Filament\Resources\Parametres\Pages\EditParametre;
use App\Ia\ClientOllama;
use App\Ia\ErreurIa;
use App\Ia\Quota;
use App\Models\Admin;
use App\Models\AppelIa;
use App\Models\Parametre;
use Database\Seeders\ParametresSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const SCHEMA_ESSAI = ['type' => 'object', 'properties' => ['genre' => ['type' => 'string', 'enum' => ['theatre', 'humour']], 'confiance' => ['type' => 'integer']], 'required' => ['genre', 'confiance']];

function reponseChat(string $contenu, int $status = 200): array
{
    return ['localhost:11434/api/chat' => Http::response(['model' => 'gemma4', 'message' => ['role' => 'assistant', 'content' => $contenu], 'done' => true, 'prompt_eval_count' => 120, 'eval_count' => 15], $status)];
}

function solde(array $inclus, float $achats = 0): array
{
    return ['ollama.com/api/balance' => Http::response(['included' => $inclus, 'purchased' => ['balance_usd' => $achats]])];
}

function fenetres(float $session, float $semaine): array
{
    return [
        'session' => ['remaining_percent' => $session, 'resets_at' => now()->addHours(2)->toIso8601String()],
        'weekly' => ['remaining_percent' => $semaine, 'resets_at' => now()->addDays(3)->toIso8601String()],
    ];
}

beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    config(['services.ollama.url' => 'http://localhost:11434', 'services.ollama.cle_api' => 'cle-de-test', 'services.ollama.url_compte' => 'https://ollama.com']);
    Cache::forget(Quota::CLE_CACHE);
    $this->messages = [['role' => 'user', 'content' => 'Seul en scène']];
});

it('crée les réglages de l’assistant, tous les modes désactivés', function () {
    foreach (['ia_mode_a_classer', 'ia_mode_a_trier', 'ia_mode_doublons', 'ia_mode_fusions'] as $cle) {
        expect(Parametre::valeur($cle))->toBe('desactive')
            ->and(Parametre::firstWhere('cle', $cle)->type)->toBe(TypeParametre::ModeIa);
    }
    expect(Parametre::valeur('ia_quota_reserve_pct'))->toBe(50)
        ->and(ClientOllama::estCloud(Parametre::valeur('ia_modele')))->toBeTrue();
});

it('change le mode d’une file depuis les réglages', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $parametre = Parametre::firstWhere('cle', 'ia_mode_a_classer');

    Livewire::test(EditParametre::class, ['record' => $parametre->id])
        ->fillForm(['valeur' => 'observation'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Parametre::valeur('ia_mode_a_classer'))->toBe('observation');
    $this->get('/admin/parametres')->assertOk()->assertSee('Observation (propose seulement)');
});

it('envoie la demande au modèle cloud et journalise l’appel', function () {
    Http::fake(reponseChat('{"genre":"humour","confiance":85}'));

    $reponse = app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI, 10);

    expect($reponse->contenu)->toBe(['genre' => 'humour', 'confiance' => 85])
        ->and($reponse->appel->only(['tache', 'modele', 'nb_elements', 'jetons_entree', 'jetons_sortie', 'erreur']))
        ->toBe(['tache' => 'a_classer', 'modele' => 'gemma4:cloud', 'nb_elements' => 10, 'jetons_entree' => 120, 'jetons_sortie' => 15, 'erreur' => null]);
    Http::assertSent(fn ($requete) => $requete['model'] === 'gemma4:cloud' && $requete['stream'] === false && $requete['format'] === SCHEMA_ESSAI);
});

it('accepte un objet JSON entouré de texte', function () {
    Http::fake(reponseChat("Voici :\n```json\n{\"genre\": \"theatre\", \"confiance\": 70}\n```"));

    expect(app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI)->contenu['genre'])->toBe('theatre');
});

it('rejette une réponse hors cadre et garde l’erreur au journal', function (string $contenu, string $message) {
    Http::fake(reponseChat($contenu));

    expect(fn () => app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI))->toThrow(ErreurIa::class, $message)
        ->and(AppelIa::sole()->erreur)->toContain($message);
})->with([
    'pas du JSON' => ['ok', 'illisible'],
    'clé manquante' => ['{"genre":"humour"}', '« confiance » manquant'],
    'valeur non permise' => ['{"genre":"opera","confiance":90}', 'hors des choix permis'],
]);

it('refuse un modèle qui n’est pas cloud, sans rien envoyer', function () {
    Http::fake();
    Parametre::firstWhere('cle', 'ia_modele')->update(['valeur' => 'llama3:8b']);

    expect(fn () => app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI))->toThrow(ErreurIa::class, 'pas un modèle cloud');
    Http::assertNothingSent();
});

it('signale une erreur d’Ollama', function () {
    Http::fake(['localhost:11434/api/chat' => Http::response(['error' => 'model not found'], 404)]);

    expect(fn () => app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI))->toThrow(ErreurIa::class, 'model not found');
});

it('autorise l’assistant tant que le forfait dépasse la part réservée', function () {
    Http::fake(solde(fenetres(80, 65)));

    $decision = app(Quota::class)->verifier();

    expect($decision->autorise)->toBeTrue()
        ->and($decision->motif)->toContain('65 %');
    Http::assertSent(fn ($requete) => $requete->hasHeader('Authorization', 'Bearer cle-de-test'));
});

it('arrête l’assistant sous la part réservée, jusqu’à la remise à zéro', function () {
    Http::fake(solde(fenetres(40, 70)));

    $decision = app(Quota::class)->verifier();

    expect($decision->autorise)->toBeFalse()
        ->and($decision->motif)->toContain('fenêtre 5 heures')
        ->and($decision->repriseLe->timestamp)->toBe(now()->addHours(2)->timestamp);
});

it('arrête l’assistant si des crédits achetés pourraient être facturés', function () {
    Http::fake(solde(fenetres(90, 90), achats: 25));

    $decision = app(Quota::class)->verifier();

    expect($decision->autorise)->toBeFalse()
        ->and($decision->motif)->toContain('Crédits achetés')
        ->and($decision->repriseLe)->toBeNull();
});

it('comprend aussi la formule au crédit mensuel', function () {
    Http::fake(solde(['balance_usd' => 15, 'allowance_usd' => 60, 'period' => ['from' => now()->subDays(10)->toIso8601String(), 'until' => now()->addDays(20)->toIso8601String()]]));

    $decision = app(Quota::class)->verifier();

    expect($decision->autorise)->toBeFalse()
        ->and($decision->motif)->toContain('25 % sur la fenêtre mois');
});

it('ne consomme pas sans pouvoir lire le solde', function (string $cle, int $statut, string $motif) {
    config(['services.ollama.cle_api' => $cle]);
    Http::fake(['ollama.com/api/balance' => Http::response(['error' => 'non'], $statut)]);

    $decision = app(Quota::class)->verifier();

    expect($decision->autorise)->toBeFalse()
        ->and($decision->motif)->toContain($motif)
        ->and($decision->repriseLe)->not->toBeNull();
})->with([
    'sans clé' => ['', 200, 'Clé API ollama.com absente'],
    'clé refusée' => ['mauvaise', 401, 'clé API refusée'],
    'ollama.com indisponible' => ['cle', 503, 'momentanément indisponible'],
]);

it('lit le solde au plus une fois par minute', function () {
    Http::fake(solde(fenetres(80, 80)));

    app(Quota::class)->verifier();
    app(Quota::class)->verifier();

    Http::assertSentCount(1);
});

it('affiche l’état de l’assistant et fait un essai', function () {
    Http::fake([
        'localhost:11434/api/version' => Http::response(['version' => '0.40.6']),
        ...solde(fenetres(80, 60)),
        ...reponseChat('{"reponse":"ok"}'),
    ]);

    $this->artisan('ia:etat', ['--essai' => true])
        ->expectsOutputToContain('version 0.40.6')
        ->expectsOutputToContain('Modèle : gemma4:cloud')
        ->expectsOutputToContain('fenêtre semaine : 60 % restant')
        ->expectsOutputToContain('Essai réussi')
        ->assertSuccessful();
});

it('échoue si Ollama est injoignable', function () {
    Http::fake([
        'localhost:11434/*' => fn () => throw new ConnectionException('Connection refused'),
        ...solde(fenetres(80, 60)),
    ]);

    $this->artisan('ia:etat')->expectsOutputToContain('Ollama injoignable')->assertFailed();
});
