<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mémoire du rattachement des lieux (COLLECTE §4, étape 1) : un lieu tel qu'une source l'écrit
 * (nom + adresse + ville) → le lieu du catalogue. Ajout au schéma (K04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lieux_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('cle', 64);
            $table->string('nom')->nullable();
            $table->string('adresse')->nullable();
            $table->string('ville')->nullable();
            $table->foreignId('lieu_id')->constrained('lieux')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['source_id', 'cle']);
            $table->index('lieu_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lieux_sources');
    }
};
