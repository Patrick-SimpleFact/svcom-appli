<?php

use App\Enums\ActionJournal;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\Admin;
use App\Models\JournalAction;

it('inscrit au journal une modification faite par un admin, avec avant et après', function () {
    $moi = Admin::factory()->avecDoubleAuthentification()->create();
    $autre = Admin::factory()->create(['nom' => 'Ancien nom']);

    $this->actingAs($moi);
    $autre->update(['nom' => 'Nouveau nom']);

    $ligne = JournalAction::latest('id')->first();

    expect($ligne->admin_id)->toBe($moi->id)
        ->and($ligne->action)->toBe(ActionJournal::Modification)
        ->and($ligne->cible_id)->toBe($autre->id)
        ->and($ligne->avant)->toBe(['nom' => 'Ancien nom'])
        ->and($ligne->apres)->toBe(['nom' => 'Nouveau nom']);
});

it('note un changement de mot de passe sans jamais recopier la valeur', function () {
    $moi = Admin::factory()->avecDoubleAuthentification()->create();
    $this->actingAs($moi);

    $moi->update(['password' => 'un-nouveau-mot-de-passe']);

    $ligne = JournalAction::sole();

    expect($ligne->apres)->toBe(['mot de passe' => '••• (masqué)'])
        ->and(json_encode($ligne->getAttributes()))->not->toContain('un-nouveau-mot-de-passe')
        ->and($ligne->avant)->toBe(['mot de passe' => '••• (masqué)']);
});

it('note l’activation de la double authentification sans recopier le secret', function () {
    $moi = Admin::factory()->create();
    $this->actingAs($moi);

    $moi->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    expect(JournalAction::sole()->apres)->toBe(['double authentification' => '••• (masqué)']);
});

it('n’inscrit rien quand aucun admin n’est connecté (collecte, console)', function () {
    Admin::factory()->create();

    expect(JournalAction::count())->toBe(0);
});

it('inscrit la création d’un admin depuis le back-office', function () {
    $moi = Admin::factory()->avecDoubleAuthentification()->create();
    $this->actingAs($moi);

    $nouveau = Admin::factory()->create(['nom' => 'Camille']);

    $ligne = JournalAction::where('cible_id', $nouveau->id)->first();

    expect($ligne->action)->toBe(ActionJournal::Creation)
        ->and($ligne->apres)->toHaveKey('nom', 'Camille')
        ->and($ligne->apres)->not->toHaveKey('password')
        ->and($ligne->apres)->toHaveKey('mot de passe', '••• (masqué)');
});

it('affiche le journal en lecture seule dans le back-office', function () {
    $moi = Admin::factory()->avecDoubleAuthentification()->create();

    $this->actingAs($moi)->get('/admin/journal-actions')->assertOk();
    $this->actingAs($moi)->get('/admin/journal-actions/create')->assertNotFound();
});

it('enregistre le nom modifié depuis la page Profil et l’inscrit au journal', function () {
    $moi = Admin::factory()->avecDoubleAuthentification()->create(['nom' => 'Patrick']);

    $this->actingAs($moi);

    Livewire\Livewire::test(EditProfile::class)
        ->fillForm(['nom' => 'Patrick Carloni'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($moi->fresh()->nom)->toBe('Patrick Carloni')
        ->and(JournalAction::sole()->apres)->toBe(['nom' => 'Patrick Carloni']);
});
