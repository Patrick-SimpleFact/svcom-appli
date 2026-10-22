<?php

use App\Models\Parametre;
use Database\Seeders\ParametresSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Réglage « Heure de sortie des séances passées » : la tâche planifiée le lit chaque minute.
 * Créé ici (et non seulement par le seeder) pour que la tâche le trouve dès le déploiement.
 */
return new class extends Migration
{
    private const CLE = 'boite_heure_sortie_passes';

    public function up(): void
    {
        $parametre = collect(ParametresSeeder::parametres())->firstWhere('cle', self::CLE);
        Parametre::firstOrCreate(['cle' => self::CLE], $parametre);
    }

    public function down(): void
    {
        Parametre::where('cle', self::CLE)->get()->each->delete();
    }
};
