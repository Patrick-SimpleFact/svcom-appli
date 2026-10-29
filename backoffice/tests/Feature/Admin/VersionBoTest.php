<?php

use App\Models\Admin;
use Database\Seeders\ParametresSeeder;

/** Version du B.O. en service (tag gravé dans l'image par deploy.sh), en haut du menu, avant « Mes actions du jour ». */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
});

it('affiche la version déployée en haut du menu', function () {
    config(['app.version' => 'bo-2026.10.29-2']);

    $this->get('/admin')->assertOk()->assertSeeInOrder(['Version', 'bo-2026.10.29-2', 'Mes actions du jour']);
});

it('affiche « locale » sans version (poste de développement)', function () {
    config(['app.version' => null]);

    $this->get('/admin')->assertOk()->assertSeeInOrder(['Version', 'locale']);
});
