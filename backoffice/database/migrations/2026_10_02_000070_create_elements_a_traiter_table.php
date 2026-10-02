<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Boîte de travail : une seule table pour les files techniques (SCHEMA-BDD §4, F7.10).
        Schema::create('elements_a_traiter', function (Blueprint $table) {
            $table->id();
            $table->string('file', 30);
            $table->nullableMorphs('cible');
            $table->jsonb('donnees')->nullable();
            $table->unsignedSmallInteger('priorite')->default(0);
            $table->string('statut', 20)->default('en_attente');
            $table->jsonb('decision')->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('traite_le')->nullable();
            $table->timestampsTz();

            $table->index(['file', 'statut']);
        });

        Schema::table('collectes', function (Blueprint $table) {
            $table->unsignedInteger('nb_exclus')->default(0)->after('nb_retenus');
            $table->unsignedInteger('nb_a_trier')->default(0)->after('nb_exclus');
        });
    }

    public function down(): void
    {
        Schema::table('collectes', function (Blueprint $table) {
            $table->dropColumn(['nb_exclus', 'nb_a_trier']);
        });

        Schema::dropIfExists('elements_a_traiter');
    }
};
