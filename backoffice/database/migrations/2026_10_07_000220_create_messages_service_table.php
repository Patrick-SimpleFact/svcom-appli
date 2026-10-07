<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages de service (F2.8, F7.12) : bandeau affiché dans l'app (maintenance, incident) sans nouvelle version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_service', function (Blueprint $table) {
            $table->id();
            $table->string('texte', 200);
            $table->string('type', 10);
            $table->timestampTz('debut');
            $table->timestampTz('fin')->nullable();
            $table->foreignId('ville_id')->nullable()->constrained('villes');
            $table->boolean('actif')->default(true);
            $table->timestampsTz();

            $table->index(['actif', 'debut', 'fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_service');
    }
};
