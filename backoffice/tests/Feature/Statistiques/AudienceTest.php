<?php

use App\Filament\Pages\Audience as PageAudience;
use App\Filament\Resources\ActionsCommunication\ActionCommunicationResource;
use App\Filament\Resources\ActionsCommunication\Pages\ManageActionsCommunication;
use App\Filament\Resources\Appareils\AppareilResource;
use App\Filament\Resources\Appareils\Pages\ListAppareils;
use App\Filament\Widgets\Audience\FideliteNouveauxAppareils;
use App\Filament\Widgets\Audience\NouveauxAppareilsParHeure;
use App\Filament\Widgets\Audience\NouveauxAppareilsParJour;
use App\Mesure\Evenements;
use App\Models\ActionCommunication;
use App\Models\Admin;
use App\Models\Appareil;
use App\Models\ClicSortant;
use App\Models\Source;
use App\Models\Utilisateur;
use App\Statistiques\Audience;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([ParametresSeeder::class, SourcesSeeder::class]);
    // Mercredi 28/10/2026 à 15 h 30, heure de Paris.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 15:30', 'Europe/Paris'));
    config(['app.compte_examen.email' => 'examen@spectacles-vivants.com']);
    $this->paris = fn (string $quand) => CarbonImmutable::parse($quand, 'Europe/Paris');

    $this->appareil = function (string $id, string $plateforme, string $installe, ?string $derniere = null, array $autres = []) {
        return Appareil::create(['identifiant' => $id, 'plateforme' => $plateforme, 'version_app' => '1.0.0',
            'premiere_ouverture' => ($this->paris)($installe)->utc(), 'derniere_ouverture' => ($this->paris)($derniere ?? $installe)->utc(), ...$autres]);
    };
    $this->evenement = function (Appareil $a, string $type, string $quand) {
        app(Evenements::class)->assurerPartition(($this->paris)($quand)->utc()->startOfMonth());
        DB::table('evenements_app')->insert(['horodatage' => ($this->paris)($quand)->toIso8601String(), 'appareil_hash' => $a->appareil_hash, 'type' => $type]);
    };
});

it('relie chaque appareil à l’empreinte de ses événements et de ses clics', function () {
    $a = ($this->appareil)('appareil-0001', 'ios', '2026-10-28 09:00');

    expect($a->appareil_hash)->toBe(ClicSortant::empreinte('appareil-0001'));
});

it('compte les appareils, iOS et Android, ouverts, actifs et revenus, sans les appareils de test ni le compte des boutiques', function () {
    $a1 = ($this->appareil)('a1', 'ios', '2026-10-28 08:10');
    $a2 = ($this->appareil)('a2', 'android', '2026-10-20 10:00');
    $a3 = ($this->appareil)('a3', 'android', '2026-10-01 10:00');
    $a4 = ($this->appareil)('a4', 'android', '2026-09-01 10:00');
    $test = ($this->appareil)('test', 'ios', '2026-10-28 10:00', autres: ['est_test' => true]);
    $examen = Utilisateur::create(['email' => 'Examen@spectacles-vivants.com']);
    $boutique = ($this->appareil)('boutique', 'ios', '2026-10-28 11:00', autres: ['utilisateur_id' => $examen->id]);
    Utilisateur::create(['email' => 'marie@exemple.fr']);
    ($this->evenement)($a1, 'ouverture', '2026-10-28 08:10');           // nouveau, ouvert aujourd'hui
    ($this->evenement)($a2, 'ouverture', '2026-10-28 09:00');           // revenu aujourd'hui
    ($this->evenement)($a3, 'ouverture', '2026-10-25 09:00');           // revenu il y a 3 jours
    ($this->evenement)($a4, 'ouverture', '2026-09-01 10:05');           // jamais revenu
    ($this->evenement)($test, 'ouverture', '2026-10-28 10:00');
    ($this->evenement)($boutique, 'ouverture', '2026-10-28 11:00');

    expect(app(Audience::class)->compteurs())->toBe([
        'total' => 4, 'ios' => 1, 'android' => 3, 'nouveaux_jour' => 1,
        'ouverts_jour' => 2, 'actifs_7j' => 3, 'actifs_30j' => 3, 'revenus' => 2,
        'comptes' => 1, 'comptes_jour' => 1,
    ]);
});

