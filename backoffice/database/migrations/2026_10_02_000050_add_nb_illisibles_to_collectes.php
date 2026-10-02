<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collectes', function (Blueprint $table) {
            $table->unsignedInteger('nb_illisibles')->default(0)->after('nb_recus');
        });
    }

    public function down(): void
    {
        Schema::table('collectes', function (Blueprint $table) {
            $table->dropColumn('nb_illisibles');
        });
    }
};
