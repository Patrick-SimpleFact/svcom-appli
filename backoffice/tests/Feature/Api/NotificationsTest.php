<?php

use App\Actions\EnvoyerNotificationsDuSoir;
use App\Enums\StatutRepresentation;
use App\Filament\Resources\NotificationsEnvoyees\Pages\ListNotificationsEnvoyees;
use App\Models\Admin;
use App\Models\Appareil;
use App\Models\Lieu;
use App\Models\NotificationEnvoyee;
use App\Models\Nouveaute;
use App\Models\Preference;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\ParametresSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** P11 : notification du soir, envoyée directement à Apple (APNs) et Google (FCM). « Maintenant » : 14/10/2026 à 18 h. */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', 'Europe/Paris'));

    // Clés de test : EC P-256 pour Apple, RSA pour le compte de service Google.
    $dossier = sys_get_temp_dir().'/spettacoli-push-'.uniqid();
    mkdir($dossier);
    openssl_pkey_export(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 384]), $ec);
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $rsa);
    file_put_contents("{$dossier}/apns.p8", $ec);
    file_put_contents("{$dossier}/fcm.json", json_encode(['project_id' => 'spettacoli-test', 'client_email' => 'push@spettacoli-test.iam.gserviceaccount.com', 'private_key' => $rsa, 'token_uri' => 'https://oauth2.googleapis.com/token']));
    config(['services.apns' => ['cle' => "{$dossier}/apns.p8", 'cle_id' => 'ABC123', 'equipe_id' => 'EQUIPE1', 'bundle_id' => 'fr.spettacoli.app', 'production' => false],
        'services.fcm.compte_service' => "{$dossier}/fcm.json"]);

    Http::fake([
        'api.sandbox.push.apple.com/3/device/jeton-perime' => Http::response(['reason' => 'Unregistered'], 410),
        'api.sandbox.push.apple.com/*' => Http::response(null, 200),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'jeton-oauth', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/spettacoli-test/messages/1']),
    ]);

    $avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 1, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    $this->observance = Lieu::factory()->create(['nom' => "Théâtre de l'Observance", 'ville_id' => $avignon->id, 'position' => new Point(43.9355, 4.8038)]);
    $this->seance = fn (string $titre, string $quand, array $a = []) => Representation::factory()->create(['spectacle_id' => Spectacle::factory()->create(['titre' => $titre])->id, 'lieu_id' => $this->observance->id, 'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'), 'complet' => false, ...$a]);

    $this->u = Utilisateur::create(['email' => 'patrick@exemple.fr']);
    $this->telephone = fn (string $id, string $plateforme, ?string $jeton) => Appareil::create(['identifiant' => $id, 'plateforme' => $plateforme, 'version_app' => '1.0.0', 'premiere_ouverture' => now(), 'derniere_ouverture' => now(), 'utilisateur_id' => $this->u->id, 'jeton_push' => $jeton]);
    $this->nouveaute = fn (string $type, Representation $r, ?CarbonInterface $cree = null) => Nouveaute::create(['utilisateur_id' => $this->u->id, 'type' => $type, 'cle' => uniqid(), 'representation_id' => $r->id, 'spectacle_id' => $r->spectacle_id, 'cree_le' => $cree ?? now()->subHours(3)]);
});

