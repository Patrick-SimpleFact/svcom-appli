<?php

use App\Comptes\Comptes;
use App\Enums\StatutPiste;
use App\Enums\StatutRepresentation;
use App\Enums\StatutSignalement;
use App\Filament\Resources\MotifsRefus\Pages\ManageMotifsRefus;
use App\Filament\Resources\Pistes\Pages\ListPistes;
use App\Filament\Resources\Signalements\Pages\ListSignalements;
use App\Filament\Widgets\CompteursFiles;
use App\Mail\ReponsePisteMail;
use App\Models\Admin;
use App\Models\Lieu;
use App\Models\MotifRefus;
use App\Models\Parametre;
use App\Models\Piste;
use App\Models\Representation;
use App\Models\Signalement;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** P08 : signalements, pistes, « Mes propositions » et réponses motivées (API §10, F5.6, F8). « Maintenant » : 14/10/2026 à 9 h. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Paris'));
    Mail::fake();
    $ville = fn (string $nom, string $insee, float $lat, float $lon, bool $pilote = false) => Ville::create(['nom' => $nom, 'nom_normalise' => mb_strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2), 'codes_postaux' => [], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => $pilote]);
    $this->avignon = $ville('Avignon', '84007', 43.9493, 4.8055, true);
    $this->ales = $ville('Alès', '30007', 44.1250, 4.0819);
    $this->halles = Lieu::factory()->create(['nom' => 'Théâtre des Halles', 'nom_normalise' => 'theatre des halles', 'ville_id' => $this->avignon->id, 'position' => new Point(43.947, 4.809)]);
    $this->seance = Representation::factory()->create(['lieu_id' => $this->halles->id, 'debut' => CarbonImmutable::parse('2026-10-17 20:30', 'Europe/Paris')]);

    $this->api = fn (string $methode, string $url, array $corps = [], string $appareil = 'appareil-de-test-0001', ?string $jeton = null) => $this->json($methode, $url, $corps,
        ['X-Appareil' => $appareil, 'X-App-Version' => '1.0.0', ...($jeton ? ['Authorization' => "Bearer {$jeton}"] : [])]);
    $this->piste = fn (array $d = [], string $appareil = 'appareil-de-test-0001', ?string $jeton = null) => ($this->api)('POST', '/v1/pistes',
        ['type' => 'salle', 'ville_id' => $this->avignon->id, 'nom' => 'ForumSirius', ...$d], $appareil, $jeton);
});

it('enregistre un signalement sans compte, une fois par appareil et par séance', function () {
    ($this->api)('POST', '/v1/signalements', ['representation_id' => $this->seance->id, 'motif' => 'annule'])
        ->assertCreated()->assertJsonPath('statut', 'nouveau');
    ($this->api)('POST', '/v1/signalements', ['representation_id' => $this->seance->id, 'motif' => 'horaire_faux', 'commentaire' => 'C’est à 21 h'])->assertOk();
    ($this->api)('POST', '/v1/signalements', ['representation_id' => $this->seance->id, 'motif' => 'annule'], 'autre-appareil-0002')->assertCreated();

    expect(Signalement::count())->toBe(2)
        ->and(Signalement::firstWhere('appareil', 'appareil-de-test-0001'))->motif->value->toBe('horaire_faux')->commentaire->toBe('C’est à 21 h')
        // Jamais masqué automatiquement (F5.6).
        ->and($this->seance->fresh()->statut)->toBe(StatutRepresentation::Programmee);

    ($this->api)('POST', '/v1/signalements', ['representation_id' => 999999, 'motif' => 'faux'])
        ->assertStatus(422)->assertJsonPath('erreur.code', 'donnees_invalides');
});

it('dit pendant la frappe si le lieu est déjà connu, y compris avec une faute', function () {
    ($this->api)('GET', '/v1/pistes/lieu-connu?nom=theatre des hales&ville_id='.$this->avignon->id)
        ->assertOk()->assertJsonPath('lieu.id', $this->halles->id)->assertJsonPath('lieu.ville', 'Avignon');
    ($this->api)('GET', '/v1/pistes/lieu-connu?nom=Théâtre des Halles&ville_id='.$this->ales->id)->assertOk()->assertJsonPath('lieu', null);
    ($this->api)('GET', '/v1/pistes/lieu-connu?nom=ForumSirius&ville_id='.$this->avignon->id)->assertOk()->assertJsonPath('lieu', null);
});

