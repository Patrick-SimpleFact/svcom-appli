<?php

use App\Actions\MesurerCouverture;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Filament\Pages\Couverture as PageCouverture;
use App\Filament\Widgets\CouvertureVilles;
use App\Filament\Widgets\EvolutionCouverture;
use App\Models\Admin;
use App\Models\Couverture;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Livewire\Livewire;

/** F7.13 (A05) : couverture des villes pilotes, ce soir, ce week-end, sur 30 jours. */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00', 'Europe/Paris')); // un mercredi
    $this->avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 92188, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => true]);
    $this->lieu = Lieu::factory()->create(['position' => new Point(43.9500, 4.8100)]);
    $this->seance = fn (string $quand, array $attributs = []) => Representation::factory()->create([
        'lieu_id' => $this->lieu->id, 'debut' => CarbonImmutable::parse($quand, 'Europe/Paris'), ...$attributs,
    ]);
});

it('compte ce soir, le week-end qui vient et les 30 prochains jours, avec la part à l’heure connue', function () {
    ($this->seance)('2026-10-14 20:30');                       // ce soir
    ($this->seance)('2026-10-15 00:30');                       // après minuit : encore ce soir
    ($this->seance)('2026-10-16 20:00');                       // vendredi
    ($this->seance)('2026-10-18 16:00');                       // dimanche
    ($this->seance)('2026-10-19 20:00');                       // lundi : hors week-end
    ($this->seance)('2026-11-20 20:00');                       // au-delà de 30 jours
    ($this->seance)('2026-10-17 20:00', ['statut' => StatutRepresentation::Masquee]); // invisible
    ($this->seance)('2026-10-14 00:00', ['type' => TypeRepresentation::Periode, 'debut' => null, 'date_locale' => '2026-10-10', 'date_fin' => '2026-10-20']);
    Representation::factory()->create(['debut' => CarbonImmutable::parse('2026-10-14 20:30', 'Europe/Paris'),
        'lieu_id' => Lieu::factory()->create(['position' => new Point(43.2965, 5.3698)])->id]); // Marseille : hors rayon

    expect(app(MesurerCouverture::class)->handle($this->avignon))->toBe([
        'ce_soir' => 3,       // 20 h 30, 0 h 30 et la période
        'week_end' => 3,      // vendredi, dimanche, la période
        'trente_jours' => 6,
        'spectacles' => 6,
        'avec_horaire_pct' => 83, // 5 séances à l'heure sur 6
    ]);
});

it('compte le week-end en cours à partir du vendredi', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 15:00', 'Europe/Paris')); // samedi
    ($this->seance)('2026-10-16 20:00'); // hier : passé
    ($this->seance)('2026-10-17 20:00');
    ($this->seance)('2026-10-18 20:00');

    expect(app(MesurerCouverture::class)->handle($this->avignon)['week_end'])->toBe(2);
});

it('garde une mesure par ville et par jour, et montre l’évolution sur 7 jours', function () {
    Couverture::create(['jour' => '2026-10-07', 'ville_id' => $this->avignon->id, 'ce_soir' => 1, 'week_end' => 2, 'trente_jours' => 4, 'spectacles' => 4, 'avec_horaire_pct' => 100, 'mesuree_le' => now()->subWeek()]);
    ($this->seance)('2026-10-14 20:30');
    ($this->seance)('2026-10-20 20:30');

    app(MesurerCouverture::class)->enregistrer();
    app(MesurerCouverture::class)->enregistrer(); // la 2e mesure du jour remplace la 1re

    expect(Couverture::where('ville_id', $this->avignon->id)->count())->toBe(2);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    Livewire::test(CouvertureVilles::class)->assertSee('Avignon')->assertSee('-50 % sur 7 j')->assertSee('-100 % sur 7 j')->assertSee('+0 % sur 7 j');
    Livewire::test(EvolutionCouverture::class)->assertOk();
    $this->get('/admin/couverture')->assertOk()->assertSee('Couverture des villes pilotes');
});

it('mesure à l’ouverture de la page une ville pilote ajoutée, et redessine le tableau après « Mesurer maintenant »', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    app(MesurerCouverture::class)->enregistrer();
    $nouvelle = Ville::create(['nom' => 'Uzès', 'nom_normalise' => 'uzes', 'code_insee' => '30334', 'departement' => '30', 'codes_postaux' => [], 'population' => 8000, 'position' => new Point(44.0125, 4.4197), 'fuseau_horaire' => 'Europe/Paris', 'est_pilote' => true]);

    Livewire::test(PageCouverture::class)
        ->callAction('mesurer')
        ->assertDispatched('couverture-mesuree');

    expect(Couverture::where('ville_id', $nouvelle->id)->exists())->toBeTrue()
        ->and(Couverture::where('ville_id', $this->avignon->id)->count())->toBe(1);
    Livewire::test(CouvertureVilles::class)->assertSee('Uzès');
});
