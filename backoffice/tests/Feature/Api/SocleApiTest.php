<?php

use App\Enums\ChoixSuggestion;
use App\Enums\TypeMessageService;
use App\Models\Appareil;
use App\Models\MessageService;
use App\Models\Parametre;
use App\Models\Ville;
use App\Providers\AppServiceProvider;
use App\Support\Point;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;

/** P01 : socle de l'API /v1 (API §1 et §2). */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->entetes = ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'];
    $this->ouvrir = fn (array $corps = ['plateforme' => 'ios'], array $entetes = []) => $this->postJson('/v1/appareils', $corps, [...$this->entetes, ...$entetes]);
});

it('exige l’identifiant de l’appareil et la version de l’app', function (array $entetes, string $code) {
    $this->postJson('/v1/appareils', ['plateforme' => 'ios'], $entetes)
        ->assertStatus(400)
        ->assertExactJson(['erreur' => ['code' => $code, 'message' => $code === 'appareil_manquant' ? 'En-tête X-Appareil absent ou invalide.' : 'En-tête X-App-Version absent ou invalide (ex. 1.0.0).']]);
})->with([
    'sans appareil' => [['X-App-Version' => '1.0.0'], 'appareil_manquant'],
    'appareil trop court' => [['X-Appareil' => 'abc', 'X-App-Version' => '1.0.0'], 'appareil_manquant'],
    'sans version' => [['X-Appareil' => 'appareil-de-test-0001'], 'version_manquante'],
]);

it('répond 426 à une version de l’app plus ancienne que la version minimale du back-office', function () {
    Parametre::firstWhere('cle', 'version_minimale_app')->update(['valeur' => '1.2.0']);

    ($this->ouvrir)(entetes: ['X-App-Version' => '1.1.9'])->assertStatus(426)->assertJsonPath('erreur.code', 'version_obsolete');
    ($this->ouvrir)(entetes: ['X-App-Version' => '1.10.0'])->assertOk(); // 1.10 > 1.2 (pas une comparaison de texte)
});

it('enregistre l’appareil à chaque ouverture et renvoie la configuration du moment', function () {
    ($this->ouvrir)(['plateforme' => 'ios', 'jeton_push' => 'jeton-1'])->assertOk();
    $this->travel(2)->hours();
    $reponse = ($this->ouvrir)()->assertOk();

    $appareil = Appareil::sole();
    expect($appareil)->nb_ouvertures->toBe(2)->jeton_push->toBe('jeton-1')->version_app->toBe('1.0.0')
        ->and($appareil->derniere_ouverture->greaterThan($appareil->premiere_ouverture))->toBeTrue();

    $reponse->assertJsonStructure(['version_minimale', 'parametres' => ['rayon_auto_min_representations', 'seuil_couverture_faible', 'delais_messages_s', 'paliers_prix', 'pistes_max_par_jour'], 'genres' => [['id', 'slug', 'libelle']], 'motifs_signalement', 'messages_service', 'poser_question_suggestion'])
        ->assertJsonPath('parametres.paliers_prix', [0, 15, 30])
        ->assertJsonPath('genres.0.slug', 'theatre');
});

it('envoie les messages de service pour tous et ceux de la ville de l’utilisateur', function () {
    $avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 1, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    MessageService::create(['texte' => 'Pour tous', 'type' => TypeMessageService::Info, 'debut' => now()->subHour()]);
    MessageService::create(['texte' => 'Grève à Avignon', 'type' => TypeMessageService::Alerte, 'debut' => now()->subHour(), 'ville_id' => $avignon->id]);

    expect(($this->ouvrir)(['plateforme' => 'android', 'latitude' => 43.95, 'longitude' => 4.81])->json('messages_service.*.texte'))->toBe(['Grève à Avignon', 'Pour tous'])
        ->and(($this->ouvrir)(['plateforme' => 'android', 'latitude' => 48.85, 'longitude' => 2.35])->json('messages_service.*.texte'))->toBe(['Pour tous'])
        ->and(($this->ouvrir)()->json('messages_service.*.texte'))->toBe(['Pour tous']);
});

it('pose la question des suggestions une fois à la 5e ouverture, puis relance après 30 jours et 10 ouvertures', function () {
    $questions = collect(range(1, 6))->map(fn () => ($this->ouvrir)()->json('poser_question_suggestion'))->all();
    expect($questions)->toBe([false, false, false, false, true, false]);

    // Réponse « Non merci » (enregistrée par l'app à l'étape P09).
    Appareil::sole()->update(['suggestion_choix' => ChoixSuggestion::NonMerci, 'suggestion_repondu_le' => now(), 'suggestion_ouvertures_a_la_reponse' => 6]);
    foreach (range(1, 10) as $i) {
        ($this->ouvrir)();
    }
    expect(($this->ouvrir)()->json('poser_question_suggestion'))->toBeFalse(); // 11 ouvertures, mais moins de 30 jours

    $this->travel(31)->days();
    expect(($this->ouvrir)()->json('poser_question_suggestion'))->toBeTrue();

    Appareil::sole()->update(['suggestion_relances' => 1]);
    expect(($this->ouvrir)()->json('poser_question_suggestion'))->toBeFalse(); // une relance au plus
});

it('ne pose jamais la question quand les suggestions sont coupées', function () {
    Parametre::firstWhere('cle', 'suggestions_actives')->update(['valeur' => false]);

    expect(collect(range(1, 6))->map(fn () => ($this->ouvrir)()->json('poser_question_suggestion'))->contains(true))->toBeFalse();
});

it('répond au format d’erreur commun, en français et sans détail technique', function () {
    ($this->ouvrir)(['plateforme' => 'windows'])->assertStatus(422)
        ->assertJsonPath('erreur.code', 'donnees_invalides')
        ->assertJsonPath('erreur.champs.plateforme.0', 'Le champ plateforme est invalide.');
    $this->getJson('/v1/inconnu', $this->entetes)->assertNotFound()->assertJsonPath('erreur.code', 'introuvable');
    $this->getJson('/v1/appareils', $this->entetes)->assertStatus(405)->assertJsonPath('erreur.code', 'methode_non_permise');
});

it('limite le nombre d’appels par appareil', function () {
    foreach (range(1, AppServiceProvider::LIMITE_API_APPAREIL) as $i) {
        ($this->ouvrir)()->assertOk();
    }

    ($this->ouvrir)()->assertStatus(429)->assertJsonPath('erreur.code', 'trop_de_requetes')->assertHeader('Retry-After');
    ($this->ouvrir)(entetes: ['X-Appareil' => 'autre-appareil-0002'])->assertOk(); // un autre téléphone n'est pas bloqué
});
