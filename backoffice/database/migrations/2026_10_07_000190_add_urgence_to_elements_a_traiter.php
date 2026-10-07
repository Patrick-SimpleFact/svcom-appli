<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boîte de travail (A01, F7.10) : chaque élément en attente porte l'échéance de la séance concernée,
 * le fait de toucher une ville pilote et un niveau d'urgence qui en découle (recalculé régulièrement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elements_a_traiter', function (Blueprint $table) {
            $table->date('echeance')->nullable()->after('priorite');
            $table->boolean('ville_pilote')->default(false)->after('echeance');
            $table->unsignedSmallInteger('urgence')->default(0)->after('ville_pilote');

            $table->index(['statut', 'urgence']);
        });
    }

    public function down(): void
    {
        Schema::table('elements_a_traiter', function (Blueprint $table) {
            $table->dropIndex(['statut', 'urgence']);
            $table->dropColumn(['echeance', 'ville_pilote', 'urgence']);
        });
    }
};
