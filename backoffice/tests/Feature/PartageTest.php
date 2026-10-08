<?php

use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;

/** W01 : page du lien partagé (F5.8, API §12). « Maintenant » : 14/10/2026 à 18 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class, SourcesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', 'Europe/Paris'));
    $this->spectacle = Spectacle::factory()->create(['titre' => 'Donne moi ta chance !', 'image_url' => null]);
    $this->seance = Representation::factory()->create([
        'spectacle_id' => $this->spectacle->id, 'debut' => CarbonImmutable::parse('2026-10-17 19:30', 'Europe/Paris'), 'complet' => false, 'prix_min' => 19.5,
        'lieu_id' => Lieu::factory()->create(['nom' => "Théâtre de l'Observance", 'position' => new Point(43.9355, 4.8038)])->id,
    ]);
    $this->offre = Offre::create([
        'source_id' => Source::firstWhere('code', 'billetreduc')->id, 'identifiant_externe' => 'br-1', 'representation_id' => $this->seance->id,
        'donnees_normalisees' => ['titre' => 'x'], 'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'lien' => 'https://billetreduc.example/x', 'prix_min' => 19.5,
    ]);
    $this->adresse = "/s/donne-moi-ta-chance-{$this->spectacle->id}";
});

it('montre une fiche minimale avec le bouton de réservation, sans indexation', function () {
    $this->get($this->adresse)->assertOk()
        ->assertSee('Donne moi ta chance !')->assertSee('samedi 17 octobre 2026')->assertSee('19h30')->assertSee('19,50 €')
        ->assertSee("/sortie/{$this->offre->id}?origine=lien_partage", false)
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('property="og:title" content="Donne moi ta chance !"', false)
        ->assertSee('class="affiche"', false) // sans visuel : affiche typographique
        ->assertSee('Bientôt sur l’App Store et Google Play');

    // Le lien vers la billetterie compte le clic avec l'origine « lien partagé ».
    $this->get("/sortie/{$this->offre->id}?origine=lien_partage")->assertRedirect('https://billetreduc.example/x');
});

it('donne le lien partagé dans la fiche de l’API et corrige une adresse approximative', function () {
    $this->getJson("/v1/spectacles/{$this->spectacle->id}", ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'])
        ->assertJsonPath('lien_partage', url($this->adresse)."?r={$this->seance->id}");

    $this->get("/s/ancien-titre-{$this->spectacle->id}")->assertRedirect(url($this->adresse));
    $this->get($this->adresse."?r={$this->seance->id}")->assertOk();
});

it('indique un spectacle terminé ou retiré', function () {
    $this->seance->update(['debut' => CarbonImmutable::parse('2026-10-10 20:00', 'Europe/Paris')]);
    $this->get($this->adresse)->assertOk()->assertSee('Ce spectacle est terminé');

    $this->spectacle->update(['masque' => true]);
    $this->get($this->adresse)->assertNotFound()->assertSee('Ce spectacle n’est plus disponible');
});

it('publie les fichiers d’ouverture de l’app quand elle est déclarée', function () {
    $this->get('/.well-known/apple-app-site-association')->assertNotFound();

    config(['app_mobile.ios_equipe_id' => 'EQUIPE1', 'app_mobile.ios_bundle_id' => 'fr.spettacoli.app', 'app_mobile.android_paquet' => 'fr.spettacoli.app', 'app_mobile.android_empreintes' => 'AA:BB, CC:DD',
        'app_mobile.app_store' => 'https://apps.apple.com/app/id1', 'app_mobile.google_play' => null]);
    $this->get('/.well-known/apple-app-site-association')->assertOk()->assertJsonPath('applinks.details.0.appIDs.0', 'EQUIPE1.fr.spettacoli.app');
    $this->get('/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.sha256_cert_fingerprints', ['AA:BB', 'CC:DD']);
    $this->get($this->adresse)->assertSee('https://apps.apple.com/app/id1')->assertDontSee('Google Play');
});
