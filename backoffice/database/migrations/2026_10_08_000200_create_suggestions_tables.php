<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suggestion à l'ouverture et campagnes sponsorisées (SCHEMA §8, F6, P09).
 * - villes.suggestions_test : villes où les suggestions sont ouvertes quand le réglage les limite aux villes test (F6.4) ;
 * - utilisateurs.droits : liste de droits (ex. sans_sponsorise), vide au MVP1 (F6.6, anticipé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('villes', function (Blueprint $table) {
            $table->boolean('suggestions_test')->default(false);
        });

        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->jsonb('droits')->default('[]');
        });

        Schema::create('annonceurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('contact')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone', 30)->nullable();
            // Un lieu avec espace salle (F9) : ses campagnes lui sont rattachées.
            $table->foreignId('lieu_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('campagnes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annonceur_id')->constrained('annonceurs')->restrictOnDelete();
            $table->foreignId('spectacle_id')->constrained('spectacles')->cascadeOnDelete();
            $table->string('visuel')->nullable();
            // Zone : autour du centre d'une ville.
            $table->foreignId('ville_id')->constrained('villes')->restrictOnDelete();
            $table->unsignedSmallInteger('zone_rayon_km')->default(20);
            $table->jsonb('genres')->default('[]'); // vide = tous les goûts
            $table->date('debut');
            $table->date('fin');
            $table->unsignedInteger('affichages_achetes');
            $table->string('statut', 10)->default('brouillon'); // brouillon, active, suspendue, terminee
            $table->timestampsTz();

            $table->index(['statut', 'debut', 'fin']);
        });

        Schema::create('affichages_suggestion', function (Blueprint $table) {
            $table->id();
            $table->string('appareil', 100);
            $table->foreignId('representation_id')->nullable()->constrained('representations')->nullOnDelete();
            $table->foreignId('spectacle_id')->nullable()->constrained('spectacles')->nullOnDelete();
            $table->foreignId('campagne_id')->nullable()->constrained('campagnes')->nullOnDelete(); // vide = suggestion automatique
            $table->timestampTz('affiche_le')->useCurrent();
            $table->boolean('clic_fiche')->default(false);
            $table->boolean('clic_billetterie')->default(false);
            $table->boolean('passee')->default(false);

            $table->index(['appareil', 'affiche_le']);
            $table->index(['campagne_id', 'affiche_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affichages_suggestion');
        Schema::dropIfExists('campagnes');
        Schema::dropIfExists('annonceurs');
        Schema::table('utilisateurs', fn (Blueprint $table) => $table->dropColumn('droits'));
        Schema::table('villes', fn (Blueprint $table) => $table->dropColumn('suggestions_test'));
    }
};
