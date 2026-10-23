<?php

use App\Enums\FileATraiter;
use App\Enums\StatutCollecte;
use App\Enums\StatutElement;
use App\Enums\StatutPropositionIa;
use App\Filament\Resources\DecisionsIa\Pages\ListDecisionsIa;
use App\Ia\Quota;
use App\Mail\RecapitulatifIa;
use App\Models\Admin;
use App\Models\Collecte;
use App\Models\ElementATraiter;
use App\Models\Parametre;
use App\Models\PropositionIa;
use App\Models\Source;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** « Décisions de l'IA » (relire, annuler), pas de passage pendant une collecte, e-mail du matin. */
beforeEach(function () {
    $this->seed([GenresSeeder::class, ParametresSeeder::class, SourcesSeeder::class]);
    config(['services.ollama.url' => 'http://localhost:11434', 'services.ollama.cle_api' => 'cle', 'services.ollama.url_compte' => 'https://ollama.com']);
    Cache::forget(Quota::CLE_CACHE);
    Parametre::firstWhere('cle', 'ia_mode_a_trier')->update(['valeur' => 'actif']);
    $billetreduc = Source::firstWhere('code', 'billetreduc');
    $this->aTrier = fn (string $titre) => ElementATraiter::create([
        'file' => FileATraiter::ATrier, 'cible_type' => $billetreduc->getMorphClass(), 'cible_id' => $billetreduc->id,
        'donnees' => ['identifiant_externe' => 'BR-'.md5($titre), 'titre' => $titre, 'categories_source' => ['Loisirs']],
    ]);
});

it('liste les décisions appliquées par l’IA et en annule une : l’élément revient dans « Mes actions du jour »', function () {
    $edmond = ($this->aTrier)('Edmond');
    ollamaRepond(fn () => ['decision' => 'garder', 'confiance' => 96]);
    $this->artisan('ia:traiter', ['--file' => 'a_trier']);
    expect($edmond->fresh()->decision)->toBe(['issue' => 'garde']);
    $decision = PropositionIa::where('modele', 'cascade')->sole();
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListDecisionsIa::class)
        ->assertCanSeeTableRecords([$decision])
        ->assertSee('Garder (publier)')
        ->assertSee('1er (deux modèles)')
        ->callTableAction('annuler', $decision);

    expect($edmond->fresh()->statut)->toBe(StatutElement::EnAttente)
        ->and($edmond->fresh()->decision)->toBeNull()
        ->and($decision->fresh()->statut)->toBe(StatutPropositionIa::Annulee);
});

it('ne fait rien pendant une collecte', function () {
    ($this->aTrier)('Edmond');
    Collecte::create(['source_id' => Source::firstWhere('code', 'fnac')->id, 'debut' => now(), 'statut' => StatutCollecte::EnCours]);
    Http::fake();

    $this->artisan('ia:traiter')->expectsOutputToContain('Collecte en cours')->assertSuccessful();

    Http::assertNothingSent();
});

it('envoie l’e-mail du matin : actions du jour, travail de l’IA depuis hier, forfait', function () {
    Mail::fake();
    Parametre::firstWhere('cle', 'alertes_destinataires')->update(['valeur' => 'patrick@exemple.fr']);
    ($this->aTrier)('Edmond');
    ($this->aTrier)('Atelier théâtre');
    ollamaRepond(fn () => ['decision' => 'garder', 'confiance' => 96]);
    $this->artisan('ia:traiter', ['--file' => 'a_trier']); // Edmond appliqué, l'atelier laissé à Patrick (garde-fou)

    $this->artisan('ia:recapitulatif')->expectsOutputToContain('Spettacoli : 1 action(s) à faire aujourd’hui, dont 1 ajoutée(s) cette nuit')->assertSuccessful();

    Mail::assertSent(RecapitulatifIa::class, function (RecapitulatifIa $mail) {
        $mail->assertSeeInHtml('1 décision(s) vous attendent');
        $mail->assertSeeInHtml('Dont 1 ajoutée(s) cette nuit');
        $mail->assertSeeInHtml('Ouvrir Mes actions du jour');
        $mail->assertSeeInHtml('5 heures : 90 % restant');

        return $mail->hasTo('patrick@exemple.fr')
            && $mail->activite === [['file' => 'À trier', 'appliquees' => 1, 'proposees' => 0, 'doutes' => 1]];
    });
});
