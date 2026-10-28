<?php

use App\Enums\TypeParametre;
use App\Filament\Resources\Parametres\Pages\EditParametre;
use App\Ia\Assistant;
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
    Cache::forget('ia:modeles');
    $this->messages = [['role' => 'user', 'content' => 'Seul en scène']];
});

it('crée les réglages de l’assistant, tous les modes désactivés', function () {
    foreach (['ia_mode_a_classer', 'ia_mode_a_trier', 'ia_mode_doublons', 'ia_mode_fusions', 'ia_mode_spectacles_a_controler'] as $cle) {
        expect(Parametre::valeur($cle))->toBe('desactive')
            ->and(Parametre::firstWhere('cle', $cle)->type)->toBe(TypeParametre::ModeIa);
    }
    // Les choix du banc d'essai du 22/10/2026, et les limites du forfait fixées par Patrick (95 % sur 5 h, 80 % sur la semaine).
    expect(Assistant::modelesNiveau1())->toBe(['gpt-oss:120b-cloud', 'deepseek-v4.1-flash:cloud'])
        ->and(Assistant::modeleNiveau2())->toBe('glm-5.3-flash:cloud')
        ->and(Parametre::valeur('ia_quota_reserve_5h_pct'))->toBe(5)
        ->and(Parametre::valeur('ia_quota_reserve_semaine_pct'))->toBe(20)
        ->and(Parametre::valeur('ia_confiance_min_pct'))->toBe(90);
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

it('propose les modèles cloud d’ollama.com sous leur nom local, sauf ceux facturés en plus du forfait', function () {
    Http::fake(['ollama.com/api/tags' => Http::response(['models' => [['name' => 'kimi-k3'], ['name' => 'gpt-oss:120b'], ['name' => 'gemma4:31b'], ['name' => 'deepseek-v4-pro:0813']]])]);

    expect(app(ClientOllama::class)->modelesCloud())->toBe(['gemma4:31b-cloud', 'gpt-oss:120b-cloud']);
    app(ClientOllama::class)->modelesCloud();
    Http::assertSentCount(1); // gardée en cache
});

it('choisit les modèles de chaque niveau dans la liste et les vérifie auprès d’Ollama à l’enregistrement', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    Http::fake([
        'ollama.com/api/tags' => Http::response(['models' => [['name' => 'gpt-oss:120b'], ['name' => 'deepseek-v4.1-flash'], ['name' => 'mistral-large-3:675b'], ['name' => 'kimi-k3']]]),
        'localhost:11434/api/show' => fn ($requete) => $requete['model'] === 'mistral-large-3:675b-cloud'
            ? Http::response(['error' => "model 'mistral-large-3:675b' not found"], 404)
            : Http::response(['details' => []]),
    ]);
    $formulaire = fn (string $cle, mixed $valeur) => Livewire::test(EditParametre::class, ['record' => Parametre::firstWhere('cle', $cle)->id])->fillForm(['valeur' => $valeur])->call('save');

    $formulaire('ia_modele_niveau2', 'gpt-oss:cloud')->assertHasFormErrors(['valeur']);  // hors de la liste
    $formulaire('ia_modele_niveau2', 'kimi-k3:cloud')->assertHasFormErrors(['valeur']);  // facturé en plus : absent de la liste
    $formulaire('ia_modele_niveau2', 'mistral-large-3:675b-cloud')->assertHasFormErrors(['valeur']);  // inconnu de cet Ollama
    expect(Assistant::modeleNiveau2())->toBe('glm-5.3-flash:cloud');

    $formulaire('ia_modele_niveau2', 'gpt-oss:120b-cloud')->assertHasNoFormErrors();
    $formulaire('ia_modeles_niveau1', ['deepseek-v4.1-flash:cloud', 'gpt-oss:120b-cloud'])->assertHasNoFormErrors();
    expect(Assistant::modeleNiveau2())->toBe('gpt-oss:120b-cloud')
        ->and(Parametre::valeur('ia_modeles_niveau1'))->toBe('deepseek-v4.1-flash:cloud, gpt-oss:120b-cloud');
});

it('envoie la demande au modèle cloud et journalise l’appel', function () {
    Http::fake(reponseChat('{"genre":"humour","confiance":85}'));

    $reponse = app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI, 10);

    expect($reponse->contenu)->toBe(['genre' => 'humour', 'confiance' => 85])
        ->and($reponse->appel->only(['tache', 'modele', 'nb_elements', 'jetons_entree', 'jetons_sortie', 'erreur']))
        ->toBe(['tache' => 'a_classer', 'modele' => 'gpt-oss:120b-cloud', 'nb_elements' => 10, 'jetons_entree' => 120, 'jetons_sortie' => 15, 'erreur' => null]);
    Http::assertSent(fn ($requete) => $requete['model'] === 'gpt-oss:120b-cloud' && $requete['stream'] === false && $requete['format'] === SCHEMA_ESSAI);
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

it('refuse un modèle qui n’est pas cloud ou qui est facturé en plus du forfait, sans rien envoyer', function (string $modele, string $motif) {
    Http::fake();

    expect(fn () => app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI, 1, $modele))->toThrow(ErreurIa::class, $motif);
    Http::assertNothingSent();
})->with([
    'local' => ['llama3:8b', 'pas un modèle cloud'],
    'kimi-k3' => ['kimi-k3:cloud', 'facturé en plus du forfait'],
    'deepseek-v4-pro' => ['deepseek-v4-pro:0813-cloud', 'facturé en plus du forfait'],
]);

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
    Http::fake(solde(fenetres(4, 70)));

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
    Http::fake(solde(['balance_usd' => 9, 'allowance_usd' => 60, 'period' => ['from' => now()->subDays(10)->toIso8601String(), 'until' => now()->addDays(20)->toIso8601String()]]));

    $decision = app(Quota::class)->verifier();

    expect($decision->autorise)->toBeFalse()
        ->and($decision->motif)->toContain('15 % sur la fenêtre mois, réserve 20 %');
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
    // Un simple tableau en cache : le cache de la base ne restaure pas nos objets (« serializable_classes » à false).
    expect(Cache::get(Quota::CLE_CACHE))->toBeArray();
});

