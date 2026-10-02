<?php

use App\Enums\TypeLienSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Models\Admin;
use App\Models\JournalAction;
use App\Models\Offre;
use App\Models\RegleFiltrage;
use App\Models\Source;
use Database\Seeders\ReglesFiltrageSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

it('déclare les six sources avec leur licence et leur mention obligatoire', function () {
    $this->seed(SourcesSeeder::class);

    expect(Source::orderBy('id')->pluck('code')->all())
        ->toBe(['billetreduc', 'fnac', 'datatourisme', 'openagenda', 'ticketmaster', 'paris_qfap']);

    $datatourisme = Source::firstWhere('code', 'datatourisme');
    expect($datatourisme->licence)->toContain('Licence Ouverte')
        ->and($datatourisme->mention_obligatoire)->toContain('DATAtourisme')
        ->and($datatourisme->type_lien)->toBe(TypeLienSource::Aucun)
        ->and(Source::firstWhere('code', 'billetreduc')->type_lien)->toBe(TypeLienSource::Affilie)
        ->and(Source::firstWhere('code', 'paris_qfap')->zone)->toBe(['villes' => ['75056']]);
});

it('ne réactive jamais une source désactivée dans le BO quand on relance la déclaration', function () {
    $this->seed(SourcesSeeder::class);
    Source::where('code', 'fnac')->update(['actif' => false]);

    $this->seed(SourcesSeeder::class);

    expect(Source::firstWhere('code', 'fnac')->actif)->toBeFalse()
        ->and(Source::count())->toBe(6);
});

it('désactive une source d’un clic dans le BO, avec trace au journal', function () {
    $this->seed(SourcesSeeder::class);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $fnac = Source::firstWhere('code', 'fnac');

    Livewire::test(ListSources::class)
        ->assertCanSeeTableRecords(Source::all())
        ->call('updateTableColumnState', 'actif', (string) $fnac->getKey(), false);

    expect($fnac->fresh()->actif)->toBeFalse()
        ->and(JournalAction::sole()->apres)->toBe(['actif' => false]);

    $this->get('/admin/sources/'.$fnac->id)->assertOk()->assertSee('Contrat d’affiliation Awin', false);
});

it('charge les mots du filtre « spectacle vivant » sans doublon', function () {
    $this->seed(ReglesFiltrageSeeder::class);
    $this->seed(ReglesFiltrageSeeder::class);

    expect(RegleFiltrage::where('type', 'inclure')->count())->toBe(count(ReglesFiltrageSeeder::INCLURE))
        ->and(RegleFiltrage::where('type', 'exclure')->pluck('mot'))->toContain('exposition', 'parcours de l art');
});

it('refuse deux offres avec le même identifiant pour une même source', function () {
    $this->seed(SourcesSeeder::class);
    $source = Source::firstWhere('code', 'billetreduc');
    $offre = fn () => Offre::create([
        'source_id' => $source->id, 'identifiant_externe' => 'BR-123', 'donnees_normalisees' => ['titre' => 'Test'],
        'empreinte' => str_repeat('a', 64), 'vue_le' => now(),
    ]);

    $offre();
    $offre();
})->throws(UniqueConstraintViolationException::class);