it('calcule tout au jour de référence choisi', function () {
    $a1 = ($this->appareil)('a1', 'ios', '2026-10-20 09:00');
    $a2 = ($this->appareil)('a2', 'android', '2026-10-21 12:00');
    ($this->appareil)('a3', 'android', '2026-10-27 12:00');   // après le jour de référence
    ($this->evenement)($a1, 'ouverture', '2026-10-21 08:00');
    ($this->evenement)($a2, 'ouverture', '2026-10-21 12:00');
    ($this->evenement)($a1, 'ouverture', '2026-10-27 08:00');  // après : ignoré
    $reference = ($this->paris)('2026-10-21');
    $audience = app(Audience::class);

    expect($audience->compteurs($reference))->toMatchArray(['total' => 2, 'nouveaux_jour' => 1, 'ouverts_jour' => 2, 'revenus' => 1])
        ->and($audience->nouveauxParJour(3, $reference))->toBe(['jours' => ['2026-10-19', '2026-10-20', '2026-10-21'], 'ios' => [0, 1, 0], 'android' => [0, 0, 1]])
        ->and($audience->actifsParJour(2, $reference)['actifs'])->toBe([0, 2])
        ->and($audience->parcours(1, $reference))->toBe([['jour' => '2026-10-21', 'actifs' => 2, 'fiches' => 0, 'clics' => 0]])
        ->and($audience->reference(($this->paris)('2026-12-25'))->toDateString())->toBe('2026-10-28')   // jamais dans le futur
        ->and($audience->referenceFiltre('pas une date')->toDateString())->toBe('2026-10-28');

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $this->get(PageAudience::getUrl(['filters' => ['jour' => '2026-10-21']]))->assertOk()->assertSee('Jour de référence');
    $widget = Livewire::test(NouveauxAppareilsParJour::class, ['pageFilters' => ['jour' => '2026-10-21']])->assertOk()->instance();
    expect($widget->getHeading())->toBe('Nouveaux appareils par jour (30 jours jusqu’au 21/10/2026)');
    $heure = Livewire::test(NouveauxAppareilsParHeure::class, ['pageFilters' => ['jour' => '2026-10-21']])->instance();
    expect($heure->getDescription())->toContain('Trait plein : mercredi 21/10')->toContain('Pointillés : mardi 20/10');
});

it('compte les nouveaux appareils par jour et par heure, à l’heure de Paris', function () {
    ($this->appareil)('a1', 'ios', '2026-10-28 00:30');   // 27/10 à 23 h 30 en UTC : compté le 28 à Paris
    ($this->appareil)('a2', 'android', '2026-10-28 14:05');
    ($this->appareil)('a3', 'android', '2026-10-28 14:50');
    ($this->appareil)('a4', 'ios', '2026-10-27 14:20');

    $parJour = app(Audience::class)->nouveauxParJour(3);
    $parHeure = app(Audience::class)->nouveauxParHeure();

    expect($parJour)->toBe(['jours' => ['2026-10-26', '2026-10-27', '2026-10-28'], 'ios' => [0, 1, 1], 'android' => [0, 0, 2]])
        ->and($parHeure['jour'][0])->toBe(1)
        ->and($parHeure['jour'][14])->toBe(2)
        ->and($parHeure['jour'][16])->toBeNull()   // heure pas encore passée
        ->and($parHeure['comparaison'][14])->toBe(1);
});

