<?php

use App\Filament\Resources\ClicsSortants\Pages\ListClicsSortants;
use App\Models\Admin;
use App\Models\ClicSortant;
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
use Livewire\Livewire;

/** P05 : sortie vers la billetterie et comptage des clics (API §6, F7.13 bis). */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class, SourcesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-17 17:42', 'Europe/Paris'));
    $this->representation = Representation::factory()->create([
        'spectacle_id' => Spectacle::factory()->create(['titre' => 'Lesbien Tomber'])->id,
        'lieu_id' => Lieu::factory()->create(['nom' => 'Laurette Théâtre', 'position' => new Point(43.948, 4.809)])->id,
        'debut' => CarbonImmutable::parse('2026-10-17 19:00', 'Europe/Paris'), 'complet' => false,
    ]);
    $this->offre = fn (string $code, array $attributs = []) => Offre::create([
        'source_id' => Source::firstWhere('code', $code)->id, 'identifiant_externe' => $code.'-'.uniqid(), 'representation_id' => $this->representation->id,
        'donnees_normalisees' => ['titre' => 'x'], 'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'lien' => "https://{$code}.example/billet?aff=1", 'prix_min' => 15, ...$attributs,
    ]);
    $this->billetreduc = ($this->offre)('billetreduc');
    $this->navigateur = ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'];
    $this->lien = fn (Offre $o) => $this->getJson("/v1/spectacles/{$this->representation->spectacle_id}", ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'])
        ->json('seances.0.billetteries.'.collect($this->getJson("/v1/spectacles/{$this->representation->spectacle_id}", ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'])->json('seances.0.billetteries'))->search(fn ($b) => $b['offre_id'] === $o->id).'.lien_sortie');
    $this->cliquer = fn (string $lien, array $entetes = []) => $this->get($lien, $entetes ?: $this->navigateur);
});

it('met dans le lien de la fiche une empreinte de l’appareil, jamais son identifiant', function () {
    $lien = ($this->lien)($this->billetreduc);

    expect($lien)->toMatch('#/sortie/'.$this->billetreduc->id.'\?a=[a-f0-9]{64}$#')->not->toContain('appareil-de-test-0001');
});

it('enregistre le clic puis redirige vers le lien de la billetterie, sans cache ni cookie', function () {
    $reponse = ($this->cliquer)(($this->lien)($this->billetreduc).'&origine=liste_ce_soir&bouton=principal&distance_km=1.2');

    $reponse->assertRedirect('https://billetreduc.example/billet?aff=1')->assertStatus(302)->assertHeader('Cache-Control', 'no-store, private');
    expect($reponse->headers->getCookies())->toBe([]);

    expect(ClicSortant::sole())
        ->compte->toBeTrue()->origine->toBe('liste_ce_soir')->bouton->toBe('principal')->distance_km->toBe(1)
        ->spectacle_id->toBe($this->representation->spectacle_id)->lieu_id->toBe($this->representation->lieu_id)
        ->delai_avant_seance_min->toBe(78) // « 1 h 18 avant »
        ->and((float) ClicSortant::sole()->prix_affiche)->toBe(15.0);
});

it('ne compte qu’une fois deux clics en 30 min vers la même billetterie pour la même séance', function () {
    $lien = ($this->lien)($this->billetreduc);
    ($this->cliquer)($lien);
    $this->travel(10)->minutes();
    ($this->cliquer)($lien.'&bouton=autre');
    ($this->cliquer)(($this->lien)(($this->offre)('fnac')));        // autre billetterie : comptée
    $this->travel(31)->minutes();
    ($this->cliquer)($lien);                                          // plus de 30 min après le 1er : compté

    expect(ClicSortant::orderBy('id')->pluck('compte')->all())->toBe([true, false, true, true])
        ->and(ClicSortant::orderBy('id')->pluck('bouton')->all())->toBe(['principal', 'autre', 'principal', 'principal']);
});

it('ne compte ni les robots, ni les aperçus de liens, ni les appels de test', function (string $agent) {
    ($this->cliquer)(($this->lien)($this->billetreduc), ['User-Agent' => $agent])->assertRedirect();

    expect(ClicSortant::sole()->compte)->toBeFalse();
})->with(['Googlebot/2.1', 'facebookexternalhit/1.1', 'WhatsApp/2.23', 'curl/8.4.0', 'PostmanRuntime/7.39.0', '']);

it('compte un clic de la page web partagée (sans empreinte) par adresse IP, et ignore une origine inconnue', function () {
    $this->get("/sortie/{$this->billetreduc->id}?origine=nimporte", $this->navigateur)->assertRedirect();
    $this->get("/sortie/{$this->billetreduc->id}", $this->navigateur);

    expect(ClicSortant::orderBy('id')->pluck('compte')->all())->toBe([true, false])
        ->and(ClicSortant::first()->origine)->toBeNull()
        ->and(ClicSortant::first()->appareil_hash)->toBe(ClicSortant::empreinte('ip:127.0.0.1'));
});

it('répond 404 pour une offre inconnue ou sans lien', function () {
    $this->get('/sortie/999999', $this->navigateur)->assertNotFound();
    $this->get('/sortie/'.($this->offre)('openagenda', ['lien' => null])->id, $this->navigateur)->assertNotFound();
    expect(ClicSortant::count())->toBe(0);
});

it('montre les clics comptés dans le back-office', function () {
    ($this->cliquer)(($this->lien)($this->billetreduc));
    ($this->cliquer)(($this->lien)($this->billetreduc), ['User-Agent' => 'curl/8']);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListClicsSortants::class)
        ->assertCanSeeTableRecords(ClicSortant::where('compte', true)->get())
        ->assertCanNotSeeTableRecords(ClicSortant::where('compte', false)->get())
        ->assertSee('Lesbien Tomber');
});
