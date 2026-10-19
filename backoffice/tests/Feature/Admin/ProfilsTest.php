<?php

use App\Filament\Pages\BoiteDeTravail;
use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\EditAdmin;
use App\Filament\Resources\ATrier\ATrierResource;
use App\Filament\Resources\Lieux\LieuResource;
use App\Filament\Resources\LieuxAVerifier\LieuAVerifierResource;
use App\Filament\Resources\Profils\Pages\CreateProfil;
use App\Filament\Resources\Profils\Pages\EditProfil;
use App\Filament\Resources\Profils\ProfilResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Support\Documentation;
use App\Filament\Support\MenusProfil;
use App\Filament\Support\OuvertSelonProfil;
use App\Filament\Support\ReserveAuSuperAdmin;
use App\Filament\Widgets\CompteursFiles;
use App\Models\Admin;
use App\Models\Profil;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('donne le modèle « Super Admin » aux comptes existants, qui ouvre tout', function () {
    $super = Profil::superAdmin()->sole();
    $admin = Admin::factory()->avecDoubleAuthentification()->create();

    expect($super->nom)->toBe('Super Admin')
        ->and($admin->profil->is($super))->toBeTrue()
        ->and($admin->estSuperAdmin())->toBeTrue();

    $this->actingAs($admin);
    foreach ([ProfilResource::class, AdminResource::class, SourceResource::class, ATrierResource::class] as $ecran) {
        $this->get($ecran::getUrl())->assertOk();
    }
});

it('n’ouvre à un compte limité que les menus de son modèle, ni les administrateurs ni les modèles', function () {
    $trieur = Admin::factory()->avecDoubleAuthentification()->avecMenus([ATrierResource::class, LieuAVerifierResource::class])->create();
    $this->actingAs($trieur);

    $this->get('/admin')->assertOk()->assertSee('À trier')->assertSee('Lieux à vérifier')->assertDontSee('Sources')->assertDontSee('Modèles de profil');
    $this->get(ATrierResource::getUrl())->assertOk();
    $this->get(SourceResource::getUrl())->assertForbidden();
    $this->get(ProfilResource::getUrl())->assertForbidden();
    $this->get(AdminResource::getUrl())->assertForbidden();

    // Boîte de travail : seulement ses files, sans les contributions fermées.
    expect(array_values(BoiteDeTravail::ressourcesOuvertes()))->toBe([ATrierResource::class, LieuAVerifierResource::class]);
    Livewire::test(CompteursFiles::class)->assertSee('À trier')->assertDontSee('Signalements')->assertDontSee('Pistes utilisateurs');
});

it('crée un modèle limité, et le modifier change aussitôt les menus de ses comptes', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $collecte = MenusProfil::parGroupe()['Collecte'];

    Livewire::test(CreateProfil::class)
        ->fillForm(['nom' => 'Trieur', 'menus_'.md5('Collecte') => [ATrierResource::class]])
        ->call('create')
        ->assertHasNoFormErrors();

    $modele = Profil::firstWhere('nom', 'Trieur');
    expect($modele->menus)->toBe([ATrierResource::class])->and($collecte)->toHaveKey(ATrierResource::class);

    $marie = Admin::factory()->avecDoubleAuthentification()->create(['profil_id' => $modele->id]);
    expect($marie->peutOuvrir(LieuAVerifierResource::class))->toBeFalse();

    Livewire::test(EditProfil::class, ['record' => $modele->getRouteKey()])
        ->fillForm(['menus_'.md5('Collecte') => [ATrierResource::class, LieuAVerifierResource::class]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($marie->fresh()->peutOuvrir(LieuAVerifierResource::class))->toBeTrue()
        ->and($modele->fresh()->menus)->toEqualCanonicalizing([ATrierResource::class, LieuAVerifierResource::class]);
});

it('garde « Super Admin » intact et demande un modèle à chaque nouveau compte', function () {
    $moi = Admin::factory()->avecDoubleAuthentification()->create();
    $this->actingAs($moi);
    $super = Profil::superAdmin()->sole();

    Livewire::test(EditProfil::class, ['record' => $super->getRouteKey()])
        ->assertActionHidden('delete')
        ->call('save');
    expect($super->fresh()->nom)->toBe('Super Admin')->and($super->fresh()->super_admin)->toBeTrue();

    Livewire::test(CreateAdmin::class)
        ->fillForm(['nom' => 'Marie', 'email' => 'marie@exemple.fr', 'password' => 'un-mot-de-passe-long', 'actif' => true])
        ->call('create')
        ->assertHasFormErrors(['profil_id' => 'required']);
});

it('crée un compte « Libre » avec ses propres menus, puis le rattache à un modèle', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $trieur = Profil::create(['nom' => 'Trieur', 'menus' => [ATrierResource::class]]);

    Livewire::test(CreateAdmin::class)
        ->fillForm(['nom' => 'Julien', 'email' => 'julien@exemple.fr', 'password' => 'un-mot-de-passe-long', 'actif' => true, 'profil_id' => 'libre'])
        ->assertFormFieldExists('menus_'.md5('Collecte'))
        ->fillForm(['menus_'.md5('Collecte') => [LieuAVerifierResource::class], 'menus_'.md5('Référentiels') => [LieuResource::class]])
        ->call('create')
        ->assertHasNoFormErrors();

    $julien = Admin::firstWhere('email', 'julien@exemple.fr');
    expect($julien->profil_id)->toBeNull()
        ->and($julien->estSuperAdmin())->toBeFalse()
        ->and($julien->peutOuvrir(LieuAVerifierResource::class))->toBeTrue()
        ->and($julien->peutOuvrir(LieuResource::class))->toBeTrue()
        ->and($julien->peutOuvrir(ATrierResource::class))->toBeFalse();

    Livewire::test(EditAdmin::class, ['record' => $julien->getRouteKey()])
        ->assertFormSet(['profil_id' => 'libre', 'menus_'.md5('Collecte') => [LieuAVerifierResource::class]])
        ->fillForm(['profil_id' => $trieur->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($julien->fresh()->profil_id)->toBe($trieur->id)
        ->and($julien->fresh()->menus)->toBeNull()
        ->and($julien->fresh()->peutOuvrir(ATrierResource::class))->toBeTrue()
        ->and($julien->fresh()->peutOuvrir(LieuAVerifierResource::class))->toBeFalse();
});

it('protège chaque menu du back-office par le modèle de profil', function () {
    $panel = Filament::getPanel('admin');

    foreach ([...$panel->getResources(), ...$panel->getPages()] as $ecran) {
        if (in_array($ecran, Documentation::OUVERTS_A_TOUS, true)) {
            continue;
        }
        $traits = class_uses_recursive($ecran);
        expect(in_array(OuvertSelonProfil::class, $traits, true) || in_array(ReserveAuSuperAdmin::class, $traits, true))
            ->toBeTrue("{$ecran} : ajouter le trait OuvertSelonProfil (ou ReserveAuSuperAdmin)");
    }
});