it('compare deux jours au choix heure par heure', function () {
    ($this->appareil)('a1', 'android', '2026-10-20 09:15');
    ($this->appareil)('a2', 'ios', '2026-10-20 09:40');
    ($this->appareil)('a3', 'ios', '2026-10-13 21:00');
    ActionCommunication::create(['date_heure' => ($this->paris)('2026-10-20 08:45')->utc(), 'canal' => 'Presse', 'libelle' => 'Article La Provence']);

    $parHeure = app(Audience::class)->nouveauxParHeure(($this->paris)('2026-10-20'), ($this->paris)('2026-10-13'));
    expect($parHeure['jour'][9])->toBe(2)->and($parHeure['jour'][23])->toBe(0)   // jour passé : toutes les heures comptées
        ->and($parHeure['comparaison'][21])->toBe(1);

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $widget = Livewire::test(NouveauxAppareilsParHeure::class)->set('filters.jour', '2026-10-20')->set('filters.comparaison', '2026-10-13')->assertOk()->instance();
    $donnees = (fn () => $this->getData())->call($widget);

    expect(collect($donnees['datasets'])->pluck('label')->all())->toBe(['Mardi 20/10', 'Mardi 13/10', 'Actions de communication'])
        ->and($donnees['datasets'][0]['data'][9])->toBe(2)
        ->and($donnees['datasets'][2]['libelles'][8])->toBe(['20/10 08h45 · Presse · Article La Provence'])
        ->and($widget->getDescription())->toContain('Trait plein : mardi 20/10');
});

it('mesure la fidélité des nouveaux appareils, seulement une fois la période finie', function () {
    $fidele = ($this->appareil)('fidele', 'ios', '2026-10-19 10:00');
    $curieux = ($this->appareil)('curieux', 'android', '2026-10-19 18:00');
    $tardif = ($this->appareil)('tardif', 'android', '2026-10-19 20:00');
    $recent = ($this->appareil)('recent', 'ios', '2026-10-27 10:00');
    ($this->evenement)($fidele, 'ouverture', '2026-10-20 08:00');
    ($this->evenement)($tardif, 'ouverture', '2026-10-24 21:00');
    ($this->evenement)($curieux, 'fiche_vue', '2026-10-20 09:00'); // pas une ouverture

    $fidelite = collect(app(Audience::class)->fidelite(30))->keyBy('jour');

    expect($fidelite['2026-10-19'])->toBe(['jour' => '2026-10-19', 'nouveaux' => 3, 'lendemain' => 1, 'sept_jours' => 2])
        // Installé hier : le lendemain (aujourd'hui) n'est pas fini.
        ->and($fidelite['2026-10-27'])->toBe(['jour' => '2026-10-27', 'nouveaux' => 1, 'lendemain' => null, 'sept_jours' => null]);
});

it('suit le parcours ouverture, fiche, clic, et les actifs par jour, sans les appareils de test', function () {
    $a = ($this->appareil)('a1', 'ios', '2026-10-27 10:00');
    $b = ($this->appareil)('a2', 'android', '2026-10-27 11:00');
    $test = ($this->appareil)('test', 'ios', '2026-10-27 12:00', autres: ['est_test' => true]);
    foreach ([$a, $b, $test] as $x) {
        ($this->evenement)($x, 'ouverture', '2026-10-28 09:00');
    }
    ($this->evenement)($a, 'ouverture', '2026-10-28 13:00');
    ($this->evenement)($a, 'fiche_vue', '2026-10-28 09:05');
    ($this->evenement)($test, 'fiche_vue', '2026-10-28 09:05');
    ClicSortant::create(['horodatage' => ($this->paris)('2026-10-28 09:06')->utc(), 'appareil_hash' => $a->appareil_hash, 'source_id' => Source::first()->id, 'compte' => true]);
    // Écartés : un clic venu du site web (empreinte de l'adresse IP) et les événements d'une installation disparue.
    ClicSortant::create(['horodatage' => ($this->paris)('2026-10-28 10:00')->utc(), 'appareil_hash' => ClicSortant::empreinte('203.0.113.7'), 'source_id' => Source::first()->id, 'compte' => true]);
    app(Evenements::class)->assurerPartition(($this->paris)('2026-10-28')->utc()->startOfMonth());
    DB::table('evenements_app')->insert(['horodatage' => ($this->paris)('2026-10-28 11:00')->toIso8601String(), 'appareil_hash' => ClicSortant::empreinte('installation-disparue'), 'type' => 'ouverture']);

    $parcours = app(Audience::class)->parcours(2);
    $actifs = app(Audience::class)->actifsParJour(2);

    expect($parcours[0])->toBe(['jour' => '2026-10-28', 'actifs' => 2, 'fiches' => 1, 'clics' => 1])
        ->and($parcours[1])->toBe(['jour' => '2026-10-27', 'actifs' => 0, 'fiches' => 0, 'clics' => 0])
        ->and($actifs['actifs'])->toBe([0, 2]);
});