it('regroupe les pistes qui parlent de la même chose et rapproche les lieux connus', function () {
    $a = ($this->piste)(['lien' => 'https://www.forumsirius.fr/x', 'email' => 'a@exemple.fr'])->assertCreated()
        ->assertJsonPath('deja_connu', null)->assertJsonPath('reponse_possible', true)->json('id');
    $b = ($this->piste)(['nom' => 'Le Forumsirius'], 'autre-appareil-0002')->assertJsonPath('reponse_possible', false)->json('id');
    $halles = ($this->piste)(['nom' => 'théâtre des halles'])->assertJsonPath('deja_connu.id', $this->halles->id)->json('id');
    ($this->piste)(['nom' => 'ForumSirius', 'ville_id' => $this->ales->id]);
    $site1 = ($this->piste)(['type' => 'billetterie_ou_agenda', 'ville_id' => null, 'nom' => 'Ticket Avignon', 'lien' => 'https://billets.exemple.fr/a'])->json('id');
    $site2 = ($this->piste)(['type' => 'billetterie_ou_agenda', 'ville_id' => null, 'nom' => 'Autre nom', 'lien' => 'http://www.billets.exemple.fr/b'], 'autre-appareil-0002')->json('id');

    expect(Piste::find($b)->groupe_id)->toBe($a)
        ->and(Piste::find($halles)->lieu_id)->toBe($this->halles->id)
        ->and(Piste::find($site2)->groupe_id)->toBe($site1)
        ->and(Piste::tetesDeGroupe()->count())->toBe(4);
});

it('limite le nombre de pistes par jour et par téléphone (réglage du BO)', function () {
    Parametre::firstWhere('cle', 'pistes_max_par_jour')->update(['valeur' => 2]);

    ($this->piste)()->assertCreated();
    ($this->piste)(['nom' => 'Autre'])->assertCreated();
    ($this->piste)(['nom' => 'Encore'])->assertStatus(429)->assertJsonPath('erreur.code', 'limite_atteinte');
    ($this->piste)(['nom' => 'Encore'], 'autre-appareil-0002')->assertCreated();

    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:05', 'Europe/Paris'));
    ($this->piste)(['nom' => 'Encore'])->assertCreated();
});

it('vérifie le formulaire : type, ville, lien web', function () {
    ($this->piste)(['type' => 'musee'])->assertStatus(422);
    ($this->piste)(['ville_id' => null])->assertStatus(422)->assertJsonStructure(['erreur' => ['champs' => ['ville_id']]]);
    ($this->piste)(['lien' => 'forumsirius'])->assertStatus(422)->assertJsonStructure(['erreur' => ['champs' => ['lien']]]);
    ($this->piste)(['commentaire' => str_repeat('a', 501)])->assertStatus(422);
});

