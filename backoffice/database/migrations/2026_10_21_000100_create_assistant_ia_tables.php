<?php

use App\Models\Parametre;
use Database\Seeders\ParametresSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant IA de la boîte de travail (plan-module-ia, IA02) :
 * - appels_ia : chaque demande au modèle (jetons, durée, erreur), pour suivre la consommation ;
 * - propositions_ia : ce que l'assistant propose pour un élément d'une file, et ce qu'il en est advenu ;
 * - ses réglages, tous les modes à « Désactivé » (créés ici : une base existante ne repasse pas par le seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appels_ia', function (Blueprint $table) {
            $table->id();
            $table->string('tache', 40); // ex. a_classer, essai
            $table->string('modele', 120);
            $table->unsignedInteger('nb_elements')->default(0);
            $table->unsignedInteger('jetons_entree')->nullable();
            $table->unsignedInteger('jetons_sortie')->nullable();
            $table->unsignedInteger('duree_ms')->nullable();
            $table->string('erreur', 500)->nullable();
            $table->timestampsTz();

            $table->index('created_at');
        });

        Schema::create('propositions_ia', function (Blueprint $table) {
            $table->id();
            // Un élément sorti de la boîte (entretien du catalogue) emporte ses propositions.
            $table->foreignId('element_a_traiter_id')->constrained('elements_a_traiter')->cascadeOnDelete();
            $table->foreignId('appel_ia_id')->nullable()->constrained('appels_ia')->nullOnDelete();
            $table->string('file', 40);
            $table->jsonb('proposition');
            $table->unsignedTinyInteger('confiance');
            $table->string('justification', 500)->nullable();
            $table->string('modele', 120);
            $table->string('statut', 20)->default('proposee');
            $table->timestampTz('decidee_le')->nullable();
            $table->timestampsTz();

            $table->index(['file', 'statut']);
            $table->index('element_a_traiter_id');
        });

        foreach (ParametresSeeder::parametres() as $parametre) {
            if (str_starts_with($parametre['cle'], 'ia_')) {
                Parametre::firstOrCreate(['cle' => $parametre['cle']], $parametre);
            }
        }
    }

    public function down(): void
    {
        Parametre::where('cle', 'like', 'ia\_%')->get()->each->delete();
        Schema::dropIfExists('propositions_ia');
        Schema::dropIfExists('appels_ia');
    }
};
