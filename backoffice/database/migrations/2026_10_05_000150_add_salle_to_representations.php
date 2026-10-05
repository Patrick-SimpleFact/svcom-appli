<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salle de la représentation (« Salle 1 ») : les salles d'un même théâtre sont rattachées à un seul lieu (K04),
 * la salle est donc gardée sur la représentation, à partir du nom de lieu donné par la source (N01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('representations', function (Blueprint $table) {
            $table->string('salle', 60)->nullable()->after('lieu_id');
        });
    }

    public function down(): void
    {
        Schema::table('representations', fn (Blueprint $table) => $table->dropColumn('salle'));
    }
};