it('place les actions de communication en repères, par jour et par heure', function () {
    ActionCommunication::create(['date_heure' => ($this->paris)('2026-10-27 18:30')->utc(), 'canal' => 'Instagram', 'libelle' => 'Post de lancement']);
    ActionCommunication::create(['date_heure' => ($this->paris)('2026-09-01 10:00')->utc(), 'canal' => 'Presse', 'libelle' => 'Trop ancien']);
    ($this->appareil)('a1', 'android', '2026-10-27 19:00');

    $audience = app(Audience::class);
    expect($audience->actions(($this->paris)('2026-10-26 00:00'), now()))->toBe(['2026-10-27' => ['18h30 · Instagram · Post de lancement']])
        ->and($audience->actions(($this->paris)('2026-10-27 00:00'), now(), parHeure: true))->toHaveKey('2026-10-27 18');

    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());
    $widget = Livewire::test(NouveauxAppareilsParJour::class)->instance();
    $repere = collect((fn () => $this->getData())->call($widget)['datasets'])->firstWhere('label', 'Actions de communication');
    expect(array_values(array_filter($repere['data'], fn ($v) => $v !== null)))->toBe([1.0])
        ->and(array_values(array_filter($repere['libelles'])))->toBe([['18h30 · Instagram · Post de lancement']]);
});

it('ouvre la page Audience et tous ses widgets', function () {
    ($this->appareil)('a1', 'ios', '2026-10-20 10:00');
    ActionCommunication::create(['date_heure' => now(), 'canal' => 'Radio', 'libelle' => 'Interview France Bleu']);
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get(PageAudience::getUrl())->assertOk()->assertSee('Audience de l’app')->assertSee('Noter une action de communication');
    foreach ((new PageAudience)->getWidgets() as $widget) {
        Livewire::test($widget)->assertOk();
    }
    Livewire::test(FideliteNouveauxAppareils::class)->assertOk();
});

it('note une action de communication à l’heure de Paris', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(ManageActionsCommunication::class)
        ->callAction('create', ['date_heure' => '2026-10-28 18:00', 'canal' => 'Instagram', 'libelle' => 'Story spectacles du week-end', 'lien' => 'https://instagram.com/p/x'])
        ->assertHasNoActionErrors();

    expect(ActionCommunication::sole()->date_heure->equalTo(($this->paris)('2026-10-28 18:00')))->toBeTrue();
    $this->get(ActionCommunicationResource::getUrl())->assertOk()->assertSee('Story spectacles du week-end');
});

it('coche les appareils de test depuis la liste, sans montrer leur identifiant', function () {
    $a = ($this->appareil)('identifiant-secret-0001', 'android', '2026-10-28 10:00');
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    $this->get(AppareilResource::getUrl())->assertOk()->assertSee('Android')->assertDontSee('identifiant-secret-0001');
    Livewire::test(ListAppareils::class)->callTableBulkAction('test', [$a]);

    expect($a->fresh()->est_test)->toBeTrue()
        ->and(app(Audience::class)->compteurs()['total'])->toBe(0);
});
