<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications envoyées (SCHEMA §6, F3.7, P11) : une ligne par téléphone joint, avec l'ouverture et l'erreur éventuelle.
 * Sert aussi à garantir une seule notification par jour et par personne (F3.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_envoyees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appareil_id')->constrained('appareils')->cascadeOnDelete();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('titre', 120);
            $table->string('corps', 300);
            $table->unsignedSmallInteger('nb_nouveautes')->default(0);
            $table->boolean('test')->default(false);
            $table->timestampTz('envoyee_le')->useCurrent();
            $table->timestampTz('ouverte_le')->nullable();
            $table->string('resultat', 20)->default('envoyee'); // envoyee, jeton_invalide, erreur, non_configure
            $table->string('erreur', 300)->nullable();

            $table->index(['utilisateur_id', 'envoyee_le']);
            $table->index(['envoyee_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_envoyees');
    }
};
