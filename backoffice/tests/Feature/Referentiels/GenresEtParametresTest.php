<?php

use App\Filament\Resources\Parametres\Pages\EditParametre;
use App\Models\Admin;
use App\Models\Genre;
use App\Models\JournalAction;
use App\Models\Parametre;
use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Livewire\Livewire;

it('crée les 8 genres de l’app dans l’ordre, sans doublon si on relance', function () {
    $this->seed(GenresSeeder::class);
    $this->seed(GenresSeeder::class);

    expect(Genre::orderBy('ordre')->pluck('libelle')->all())->toBe([
        'Théâtre', 'Humour', 'Concert', 'Comédie musicale & cabaret',
        'Danse', 'Cirque & magie', 'Opéra & lyrique', 'Autres',
    ]);
});

it('crée les réglages avec leurs valeurs de départ', function () {
    $this->seed(ParametresSeeder::class);

    expect(Parametre::valeur('rayon_auto_min_representations'))->toBe(8)
        ->and(Parametre::valeur('paliers_prix'))->toBe([0, 15, 30])
        ->and(Parametre::valeur('suggestions_actives'))->toBeTrue()
        ->and(Parametre::valeur('version_minimale_app'))->toBe('1.0.0');
});

it('ne remplace jamais une valeur modifiée dans le BO quand on relance les réglages', function () {
    $this->seed(ParametresSeeder::class);
    Parametre::firstWhere('cle', 'pistes_max_par_jour')->update(['valeur' => 3]);

    $this->seed(ParametresSeeder::class);

    expect(Parametre::valeur('pistes_max_par_jour'))->toBe(3);
});

it('signale un réglage inconnu', function () {
    $this->seed(ParametresSeeder::class);

    Parametre::valeur('reglage_qui_n_existe_pas');
})->throws(InvalidArgumentException::class);

it('modifie une liste de paliers depuis le BO, prise en compte aussitôt et journalisée', function () {
    $this->seed(ParametresSeeder::class);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    expect(Parametre::valeur('paliers_prix'))->toBe([0, 15, 30]);

    $parametre = Parametre::firstWhere('cle', 'paliers_prix');

    Livewire::test(EditParametre::class, ['record' => $parametre->getKey()])
        ->fillForm(['valeur' => ['0', '10', '20', '40']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Parametre::valeur('paliers_prix'))->toBe([0, 10, 20, 40])
        ->and(JournalAction::sole()->apres)->toBe(['valeur' => '[0,10,20,40]']);
});

it('affiche les écrans Villes et Réglages', function () {
    $this->seed(ParametresSeeder::class);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get('/admin/villes')->assertOk();
    $this->get('/admin/parametres')->assertOk()->assertSee('Élargissement automatique du rayon');
});