it('affiche l’état de l’assistant et fait un essai', function () {
    Http::fake([
        'localhost:11434/api/version' => Http::response(['version' => '0.40.6']),
        'localhost:11434/api/show' => Http::response(['details' => ['parent_model' => 'gemma4']]),
        ...solde(fenetres(80, 60)),
        ...reponseChat('{"reponse":"ok"}'),
    ]);

    $this->artisan('ia:etat', ['--essai' => true])
        ->expectsOutputToContain('version 0.40.6')
        ->expectsOutputToContain('Modèle du niveau 1 : gpt-oss:120b-cloud')
        ->expectsOutputToContain('Modèle du niveau 2 : glm-5.3-flash:cloud')
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

it('appelle directement l’API d’ollama.com, avec la clé et le nom de modèle sans suffixe', function () {
    config(['services.ollama.url' => 'https://ollama.com']);
    Http::fake(['ollama.com/api/chat' => Http::response(['model' => 'gpt-oss:120b', 'message' => ['role' => 'assistant', 'content' => '{"genre":"humour","confiance":85}'], 'done' => true, 'prompt_eval_count' => 120, 'eval_count' => 15])]);

    $reponse = app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI);

    // Le journal garde le nom local, celui des réglages.
    expect($reponse->contenu['genre'])->toBe('humour')
        ->and($reponse->appel->modele)->toBe('gpt-oss:120b-cloud');
    Http::assertSent(fn ($requete) => $requete->url() === 'https://ollama.com/api/chat'
        && $requete['model'] === 'gpt-oss:120b'
        && $requete->hasHeader('Authorization', 'Bearer cle-de-test'));
});

it('retire le suffixe des deux formes de noms en direct, et les garde tels quels avec un Ollama local', function () {
    $client = app(ClientOllama::class);
    expect($client->direct())->toBeFalse()
        ->and($client->nomEnvoye('glm-5.3-flash:cloud'))->toBe('glm-5.3-flash:cloud');

    config(['services.ollama.url' => 'https://ollama.com/']);
    expect($client->direct())->toBeTrue()
        ->and($client->nomEnvoye('glm-5.3-flash:cloud'))->toBe('glm-5.3-flash')
        ->and($client->nomEnvoye('gpt-oss:120b-cloud'))->toBe('gpt-oss:120b');
});

it('refuse l’appel direct sans clé, sans rien envoyer', function () {
    config(['services.ollama.url' => 'https://ollama.com', 'services.ollama.cle_api' => '']);
    Http::fake();

    expect(fn () => app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI))->toThrow(ErreurIa::class, 'Clé API ollama.com absente')
        ->and(AppelIa::sole()->erreur)->toContain('Clé API ollama.com absente');
    Http::assertNothingSent();
});

it('n’envoie jamais la clé à un Ollama installé sur le serveur', function () {
    Http::fake(reponseChat('{"genre":"humour","confiance":85}'));

    app(ClientOllama::class)->demander('a_classer', $this->messages, SCHEMA_ESSAI);

    Http::assertSent(fn ($requete) => ! $requete->hasHeader('Authorization'));
});

it('affiche l’état de l’assistant en appels directs', function () {
    config(['services.ollama.url' => 'https://ollama.com']);
    Http::fake([
        'ollama.com/api/version' => Http::response(['version' => '0.0.0']),
        'ollama.com/api/show' => fn ($requete) => in_array($requete['model'], ['gpt-oss:120b', 'deepseek-v4.1-flash', 'glm-5.3-flash'], true)
            ? Http::response(['details' => ['parent_model' => $requete['model']]])
            : Http::response(['error' => 'model not found'], 404),
        ...solde(fenetres(80, 60)),
    ]);

    $this->artisan('ia:etat')
        ->expectsOutputToContain('appels directs')
        ->expectsOutputToContain('Modèle du niveau 1 : gpt-oss:120b-cloud')
        ->expectsOutputToContain('Modèle du niveau 2 : glm-5.3-flash:cloud')
        ->assertSuccessful();
});
