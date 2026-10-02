<?php

use App\Models\Admin;

it('crée un admin avec un mot de passe provisoire', function () {
    $this->artisan('admin:creer', ['email' => 'Patrick@Exemple.fr', 'nom' => 'Patrick'])
        ->expectsOutputToContain('Mot de passe provisoire')
        ->assertSuccessful();

    $admin = Admin::where('email', 'patrick@exemple.fr')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->actif)->toBeTrue()
        ->and($admin->app_authentication_secret)->toBeNull();
});

it('refuse de créer deux comptes avec le même e-mail', function () {
    Admin::factory()->create(['email' => 'patrick@exemple.fr']);

    $this->artisan('admin:creer', ['email' => 'patrick@exemple.fr', 'nom' => 'Patrick'])
        ->assertFailed();
});