it('écarte un groupe avec motif : e-mail à chacun et réponse dans « Mes propositions »', function () {
    $u = Utilisateur::create(['email' => 'compte@exemple.fr']);
    $jeton = $u->createToken('test')->plainTextToken;
    ($this->piste)(['email' => 'a@exemple.fr']);
    ($this->piste)(['email' => 'A@exemple.fr'], 'autre-appareil-0002');
    ($this->piste)([], 'appareil-compte-0003', $jeton);
    $tete = Piste::tetesDeGroupe()->sole();

    ($this->api)('GET', '/v1/moi/propositions', jeton: $jeton)->assertOk()
        ->assertJsonPath('propositions.0.statut', 'nouvelle')->assertJsonPath('propositions.0.reponse', null);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $motif = MotifRefus::firstWhere('code', 'pas_de_programme');
    Livewire::test(ListPistes::class)
        ->assertCanSeeTableRecords([$tete])
        ->callTableAction('ecarter', $tete, ['motif_refus_id' => $motif->id, 'message_personnel' => 'Merci pour l’info !'])
        ->assertHasNoTableActionErrors();

    // Même adresse (à la casse près) : un seul e-mail ; le compte voit la réponse dans l'app.
    Mail::assertSent(ReponsePisteMail::class, 1);
    Mail::assertSent(ReponsePisteMail::class, fn (ReponsePisteMail $m) => $m->hasTo('a@exemple.fr') && str_contains($m->render(), 'Merci pour l’info !') && str_contains($m->render(), 'réessaierons'));
    expect(Piste::where('statut', StatutPiste::Ecartee)->count())->toBe(3);

    $this->app['auth']->forgetGuards();
    $reponse = ($this->api)('GET', '/v1/moi/propositions', jeton: $jeton)->json('propositions.0');
    expect($reponse['statut'])->toBe('ecartee')->and($reponse['reponse'])->toContain('réessaierons')->toContain('Merci pour l’info !');

    // Une nouvelle piste sur le même sujet ouvre un nouveau groupe.
    $nouvelle = ($this->piste)([], 'appareil-quatre-0004')->json('id');
    expect(Piste::find($nouvelle)->groupe_id)->toBe($nouvelle);
});

it('« Autre » oblige à écrire la réponse ; intégrer envoie la bonne nouvelle', function () {
    ($this->piste)(['email' => 'a@exemple.fr']);
    $tete = Piste::tetesDeGroupe()->sole();
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListPistes::class)
        ->callTableAction('ecarter', $tete, ['motif_refus_id' => MotifRefus::firstWhere('code', 'autre')->id])
        ->assertHasTableActionErrors(['message_personnel' => 'required']);
    Livewire::test(ManageMotifsRefus::class)->assertSee('Pas du spectacle vivant')->assertSee('Écrit à chaque fois');
    Mail::assertNothingSent();

    Livewire::test(ListPistes::class)
        ->callTableAction('etudier', $tete)
        ->callTableAction('integrer', $tete->fresh(), ['lieu_id' => $this->halles->id]);

    expect($tete->fresh())->statut->toBe(StatutPiste::Integree)->lieu_id->toBe($this->halles->id)->reponse_envoyee_le->not->toBeNull();
    Mail::assertSent(ReponsePisteMail::class, fn (ReponsePisteMail $m) => str_contains($m->render(), 'Bonne nouvelle'));
});

it('traite les signalements d’une séance d’un geste ; « Masquer » cache la séance', function () {
    ($this->api)('POST', '/v1/signalements', ['representation_id' => $this->seance->id, 'motif' => 'annule']);
    ($this->api)('POST', '/v1/signalements', ['representation_id' => $this->seance->id, 'motif' => 'annule'], 'autre-appareil-0002');
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(CompteursFiles::class)->assertSee('Signalements')->assertSee('Pistes utilisateurs');
    Livewire::test(ListSignalements::class)->callTableAction('masquer', Signalement::first());

    expect($this->seance->fresh()->statut)->toBe(StatutRepresentation::Masquee)
        ->and(Signalement::where('statut', StatutSignalement::Traite)->where('action', 'masque')->count())->toBe(2);
});

it('efface l’e-mail 12 mois après le traitement, et à la suppression du compte', function () {
    ($this->piste)(['email' => 'a@exemple.fr']);
    Piste::query()->update(['statut' => 'ecartee', 'traite_le' => now()->subMonths(13)]);
    ($this->piste)(['nom' => 'Récente', 'email' => 'b@exemple.fr']);

    $this->artisan('pistes:effacer-emails')->assertSuccessful();
    expect(Piste::whereNotNull('email')->pluck('email')->all())->toBe(['b@exemple.fr']);

    $u = Utilisateur::create(['email' => 'compte@exemple.fr']);
    ($this->piste)(['nom' => 'Du compte', 'email' => 'compte@exemple.fr'], 'appareil-compte-0003', $u->createToken('t')->plainTextToken);
    app(Comptes::class)->supprimer($u);
    expect(Piste::firstWhere('nom', 'Du compte'))->email->toBeNull()->utilisateur_id->toBeNull();
});
