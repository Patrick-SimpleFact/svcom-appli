<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien de billetterie retiré à la main (« Mes actions du jour ») : l'offre reste « disparue », même si la collecte la revoit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->boolean('masquee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->dropColumn('masquee');
        });
    }
};
