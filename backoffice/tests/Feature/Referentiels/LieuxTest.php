<?php

use App\Actions\FusionnerLieux;
use App\Actions\ImporterLieuxMinistere;
use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Filament\Resources\Lieux\Pages\EditLieu;
use App\Filament\Resources\Lieux\Pages\ListLieux;
use App\Models\Admin;
use App\Models\JournalAction;
use App\Models\Lieu;
use App\Models\Ville;
use App\Support\Point;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

function csvBasilic(array $lignes): string
{
    $entetes = ['Nom', 'Adresse', 'Code Postal', 'code_insee', 'Type équipement ou lieu', 'Label et appellation', 'Jauge_du_theatre', 'Identifiant_deps_a_partir_de_2022', 'Latitude', 'Longitude'];
    $csv = "\xEF\xBB\xBF".implode(';', $entetes)."\n";

    foreach ($lignes as $ligne) {
        $csv .= implode(';', array_map(fn ($e) => $ligne[$e] ?? '', $entetes))."\n";
    }

    return $csv;
}

beforeEach(function () {
    $this->avignon = Ville::create([
        'nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84',
        'codes_postaux' => ['84000'], 'population' => 92188, 'position' => new Point(43.9416, 4.8333), 'fuseau_horaire' => 'Europe/Paris',
    ]);

    $this->csv = csvBasilic([
        ['Nom' => 'Théâtre du Chêne noir', 'Adresse' => '8 bis r. Sainte-Catherine', 'Code Postal' => '84000', 'code_insee' => '84007', 'Type équipement ou lieu' => 'Théâtre', 'Label et appellation' => 'Compagnie avec lieu', 'Jauge_du_theatre' => '408', 'Identifiant_deps_a_partir_de_2022' => 'COMP_84007_21319', 'Latitude' => '43.950518', 'Longitude' => '4.809771'],
        ['Nom' => 'La Manutention', 'Adresse' => '4 r. Esc.saint-Anne', 'Code Postal' => '84000', 'code_insee' => '84007', 'Type équipement ou lieu' => 'Scène', 'Label et appellation' => 'Scène de musiques actuelles', 'Identifiant_deps_a_partir_de_2022' => 'SMAC_84007_70571', 'Latitude' => '43.951408', 'Longitude' => '4.808588'],
        ['Nom' => 'Musée Calvet', 'code_insee' => '84007', 'Type équipement ou lieu' => 'Musée', 'Identifiant_deps_a_partir_de_2022' => 'MUSE_1', 'Latitude' => '43.94', 'Longitude' => '4.80'],
        ['Nom' => 'Théâtre sans position', 'code_insee' => '84007', 'Type équipement ou lieu' => 'Théâtre', 'Identifiant_deps_a_partir_de_2022' => 'THEA_X'],
    ]);
});

it('importe les lieux de spectacle du Ministère et ignore le reste', function () {
    $resultat = app(ImporterLieuxMinistere::class)->handle($this->csv);

    expect($resultat)->toBe(['creees' => 2, 'mises_a_jour' => 0, 'ignorees' => 1]);

    $chene = Lieu::firstWhere('ref_ministere', 'COMP_84007_21319');
    expect($chene->nom_normalise)->toBe('theatre du chene noir')
        ->and($chene->type)->toBe(TypeLieu::Theatre)
        ->and($chene->ville_id)->toBe($this->avignon->id)
        ->and($chene->jauge)->toBe(408)
        ->and($chene->precision_position)->toBe(PrecisionPosition::Exacte)
        ->and($chene->position->latitude)->toEqualWithDelta(43.950518, 0.000001);

    expect(Lieu::firstWhere('ref_ministere', 'SMAC_84007_70571')->type)->toBe(TypeLieu::SalleConcert);
});

it('ne remplace jamais un champ corrigé à la main lors d’un nouvel import', function () {
    app(ImporterLieuxMinistere::class)->handle($this->csv);
    $chene = Lieu::firstWhere('ref_ministere', 'COMP_84007_21319');

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $chene->update(['nom' => 'Théâtre du Chêne Noir', 'telephone' => '04 90 86 58 11']);
    auth()->logout();

    expect($chene->fresh()->champs_verrouilles)->toBe(['nom', 'telephone']);

    $resultat = app(ImporterLieuxMinistere::class)->handle($this->csv);

    expect($resultat['mises_a_jour'])->toBe(2)
        ->and($chene->fresh()->nom)->toBe('Théâtre du Chêne Noir')
        ->and($chene->fresh()->jauge)->toBe(408);
});

