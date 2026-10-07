<?php

use App\Mail\CodeConnexionMail;
use App\Mail\ExportDonneesMail;
use App\Models\Appareil;
use App\Models\Genre;
use App\Models\Utilisateur;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/** P06 : comptes (API §7, F1). */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Paris'));
    Mail::fake();
    $this->entetes = ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'];
    $this->postJson('/v1/appareils', ['plateforme' => 'ios'], $this->entetes);
    $this->api = fn (string $methode, string $url, array $corps = [], ?string $jeton = null) => $this->json($methode, $url, $corps, [...$this->entetes, ...($jeton ? ['Authorization' => "Bearer {$jeton}"] : [])]);
    $this->codeEnvoye = function (string $email): string {
        ($this->api)('POST', '/v1/auth/code', ['email' => $email])->assertStatus(202);
        $code = null;
        Mail::assertSent(CodeConnexionMail::class, function (CodeConnexionMail $m) use ($email, &$code) {
            $code = $m->code;

            return $m->hasTo(mb_strtolower($email));
        });

        return $code;
    };
    $this->connecter = fn (string $email = 'Patrick@Exemple.fr', array $invite = []) => ($this->api)('POST', '/v1/auth/code/verification', ['email' => $email, 'code' => ($this->codeEnvoye)($email), ...$invite]);

    // Fausses clés Apple et Google : le jeton est signé ici, les clés publiques servies par un faux serveur.
    $cle = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($cle, $this->clePrivee);
    $details = openssl_pkey_get_details($cle)['rsa'];
    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'cle-test', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['n']), 'e' => $b64($details['e'])]]];
    Http::fake(['appleid.apple.com/*' => Http::response($jwks), 'www.googleapis.com/*' => Http::response($jwks)]);
    config(['services.apple.client_ids' => 'fr.spettacoli.app', 'services.google.client_ids' => 'client-google.apps.googleusercontent.com']);
    $this->jeton = fn (array $contenu, ?string $cle = null) => JWT::encode([
        'iss' => 'https://appleid.apple.com', 'aud' => 'fr.spettacoli.app', 'sub' => '001234.apple', 'iat' => time(), 'exp' => time() + 600, ...$contenu,
    ], $cle ?? $this->clePrivee, 'RS256', 'cle-test');
});

it('connecte par un code envoyé par e-mail, crée le compte et rattache le téléphone', function () {
    $reponse = ($this->connecter)()->assertOk()->json();

    expect($reponse)->nouveau_compte->toBeTrue()->jeton->toBeString()
        ->and($reponse['utilisateur'])->toMatchArray(['email' => 'patrick@exemple.fr', 'profil_complet' => false, 'statuts' => [['statut' => 'spectateur', 'lieu_id' => null]]])
        ->and(Appareil::sole()->utilisateur_id)->toBe($reponse['utilisateur']['id']);

    ($this->api)('GET', '/v1/moi', [], $reponse['jeton'])->assertOk()->assertJsonPath('email', 'patrick@exemple.fr');
    $this->travel(2)->minutes(); // un code par minute au plus
    expect(($this->connecter)()->json('nouveau_compte'))->toBeFalse(); // 2e connexion : même compte
});

it('n’accepte qu’une fois un code valable 10 min, et bloque 15 min après 5 essais faux', function () {
    $code = ($this->codeEnvoye)('patrick@exemple.fr');
    $verifier = fn (string $c) => ($this->api)('POST', '/v1/auth/code/verification', ['email' => 'patrick@exemple.fr', 'code' => $c]);

    $verifier($code === '000000' ? '111111' : '000000')->assertStatus(422)->assertJsonPath('erreur.code', 'code_invalide');
    $verifier($code)->assertOk();
    $verifier($code)->assertStatus(422)->assertJsonPath('erreur.code', 'code_expire'); // une seule fois

    $this->travel(2)->minutes();
    $code = ($this->codeEnvoye)('patrick@exemple.fr');
    $this->travel(11)->minutes();
    $verifier($code)->assertJsonPath('erreur.code', 'code_expire'); // plus de 10 min

    $code = ($this->codeEnvoye)('patrick@exemple.fr');
    foreach (range(1, 4) as $i) {
        $verifier($code === '000000' ? '111111' : '000000')->assertJsonPath('erreur.code', 'code_invalide');
    }
    $verifier($code)->assertStatus(429)->assertJsonPath('erreur.code', 'trop_d_essais'); // 5 essais faux en tout : même le bon code attend
    $this->travel(16)->minutes();
    ($this->api)('POST', '/v1/auth/code', ['email' => 'patrick@exemple.fr'])->assertStatus(202);
});

