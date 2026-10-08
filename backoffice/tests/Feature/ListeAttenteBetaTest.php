<?php

use App\Filament\Resources\InscriptionsBeta\Pages\ListInscriptionsBeta;
use App\Mail\InscriptionBetaMail;
use App\Models\Admin;
use App\Models\InscriptionBeta;
use App\Web\ListeAttenteBeta;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** W05b : liste d'attente de la bêta (double opt-in). « Maintenant » : 14/10/2026 à 10 h. */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Paris'));
    Mail::fake();
    $this->formulaire = fn (array $d = []) => ['email' => 'Patrick@Exemple.fr', 'plateforme' => 'ios', 'ville' => 'Avignon', 'consentement' => '1', 'debut' => Crypt::encrypt(now()->subMinute()->timestamp), ...$d];
    $this->lien = function (string $quel): string {
        $url = null;
        Mail::assertSent(InscriptionBetaMail::class, function (InscriptionBetaMail $m) use (&$url, $quel) {
            $url = $quel === 'confirmer' ? $m->lienConfirmation : $m->lienDesinscription;

            return true;
        });

        return $url;
    };
});

it('inscrit après confirmation par e-mail seulement', function () {
    $this->get('/')->assertSee('Testez Spettacoli en avant-première');
    $this->post('/beta', ($this->formulaire)())->assertRedirect(url('/').'#beta');
    $this->get('/')->assertDontSee('Prévenez-moi'); // message de remerciement à la place

    expect(InscriptionBeta::sole())->email->toBe('patrick@exemple.fr')->confirmee_le->toBeNull()->ville->toBe('Avignon');
    Mail::assertSent(InscriptionBetaMail::class, fn ($m) => $m->hasTo('patrick@exemple.fr'));

    $this->get(($this->lien)('confirmer'))->assertOk()->assertSee('Inscription confirmée');
    expect(InscriptionBeta::sole()->confirmee_le)->not->toBeNull();
});

it('exige le consentement et iPhone ou Android ; filtre les robots ; n’inonde pas une adresse', function () {
    $this->post('/beta', ($this->formulaire)(['consentement' => null, 'plateforme' => null]))->assertSessionHasErrorsIn('beta', ['consentement', 'plateforme']);
    $this->post('/beta', ($this->formulaire)(['site_entreprise' => 'spam']));
    expect(InscriptionBeta::count())->toBe(0);

    $this->post('/beta', ($this->formulaire)());
    $this->post('/beta', ($this->formulaire)());
    Mail::assertSent(InscriptionBetaMail::class, 1);
});

it('désinscrit en un bouton et efface les inscriptions jamais confirmées après 30 jours', function () {
    $this->post('/beta', ($this->formulaire)());
    $lien = ($this->lien)('desinscrire');
    $this->get($lien)->assertOk()->assertSee('Effacer mon adresse');
    expect(InscriptionBeta::count())->toBe(1); // un simple clic (ou l'antivirus de la messagerie) n'efface rien
    $this->post($lien)->assertOk()->assertSee('effacée');
    expect(InscriptionBeta::count())->toBe(0);

    InscriptionBeta::create(['email' => 'ancien@exemple.fr', 'plateforme' => 'android', 'consentement_le' => now()->subDays(31)])->forceFill(['created_at' => now()->subDays(31)])->save();
    InscriptionBeta::create(['email' => 'recent@exemple.fr', 'plateforme' => 'android', 'consentement_le' => now()]);
    expect(app(ListeAttenteBeta::class)->purger())->toBe(1);
});

it('liste et exporte les inscriptions confirmées dans le back-office', function () {
    InscriptionBeta::create(['email' => 'oui@exemple.fr', 'plateforme' => 'ios', 'consentement_le' => now(), 'confirmee_le' => now()]);
    InscriptionBeta::create(['email' => 'non@exemple.fr', 'plateforme' => 'android', 'consentement_le' => now()]);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListInscriptionsBeta::class)->assertSee('oui@exemple.fr')->assertDontSee('non@exemple.fr')
        ->callTableAction('exporter')->assertFileDownloaded('spettacoli-liste-attente-beta-2026-10-14.csv');
});