it('regroupe les nouveautés en une notification, envoyée à l’iPhone et au téléphone Android', function () {
    $iphone = ($this->telephone)('iphone-de-patrick-01', 'ios', 'jeton-iphone');
    ($this->telephone)('android-de-patrick-1', 'android', 'jeton-android');
    ($this->nouveaute)(Nouveaute::NOUVEAU_SPECTACLE_LIEU, ($this->seance)('Pièce A', '2026-10-20 20:00'));
    ($this->nouveaute)(Nouveaute::NOUVEAU_SPECTACLE_LIEU, ($this->seance)('Pièce B', '2026-10-21 20:00'));
    ($this->nouveaute)(Nouveaute::RAPPEL_JOUR_J, ($this->seance)('Ce soir', '2026-10-14 20:30'));
    // Garde-fous : complet, masqué, déjà vu ne sont pas annoncés.
    ($this->nouveaute)(Nouveaute::NOUVEAU_SPECTACLE_LIEU, ($this->seance)('Complet', '2026-10-20 20:00', ['complet' => true]));
    ($this->nouveaute)(Nouveaute::NOUVEAU_SPECTACLE_LIEU, ($this->seance)('Masqué', '2026-10-20 20:00', ['statut' => StatutRepresentation::Masquee]));
    Nouveaute::create(['utilisateur_id' => $this->u->id, 'type' => Nouveaute::NOUVEAU_SPECTACLE_LIEU, 'cle' => 'vue', 'representation_id' => ($this->seance)('Vu', '2026-10-22 20:00')->id, 'cree_le' => now(), 'vue_le' => now()]);

    $this->artisan('notifications:envoyer')->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.sandbox.push.apple.com/3/device/jeton-iphone')
        && $r->hasHeader('apns-topic', 'fr.spettacoli.app')
        && $r['aps']['alert']['title'] === '3 nouveautés pour vous'
        && $r['aps']['alert']['body'] === "Ce soir : Ce soir (Théâtre de l'Observance) · Théâtre de l'Observance : 2 nouveaux spectacles"
        && $r['aps']['badge'] === 5 && $r['ecran'] === 'nouveautes');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/spettacoli-test/messages:send')
        && $r->hasHeader('Authorization', 'Bearer jeton-oauth') && $r['message']['token'] === 'jeton-android');

    expect(NotificationEnvoyee::where('resultat', 'envoyee')->count())->toBe(2)
        ->and(Nouveaute::whereNotNull('notifiee_le')->count())->toBe(3);

    // L'app signale l'ouverture.
    $id = NotificationEnvoyee::firstWhere('appareil_id', $iphone->id)->id;
    $this->postJson("/v1/notifications/{$id}/ouverte", [], ['X-Appareil' => 'iphone-de-patrick-01', 'X-App-Version' => '1.0.0'])->assertNoContent();
    expect(NotificationEnvoyee::find($id)->ouverte_le)->not->toBeNull();
});

it('une seule notification par jour ; la suivante le lendemain', function () {
    ($this->telephone)('iphone-de-patrick-01', 'ios', 'jeton-iphone');
    ($this->nouveaute)(Nouveaute::NOUVEAU_SPECTACLE_LIEU, ($this->seance)('Pièce A', '2026-10-20 20:00'));
    app(EnvoyerNotificationsDuSoir::class)->handle();

    ($this->nouveaute)(Nouveaute::NOUVEAU_SPECTACLE_LIEU, ($this->seance)('Pièce B', '2026-10-21 20:00'), now());
    expect(app(EnvoyerNotificationsDuSoir::class)->handle()['notifications'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00', 'Europe/Paris'));
    expect(app(EnvoyerNotificationsDuSoir::class)->handle()['notifications'])->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'apple') && ($r['aps']['alert']['body'] ?? '') === "Théâtre de l'Observance : Pièce B");
});

it('respecte les réglages coupés et efface un jeton refusé par Apple', function () {
    $perime = ($this->telephone)('iphone-perime-0001', 'ios', 'jeton-perime');
    ($this->nouveaute)(Nouveaute::RAPPEL_JOUR_J, ($this->seance)('Ce soir', '2026-10-14 20:30'));
    Preference::create(['utilisateur_id' => $this->u->id, 'rappel_jour_j' => false]);

    expect(app(EnvoyerNotificationsDuSoir::class)->handle()['notifications'])->toBe(0);

    $this->u->preferences->update(['rappel_jour_j' => true]);
    app(EnvoyerNotificationsDuSoir::class)->handle();
    expect($perime->fresh()->jeton_push)->toBeNull()
        ->and(NotificationEnvoyee::sole()->resultat)->toBe('jeton_invalide')
        ->and(Nouveaute::sole()->notifiee_le)->toBeNull(); // reste dans l'app (badge)
});

it('envoie une notification de test depuis le back-office ; sans clés, le dit', function () {
    $iphone = ($this->telephone)('iphone-de-patrick-01', 'ios', 'jeton-iphone');
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListNotificationsEnvoyees::class)->callTableAction('tester', data: ['appareil_id' => $iphone->id, 'texte' => 'Bonjour'])->assertHasNoTableActionErrors();
    expect(NotificationEnvoyee::sole())->test->toBeTrue()->resultat->toBe('envoyee');

    config(['services.apns.cle' => null]);
    Livewire::test(ListNotificationsEnvoyees::class)->callTableAction('tester', data: ['appareil_id' => $iphone->id, 'texte' => 'Bonjour']);
    expect(NotificationEnvoyee::latest('id')->first()->resultat)->toBe('non_configure')
        ->and($iphone->fresh()->jeton_push)->toBe('jeton-iphone');
});
