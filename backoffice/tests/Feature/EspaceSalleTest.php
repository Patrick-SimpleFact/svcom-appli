<?php

use App\Actions\FusionnerLieux;
use App\Enums\StatutDemandeSalle;
use App\EspaceSalle\EspacesSalle;
use App\Filament\Resources\ComptesSalle\Pages\ListComptesSalle;
use App\Filament\Resources\DemandesEspaceSalle\DemandeEspaceSalleResource;
use App\Filament\Resources\DemandesEspaceSalle\Pages\ListDemandesEspaceSalle;
use App\Http\Controllers\EspaceSalleController;
use App\Mail\CodeConnexionMail;
use App\Mail\EspaceSalleMail;
use App\Models\Admin;
use App\Models\DemandeEspaceSalle;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\StatutUtilisateur;
use App\Models\Utilisateur;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** W02 : demande d'espace salle et traitement dans le back-office (F9.1, F9.2). « Maintenant » : 14/10/2026 à 10 h. */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Paris'));
    Mail::fake();
    $this->avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => ['84000'], 'population' => 1, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    $this->halles = Lieu::factory()->create(['nom' => 'Théâtre des Halles', 'nom_normalise' => 'theatre des halles', 'ville_id' => $this->avignon->id, 'position' => new Point(43.947, 4.809)]);
    $this->admin = Admin::factory()->avecDoubleAuthentification()->create(['email' => 'patrick@exemple.fr']);
    $this->formulaire = fn (array $d = []) => [
        'nom_lieu' => 'Théâtre des Halles', 'adresse' => '4 rue Noël Biret', 'code_postal' => '84000', 'ville' => 'Avignon',
        'nom_demandeur' => 'Alain Timar', 'fonction' => 'Directeur', 'email' => 'Direction@Halles.fr', 'telephone' => '04 32 76 24 51',
        'consentement' => '1', 'debut' => Crypt::encrypt(now()->subMinute()->timestamp), ...$d,
    ];
});

it('enregistre une demande, la rapproche du référentiel, confirme et prévient le super-admin', function () {
    $this->get('/espace-salle/demande?nom_lieu=Théâtre des Halles&ville=Avignon')->assertOk()->assertSee('value="Théâtre des Halles"', false);
    $this->post('/espace-salle/demande', ($this->formulaire)())->assertRedirect('/espace-salle/merci');

    expect(DemandeEspaceSalle::sole())->email->toBe('direction@halles.fr')->ville_id->toBe($this->avignon->id)
        ->lieu_propose_id->toBe($this->halles->id)->statut->toBe(StatutDemandeSalle::EnAttente)->consentement_le->not->toBeNull();
    Mail::assertSent(EspaceSalleMail::class, fn ($m) => $m->hasTo('direction@halles.fr') && $m->vue === 'mail.espace-salle.recue');
    Mail::assertSent(EspaceSalleMail::class, fn ($m) => $m->hasTo('patrick@exemple.fr') && $m->vue === 'mail.espace-salle.nouvelle');
});

it('exige le consentement et filtre les robots sans le leur dire', function () {
    $this->post('/espace-salle/demande', ($this->formulaire)(['consentement' => null]))->assertSessionHasErrors('consentement');
    $this->post('/espace-salle/demande', ($this->formulaire)(['site_entreprise' => 'spam']))->assertRedirect('/espace-salle/merci');
    $this->post('/espace-salle/demande', ($this->formulaire)(['debut' => Crypt::encrypt(now()->timestamp)]))->assertRedirect('/espace-salle/merci');

    expect(DemandeEspaceSalle::count())->toBe(0);
    Mail::assertNothingSent();
});

it('valide : compte rattaché au lieu, invitation qui connecte à l’espace', function () {
    $this->post('/espace-salle/demande', ($this->formulaire)());
    $demande = DemandeEspaceSalle::sole();
    Representation::factory()->create(['spectacle_id' => Spectacle::factory()->create(['titre' => 'Le Horla'])->id, 'lieu_id' => $this->halles->id, 'debut' => CarbonImmutable::parse('2026-10-20 20:00', 'Europe/Paris')]);

    $this->actingAs($this->admin);
    Livewire::test(ListDemandesEspaceSalle::class)->assertCanSeeTableRecords([$demande])
        ->callTableAction('valider', $demande, ['lieu_id' => $this->halles->id])->assertHasNoTableActionErrors();

    $u = Utilisateur::firstWhere('email', 'direction@halles.fr');
    expect($demande->fresh())->statut->toBe(StatutDemandeSalle::Validee)->utilisateur_cree_id->toBe($u->id)
        ->and($u->statuts()->where('statut', StatutUtilisateur::GESTIONNAIRE_LIEU)->value('lieu_id'))->toBe($this->halles->id);

    $lien = null;
    Mail::assertSent(EspaceSalleMail::class, function ($m) use (&$lien) {
        $lien = $m->donnees['lien'] ?? $lien;

        return $m->vue === 'mail.espace-salle.invitation' && $m->hasTo('direction@halles.fr');
    });

    auth()->logout();
    $this->get('/espace-salle')->assertRedirect('/espace-salle/connexion');
    $this->get($lien)->assertRedirect('/espace-salle');
    $this->get('/espace-salle')->assertOk()->assertSee('Votre espace pour Théâtre des Halles')->assertSee('Le Horla');

    // Lien modifié ou expiré : refusé.
    $this->get(str_replace('signature=', 'signature=x', $lien))->assertForbidden();
});

