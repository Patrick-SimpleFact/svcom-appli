<?php

use App\Models\Admin;

it('renvoie un visiteur non connecté vers la page de connexion', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('oblige un admin sans double authentification à la configurer', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
});

it('donne accès au tableau de bord à un admin avec double authentification', function () {
    $admin = Admin::factory()->avecDoubleAuthentification()->create();

    $this->actingAs($admin)->get('/admin')->assertOk();
});

it('refuse l’accès à un compte inactif', function () {
    $admin = Admin::factory()->avecDoubleAuthentification()->inactif()->create();

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('garde le secret de double authentification chiffré en base', function () {
    $admin = Admin::factory()->avecDoubleAuthentification()->create();

    $brut = DB::table('admins')->where('id', $admin->id)->value('app_authentication_secret');

    expect($brut)->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($admin->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP');
});
