<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes de l'app (SCHEMA §5, F1) : utilisateurs, connexions Apple et Google, codes envoyés par e-mail,
 * statuts (compte unique multi-statuts, F1.8) et préférences reprises du mode invité (F1.4, complétées en P07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('prenom', 50)->nullable();
            // Vidé à la suppression du compte (F1.7) ; la ligne disparaît 30 jours plus tard.
            $table->string('email', 255)->nullable()->unique();
            $table->unsignedTinyInteger('naissance_mois')->nullable();
            $table->unsignedSmallInteger('naissance_annee')->nullable();
            $table->timestampTz('cgu_acceptees_le')->nullable();
            $table->boolean('lettre_info')->default(false);
            $table->timestampTz('lettre_info_consentie_le')->nullable();
            $table->timestampTz('derniere_connexion')->nullable();
            $table->timestampTz('supprime_le')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('connexions_externes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('fournisseur', 10);
            $table->string('identifiant_fournisseur', 255);
            $table->timestampsTz();

            $table->unique(['fournisseur', 'identifiant_fournisseur']);
        });

        Schema::create('codes_connexion', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->index();
            $table->string('code_hash', 64);
            $table->timestampTz('expire_le');
            $table->unsignedTinyInteger('essais')->default(0);
            $table->timestampTz('utilise_le')->nullable();
            $table->timestampsTz();
        });

        Schema::create('statuts_utilisateur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('statut', 30);
            $table->foreignId('lieu_id')->nullable()->constrained('lieux');
            $table->foreignId('accorde_par')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('accorde_le');

            $table->unique(['utilisateur_id', 'statut', 'lieu_id']);
        });

        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->unique()->constrained('utilisateurs')->cascadeOnDelete();
            $table->jsonb('genres')->default('[]');
            $table->unsignedInteger('rayon_m')->nullable(); // null = automatique (F2.4)
            $table->timestampsTz();
        });

        Schema::table('appareils', function (Blueprint $table) {
            $table->foreign('utilisateur_id')->references('id')->on('utilisateurs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appareils', function (Blueprint $table) {
            $table->dropForeign(['utilisateur_id']);
        });
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('statuts_utilisateur');
        Schema::dropIfExists('codes_connexion');
        Schema::dropIfExists('connexions_externes');
        Schema::dropIfExists('utilisateurs');
    }
};
