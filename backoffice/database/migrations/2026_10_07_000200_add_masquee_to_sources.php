<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masquage d'une source entière (F7.8, A02) : immédiat et réversible, sans arrêter sa collecte
 * (contrairement à « actif », qui l'arrête et retire ses séances à la publication suivante).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('masquee')->default(false)->after('actif');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('masquee');
        });
    }
};
