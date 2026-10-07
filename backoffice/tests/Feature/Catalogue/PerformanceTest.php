<?php

use App\Api\AutourDeMoi;
use App\Models\Representation;
use App\Support\Point;
use Database\Seeders\ParametresSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Objectif F2.10 : « autour de moi ce soir » en moins de 500 ms côté serveur,
 * sur un catalogue à l'échelle de la France (≈ 150 000 représentations à venir).
 */
it('répond à « autour d’Avignon ce soir » en moins de 500 ms sur 150 000 représentations', function () {
    // Les identifiants 1… sont utilisés en dur ci-dessous : les séquences repartent de 1 (d'autres tests ont pu les avancer).
    DB::statement("SELECT setval('genres_id_seq', 1, false), setval('spectacles_id_seq', 1, false)");
    DB::statement("INSERT INTO genres (slug, libelle, ordre) VALUES ('theatre', 'Théâtre', 1)");
    DB::statement(<<<'SQL'
        INSERT INTO spectacles (titre, titre_normalise, genre_id)
        SELECT 'Spectacle '||i, 'spectacle '||i, 1 FROM generate_series(1, 20000) AS i
    SQL);
    // 2 000 lieux en France, dont 10 % autour d'Avignon
    DB::statement(<<<'SQL'
        INSERT INTO lieux (nom, nom_normalise, type, position, precision_position, fuseau_horaire)
        SELECT 'Lieu '||i, 'lieu '||i, 'theatre',
               CASE WHEN i % 10 = 0
                    THEN ST_SetSRID(ST_MakePoint(4.80 + random() * 0.06, 43.92 + random() * 0.05), 4326)::geography
                    ELSE ST_SetSRID(ST_MakePoint(-4.5 + random() * 12.5, 42.5 + random() * 8.5), 4326)::geography END,
               'exacte', 'Europe/Paris'
        FROM generate_series(1, 2000) AS i
    SQL);
    // 150 000 représentations sur un an
    DB::statement(<<<'SQL'
        INSERT INTO representations (spectacle_id, lieu_id, type, debut, date_locale, position, genre_id, statut)
        SELECT 1 + floor(random() * 20000)::int, l.id, 'seance',
               d.jour + time '20:30', d.jour, l.position, 1, 'programmee'
        FROM (SELECT i, (current_date + (floor(random() * 365))::int) AS jour,
                     (SELECT min(id) FROM lieux) + floor(random() * 2000)::int AS lieu_id
              FROM generate_series(1, 150000) AS i) AS d
        JOIN lieux l ON l.id = d.lieu_id
    SQL);
    DB::statement('ANALYZE representations');

    expect(Representation::count())->toBe(150000);

    $avignon = new Point(43.9493, 4.8057);
    Representation::autourDe($avignon, 5000, today())->orderBy('representations.debut')->limit(30)->get(); // échauffement

    $debut = hrtime(true);
    $resultats = Representation::autourDe($avignon, 5000, today())->orderBy('representations.debut')->limit(30)->get();
    $millisecondes = (hrtime(true) - $debut) / 1e6;

    expect($resultats)->not->toBeEmpty()
        ->and($millisecondes)->toBeLessThan(500);

    fwrite(STDERR, sprintf("\n  « Autour d’Avignon ce soir » : %d résultats en %.1f ms\n", $resultats->count(), $millisecondes));

    // L'appel complet de l'API (rayon automatique, cartes, couverture), ce soir et sur un week-end (P02).
    $this->seed(ParametresSeeder::class);
    foreach (['ce_soir', 'week_end'] as $quand) {
        $debut = hrtime(true);
        $reponse = app(AutourDeMoi::class)->handle($avignon, $quand);
        $millisecondes = (hrtime(true) - $debut) / 1e6;

        expect($reponse['total'])->toBeGreaterThan(0)->and($millisecondes)->toBeLessThan(500);
        fwrite(STDERR, sprintf("  API « autour de moi » (%s) : %d résultats, rayon %d m, en %.1f ms\n", $quand, $reponse['total'], $reponse['rayon_retenu_m'], $millisecondes));
    }
});
