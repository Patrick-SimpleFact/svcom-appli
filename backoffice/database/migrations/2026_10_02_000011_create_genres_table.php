<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Genres de l'app (F2.3). « Jeune public » n'est pas un genre mais un marqueur du spectacle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('libelle', 60);
            $table->unsignedSmallInteger('ordre');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genres');
    }
};
