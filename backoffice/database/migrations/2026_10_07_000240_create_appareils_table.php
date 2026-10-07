<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appareils (SCHEMA §5) : un téléphone, avec ou sans compte, identifié par l'identifiant que l'app génère à l'installation.
 * La clé étrangère vers les utilisateurs viendra avec les comptes (P06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 100)->unique();
            $table->unsignedBigInteger('utilisateur_id')->nullable()->index();
            $table->string('plateforme', 10);
            $table->string('version_app', 20);
            $table->string('jeton_push', 500)->nullable();
            $table->unsignedInteger('nb_ouvertures')->default(0);
            $table->timestampTz('premiere_ouverture');
            $table->timestampTz('derniere_ouverture');
            // Question des suggestions (F6.2) : posée à la 5e ouverture, relancée après un « Non merci » (30 jours et 10 ouvertures).
            $table->string('suggestion_choix', 20)->default('non_demande');
            $table->timestampTz('suggestion_question_le')->nullable();
            $table->timestampTz('suggestion_repondu_le')->nullable();
            $table->unsignedInteger('suggestion_ouvertures_a_la_reponse')->nullable();
            $table->unsignedSmallInteger('suggestion_relances')->default(0);
            $table->timestampTz('derniere_suggestion_le')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils');
    }
};
