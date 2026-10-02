<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Communes françaises, outre-mer compris (SCHEMA-BDD §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('villes', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('nom_normalise');
            $table->string('code_insee', 5)->unique();
            $table->string('departement', 3)->index();
            $table->jsonb('codes_postaux')->default('[]');
            $table->unsignedInteger('population')->nullable();
            $table->geography('position', subtype: 'point', srid: 4326);
            $table->string('fuseau_horaire', 40);
            $table->boolean('est_pilote')->default(false)->index();
            $table->timestampsTz();

            $table->spatialIndex('position');
        });

        // Recherche tolérante aux fautes sur le nom (F4).
        DB::statement('CREATE INDEX villes_nom_normalise_trgm ON villes USING gin (nom_normalise gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('villes');
    }
};
