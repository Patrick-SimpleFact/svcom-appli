<?php

use App\Models\Parametre;
use Database\Seeders\ParametresSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Réglages « Dernier build iOS / Android disponible » : l'app propose la mise à jour quand elle est plus ancienne.
 * Créés ici (et non seulement par le seeder) car l'API refuse de répondre si un réglage manque.
 */
return new class extends Migration
{
    private const CLES = ['dernier_build_ios', 'dernier_build_android'];

    public function up(): void
    {
        foreach (ParametresSeeder::parametres() as $parametre) {
            if (in_array($parametre['cle'], self::CLES, true)) {
                Parametre::firstOrCreate(['cle' => $parametre['cle']], $parametre);
            }
        }
    }

    public function down(): void
    {
        Parametre::whereIn('cle', self::CLES)->get()->each->delete();
    }
};
