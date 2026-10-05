<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dernière collecte qui a vu l'offre (N01) : les offres de la source qu'une collecte réussie n'a pas vues ont disparu
 * du flux. Remplace la liste des offres vues, trop longue pour PostgreSQL chez BilletRéduc (98 000 séances).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->foreignId('derniere_collecte_id')->nullable()->after('vue_le')->constrained('collectes')->nullOnDelete();
            $table->index(['source_id', 'derniere_collecte_id']);
        });
    }

    public function down(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->dropIndex(['source_id', 'derniere_collecte_id']);
            $table->dropConstrainedForeignId('derniere_collecte_id');
        });
    }
};
