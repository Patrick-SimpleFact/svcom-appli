<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages modifiables dans le BO sans nouvelle version de l'app (F7.12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->string('cle', 80)->unique();
            $table->string('groupe', 40);
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->jsonb('valeur');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
