<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F5.4 : une source qui vend des billets (« Réserver sur BilletRéduc ») ou qui renvoie vers l'organisateur
 * (« Voir sur le site de l'organisateur » : OpenAgenda, DATAtourisme, Que faire à Paris).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('billetterie')->default(false)->after('type_lien');
        });

        DB::table('sources')->whereIn('code', ['billetreduc', 'fnac', 'ticketmaster', 'factice', 'factice_bis'])->update(['billetterie' => true]);
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('billetterie');
        });
    }
};
