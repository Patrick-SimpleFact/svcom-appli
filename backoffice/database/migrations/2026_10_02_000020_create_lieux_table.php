<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lieux de spectacle (SCHEMA-BDD §2, F7.5).
 * Ajouts au schéma : `label` (appellation du Ministère) et `jauge`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lieux', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('nom_normalise');
            $table->string('type', 30);
            $table->string('label')->nullable();
            $table->string('adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->foreignId('ville_id')->nullable()->constrained('villes');
            $table->geography('position', subtype: 'point', srid: 4326)->nullable();
            $table->string('precision_position', 20)->default('commune');
            $table->string('fuseau_horaire', 40)->default('Europe/Paris');
            $table->string('telephone', 30)->nullable();
            $table->string('site_web')->nullable();
            $table->unsignedInteger('jauge')->nullable();
            $table->string('ref_ministere', 40)->nullable()->unique();
            $table->foreignId('fusionne_dans_id')->nullable()->constrained('lieux');
            $table->boolean('masque')->default(false);
            $table->jsonb('champs_verrouilles')->default('[]');
            $table->timestampsTz();

            $table->spatialIndex('position');
            $table->index('ville_id');
        });

        DB::statement('CREATE INDEX lieux_nom_normalise_trgm ON lieux USING gin (nom_normalise gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lieux');
    }
};