it('limite les codes : un par minute, cinq par heure, et seul le dernier est valable', function () {
    $premier = ($this->codeEnvoye)('patrick@exemple.fr');
    ($this->api)('POST', '/v1/auth/code', ['email' => 'patrick@exemple.fr'])->assertStatus(429)->assertJsonPath('erreur.code', 'patientez');

    $this->travel(2)->minutes();
    ($this->codeEnvoye)('patrick@exemple.fr');
    ($this->api)('POST', '/v1/auth/code/verification', ['email' => 'patrick@exemple.fr', 'code' => $premier])->assertJsonPath('erreur.code', $premier === Mail::sent(CodeConnexionMail::class)->last()->code ? null : 'code_invalide');

    foreach (range(1, 3) as $i) {
        $this->travel(2)->minutes();
        ($this->codeEnvoye)('patrick@exemple.fr');
    }
    $this->travel(2)->minutes();
    ($this->api)('POST', '/v1/auth/code', ['email' => 'patrick@exemple.fr'])->assertStatus(429)->assertJsonPath('erreur.code', 'trop_de_codes');
});

it('reprend les goûts et le rayon du mode invité à la 1re connexion, sans écraser ceux du compte ensuite', function () {
    [$theatre, $danse] = [Genre::firstWhere('slug', 'theatre')->id, Genre::firstWhere('slug', 'danse')->id];

    expect(($this->connecter)(invite: ['gouts' => [$theatre, 999], 'rayon_m' => 5000])->json('utilisateur.preferences'))->toBe(['genres' => [$theatre], 'rayon_m' => 5000]);

    $this->travel(2)->minutes();
    expect(($this->connecter)(invite: ['gouts' => [$danse]])->json('utilisateur.preferences'))->toBe(['genres' => [$theatre], 'rayon_m' => 5000]);
});

it('complète le profil (prénom, naissance, conditions, lettre d’information datée)', function () {
    $jeton = ($this->connecter)()->json('jeton');

    $moi = ($this->api)('PATCH', '/v1/moi', ['prenom' => 'Patrick', 'naissance_mois' => 3, 'naissance_annee' => 1970, 'cgu_acceptees' => true, 'lettre_info' => true], $jeton)->assertOk()->json();

    expect($moi)->profil_complet->toBeTrue()->lettre_info->toBeTrue()
        ->and(Utilisateur::sole()->lettre_info_consentie_le)->not->toBeNull();

    ($this->api)('PATCH', '/v1/moi', ['lettre_info' => false], $jeton);
    expect(Utilisateur::sole()->lettre_info_consentie_le)->toBeNull();
});

it('ne garde pas le compte d’une personne de moins de 15 ans', function () {
    $jeton = ($this->connecter)()->json('jeton');

    ($this->api)('PATCH', '/v1/moi', ['prenom' => 'Léo', 'naissance_mois' => 11, 'naissance_annee' => 2011], $jeton) // 14 ans en octobre 2026
        ->assertStatus(422)->assertJsonPath('erreur.code', 'age_minimum');

    expect(Utilisateur::count())->toBe(0)->and(Appareil::sole()->utilisateur_id)->toBeNull();
    $this->app['auth']->forgetGuards();
    ($this->api)('GET', '/v1/moi', [], $jeton)->assertUnauthorized();
});

