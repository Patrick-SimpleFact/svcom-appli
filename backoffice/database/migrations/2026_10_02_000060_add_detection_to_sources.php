<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->timestampTz('derniere_verification_le')->nullable();
            $table->string('derniere_version_vue')->nullable();
            $table->text('erreur_detection')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['derniere_verification_le', 'derniere_version_vue', 'erreur_detection']);
        });
    }
};
