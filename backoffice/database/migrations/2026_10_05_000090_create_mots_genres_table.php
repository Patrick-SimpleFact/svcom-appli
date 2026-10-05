<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mots du titre et de la description qui donnent un genre (COLLECTE §6, étape 2) ou le marqueur « Jeune public ».
 * Modifiables dans le back-office. Ajout au schéma (K05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mots_genres', function (Blueprint $table) {
            $table->id();
            $table->string('mot', 100)->unique();
            $table->foreignId('genre_id')->nullable()->constrained('genres'); // vide = marqueur « Jeune public » seulement
            $table->boolean('jeune_public')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mots_genres');
    }
};