it('connecte avec Apple : crée le compte, le retrouve ensuite, le rattache à un compte e-mail existant', function () {
    ($this->connecter)('patrick@exemple.fr');

    $apple = ($this->api)('POST', '/v1/auth/apple', ['jeton' => ($this->jeton)(['email' => 'patrick@exemple.fr', 'email_verified' => 'true'])])->assertOk()->json();
    expect($apple)->nouveau_compte->toBeFalse()->and($apple['utilisateur']['methodes'])->toBe(['apple']);

    $encore = ($this->api)('POST', '/v1/auth/apple', ['jeton' => ($this->jeton)([])])->json(); // Apple ne redonne pas toujours l'e-mail
    expect($encore['utilisateur']['id'])->toBe($apple['utilisateur']['id'])->and(Utilisateur::count())->toBe(1);

    $nouveau = ($this->api)('POST', '/v1/auth/apple', ['jeton' => ($this->jeton)(['sub' => 'autre.apple', 'email' => 'cache@privaterelay.appleid.com', 'email_verified' => true])])->json();
    expect($nouveau['nouveau_compte'])->toBeTrue()->and(Utilisateur::count())->toBe(2);
});

it('connecte avec Google', function () {
    $jeton = ($this->jeton)(['iss' => 'https://accounts.google.com', 'aud' => 'client-google.apps.googleusercontent.com', 'sub' => '1098765', 'email' => 'marie@gmail.com', 'email_verified' => true]);

    expect(($this->api)('POST', '/v1/auth/google', ['jeton' => $jeton])->assertOk()->json('utilisateur'))->email->toBe('marie@gmail.com')->methodes->toBe(['google']);
});

it('refuse un jeton Apple ou Google invalide, et répond 503 tant que la connexion n’est pas configurée', function (array $contenu, bool $autreCle) {
    $autre = null;
    if ($autreCle) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $autre);
    }

    ($this->api)('POST', '/v1/auth/apple', ['jeton' => ($this->jeton)($contenu, $autre)])->assertUnauthorized()->assertJsonPath('erreur.code', 'jeton_invalide');
})->with([
    'autre app' => [['aud' => 'com.autre.app'], false],
    'autre émetteur' => [['iss' => 'https://pirate.example'], false],
    'expiré' => [['exp' => time() - 3600], false],
    'signé par une autre clé' => [[], true],
]);

it('répond 503 tant que la connexion Apple n’est pas configurée', function () {
    config(['services.apple.client_ids' => null]);

    ($this->api)('POST', '/v1/auth/apple', ['jeton' => ($this->jeton)([])])->assertStatus(503)->assertJsonPath('erreur.code', 'connexion_indisponible');
});

it('déconnecte : le jeton ne sert plus et le téléphone n’est plus rattaché', function () {
    $jeton = ($this->connecter)()->json('jeton');

    ($this->api)('POST', '/v1/auth/deconnexion', [], $jeton)->assertNoContent();

    expect(Appareil::sole()->utilisateur_id)->toBeNull();
    $this->app['auth']->forgetGuards();
    ($this->api)('GET', '/v1/moi', [], $jeton)->assertUnauthorized();
});

it('envoie ses données par e-mail, sans position', function () {
    $jeton = ($this->connecter)()->json('jeton');

    ($this->api)('POST', '/v1/moi/export', [], $jeton)->assertStatus(202);

    Mail::assertSent(ExportDonneesMail::class, fn (ExportDonneesMail $m) => $m->hasTo('patrick@exemple.fr')
        && $m->donnees['compte']['email'] === 'patrick@exemple.fr' && ! preg_match('/latitude|longitude|position/', json_encode($m->donnees)));
});

it('supprime le compte : accès coupé et données effacées tout de suite, ligne purgée après 30 jours', function () {
    $jeton = ($this->connecter)()->json('jeton');
    ($this->api)('PATCH', '/v1/moi', ['prenom' => 'Patrick'], $jeton);

    ($this->api)('DELETE', '/v1/moi', [], $jeton)->assertNoContent();

    expect(Utilisateur::sole())->email->toBeNull()->prenom->toBeNull()->supprime_le->not->toBeNull()
        ->and(Appareil::sole()->utilisateur_id)->toBeNull();
    $this->app['auth']->forgetGuards();
    ($this->api)('GET', '/v1/moi', [], $jeton)->assertUnauthorized();

    $this->travel(2)->minutes();
    expect(($this->connecter)()->json('nouveau_compte'))->toBeTrue(); // la même adresse peut recréer un compte

    $this->travel(31)->days();
    $this->artisan('comptes:purger')->expectsOutputToContain('1 compte(s)')->assertSuccessful();
    expect(Utilisateur::count())->toBe(1);
});
