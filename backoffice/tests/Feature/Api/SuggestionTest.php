<?php

use App\Api\Suggestions;
use App\Enums\ChoixSuggestion;
use App\Enums\StatutCampagne;
use App\Enums\StatutRepresentation;
use App\Filament\Resources\Campagnes\Pages\ManageCampagnes;
use App\Models\Admin;
use App\Models\AffichageSuggestion;
use App\Models\Annonceur;
use App\Models\Appareil;
use App\Models\Campagne;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Offre;
use App\Models\Parametre;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

/** P09 : suggestion à l'ouverture et campagnes (API §9, F6). « Maintenant » : mercredi 14/10/2026 à 18 h, à Avignon. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 18:00', 'Europe/Paris'));
    $this->avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 1, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    $this->theatre = Genre::firstWhere('slug', 'theatre')->id;
    $this->concert = Genre::whereNot('slug', 'theatre')->value('id');
    $this->lieu = Lieu::factory()->create(['nom' => 'Théâtre du Chêne noir', 'ville_id' => $this->avignon->id, 'position' => new Point(43.9480, 4.8070)]);
    $this->loin = Lieu::factory()->create(['nom' => 'Le Silo', 'ville_id' => $this->avignon->id, 'position' => new Point(43.9600, 4.8300)]);
    $this->seance = fn (string $titre, int $genre, string $quand, ?Lieu $lieu = null, array $a = []) => Representation::factory()->create([
        'spectacle_id' => Spectacle::factory()->create(['titre' => $titre, 'genre_id' => $genre])->id,
        'lieu_id' => ($lieu ?? $this->lieu)->id, 'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'), 'complet' => false, ...$a,
    ]);
    $this->appareil = Appareil::create(['identifiant' => 'appareil-de-test-0001', 'plateforme' => 'ios', 'version_app' => '1.0.0', 'premiere_ouverture' => now(), 'derniere_ouverture' => now(), 'suggestion_choix' => ChoixSuggestion::Oui]);
    $this->api = fn (string $methode, string $url, array $corps = [], string $appareil = 'appareil-de-test-0001') => $this->json($methode, $url, $corps, ['X-Appareil' => $appareil, 'X-App-Version' => '1.0.0']);
    $this->suggestion = fn (array $gouts = []) => ($this->api)('GET', '/v1/suggestion?lat=43.9493&lon=4.8055'.collect($gouts)->map(fn ($g) => "&gouts[]={$g}")->implode(''));
    $this->demain = fn () => $this->travelTo(CarbonImmutable::now()->addDay());
});

it('suggère le spectacle de ce soir le plus proche dans les goûts, une fois par jour', function () {
    ($this->seance)('Concert proche', $this->concert, '2026-10-14 20:00');
    $piece = ($this->seance)('Pièce', $this->theatre, '2026-10-14 20:30', $this->loin);
    ($this->seance)('Pièce complète', $this->theatre, '2026-10-14 20:00', null, ['complet' => true]);
    ($this->seance)('Pièce commencée', $this->theatre, '2026-10-14 17:00');

    $reponse = ($this->suggestion)([$this->theatre])->assertOk()->json();

    expect($reponse)->type->toBe('auto')->annonceur->toBeNull()
        ->and($reponse['seance']['representation_id'])->toBe($piece->id)
        ->and($reponse['raison'])->toStartWith('Proposé car vous aimez : Théâtre · à ')->toEndWith('· ce soir à 20 h 30');
    expect(AffichageSuggestion::sole())->appareil->toBe('appareil-de-test-0001')->campagne_id->toBeNull();

    // Une par jour ; le lendemain soir (après 4 h), de nouveau.
    ($this->suggestion)([$this->theatre])->assertNoContent();
});

it('ne suggère rien sans accord, interrupteur coupé, ou hors des villes test', function () {
    ($this->seance)('Pièce', $this->theatre, '2026-10-14 20:30');

    $this->appareil->update(['suggestion_choix' => ChoixSuggestion::NonMerci]);
    ($this->suggestion)()->assertNoContent();
    ($this->suggestion)()->assertNoContent();
    ($this->api)('GET', '/v1/suggestion?lat=43.9493&lon=4.8055', [], 'appareil-inconnu-0009')->assertNoContent();

    $this->appareil->update(['suggestion_choix' => ChoixSuggestion::Oui]);
    Parametre::firstWhere('cle', 'suggestions_villes_test_seulement')->update(['valeur' => true]);
    ($this->suggestion)()->assertNoContent();
    $this->avignon->update(['suggestions_test' => true]);
    Parametre::firstWhere('cle', 'suggestions_actives')->update(['valeur' => false]);
    ($this->suggestion)()->assertNoContent();
    Parametre::firstWhere('cle', 'suggestions_actives')->update(['valeur' => true]);
    ($this->suggestion)()->assertOk();
});

it('prend demain si rien ce soir ; jamais une séance masquée ; 204 si rien dans les goûts', function () {
    ($this->seance)('Masquée', $this->theatre, '2026-10-14 20:30', null, ['statut' => StatutRepresentation::Masquee]);
    $demain = ($this->seance)('Demain', $this->theatre, '2026-10-15 21:00');

    ($this->suggestion)([$this->concert])->assertNoContent();
    expect(($this->suggestion)([$this->theatre])->assertOk()->json())
        ->seance->representation_id->toBe($demain->id)
        ->raison->toEndWith('demain à 21 h 00');
});

it('respecte la part maximale de sponsorisé et les goûts ciblés', function () {
    $auto = ($this->seance)('Pièce proche', $this->theatre, '2026-10-14 20:00');
    $sponsorisee = ($this->seance)('Pièce sponsorisée', $this->theatre, '2026-10-14 20:30', $this->loin);
    $campagne = Campagne::create([
        'annonceur_id' => Annonceur::create(['nom' => 'Chêne noir'])->id, 'spectacle_id' => $sponsorisee->spectacle_id,
        'ville_id' => $this->avignon->id, 'zone_rayon_km' => 10, 'genres' => [$this->theatre],
        'debut' => '2026-10-01', 'fin' => '2026-10-31', 'affichages_achetes' => 2, 'statut' => StatutCampagne::Active,
    ]);

    // 1re suggestion : toujours automatique (1 sponsorisée sur 1 dépasserait la moitié).
    expect(($this->suggestion)([$this->theatre])->json('type'))->toBe('auto');

    // Le lendemain, la campagne passe (1 sur 2) ; demain soir les séances ont lieu le 15.
    ($this->demain)();
    $auto->update(['debut' => CarbonImmutable::parse('2026-10-15 20:00', 'Europe/Paris')]);
    $sponsorisee->update(['debut' => CarbonImmutable::parse('2026-10-15 20:30', 'Europe/Paris')]);
    $reponse = ($this->suggestion)([$this->theatre])->json();
    expect($reponse)->type->toBe('sponsorise')->annonceur->toBe('Chêne noir')
        ->and($reponse['seance']['representation_id'])->toBe($sponsorisee->id);

    // Jamais à quelqu'un dont les goûts ne correspondent pas.
    $concert = Appareil::create(['identifiant' => 'appareil-concert-0002', 'plateforme' => 'ios', 'version_app' => '1.0.0', 'premiere_ouverture' => now(), 'derniere_ouverture' => now(), 'suggestion_choix' => ChoixSuggestion::Oui]);
    AffichageSuggestion::create(['appareil' => $concert->identifiant, 'affiche_le' => now()->subDays(2)]);
    AffichageSuggestion::create(['appareil' => $concert->identifiant, 'affiche_le' => now()->subDays(3)]);
    $campagne->update(['genres' => [$this->concert]]);
    expect(($this->api)('GET', '/v1/suggestion?lat=43.9493&lon=4.8055&gouts[]='.$this->theatre, [], 'appareil-concert-0002')->json('type'))->toBe('auto');

    // Un utilisateur « sans sponsorisé » (F6.6) n'en voit jamais.
    $campagne->update(['genres' => [$this->theatre]]);
    $u = Utilisateur::create(['email' => 'a@exemple.fr']);
    $u->forceFill(['droits' => [Suggestions::DROIT_SANS_SPONSORISE]])->save();
    Appareil::create(['identifiant' => 'appareil-payant-0003', 'plateforme' => 'ios', 'version_app' => '1.0.0', 'premiere_ouverture' => now(), 'derniere_ouverture' => now(), 'suggestion_choix' => ChoixSuggestion::Oui]);
    AffichageSuggestion::create(['appareil' => 'appareil-payant-0003', 'affiche_le' => now()->subDays(2)]);
    expect($this->getJson('/v1/suggestion?lat=43.9493&lon=4.8055', ['X-Appareil' => 'appareil-payant-0003', 'X-App-Version' => '1.0.0', 'Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken])->json('type'))->toBe('auto');
});

it('note « Passer », « Voir le spectacle » et le clic billetterie ; enregistre le choix du téléphone', function () {
    $r = ($this->seance)('Pièce', $this->theatre, '2026-10-14 20:30');
    $id = ($this->suggestion)()->json('affichage_id');
    $this->seed(SourcesSeeder::class);
    $offre = Offre::create([
        'source_id' => Source::firstWhere('code', 'billetreduc')->id, 'identifiant_externe' => 'br-1', 'representation_id' => $r->id,
        'donnees_normalisees' => ['titre' => 'x'], 'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'lien' => 'https://billets.exemple.fr/x',
    ]);

    ($this->api)('POST', "/v1/suggestion/{$id}", ['action' => 'fiche'])->assertNoContent();
    ($this->api)('POST', "/v1/suggestion/{$id}", ['action' => 'passee'], 'autre-appareil-0002')->assertNotFound();
    $this->get("/sortie/{$offre->id}?origine=suggestion_auto&affichage={$id}")->assertRedirect('https://billets.exemple.fr/x');
    expect(AffichageSuggestion::find($id))->clic_fiche->toBeTrue()->clic_billetterie->toBeTrue()->passee->toBeFalse();

    ($this->api)('PUT', '/v1/appareils/suggestion', ['choix' => 'desactive'])->assertOk()->assertJsonPath('choix', 'desactive');
    expect($this->appareil->fresh())->suggestion_choix->toBe(ChoixSuggestion::Desactive)->suggestion_repondu_le->not->toBeNull();
    ($this->api)('PUT', '/v1/appareils/suggestion', ['choix' => 'peut_etre'])->assertStatus(422);
    ($this->api)('PUT', '/v1/appareils/suggestion', ['choix' => 'oui'], 'appareil-inconnu-0009')->assertNotFound();
});

it('crée une campagne dans le back-office', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $spectacle = Spectacle::factory()->create(['titre' => 'Pièce']);

    Livewire::test(ManageCampagnes::class)
        ->callAction(CreateAction::class, [
            'annonceur_id' => Annonceur::create(['nom' => 'Chêne noir'])->id, 'spectacle_id' => $spectacle->id, 'ville_id' => $this->avignon->id,
            'zone_rayon_km' => 15, 'genres' => [$this->theatre], 'debut' => '2026-10-14', 'fin' => '2026-10-31', 'affichages_achetes' => 500, 'statut' => 'brouillon',
        ])
        ->assertHasNoActionErrors()
        ->assertSee('Pièce')->assertSee('0 / 500');

    expect(Campagne::sole())->genres->toBe([$this->theatre])->statut->toBe(StatutCampagne::Brouillon);
});
