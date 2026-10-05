<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication (K08a) : les représentations peuvent être corrigées à la main sans que la collecte les écrase (F7.8),
 * et chaque collecte compte les représentations mises à jour (en plus des nouvelles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('representations', function (Blueprint $table) {
            $table->jsonb('champs_verrouilles')->default('[]')->after('statut');
        });

        Schema::table('collectes', function (Blueprint $table) {
            $table->unsignedInteger('nb_mis_a_jour')->default(0)->after('nb_nouveaux');
        });
    }

    public function down(): void
    {
        Schema::table('collectes', fn (Blueprint $table) => $table->dropColumn('nb_mis_a_jour'));
        Schema::table('representations', fn (Blueprint $table) => $table->dropColumn('champs_verrouilles'));
    }
};
