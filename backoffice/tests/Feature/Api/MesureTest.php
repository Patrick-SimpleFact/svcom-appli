<?php

use App\Mesure\Evenements;
use App\Mesure\ResumesQuotidiens;
use App\Models\ClicSortant;
use App\Models\Ville;
use App\Support\Point;
use Carbon\CarbonImmutable;
use Database\Seeders\ParametresSeeder;
use Illuminate\Support\Facades\DB;

/** P10 : événements par lots, résumés quotidiens, purge à 13 mois (API §11, SCHEMA §9). « Maintenant » : 14/10/2026 à 21 h. */
beforeEach(function () {
    $this->seed(ParametresSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 21:00', 'Europe/Paris'));
    $ville = fn (string $nom, string $insee, float $lat, float $lon) => Ville::create(['nom' => $nom, 'nom_normalise' => mb_strtolower($nom), 'code_insee' => $insee, 'departement' => substr($insee, 0, 2), 'codes_postaux' => [], 'population' => 1, 'position' => new Point($lat, $lon), 'fuseau_horaire' => 'Europe/Paris']);
    $this->avignon = $ville('Avignon', '84007', 43.9493, 4.8055);
    $this->lille = $ville('Lille', '59350', 50.6292, 3.0573);
    $this->lot = fn (array $corps, string $appareil = 'appareil-de-test-0001') => $this->postJson('/v1/evenements', $corps, ['X-Appareil' => $appareil, 'X-App-Version' => '1.0.0']);
    $this->evt = fn (string $type, string $quand = '2026-10-14T20:00:00+02:00', array $autres = []) => ['type' => $type, 'horodatage' => $quand, ...$autres];
});

it('reçoit un lot sans jamais garder la position ni l’identifiant de l’appareil', function () {
    ($this->lot)(['lat' => 43.95, 'lon' => 4.81, 'evenements' => [
        ($this->evt)('ouverture'),
        ($this->evt)('fiche_vue', autres: ['donnees' => ['spectacle_id' => 812, 'origine' => 'liste_ce_soir', 'lat' => 43.95, 'longitude' => 4.81]]),
        ($this->evt)('vue_carte', autres: ['ville_id' => $this->lille->id]),
        ($this->evt)('ouverture', '2026-10-01T20:00:00+02:00'), // plus de 7 jours : ignoré
        ($this->evt)('ouverture', '2026-10-15T20:00:00+02:00'), // dans le futur : ignoré
    ]])->assertStatus(202)->assertJson(['recus' => 3, 'ignores' => 2]);

    $lignes = DB::table('evenements_app')->orderBy('id')->get();
    expect($lignes->pluck('ville_id')->all())->toBe([$this->avignon->id, $this->avignon->id, $this->lille->id])
        ->and(json_decode($lignes[1]->donnees, true))->toEqual(['spectacle_id' => 812, 'origine' => 'liste_ce_soir'])
        ->and($lignes[0]->appareil_hash)->toBe(ClicSortant::empreinte('appareil-de-test-0001'))
        ->and(DB::table('evenements_app_2026_10')->count())->toBe(3);
});

it('refuse un lot invalide ou trop gros', function () {
    ($this->lot)(['evenements' => []])->assertStatus(422);
    ($this->lot)(['evenements' => [($this->evt)('Pas Valide')]])->assertStatus(422);
    ($this->lot)(['evenements' => array_fill(0, Evenements::MAX_PAR_LOT + 1, ($this->evt)('ouverture'))])->assertStatus(422);
});

it('résume la journée par ville et au total, et se recalcule sans doublon', function () {
    ($this->lot)(['lat' => 43.95, 'lon' => 4.81, 'evenements' => [
        ($this->evt)('ouverture'), ($this->evt)('ouverture'),
        ($this->evt)('premier_clic_carte', autres: ['donnees' => ['secondes' => 12]]),
        ($this->evt)('premier_clic_carte', autres: ['donnees' => ['secondes' => 45]]),
    ]]);
    ($this->lot)(['evenements' => [($this->evt)('ouverture'), ($this->evt)('ouverture', '2026-10-13T20:00:00+02:00')]], 'autre-appareil-0002');
    DB::table('recherches')->insert([
        ['texte' => 'observance', 'ville_id' => $this->avignon->id, 'nb_resultats' => 0, 'cree_le' => now()],
        ['texte' => 'laurette', 'ville_id' => $this->avignon->id, 'nb_resultats' => 4, 'cree_le' => now()],
    ]);

    $resumes = app(ResumesQuotidiens::class);
    $resumes->calculer(CarbonImmutable::parse('2026-10-14'));
    $resumes->calculer(CarbonImmutable::parse('2026-10-14'));

    $valeur = fn (string $i, ?int $ville = null) => DB::table('stats_quotidiennes')->where('jour', '2026-10-14')->where('indicateur', $i)->where('ville_id', $ville)->value('valeur');
    expect($valeur('evenement:ouverture'))->toBe(3)
        ->and($valeur('evenement:ouverture', $this->avignon->id))->toBe(2)
        ->and($valeur('appareils_actifs'))->toBe(2)
        ->and($valeur('appareils_actifs', $this->avignon->id))->toBe(1)
        ->and($valeur('premier_clic_moins_30s'))->toBe(1)
        ->and($valeur('recherches_sans_resultat', $this->avignon->id))->toBe(1)
        ->and($valeur('recherches'))->toBe(2)
        ->and(DB::table('stats_quotidiennes')->where('jour', '2026-10-13')->exists())->toBeFalse();
});

it('supprime les mois de plus de 13 mois, chiffres résumés gardés', function () {
    $evenements = app(Evenements::class);
    foreach (['2025-08-15', '2025-09-15', '2025-10-15'] as $mois) {
        $evenements->assurerPartition(CarbonImmutable::parse($mois));
    }
    DB::table('stats_quotidiennes')->insert(['jour' => '2025-08-15', 'indicateur' => 'evenement:ouverture', 'valeur' => 10]);

    $this->artisan('mesure:quotidienne')->assertSuccessful();

    $partitions = collect(DB::select("select inhrelid::regclass::text as nom from pg_inherits where inhparent = 'evenements_app'::regclass"))->pluck('nom');
    expect($partitions)->not->toContain('evenements_app_2025_08')->toContain('evenements_app_2025_09')->toContain('evenements_app_2026_11')
        ->and(DB::table('stats_quotidiennes')->where('jour', '2025-08-15')->exists())->toBeTrue();
});
