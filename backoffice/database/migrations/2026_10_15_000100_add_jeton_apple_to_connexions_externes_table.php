<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10b : jeton de rafraîchissement d'Apple (chiffré), pour révoquer « Continuer avec Apple »
 * quand le compte est supprimé (règle 5.1.1(v) de l'App Store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connexions_externes', function (Blueprint $table) {
            $table->text('jeton_apple')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connexions_externes', function (Blueprint $table) {
            $table->dropColumn('jeton_apple');
        });
    }
};
