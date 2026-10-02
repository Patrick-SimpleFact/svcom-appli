<?php

namespace App\Console\Commands;

use App\Enums\StatutRepresentation;
use App\Models\Genre;
use App\Models\Lieu;
use App\Models\Representation;
use App\Models\Spectacle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Données de démonstration (titres fictifs « [Démo] ») dans les villes pilotes,
 * pour voir le catalogue avant la collecte réelle. Supprimables d'un coup.
 */
class CatalogueDemo extends Command
{
    protected $signature = 'catalogue:demo {--supprimer : Supprime toutes les données de démonstration}';

    protected $description = 'Crée (ou supprime) des spectacles fictifs dans les lieux des villes pilotes';

    public function handle(): int
    {
        if ($this->option('supprimer')) {
            $nombre = Spectacle::where('demo', true)->count();
            Spectacle::where('demo', true)->delete();
            $this->info("{$nombre} spectacles de démonstration supprimés (et leurs représentations).");

            return self::SUCCESS;
        }

        $lieux = Lieu::actifs()->whereHas('ville', fn ($v) => $v->where('est_pilote', true))->get();
        $genres = Genre::pluck('id');

        if ($lieux->isEmpty() || $genres->isEmpty()) {
            $this->error('Il faut des villes pilotes avec des lieux, et des genres (db:seed).');

            return self::FAILURE;
        }

        $nbRepresentations = 0;

        DB::transaction(function () use ($lieux, $genres, &$nbRepresentations) {
            foreach ($lieux as $lieu) {
                foreach (range(1, 2) as $_) {
                    $spectacle = Spectacle::create([
                        'titre' => '[Démo] '.ucfirst(fake('fr_FR')->words(fake()->numberBetween(2, 4), true)),
                        'genre_id' => $genres->random(),
                        'duree_minutes' => fake()->randomElement([60, 75, 90]),
                        'demo' => true,
                    ]);

                    foreach (fake()->randomElements(range(0, 20), 4) as $jour) {
                        Representation::create([
                            'spectacle_id' => $spectacle->id,
                            'lieu_id' => $lieu->id,
                            'debut' => now($lieu->fuseau_horaire)->startOfDay()->addDays($jour)->setTime(fake()->randomElement([19, 20, 21]), fake()->randomElement([0, 30])),
                            'prix_min' => fake()->randomElement([null, 10, 15, 20, 25]),
                            'statut' => StatutRepresentation::Programmee,
                        ]);
                        $nbRepresentations++;
                    }
                }
            }
        });

        $this->info("Démo créée : {$lieux->count()} lieux, ".($lieux->count() * 2)." spectacles, {$nbRepresentations} représentations sur 3 semaines.");
        $this->line('Pour tout supprimer : php artisan catalogue:demo --supprimer');

        return self::SUCCESS;
    }
}
