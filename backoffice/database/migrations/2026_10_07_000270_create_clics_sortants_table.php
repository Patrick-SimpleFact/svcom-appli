<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clics vers les billetteries et les organisateurs (F7.13 bis, SCHEMA §9) : sans donnée personnelle ;
 * l'appareil n'est connu que par une empreinte (dédoublonnage sur 30 min), jamais transmise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clics_sortants', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('horodatage')->useCurrent();
            $table->string('appareil_hash', 64);
            $table->foreignId('offre_id')->nullable()->constrained('offres')->nullOnDelete();
            $table->foreignId('source_id')->constrained('sources');
            $table->unsignedBigInteger('representation_id')->nullable();
            $table->unsignedBigInteger('spectacle_id')->nullable();
            $table->unsignedBigInteger('lieu_id')->nullable();
            $table->unsignedBigInteger('ville_id')->nullable();
            $table->unsignedBigInteger('genre_id')->nullable();
            $table->string('origine', 30)->nullable();
            $table->string('bouton', 10)->default('principal');
            $table->decimal('prix_affiche', 8, 2)->nullable();
            $table->integer('delai_avant_seance_min')->nullable();
            $table->unsignedSmallInteger('distance_km')->nullable();
            // Faux : clic répété dans les 30 min, robot ou appel de test (F7.13 bis).
            $table->boolean('compte')->default(true);

            $table->index(['horodatage']);
            $table->index(['source_id', 'horodatage']);
            $table->index(['appareil_hash', 'representation_id', 'source_id', 'horodatage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clics_sortants');
    }
};
