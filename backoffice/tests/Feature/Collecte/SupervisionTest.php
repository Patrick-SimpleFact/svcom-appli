<?php

use App\Actions\SuperviserSources;
use App\Enums\StatutCollecte;
use App\Enums\TypeAlerte;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Jobs\CollecterSource;
use App\Mail\AlertesSupervision;
use App\Models\Admin;
use App\Models\Alerte;
use App\Models\Collecte;
use App\Models\Parametre;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** F7.9 (A03) : alertes de supervision des sources, par e-mail. */
beforeEach(function () {
    $this->seed([SourcesSeeder::class, ParametresSeeder::class]);
    Source::query()->update(['actif' => false]);
    $this->source = Source::firstWhere('code', 'fnac');
    $this->source->update(['actif' => true]);
    Admin::factory()->create(['email' => 'patrick@exemple.fr', 'actif' => true]);
    Admin::factory()->create(['email' => 'ancien@exemple.fr', 'actif' => false]);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 10:00', 'Europe/Paris'));

    $this->collecte = fn (string $fin, StatutCollecte $statut = StatutCollecte::Reussie, int $recus = 1000) => Collecte::create([
        'source_id' => $this->source->id, 'debut' => CarbonImmutable::parse($fin, 'Europe/Paris')->subMinutes(10)->utc(),
        'fin' => CarbonImmutable::parse($fin, 'Europe/Paris')->utc(), 'statut' => $statut, 'nb_recus' => $recus,
        'erreur' => $statut === StatutCollecte::Abandonnee ? 'Flux indisponible (HTTP 503)' : null,
    ]);
    $this->types = fn () => $this->source->alertes()->ouvertes()->pluck('type')->map->value->sort()->values()->all();
});

it('ne dit rien quand tout va bien', function () {
    foreach (['2026-10-14 08:00', '2026-10-15 08:00', '2026-10-16 08:00', '2026-10-17 08:00'] as $fin) {
        ($this->collecte)($fin);
    }

    app(SuperviserSources::class)->handle();

    expect(($this->types)())->toBe([]);
    Mail::assertNothingSent();
});

it('alerte une fois sur une collecte abandonnée, puis annonce la résolution', function () {
    ($this->collecte)('2026-10-17 08:00');
    ($this->collecte)('2026-10-17 09:30', StatutCollecte::Abandonnee);

    app(SuperviserSources::class)->handle();
    app(SuperviserSources::class)->handle(); // pas de second e-mail pour la même alerte

    expect(($this->types)())->toBe(['echec']);
    Mail::assertSent(AlertesSupervision::class, 1);
    Mail::assertSent(AlertesSupervision::class, fn (AlertesSupervision $m) => $m->hasTo('patrick@exemple.fr') && ! $m->hasTo('ancien@exemple.fr')
        && str_contains($m->envelope()->subject, 'Fnac') && str_contains($m->render(), 'HTTP 503'));

    ($this->collecte)('2026-10-17 09:50');
    app(SuperviserSources::class)->handle();

    expect(($this->types)())->toBe([]);
    Mail::assertSent(AlertesSupervision::class, fn (AlertesSupervision $m) => $m->resolues->count() === 1 && str_contains($m->envelope()->subject, 'résolue'));
});

it('alerte à partir de 7 h si rien n’a été publié depuis la veille 7 h, et sur des données de plus de 36 h', function () {
    ($this->collecte)('2026-10-15 20:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-16 06:30', 'Europe/Paris'));
    app(SuperviserSources::class)->handle();
    expect(($this->types)())->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 07:05', 'Europe/Paris'));
    app(SuperviserSources::class)->handle();
    expect(($this->types)())->toBe(['publication_manquante']);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00', 'Europe/Paris'));
    app(SuperviserSources::class)->handle();
    expect(($this->types)())->toBe(['donnees_perimees', 'publication_manquante']);
});

it('alerte sur une chute de volume de plus de 30 % par rapport aux 7 derniers jours', function (int $recus, array $attendu) {
    foreach (['2026-10-14 08:00', '2026-10-15 08:00', '2026-10-16 08:00'] as $fin) {
        ($this->collecte)($fin, recus: 1000);
    }
    ($this->collecte)('2026-10-17 08:00', recus: $recus);

    app(SuperviserSources::class)->handle();

    expect(($this->types)())->toBe($attendu);
})->with([
    'flux à moitié vide' => [500, ['chute_volume']],
    'baisse normale' => [800, []],
]);

it('ne surveille pas une source désactivée et résout ses alertes', function () {
    ($this->collecte)('2026-10-17 09:30', StatutCollecte::Abandonnee);
    app(SuperviserSources::class)->handle();

    $this->source->update(['actif' => false]);
    app(SuperviserSources::class)->handle();

    expect(($this->types)())->toBe([]);
});

it('envoie aux destinataires du réglage quand il est rempli', function () {
    Parametre::firstWhere('cle', 'alertes_destinataires')->update(['valeur' => 'alertes@spettacoli.fr, patrick@exemple.fr ']);

    expect(SuperviserSources::destinataires())->toBe(['alertes@spettacoli.fr', 'patrick@exemple.fr']);
});

it('alerte tout de suite quand une collecte est abandonnée après ses 4 essais', function () {
    ($this->collecte)('2026-10-17 08:00');
    ($this->collecte)('2026-10-17 09:30', StatutCollecte::Echouee);

    (new CollecterSource($this->source))->failed(new RuntimeException('Flux indisponible'));

    expect(($this->types)())->toBe(['echec']);
    Mail::assertSent(AlertesSupervision::class);
});

it('montre les alertes et les chiffres dans le tableau des sources', function () {
    ($this->collecte)('2026-10-17 08:00');
    ($this->collecte)('2026-10-17 09:30', StatutCollecte::Abandonnee);
    app(SuperviserSources::class)->handle();
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ListSources::class)->assertSee('1 ouverte(s)')->assertSee('❌ 17/10 09:20');
    expect(Alerte::firstWhere('type', TypeAlerte::Echec)->notifiee_le)->not->toBeNull();
});

it('abandonne et alerte quand le worker est mort pendant le dernier essai', function () {
    ($this->collecte)('2026-10-17 08:00');
    $orpheline = Collecte::create(['source_id' => $this->source->id, 'debut' => now()->subHours(2), 'statut' => StatutCollecte::EnCours, 'essai' => 4]);

    (new CollecterSource($this->source))->failed(new RuntimeException('has been attempted too many times'));

    expect($orpheline->fresh())->statut->toBe(StatutCollecte::Abandonnee)->fin->not->toBeNull()
        ->and($orpheline->fresh()->erreur)->toContain('worker s’est arrêté')
        ->and(($this->types)())->toBe(['echec']);
});
