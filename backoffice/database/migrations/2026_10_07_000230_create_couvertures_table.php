<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique de la couverture des villes pilotes (F7.13) : une ligne par ville et par jour,
 * mise à jour chaque heure (la dernière mesure du jour reste).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couvertures', function (Blueprint $table) {
            $table->id();
            $table->date('jour');
            $table->foreignId('ville_id')->constrained('villes');
            $table->unsignedInteger('ce_soir');
            $table->unsignedInteger('week_end');
            $table->unsignedInteger('trente_jours');
            $table->unsignedInteger('spectacles');
            $table->unsignedSmallInteger('avec_horaire_pct')->nullable();
            $table->timestampTz('mesuree_le');

            $table->unique(['jour', 'ville_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couvertures');
    }
};
