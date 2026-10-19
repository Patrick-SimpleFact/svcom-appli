<?php

use App\Filament\Pages\Documentation as PageDocumentation;
use App\Filament\Resources\ATrier\ATrierResource;
use App\Filament\Resources\LieuxAVerifier\LieuAVerifierResource;
use App\Filament\Support\Documentation;
use App\Models\Admin;

it('documente chaque écran du back-office, sans fichier orphelin', function () {
    $ancres = [];

    foreach (Documentation::ecrans() as $ecran) {
        expect(Documentation::fichier($ecran))->toBeFile("{$ecran} : écrire sa documentation dans resources/documentation/".Documentation::ancre($ecran).'.md')
            ->and(trim(Documentation::texte($ecran)))->not->toBe('');
        $ancres[] = Documentation::ancre($ecran).'.md';
    }

    expect(array_map('basename', glob(resource_path('documentation/*.md'))))->toEqualCanonicalizing($ancres);
});

it('montre toute la documentation à un super admin', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get(PageDocumentation::getUrl())->assertOk()
        ->assertSee('Boîte de travail')->assertSee('id="a-trier"', false)->assertSee('id="sources"', false)
        ->assertSee('id="modeles-profil"', false)->assertSee('id="admins"', false);
});

it('ne documente pour un compte limité que les menus qu’il peut ouvrir', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->avecMenus([ATrierResource::class, LieuAVerifierResource::class])->create());

    $this->get(PageDocumentation::getUrl())->assertOk()
        ->assertSee('id="boite-de-travail"', false)->assertSee('id="a-trier"', false)->assertSee('id="lieux-a-verifier"', false)
        ->assertDontSee('id="sources"', false)->assertDontSee('id="a-classer"', false)
        ->assertDontSee('id="admins"', false)->assertDontSee('id="modeles-profil"', false);
});

it('met un lien « Aide » vers sa documentation en haut de chaque écran', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get(ATrierResource::getUrl())->assertOk()->assertSee(PageDocumentation::getUrl().'#a-trier', false);
});
