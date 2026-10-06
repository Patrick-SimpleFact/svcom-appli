<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Événements « période » (N03, décision de Patrick du 06/10/2026) : un événement sans horaire sur plusieurs jours
 * devient une seule représentation « du … au … » au lieu d'une séance par jour. `date_fin` = dernier jour inclus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->date('date_fin')->nullable()->after('date_locale');
        });

        Schema::table('representations', function (Blueprint $table) {
            $table->date('date_fin')->nullable()->after('date_locale');
            $table->index(['date_fin', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('representations', function (Blueprint $table) {
            $table->dropIndex(['date_fin', 'statut']);
            $table->dropColumn('date_fin');
        });

        Schema::table('offres', fn (Blueprint $table) => $table->dropColumn('date_fin'));
    }
};
