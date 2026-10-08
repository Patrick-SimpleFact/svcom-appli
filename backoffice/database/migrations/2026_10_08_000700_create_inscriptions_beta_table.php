<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liste d'attente de la bêta (W05b) : inscription depuis la page d'accueil, valable seulement après confirmation
 * par e-mail (double opt-in) ; non confirmée, elle est effacée après 30 jours ; désinscription = effacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscriptions_beta', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('plateforme', 10); // ios, android
            $table->string('ville', 100)->nullable();
            $table->timestampTz('consentement_le');
            $table->timestampTz('confirmee_le')->nullable();
            $table->timestampTz('invitee_le')->nullable();
            $table->timestampsTz();

            $table->index(['confirmee_le', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions_beta');
    }
};