it('se connecte ensuite par code e-mail, sans dire si l’adresse a un espace', function () {
    app(EspacesSalle::class)->creerCompte('direction@halles.fr', $this->halles->id, $this->admin->id);

    $this->post('/espace-salle/connexion', ['email' => 'inconnu@exemple.fr'])->assertRedirect('/espace-salle/connexion');
    Mail::assertNotSent(CodeConnexionMail::class);

    $this->post('/espace-salle/connexion', ['email' => 'direction@halles.fr']);
    $code = null;
    Mail::assertSent(CodeConnexionMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });

    $this->post('/espace-salle/connexion/code', ['email' => 'direction@halles.fr', 'code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/espace-salle/connexion/code', ['email' => 'direction@halles.fr', 'code' => $code])->assertRedirect('/espace-salle');
    $this->get('/espace-salle')->assertOk();

    // Accès retiré par le super-admin : déconnecté à la page suivante.
    StatutUtilisateur::where('statut', StatutUtilisateur::GESTIONNAIRE_LIEU)->delete();
    $this->get('/espace-salle')->assertRedirect('/espace-salle/connexion');
    expect(session(EspaceSalleController::SESSION))->toBeNull();
});

it('refuse avec motif, demande des précisions, crée un compte directement', function () {
    $this->post('/espace-salle/demande', ($this->formulaire)());
    $this->post('/espace-salle/demande', ($this->formulaire)(['nom_lieu' => 'Salle des fêtes', 'email' => 'mairie@exemple.fr']));
    [$halles, $salle] = DemandeEspaceSalle::orderBy('id')->get()->all();
    $this->actingAs($this->admin);

    Livewire::test(ListDemandesEspaceSalle::class)
        ->callTableAction('precisions', $halles, ['question' => 'Quel est votre site ?'])
        ->callTableAction('refuser', $salle, ['motif' => 'Pas de programmation de spectacles.']);

    expect($halles->fresh()->statut)->toBe(StatutDemandeSalle::PrecisionsDemandees)
        ->and($salle->fresh())->statut->toBe(StatutDemandeSalle::Refusee)->motif_refus->toBe('Pas de programmation de spectacles.');
    Mail::assertSent(EspaceSalleMail::class, fn ($m) => $m->vue === 'mail.espace-salle.precisions' && $m->hasReplyTo('patrick@exemple.fr'));
    Mail::assertSent(EspaceSalleMail::class, fn ($m) => $m->vue === 'mail.espace-salle.refusee' && str_contains($m->render(), 'Pas de programmation'));

    Livewire::test(ListComptesSalle::class)->callTableAction('creer', data: ['email' => 'compta@halles.fr', 'lieu_id' => $this->halles->id])->assertHasNoTableActionErrors()
        ->assertSee('compta@halles.fr');
});

it('crée le lieu quand il n’est pas encore dans Spettacoli, placé par la Base Adresse Nationale', function () {
    Http::fake([config('collecte.geocodage_url').'*' => Http::response(['features' => [[
        'geometry' => ['coordinates' => [4.8102, 43.9487]],
        'properties' => ['label' => '19 Rue Carnot 84000 Avignon', 'name' => '19 Rue Carnot', 'postcode' => '84000', 'city' => 'Avignon', 'citycode' => '84007', 'type' => 'housenumber', 'score' => 0.95],
    ]]])]);
    $this->post('/espace-salle/demande', ($this->formulaire)(['nom_lieu' => 'Théâtre Carnot', 'adresse' => '19 rue Carnot', 'email' => 'contact@carnot.fr']));
    $demande = DemandeEspaceSalle::sole();
    expect($demande->lieu_propose_id)->toBeNull();

    $this->actingAs($this->admin);
    Livewire::test(ListDemandesEspaceSalle::class)
        ->mountTableAction('valider', $demande)->assertTableActionDataSet(['choix' => 'nouveau', 'nom' => 'Théâtre Carnot'])
        ->callMountedTableAction()->assertHasNoTableActionErrors();

    $lieu = Lieu::firstWhere('nom', 'Théâtre Carnot');
    expect($lieu)->ville_id->toBe($this->avignon->id)->precision_position->value->toBe('adresse')
        ->and(round($lieu->position->latitude, 4))->toBe(43.9487)
        ->and($demande->fresh())->statut->toBe(StatutDemandeSalle::Validee)->lieu_id->toBe($lieu->id);
});

it('avertit quand un lieu au nom proche existe déjà dans la ville', function () {
    $this->post('/espace-salle/demande', ($this->formulaire)(['nom_lieu' => 'Halles (salle Chapelle)', 'email' => 'x@halles.fr']));
    expect(DemandeEspaceSalleResource::lieuxProches(DemandeEspaceSalle::sole()))->toBe(['Théâtre des Halles']);

    $this->post('/espace-salle/demande', ($this->formulaire)(['nom_lieu' => 'Théâtre Carnot', 'email' => 'y@carnot.fr']));
    expect(DemandeEspaceSalleResource::lieuxProches(DemandeEspaceSalle::latest('id')->first()))->toBe([]);
});

it('une fusion de lieux emmène l’accès du théâtre vers le lieu conservé', function () {
    $doublon = Lieu::factory()->create(['nom' => 'Halles', 'ville_id' => $this->avignon->id, 'position' => new Point(43.947, 4.809)]);
    $u = app(EspacesSalle::class)->creerCompte('direction@halles.fr', $doublon->id, $this->admin->id);

    app(FusionnerLieux::class)->handle($doublon, $this->halles);

    expect($u->statuts()->pluck('lieu_id')->all())->toBe([$this->halles->id]);
});