it('fusionne un doublon dans le lieu conservé, qui récupère ce qui lui manquait', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $conserve = Lieu::factory()->create(['nom' => 'Théâtre de l’Observance', 'telephone' => null, 'ville_id' => $this->avignon->id]);
    $doublon = Lieu::factory()->create(['nom' => 'Observance – salle 1', 'telephone' => '04 90 00 00 00']);
    $ancienDoublon = Lieu::factory()->create(['nom' => 'Observance salle 2', 'fusionne_dans_id' => $doublon->id]);

    app(FusionnerLieux::class)->handle($doublon, $conserve);

    expect($doublon->fresh()->fusionne_dans_id)->toBe($conserve->id)
        ->and($doublon->fresh()->masque)->toBeTrue()
        ->and($conserve->fresh()->telephone)->toBe('04 90 00 00 00')
        ->and($ancienDoublon->fresh()->fusionne_dans_id)->toBe($conserve->id)
        ->and(Lieu::actifs()->pluck('id')->all())->toBe([$conserve->id]);

    expect(JournalAction::where('cible_id', $doublon->id)->where('action', 'modification')->exists())->toBeTrue();
});

it('refuse de fusionner un lieu avec lui-même', function () {
    $lieu = Lieu::factory()->create();

    app(FusionnerLieux::class)->handle($lieu, $lieu);
})->throws(InvalidArgumentException::class);

it('corrige la position d’un lieu depuis le back-office et la verrouille', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $lieu = Lieu::factory()->create(['precision_position' => PrecisionPosition::Commune]);

    Livewire::test(EditLieu::class, ['record' => $lieu->getKey()])
        ->assertFormSet(['latitude' => $lieu->position->latitude])
        ->fillForm(['latitude' => '43.9465', 'longitude' => '4.8079', 'precision_position' => PrecisionPosition::Exacte->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $lieu->refresh();
    expect($lieu->position->latitude)->toEqualWithDelta(43.9465, 0.000001)
        ->and($lieu->precision_position)->toBe(PrecisionPosition::Exacte)
        ->and($lieu->champs_verrouilles)->toContain('position', 'precision_position');
});

it('retrouve un lieu sans accent et cache les doublons fusionnés par défaut', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $chene = Lieu::factory()->create(['nom' => 'Théâtre du Chêne noir']);
    $fusionne = Lieu::factory()->create(['nom' => 'Chêne noir (doublon)', 'fusionne_dans_id' => $chene->id]);

    Livewire::test(ListLieux::class)
        ->searchTable('chene noir')
        ->assertCanSeeTableRecords([$chene])
        ->assertCanNotSeeTableRecords([$fusionne]);

    $this->get('/admin/lieux/'.$chene->id.'/edit')->assertOk()->assertSee('Fusionner avec un autre lieu');
});

it('propose les villes quand on tape « avignon » et affiche le nom de la ville choisie', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $lieu = Lieu::factory()->create(['ville_id' => $this->avignon->id]);

    $page = Livewire::test(EditLieu::class, ['record' => $lieu->getKey()]);
    $champVille = $page->instance()->getSchema('form')->getComponent(
        fn ($composant) => $composant instanceof Select && $composant->getName() === 'ville_id',
    );

    expect($champVille->getSearchResults('avignon'))->toBe([$this->avignon->id => 'Avignon (84)'])
        ->and($champVille->getSearchResults('AVIGNON'))->toHaveKey($this->avignon->id)
        ->and($champVille->getOptionLabel())->toBe('Avignon (84)');

    $page->fillForm(['ville_id' => $this->avignon->id, 'telephone' => '04 90 86 58 11'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lieu->fresh()->telephone)->toBe('04 90 86 58 11');
});

it('propose les lieux à conserver dans la fenêtre de fusion', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $doublon = Lieu::factory()->create(['nom' => 'Observance salle 1']);
    $conserve = Lieu::factory()->create(['nom' => 'Théâtre de l’Observance', 'ville_id' => $this->avignon->id]);

    Livewire::test(EditLieu::class, ['record' => $doublon->getKey()])
        ->callAction('fusionner', ['conserve_id' => $conserve->id])
        ->assertHasNoActionErrors();

    expect($doublon->fresh()->fusionne_dans_id)->toBe($conserve->id);
});
