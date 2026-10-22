<?php

use App\Models\Parametre;
use Database\Seeders\ParametresSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant IA en cascade (plan-module-ia v2, 22/10/2026) : deux modèles au 1er niveau, un au 2e.
 * - propositions_ia.niveau : 1 ou 2 pour l'avis d'un modèle ; null pour la décision de la cascade ;
 * - réglages : modèles par niveau et réserve du forfait par fenêtre, à la place du modèle unique et de la réserve unique.
 */
return new class extends Migration
{
    private const REMPLACES = ['ia_modele', 'ia_quota_reserve_pct'];

    public function up(): void
    {
        Schema::table('propositions_ia', function (Blueprint $table) {
            $table->unsignedTinyInteger('niveau')->nullable()->after('modele');
        });

        foreach (ParametresSeeder::parametres() as $parametre) {
            if (str_starts_with($parametre['cle'], 'ia_')) {
                Parametre::firstOrCreate(['cle' => $parametre['cle']], $parametre);
            }
        }
        Parametre::whereIn('cle', self::REMPLACES)->get()->each->delete();
    }

    public function down(): void
    {
        Schema::table('propositions_ia', function (Blueprint $table) {
            $table->dropColumn('niveau');
        });
    }
};
