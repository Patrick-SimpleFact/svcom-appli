<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Séances lues mais au-delà de l'horizon des séances (réglage « horizon_mois »), non enregistrées. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collectes', function (Blueprint $table) {
            $table->unsignedInteger('nb_hors_horizon')->default(0)->after('nb_a_trier');
        });
    }

    public function down(): void
    {
        Schema::table('collectes', fn (Blueprint $table) => $table->dropColumn('nb_hors_horizon'));
    }
};
