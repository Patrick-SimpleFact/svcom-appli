<?php

use App\Filament\Resources\Spectacles\Pages\ListSpectacles;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Models\Admin;
use App\Models\Representation;
use App\Models\Spectacle;
use Carbon\CarbonImmutable;
use Database\Seeders\SourcesSeeder;
use Livewire\Livewire;

/** Fiche spectacle : « Fusionner avec… » ; liste : spectacles ajoutés aujourd'hui, hier ou un jour donné. */
beforeEach(function () {
    $this->seed(SourcesSeeder::class);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
});

it('fusionne la fiche ouverte dans le spectacle choisi, qui garde son titre', function () {
    $doublon = Representation::factory()->create(['debut' => CarbonImmutable::tomorrow('Europe/Paris')->setTime(10, 0)]);
    $garde = Representation::factory()->create(['debut' => CarbonImmutable::tomorrow('Europe/Paris')->setTime(15, 20)]);
    $doublon->spectacle->update(['titre' => 'Voyagez avec Jules Verne au Grand Hôtel des Rêves !']);
    $garde->spectacle->update(['titre' => 'Jules Verne, Le Voyage Extraordinaire']);

    Livewire::test(ViewSpectacle::class, ['record' => $doublon->spectacle->getRouteKey()])
        ->callAction('fusionner', ['garde' => $garde->spectacle_id])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    expect($doublon->fresh()->spectacle_id)->toBe($garde->spectacle_id)
        ->and(Representation::where('spectacle_id', $garde->spectacle_id)->count())->toBe(2)
        ->and($garde->spectacle->fresh()->titre)->toBe('Jules Verne, Le Voyage Extraordinaire');
});

it('exige de choisir le spectacle à garder', function () {
    $spectacle = Spectacle::factory()->create();

    Livewire::test(ViewSpectacle::class, ['record' => $spectacle->getRouteKey()])
        ->callAction('fusionner', ['garde' => null])
        ->assertHasActionErrors(['garde' => 'required']);
});

it('liste les spectacles ajoutés aujourd’hui, hier, ou un jour donné', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'Europe/Paris'));
    $ajoute = fn (string $quand) => tap(Spectacle::factory()->create(), fn (Spectacle $s) => $s->forceFill(['created_at' => CarbonImmutable::parse($quand, 'Europe/Paris')])->save());
    $ceMatin = $ajoute('2026-10-22 00:30');
    $hierSoir = $ajoute('2026-10-21 23:30'); // le 21 à Paris, mais le 21 à 21 h 30 UTC
    $avant = $ajoute('2026-10-19 12:00');

    Livewire::test(ListSpectacles::class, ['activeTab' => 'aujourdhui'])
        ->assertCanSeeTableRecords([$ceMatin])->assertCanNotSeeTableRecords([$hierSoir, $avant]);
    Livewire::test(ListSpectacles::class, ['activeTab' => 'hier'])
        ->assertCanSeeTableRecords([$hierSoir])->assertCanNotSeeTableRecords([$ceMatin, $avant]);
    Livewire::test(ListSpectacles::class)
        ->filterTable('ajoutes', ['le' => '2026-10-19'])
        ->assertCanSeeTableRecords([$avant])->assertCanNotSeeTableRecords([$ceMatin, $hierSoir]);
});
