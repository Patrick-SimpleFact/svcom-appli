<?php

use App\Enums\TypeRepresentation;
use App\Filament\Resources\Lieux\Pages\EditLieu;
use App\Filament\Resources\Lieux\RelationManagers\RepresentationsRelationManager;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Models\Admin;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->theatre = Genre::create(['slug' => 'theatre', 'libelle' => 'Théâtre', 'ordre' => 1]);
    $this->humour = Genre::create(['slug' => 'humour', 'libelle' => 'Humour', 'ordre' => 2]);
});

it('recopie la position, la ville et le genre sur la représentation', function () {
    $ville = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'position' => new Point(43.94, 4.83), 'fuseau_horaire' => 'Europe/Paris']);
    $lieu = Lieu::factory()->create(['ville_id' => $ville->id, 'position' => new Point(43.9465, 4.8079)]);
    $spectacle = Spectacle::factory()->create(['genre_id' => $this->humour->id]);

    $representation = Representation::factory()->create(['lieu_id' => $lieu->id, 'spectacle_id' => $spectacle->id])->fresh();

    expect($representation->position->latitude)->toEqualWithDelta(43.9465, 0.000001)
        ->and($representation->ville_id)->toBe($ville->id)
        ->and($representation->genre_id)->toBe($this->humour->id);
});

it('suit le lieu quand sa position est corrigée, et le spectacle quand son genre change', function () {
    $lieu = Lieu::factory()->create(['position' => new Point(43.90, 4.80)]);
    $spectacle = Spectacle::factory()->create(['genre_id' => $this->theatre->id]);
    $representation = Representation::factory()->create(['lieu_id' => $lieu->id, 'spectacle_id' => $spectacle->id]);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $lieu->update(['position' => new Point(43.9465, 4.8079)]);
    $spectacle->update(['genre_id' => $this->humour->id]);

    $representation->refresh();
    expect($representation->position->latitude)->toEqualWithDelta(43.9465, 0.000001)
        ->and($representation->genre_id)->toBe($this->humour->id);
});

it('compte une séance après minuit dans la soirée de la veille, à l’heure du lieu', function () {
    $lieu = Lieu::factory()->create(['fuseau_horaire' => 'Europe/Paris']);

    $minuitTrente = Representation::factory()->create([
        'lieu_id' => $lieu->id,
        'debut' => CarbonImmutable::parse('2026-10-18 00:30', 'Europe/Paris'),
    ]);
    $vingtHeures = Representation::factory()->create([
        'lieu_id' => $lieu->id,
        'debut' => CarbonImmutable::parse('2026-10-17 20:00', 'Europe/Paris'),
    ]);

    expect($minuitTrente->date_locale->toDateString())->toBe('2026-10-17')
        ->and($vingtHeures->date_locale->toDateString())->toBe('2026-10-17');
});

it('calcule le jour à l’heure locale en outre-mer', function () {
    $lieu = Lieu::factory()->create(['fuseau_horaire' => 'Indian/Reunion']);

    // 22 h à La Réunion = 20 h à Paris le même jour
    $representation = Representation::factory()->create([
        'lieu_id' => $lieu->id,
        'debut' => CarbonImmutable::parse('2026-10-17 22:00', 'Indian/Reunion'),
    ]);

    expect($representation->date_locale->toDateString())->toBe('2026-10-17')
        ->and($representation->fresh()->debut->utc()->format('H:i'))->toBe('18:00');
});

it('garde la date donnée pour un spectacle sans horaire', function () {
    $representation = Representation::factory()->create([
        'type' => TypeRepresentation::Jour,
        'debut' => null,
        'date_locale' => '2026-10-17',
    ]);

    expect($representation->date_locale->toDateString())->toBe('2026-10-17');
});

it('trouve les représentations autour d’un point, avec leur distance', function () {
    $proche = Lieu::factory()->create(['position' => new Point(43.9465, 4.8079)]);
    $loin = Lieu::factory()->create(['position' => new Point(48.8566, 2.3522)]);
    $soir = CarbonImmutable::parse('2026-10-17 20:00', 'Europe/Paris');
    $ici = Representation::factory()->create(['lieu_id' => $proche->id, 'debut' => $soir]);
    Representation::factory()->create(['lieu_id' => $loin->id, 'debut' => $soir]);
    Representation::factory()->create(['lieu_id' => $proche->id, 'debut' => $soir->addDay()]);

    $resultats = Representation::autourDe(new Point(43.9493, 4.8057), 5000, '2026-10-17')->get();

    expect($resultats->pluck('id')->all())->toBe([$ici->id])
        ->and((float) $resultats->first()->distance_m)->toBeLessThan(500);
});

it('crée puis supprime les données de démonstration sans toucher au reste', function () {
    $ville = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'position' => new Point(43.94, 4.83), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => true]);
    Lieu::factory()->count(2)->create(['ville_id' => $ville->id]);
    $vrai = Spectacle::factory()->create(['titre' => 'Un vrai spectacle']);

    $this->artisan('catalogue:demo')->assertSuccessful();
    expect(Spectacle::where('demo', true)->count())->toBe(4)
        ->and(Representation::count())->toBe(16);

    $this->artisan('catalogue:demo', ['--supprimer' => true])->assertSuccessful();
    expect(Spectacle::pluck('id')->all())->toBe([$vrai->id])
        ->and(Representation::count())->toBe(0);
});

it('affiche le programme d’un lieu et la liste des spectacles dans le back-office', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $lieu = Lieu::factory()->create();
    $representation = Representation::factory()->create(['lieu_id' => $lieu->id, 'debut' => now()->addDay()->setTime(20, 30)]);

    $passee = Representation::factory()->create(['lieu_id' => $lieu->id, 'debut' => now()->subDays(3)->setTime(20, 30)]);

    $this->get('/admin/lieux/'.$lieu->id.'/edit')->assertOk();
    Livewire\Livewire::test(RepresentationsRelationManager::class, [
        'ownerRecord' => $lieu,
        'pageClass' => EditLieu::class,
    ])->assertCanSeeTableRecords([$representation])->assertCanNotSeeTableRecords([$passee]);

    $this->get('/admin/spectacles')->assertOk()->assertSee($representation->spectacle->titre);
    $this->get('/admin/spectacles/'.$representation->spectacle_id)->assertOk();
    Livewire\Livewire::test(App\Filament\Resources\Spectacles\RelationManagers\RepresentationsRelationManager::class, [
        'ownerRecord' => $representation->spectacle,
        'pageClass' => ViewSpectacle::class,
    ])->assertCanSeeTableRecords([$representation]);
});
