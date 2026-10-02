<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sources et collecte (SCHEMA-BDD §3, COLLECTE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('nom');
            $table->string('logo_url')->nullable();
            $table->string('type_acces', 20);
            $table->string('licence');
            $table->string('mention_obligatoire')->nullable();
            $table->string('type_lien', 20);
            $table->boolean('actif')->default(true);
            $table->jsonb('zone')->nullable();
            $table->jsonb('fiabilite')->default('{}');
            // Paramètres non secrets du connecteur (identifiants de flux…). Les clés restent dans .env.
            $table->jsonb('config')->default('{}');
            $table->text('remarques')->nullable();
            $table->timestampsTz();
        });

        Schema::create('collectes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources');
            $table->string('version_detectee')->nullable();
            $table->timestampTz('debut');
            $table->timestampTz('fin')->nullable();
            $table->string('statut', 20);
            $table->unsignedTinyInteger('essai')->default(1);
            $table->unsignedInteger('nb_recus')->default(0);
            $table->unsignedInteger('nb_retenus')->default(0);
            $table->unsignedInteger('nb_nouveaux')->default(0);
            $table->unsignedInteger('nb_retires')->default(0);
            $table->text('erreur')->nullable();
            $table->string('fichier_brut')->nullable();
            $table->timestampsTz();

            $table->index(['source_id', 'debut']);
        });

        Schema::create('offres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources');
            $table->string('identifiant_externe');
            $table->foreignId('representation_id')->nullable()->constrained('representations')->nullOnDelete();
            $table->string('lien', 2000)->nullable();
            $table->decimal('prix_min', 8, 2)->nullable();
            $table->decimal('prix_max', 8, 2)->nullable();
            $table->boolean('complet')->default(false);
            $table->jsonb('donnees_normalisees');
            $table->string('empreinte', 64);
            $table->timestampTz('vue_le');
            $table->timestampTz('disparue_le')->nullable();
            $table->timestampsTz();

            $table->unique(['source_id', 'identifiant_externe']);
            $table->index('representation_id');
        });

        Schema::create('correspondances_genres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources');
            $table->string('categorie_source');
            $table->foreignId('genre_id')->constrained('genres');
            $table->boolean('jeune_public')->default(false);
            $table->timestampsTz();

            $table->unique(['source_id', 'categorie_source']);
        });

        Schema::create('regles_filtrage', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->string('mot', 100);
            $table->boolean('actif')->default(true);
            $table->timestampsTz();

            $table->unique(['type', 'mot']);
        });

        Schema::create('decisions_dedoublonnage', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->foreignId('offre_a_id')->constrained('offres')->cascadeOnDelete();
            $table->foreignId('offre_b_id')->constrained('offres')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['offre_a_id', 'offre_b_id']);
        });

        Schema::create('agendas_openagenda', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 40)->unique();
            $table->string('nom');
            $table->foreignId('ville_id')->nullable()->constrained('villes');
            $table->boolean('officiel')->default(false);
            $table->timestampTz('dernier_evenement_le')->nullable();
            $table->string('frequence', 20)->default('normale');
            $table->boolean('actif')->default(true);
            $table->string('origine', 20)->default('recherche');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['agendas_openagenda', 'decisions_dedoublonnage', 'regles_filtrage', 'correspondances_genres', 'offres', 'collectes', 'sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
