<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue : festivals, spectacles, représentations, artistes (SCHEMA-BDD §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festivals', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->foreignId('ville_id')->nullable()->constrained('villes');
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->string('site_web')->nullable();
            $table->timestampsTz();
        });

        Schema::create('spectacles', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->string('titre_normalise');
            $table->text('description')->nullable();
            $table->foreignId('genre_id')->constrained('genres');
            $table->string('classification_fine')->nullable();
            $table->boolean('jeune_public')->default(false);
            $table->unsignedSmallInteger('age_min')->nullable();
            $table->unsignedSmallInteger('duree_minutes')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->foreignId('festival_id')->nullable()->constrained('festivals')->nullOnDelete();
            $table->boolean('masque')->default(false);
            $table->jsonb('champs_verrouilles')->default('[]');
            // Données de démonstration (commande catalogue:demo), supprimables d'un coup.
            $table->boolean('demo')->default(false)->index();
            $table->timestampsTz();
        });
        DB::statement('CREATE INDEX spectacles_titre_normalise_trgm ON spectacles USING gin (titre_normalise gin_trgm_ops)');

        Schema::create('representations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spectacle_id')->constrained('spectacles')->cascadeOnDelete();
            $table->foreignId('lieu_id')->constrained('lieux');
            $table->string('type', 20)->default('seance');
            $table->timestampTz('debut')->nullable();
            $table->timestampTz('fin')->nullable();
            $table->date('date_locale');
            // Copies du lieu et du spectacle, pour que « autour de moi ce soir » n'ait besoin que d'un index (SCHEMA §10 point 2).
            $table->geography('position', subtype: 'point', srid: 4326)->nullable();
            $table->foreignId('ville_id')->nullable()->constrained('villes');
            $table->foreignId('genre_id')->constrained('genres');
            $table->decimal('prix_min', 8, 2)->nullable();
            $table->decimal('prix_max', 8, 2)->nullable();
            $table->boolean('gratuit')->default(false);
            $table->boolean('complet')->default(false);
            $table->string('statut', 20)->default('programmee');
            $table->timestampsTz();

            $table->index(['lieu_id', 'date_locale']);
            $table->index(['spectacle_id', 'date_locale']);
            $table->index(['date_locale', 'statut']);
        });
        DB::statement('CREATE INDEX representations_position_gist ON representations USING gist (position)');

        Schema::create('artistes', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('nom_normalise');
            $table->string('type', 20)->default('personne');
            $table->string('image_url', 1000)->nullable();
            $table->foreignId('fusionne_dans_id')->nullable()->constrained('artistes');
            $table->timestampsTz();
        });
        DB::statement('CREATE INDEX artistes_nom_normalise_trgm ON artistes USING gin (nom_normalise gin_trgm_ops)');

        Schema::create('spectacle_artiste', function (Blueprint $table) {
            $table->foreignId('spectacle_id')->constrained('spectacles')->cascadeOnDelete();
            $table->foreignId('artiste_id')->constrained('artistes')->cascadeOnDelete();
            $table->string('role', 20)->default('interprete');
            $table->primary(['spectacle_id', 'artiste_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spectacle_artiste');
        Schema::dropIfExists('artistes');
        Schema::dropIfExists('representations');
        Schema::dropIfExists('spectacles');
        Schema::dropIfExists('festivals');
    }
};
