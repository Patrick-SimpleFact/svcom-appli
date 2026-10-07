<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des recherches (F4.8) : sans identifiant d'appareil ni position. Texte, ville, nombre de résultats :
 * les recherches sans résultat montrent les trous de couverture, ville par ville (F4.5, F7.13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recherches', function (Blueprint $table) {
            $table->id();
            $table->string('texte', 200)->nullable();
            $table->foreignId('ville_id')->nullable()->constrained('villes');
            $table->unsignedInteger('nb_resultats');
            $table->jsonb('filtres')->nullable();
            $table->timestampTz('cree_le')->useCurrent();

            $table->index(['cree_le']);
            $table->index(['ville_id', 'nb_resultats']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recherches');
    }
};
