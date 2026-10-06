<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des agendas OpenAgenda (N04) : `slug` pour les liens vers les événements, date de la dernière interrogation
 * (un agenda peu actif n'est interrogé qu'une fois par semaine) et nombre d'événements à venir vus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agendas_openagenda', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('nom');
            $table->timestampTz('derniere_collecte_le')->nullable()->after('dernier_evenement_le');
            $table->unsignedInteger('nb_evenements_a_venir')->default(0)->after('derniere_collecte_le');
        });
    }

    public function down(): void
    {
        Schema::table('agendas_openagenda', fn (Blueprint $table) => $table->dropColumn(['slug', 'derniere_collecte_le', 'nb_evenements_a_venir']));
    }
};
