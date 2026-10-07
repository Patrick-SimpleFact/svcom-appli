<?php

use App\Actions\MasquerRepresentation;
use App\Enums\StatutRepresentation;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Filament\Resources\Spectacles\Pages\EditSpectacle;
use App\Filament\Resources\Spectacles\Pages\ViewSpectacle;
use App\Filament\Resources\Spectacles\RelationManagers\RepresentationsRelationManager;
use App\Models\Admin;
use App\Models\Offre;
use App\Models\Representation;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\SourcesSeeder;
use Livewire\Livewire;

/** F7.8 (A02) : corrections verrouillées et datées, masquage à quatre niveaux, immédiat et réversible. */
beforeEach(function () {
    $this->seed(SourcesSeeder::class);
    [$this->billetreduc, $this->fnac] = [Source::firstWhere('code', 'billetreduc'), Source::firstWhere('code', 'fnac')];
    $this->representation = Representation::factory()->create(['debut' => CarbonImmutable::parse('2026-10-17 20:30', 'Europe/Paris')]);
    $this->vendue = fn (Representation $r, Source $source, bool $disparue = false) => Offre::create([
        'source_id' => $source->id, 'identifiant_externe' => $source->code.'-'.$r->id, 'donnees_normalisees' => ['titre' => 'Test'],
        'empreinte' => str_repeat('a', 64), 'vue_le' => now(), 'representation_id' => $r->id, 'disparue_le' => $disparue ? now() : null,
    ]);
    $this->visible = fn () => Representation::visibles()->whereKey($this->representation->id)->exists();
    $this->admin = Admin::factory()->avecDoubleAuthentification()->create(['nom' => 'Patrick']);
});

it('montre une séance programmée, vendue par une source non masquée, ou saisie à la main', function () {
    expect(($this->visible)())->toBeTrue(); // sans offre : saisie à la main

    ($this->vendue)($this->representation, $this->billetreduc);

    expect(($this->visible)())->toBeTrue();
});

it('cache une séance dès que sa séance, son spectacle ou son lieu est masqué, et la réaffiche ensuite', function (string $niveau) {
    ($this->vendue)($this->representation, $this->billetreduc);
    $masquer = fn (bool $oui) => match ($niveau) {
        'séance' => $oui ? app(MasquerRepresentation::class)->masquer($this->representation) : app(MasquerRepresentation::class)->reafficher($this->representation),
        'spectacle' => $this->representation->spectacle->update(['masque' => $oui]),
        'lieu' => $this->representation->lieu->update(['masque' => $oui]),
        'source' => $this->billetreduc->update(['masquee' => $oui]),
    };

    $masquer(true);
    expect(($this->visible)())->toBeFalse();

    $masquer(false);
    expect(($this->visible)())->toBeTrue();
})->with(['séance', 'spectacle', 'lieu', 'source']);

it('garde visible une séance vendue aussi par une source non masquée', function () {
    ($this->vendue)($this->representation, $this->billetreduc);
    ($this->vendue)($this->representation, $this->fnac);

    $this->billetreduc->update(['masquee' => true]);

    expect(($this->visible)())->toBeTrue();

    $this->fnac->update(['masquee' => true]);

    expect(($this->visible)())->toBeFalse();
});

it('réaffiche une séance masquée selon la collecte : retirée si plus aucune billetterie ne la vend', function () {
    ($this->vendue)($this->representation, $this->billetreduc, disparue: true);
    $this->actingAs($this->admin);

    app(MasquerRepresentation::class)->masquer($this->representation);

    expect($this->representation->fresh())->statut->toBe(StatutRepresentation::Masquee)
        ->and($this->representation->fresh()->estVerrouille('statut'))->toBeTrue(); // la collecte ne la réaffiche pas

    app(MasquerRepresentation::class)->reafficher($this->representation->fresh());

    expect($this->representation->fresh())->statut->toBe(StatutRepresentation::Retiree)
        ->and($this->representation->fresh()->estVerrouille('statut'))->toBeFalse();
});

it('masque et réaffiche une séance depuis la fiche du spectacle', function () {
    $this->actingAs($this->admin);
    $test = fn () => Livewire::test(RepresentationsRelationManager::class, ['ownerRecord' => $this->representation->spectacle, 'pageClass' => ViewSpectacle::class]);

    $test()->callTableAction('masquer', $this->representation);
    expect($this->representation->fresh()->statut)->toBe(StatutRepresentation::Masquee);

    $test()->assertTableActionHidden('masquer', $this->representation)->callTableAction('reafficher', $this->representation);
    expect($this->representation->fresh()->statut)->toBe(StatutRepresentation::Programmee);
});

it('corrige l’horaire d’une séance à l’heure du lieu, et le verrouille contre la collecte', function () {
    $this->actingAs($this->admin);

    Livewire::test(RepresentationsRelationManager::class, ['ownerRecord' => $this->representation->spectacle, 'pageClass' => ViewSpectacle::class])
        ->callTableAction('edit', $this->representation, ['debut' => '2026-10-17 21:00:00'])
        ->assertHasNoTableActionErrors();

    $corrigee = $this->representation->fresh();

    expect($corrigee->debut->setTimezone('Europe/Paris')->format('H:i'))->toBe('21:00')
        ->and($corrigee->estVerrouille('debut'))->toBeTrue()
        ->and($corrigee->resumeCorrections(RepresentationsRelationManager::LIBELLES))->toContain('horaire (')->toContain('Patrick');
});

it('corrige le titre d’un spectacle : verrouillé, daté, et masque le spectacle d’un clic', function () {
    $spectacle = $this->representation->spectacle;
    $this->actingAs($this->admin);

    Livewire::test(EditSpectacle::class, ['record' => $spectacle->getRouteKey()])
        ->fillForm(['titre' => 'Le Tartuffe'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('masquer');

    expect($spectacle->fresh())->titre->toBe('Le Tartuffe')->masque->toBeTrue()
        ->and($spectacle->fresh()->estVerrouille('titre'))->toBeTrue()
        ->and($spectacle->fresh()->estVerrouille('masque'))->toBeFalse()
        ->and($spectacle->fresh()->resumeCorrections())->toMatch('/^titre \(\d\d\/\d\d\/\d{4} \d\d:\d\d, Patrick\)$/');

    $this->get("/admin/spectacles/{$spectacle->id}")->assertOk()->assertSee('Masqué dans l’app')->assertSee('Corrigé à la main');
});

it('masque une source entière depuis sa fiche, sans arrêter sa collecte', function () {
    $this->actingAs($this->admin);

    Livewire::test(ViewSource::class, ['record' => $this->fnac->getRouteKey()])->callAction('masquer');

    expect($this->fnac->fresh())->masquee->toBeTrue()->actif->toBeTrue();
});
