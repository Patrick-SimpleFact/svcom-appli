<?php

use App\Filament\Resources\PagesLegales\Pages\ManagePagesLegales;
use App\Models\Admin;
use App\Models\PageLegale;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Livewire\Livewire;

/** W03 : pages légales (F1.6, F7.15), modifiables dans le back-office. */
it('publie les trois pages, avec les passages à compléter surlignés et sans HTML injecté', function () {
    $this->get('/confidentialite')->assertOk()->assertSee('Politique de confidentialité')->assertSee('jamais votre position', false)
        ->assertSee('<mark>[À COMPLÉTER', false)->assertSee('Mentions légales');
    $this->get('/conditions')->assertOk()->assertSee('15 ans et plus');
    $this->get('/mentions-legales')->assertOk()->assertSee('OVH SAS')->assertSee('liens d’affiliation', false);
    $this->get('/autre-page')->assertNotFound();

    PageLegale::firstWhere('slug', 'conditions')->update(['contenu' => 'Texte <script>alert(1)</script> [lien](javascript:alert(1))']);
    $this->get('/conditions')->assertDontSee('<script>', false)->assertDontSee('javascript:', false);
});

it('se modifie dans le back-office ; la date avance si le texte change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Paris'));
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $page = PageLegale::firstWhere('slug', 'mentions-legales');

    Livewire::test(ManagePagesLegales::class)->assertSee('Mentions légales')
        ->callTableAction('edit', $page, ['titre' => 'Mentions légales', 'contenu' => 'Éditeur : SimpleFact.'])->assertHasNoTableActionErrors();

    expect($page->fresh())->contenu->toBe('Éditeur : SimpleFact.')->mis_a_jour_le->toDateString()->toBe('2026-11-02')
        ->and($page->fresh()->aCompleter())->toBe(0);
});

it('donne à l’app les liens des pages légales', function () {
    $this->seed(ParametresSeeder::class);
    $this->postJson('/v1/appareils', ['plateforme' => 'ios'], ['X-Appareil' => 'appareil-de-test-0001', 'X-App-Version' => '1.0.0'])
        ->assertJsonPath('liens.confidentialite', url('/confidentialite'))->assertJsonPath('liens.espace_salle', url('/espace-salle/demande'));
});
