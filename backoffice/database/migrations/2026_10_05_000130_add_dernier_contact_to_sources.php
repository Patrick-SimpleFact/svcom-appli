<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retraits (K08b) : dernière fois que la source a répondu correctement (détection ou collecte réussie).
 * Au-delà de 48 h sans réponse, ses offres à venir sont retirées (COLLECTE §8.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->timestampTz('dernier_contact_le')->nullable()->after('derniere_verification_le');
        });

        // Point de départ : la dernière collecte réussie de chaque source.
        DB::statement("UPDATE sources SET dernier_contact_le = (SELECT max(fin) FROM collectes WHERE collectes.source_id = sources.id AND collectes.statut = 'reussie')");
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('dernier_contact_le'));
    }
};
