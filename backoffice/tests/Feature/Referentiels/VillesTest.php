<?php

use App\Actions\ImporterVilles;
use App\Filament\Resources\Villes\Pages\ListVilles;
use App\Models\Admin;
use App\Models\JournalAction;
use App\Models\Ville;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::fake([
        'geo.api.gouv.fr/*' => Http::response([
            ['nom' => 'Avignon', 'code' => '84007', 'codeDepartement' => '84', 'centre' => ['type' => 'Point', 'coordinates' => [4.8333, 43.9416]], 'population' => 92188, 'codesPostaux' => ['84000', '84140']],
            ['nom' => 'Pointe-à-Pitre', 'code' => '97120', 'codeDepartement' => '971', 'centre' => ['type' => 'Point', 'coordinates' => [-61.5379, 16.2351]], 'population' => 15410, 'codesPostaux' => ['97110']],
            ['nom' => 'Papeete', 'code' => '98735', 'codeDepartement' => '987', 'centre' => ['type' => 'Point', 'coordinates' => [-149.5555, -17.5572]], 'population' => 26654, 'codesPostaux' => ['98714']],
            ['nom' => 'Sans position', 'code' => '99999', 'codeDepartement' => '99'],
        ]),
    ]);
});

it('importe les communes avec position, codes postaux et fuseau horaire', function () {
    $resultat = app(ImporterVilles::class)->handle();

    expect($resultat)->toBe(['creees' => 3, 'mises_a_jour' => 0]);

    $avignon = Ville::firstWhere('code_insee', '84007');
    expect($avignon->nom_normalise)->toBe('avignon')
        ->and($avignon->codes_postaux)->toBe(['84000', '84140'])
        ->and($avignon->fuseau_horaire)->toBe('Europe/Paris')
        ->and($avignon->position->latitude)->toEqualWithDelta(43.9416, 0.0001)
        ->and($avignon->position->longitude)->toEqualWithDelta(4.8333, 0.0001);

    expect(Ville::firstWhere('code_insee', '97120')->fuseau_horaire)->toBe('America/Guadeloupe')
        ->and(Ville::firstWhere('code_insee', '98735')->fuseau_horaire)->toBe('Pacific/Tahiti');
});

it('met à jour sans jamais écraser le choix « ville pilote »', function () {
    app(ImporterVilles::class)->handle();
    Ville::where('code_insee', '84007')->update(['est_pilote' => true]);

    $resultat = app(ImporterVilles::class)->handle();

    expect($resultat)->toBe(['creees' => 0, 'mises_a_jour' => 3])
        ->and(Ville::count())->toBe(3)
        ->and(Ville::firstWhere('code_insee', '84007')->est_pilote)->toBeTrue();
});

it('n’inscrit pas l’import automatique au journal des actions', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    app(ImporterVilles::class)->handle();

    expect(JournalAction::count())->toBe(0);
});

it('retrouve une ville par son nom sans accent ou son code postal, et la coche comme pilote', function () {
    app(ImporterVilles::class)->handle();
    $admin = Admin::factory()->avecDoubleAuthentification()->create();
    $this->actingAs($admin);
    $avignon = Ville::firstWhere('code_insee', '84007');
    $pointeAPitre = Ville::firstWhere('code_insee', '97120');

    Livewire::test(ListVilles::class)
        ->searchTable('pointe a pitre')
        ->assertCanSeeTableRecords([$pointeAPitre])
        ->assertCanNotSeeTableRecords([$avignon])
        ->searchTable('84000')
        ->assertCanSeeTableRecords([$avignon])
        ->call('updateTableColumnState', 'est_pilote', (string) $avignon->getKey(), true);

    expect($avignon->fresh()->est_pilote)->toBeTrue()
        ->and(JournalAction::sole()->apres)->toBe(['est_pilote' => true]);
});
