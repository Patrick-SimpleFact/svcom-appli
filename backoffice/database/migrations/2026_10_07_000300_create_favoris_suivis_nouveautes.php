<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préférences, favoris, suivis et file des nouveautés (SCHEMA §6, F3, P07).
 * Zone des alertes : une commune et un rayon, jamais une position (F2.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preferences', function (Blueprint $table) {
            $table->foreignId('zone_alertes_ville_id')->nullable()->after('rayon_m')->constrained('villes')->nullOnDelete();
            $table->unsignedSmallInteger('zone_alertes_rayon_km')->nullable()->after('zone_alertes_ville_id');
            $table->boolean('alertes_actives')->default(true)->after('zone_alertes_rayon_km');
            $table->boolean('rappel_jour_j')->default(true)->after('alertes_actives');
        });

        Schema::create('favoris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('spectacle_id')->constrained('spectacles')->cascadeOnDelete();
            $table->foreignId('representation_id')->nullable()->constrained('representations')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['utilisateur_id', 'spectacle_id']);
        });

        Schema::create('suivis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('type', 10); // lieu, artiste
            $table->unsignedBigInteger('cible_id');
            $table->timestampsTz();

            $table->unique(['utilisateur_id', 'type', 'cible_id']);
            $table->index(['type', 'cible_id']);
        });

        Schema::create('nouveautes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('type', 30); // nouveau_spectacle_lieu, nouvelle_date_artiste, rappel_jour_j
            // Clé d'unicité : jamais deux fois la même nouveauté (F3.4).
            $table->string('cle', 100);
            $table->foreignId('representation_id')->nullable()->constrained('representations')->nullOnDelete();
            $table->foreignId('spectacle_id')->nullable()->constrained('spectacles')->cascadeOnDelete();
            $table->unsignedBigInteger('suivi_id')->nullable();
            $table->timestampTz('cree_le')->useCurrent();
            $table->timestampTz('notifiee_le')->nullable();
            $table->timestampTz('vue_le')->nullable();

            $table->unique(['utilisateur_id', 'type', 'cle']);
            $table->index(['utilisateur_id', 'vue_le']);
            $table->index(['notifiee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nouveautes');
        Schema::dropIfExists('suivis');
        Schema::dropIfExists('favoris');
        Schema::table('preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_alertes_ville_id');
            $table->dropColumn(['zone_alertes_rayon_km', 'alertes_actives', 'rappel_jour_j']);
        });
    }
};
