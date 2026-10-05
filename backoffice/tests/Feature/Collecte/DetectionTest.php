<?php

use App\Actions\DetecterMisesAJour;
use App\Actions\ExecuterCollecte;
use App\Collecte\AnnonceNormalisee;
use App\Collecte\CollecteParIntervalle;
use App\Collecte\Connecteur;
use App\Collecte\DetecteVersion;
use App\Enums\StatutCollecte;
use App\Enums\TypeAccesSource;
use App\Enums\TypeLienSource;
use App\Filament\Resources\Sources\SourceResource;
use App\Jobs\CollecterSource;
use App\Models\Collecte;
use App\Models\Source;
use Database\Seeders\GenresSeeder;
use Database\Seeders\MotsGenresSeeder;
use Database\Seeders\SourceFacticeSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    $this->seed([GenresSeeder::class, MotsGenresSeeder::class, SourceFacticeSeeder::class]);
    $this->source = Source::firstWhere('code', 'factice');
    $this->source->update(['actif' => true, 'config' => ['version' => 'v1']]);
});

it('lance la collecte quand la source publie une nouvelle version, et la note', function () {
    expect(app(DetecterMisesAJour::class)->handle())->toBe(['factice']);

    Queue::assertPushed(CollecterSource::class, fn ($tache) => $tache->source->is($this->source) && $tache->version === 'v1');
    expect($this->source->fresh()->derniere_version_vue)->toBe('v1')
        ->and($this->source->fresh()->derniere_verification_le)->not->toBeNull();
});

it('ne relance pas une version déjà collectée avec succès', function () {
    Collecte::create(['source_id' => $this->source->id, 'debut' => now(), 'statut' => StatutCollecte::Reussie, 'version_detectee' => 'v1']);

    expect(app(DetecterMisesAJour::class)->handle())->toBe([]);
    Queue::assertNothingPushed();

    $this->source->update(['config' => ['version' => 'v2']]);
    expect(app(DetecterMisesAJour::class)->handle())->toBe(['factice']);
});

it('relance une version dont la collecte avait échoué', function () {
    Collecte::create(['source_id' => $this->source->id, 'debut' => now(), 'statut' => StatutCollecte::Abandonnee, 'version_detectee' => 'v1']);

    expect(app(DetecterMisesAJour::class)->handle())->toBe(['factice']);
});

it('ignore les sources désactivées et celles dont le connecteur n’est pas écrit', function () {
    $this->seed(SourcesSeeder::class);
    $this->source->update(['actif' => false]);

    expect(app(DetecterMisesAJour::class)->handle())->toBe([]);
    Queue::assertNothingPushed();
});

it('note une erreur de détection sans empêcher de vérifier les autres sources', function () {
    config(['collecte.connecteurs.en_panne' => ConnecteurEnPanne::class]);
    Source::create(['code' => 'en_panne', 'nom' => 'En panne', 'type_acces' => TypeAccesSource::Api, 'licence' => '—', 'type_lien' => TypeLienSource::Direct, 'actif' => true]);

    expect(app(DetecterMisesAJour::class)->handle())->toBe(['factice'])
        ->and(Source::firstWhere('code', 'en_panne')->erreur_detection)->toBe('Délai dépassé');
});

it('collecte une source sans version toutes les 4 h, entre 6 h et 22 h', function () {
    config(['collecte.connecteurs.agenda' => ConnecteurParIntervalle::class]);
    $agenda = Source::create(['code' => 'agenda', 'nom' => 'Agenda', 'type_acces' => TypeAccesSource::Api, 'licence' => '—', 'type_lien' => TypeLienSource::Direct, 'actif' => true]);
    $this->source->update(['actif' => false]);

    $this->travelTo(now('Europe/Paris')->setTime(23, 0));
    expect(app(DetecterMisesAJour::class)->handle())->toBe([]);

    $this->travelTo(now('Europe/Paris')->addDay()->setTime(9, 0));
    expect(app(DetecterMisesAJour::class)->handle())->toBe(['agenda']);

    Collecte::create(['source_id' => $agenda->id, 'debut' => now(), 'statut' => StatutCollecte::Reussie]);
    $this->travel(3)->hours();
    expect(app(DetecterMisesAJour::class)->handle())->toBe([]);

    $this->travel(61)->minutes();
    expect(app(DetecterMisesAJour::class)->handle())->toBe(['agenda']);
});

it('enregistre la version détectée sur la collecte', function () {
    Queue::fake([]);
    Storage::fake('collecte');

    (new CollecterSource($this->source, 'v1'))->handle(app(ExecuterCollecte::class));

    expect(Collecte::sole()->version_detectee)->toBe('v1');
});

it('planifie la détection toutes les 30 minutes', function () {
    $taches = collect(app(Schedule::class)->events())->filter(fn ($t) => str_contains($t->command ?? '', 'collecte:detecter'));

    expect($taches)->toHaveCount(1)
        ->and($taches->first()->expression)->toBe('*/30 * * * *');
});

class ConnecteurEnPanne implements Connecteur, DetecteVersion
{
    public function versionDisponible(Source $source): ?string
    {
        throw new RuntimeException('Délai dépassé');
    }

    public function telecharger(Source $source): string
    {
        return '';
    }

    public function lire(string $contenuBrut, Source $source): iterable
    {
        return [];
    }

    public function extensionBrut(): string
    {
        return 'json';
    }
}

class ConnecteurParIntervalle implements CollecteParIntervalle, Connecteur
{
    public function intervalleHeures(): int
    {
        return 4;
    }

    public function plageHoraire(): array
    {
        return [6, 22];
    }

    public function telecharger(Source $source): string
    {
        return '[]';
    }

    /** @return iterable<AnnonceNormalisee> */
    public function lire(string $contenuBrut, Source $source): iterable
    {
        return [];
    }

    public function extensionBrut(): string
    {
        return 'json';
    }
}

it('annonce le prochain contrôle à l’heure ou à la demie suivante', function () {
    $this->travelTo(Carbon::parse('2026-10-17 14:10', 'Europe/Paris'));
    expect(SourceResource::prochainControle()->format('H:i'))->toBe('14:30');

    $this->travelTo(Carbon::parse('2026-10-17 14:45', 'Europe/Paris'));
    expect(SourceResource::prochainControle()->format('H:i'))->toBe('15:00');

    $this->travelTo(Carbon::parse('2026-10-17 23:50', 'Europe/Paris'));
    expect(SourceResource::prochainControle()->format('d H:i'))->toBe('18 00:00');
});
