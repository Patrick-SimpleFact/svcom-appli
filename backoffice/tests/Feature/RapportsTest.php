<?php

use App\Filament\Pages\RapportsBilletteries;
use App\Models\Admin;
use App\Models\ClicSortant;
use App\Models\Lieu;
use App\Models\Source;
use App\Models\Spectacle;
use App\Models\Ville;
use App\Statistiques\ExportRapport;
use App\Statistiques\RapportClics;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Database\Seeders\SourcesSeeder;
use Livewire\Livewire;

/** W04 : statistiques et rapport pour les billetteries (F7.13 bis). Période : septembre 2026. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, SourcesSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Paris'));
    $this->billetreduc = Source::firstWhere('code', 'billetreduc');
    $this->fnac = Source::firstWhere('code', 'fnac');
    $avignon = Ville::create(['nom' => 'Avignon', 'nom_normalise' => 'avignon', 'code_insee' => '84007', 'departement' => '84', 'codes_postaux' => [], 'population' => 1, 'position' => new Point(43.9493, 4.8055), 'fuseau_horaire' => 'Europe/Paris']);
    $this->observance = Lieu::factory()->create(['nom' => "Théâtre de l'Observance", 'ville_id' => $avignon->id]);
    $this->petit = Lieu::factory()->create(['nom' => 'Petite salle', 'ville_id' => $avignon->id]);
    $piece = Spectacle::factory()->create(['titre' => 'Donne moi ta chance']);

    $this->clics = function (int $n, array $a = []) use ($piece, $avignon) {
        foreach (range(1, $n) as $i) {
            ClicSortant::create(['horodatage' => CarbonImmutable::parse('2026-09-12 19:10', 'Europe/Paris'), 'appareil_hash' => str_repeat('a', 64), 'source_id' => $this->billetreduc->id,
                'spectacle_id' => $piece->id, 'lieu_id' => $this->observance->id, 'ville_id' => $avignon->id, 'origine' => 'liste_ce_soir', 'bouton' => 'principal',
                'delai_avant_seance_min' => 80, 'compte' => true, ...$a]);
        }
    };
    ($this->clics)(12);
    ($this->clics)(3, ['lieu_id' => $this->petit->id, 'origine' => 'suggestion_auto', 'delai_avant_seance_min' => 2000]);
    ($this->clics)(5, ['compte' => false]); // répétés ou robots : jamais comptés
    ($this->clics)(4, ['source_id' => $this->fnac->id]);
    ($this->clics)(2, ['horodatage' => CarbonImmutable::parse('2026-10-02 20:00', 'Europe/Paris')]); // hors période
});

it('compte les clics d’une billetterie et regroupe les petites lignes', function () {
    $r = app(RapportClics::class)->calculer('billetterie', $this->billetreduc->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

    expect($r['total'])->toBe(15)
        ->and($r['sections']['Par lieu'])->toBe([
            ['libelle' => "Théâtre de l'Observance", 'clics' => 12, 'part' => 80.0],
            ['libelle' => 'Autres (1)', 'clics' => 3, 'part' => 20.0], // « Petite salle » : moins de 10 clics
        ])
        ->and(collect($r['sections']['Délai avant la séance'])->pluck('clics', 'libelle')->all())->toBe(['1 à 3 heures' => 12, '1 à 7 jours' => 3])
        ->and(collect($r['sections']['Moment de la journée'])->pluck('libelle')->all())->toBe(['Soirée (après 18 h)'])
        ->and($r['faits'][1])->toBe('80 % des clics ont lieu moins de 3 heures avant le spectacle.')
        ->and($r['faits'][2])->toBe('20 % viennent des suggestions à l’ouverture.')
        ->and($r['par_jour'])->toBe(['2026-09-12' => 15]);
});

it('fait le rapport d’un lieu, toutes billetteries confondues', function () {
    $r = app(RapportClics::class)->calculer('lieu', $this->observance->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

    expect($r['total'])->toBe(16)->and(collect($r['sections']['Par billetterie'])->pluck('clics', 'libelle')->all())->toBe(['BilletRéduc' => 12, 'Autres (1)' => 4]);
});

it('exporte en PDF et en tableur, sans donnée personnelle', function () {
    $export = app(ExportRapport::class);
    $r = app(RapportClics::class)->calculer('billetterie', $this->billetreduc->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

    expect($export->pdf($r))->toStartWith('%PDF')
        ->and($csv = $export->tableur($r))->toStartWith("\u{FEFF}\"Spettacoli — BilletRéduc\"")->toContain("\"Théâtre de l'Observance\";12;80")->not->toContain(str_repeat('a', 64))
        ->and($export->nomFichier($r, 'pdf'))->toBe('spettacoli-billetreduc-2026-09-01-2026-09-30.pdf');
});

it('affiche le rapport dans le back-office et le télécharge', function () {
    $this->actingAs(Admin::factory()->avecDoubleAuthentification()->create());

    Livewire::test(RapportsBilletteries::class)
        ->set('data.source_id', $this->billetreduc->id)
        ->assertSee('15 clic(s)')->assertSee("Théâtre de l'Observance")
        ->callAction('pdf')->assertFileDownloaded('spettacoli-billetreduc-2026-09-01-2026-09-30.pdf')
        ->callAction('tableur')->assertFileDownloaded('spettacoli-billetreduc-2026-09-01-2026-09-30.csv');
});
