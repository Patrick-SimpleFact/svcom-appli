<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'espace salle (SCHEMA §7, F9.1, F9.2, W02) : faites depuis une page web publique, traitées par le super-admin
 * (valider = compte rattaché au lieu, refuser avec motif, demander des précisions). Pas d'inscription libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_espace_salle', function (Blueprint $table) {
            $table->id();
            $table->string('nom_lieu', 200);
            $table->string('adresse', 300)->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville_saisie', 100);
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->string('nom_demandeur', 150);
            $table->string('fonction', 150);
            $table->string('email');
            $table->string('telephone', 30);
            $table->string('site_web', 300)->nullable();
            $table->string('billetterie', 200)->nullable();
            $table->text('message')->nullable();
            $table->timestampTz('consentement_le');
            // Rapprochement automatique avec le référentiel (F7.5), puis lieu choisi à la validation.
            $table->foreignId('lieu_propose_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->foreignId('lieu_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->string('statut', 25)->default('en_attente'); // en_attente, precisions_demandees, validee, refusee
            $table->text('motif_refus')->nullable();
            $table->text('precisions_demandees')->nullable();
            $table->foreignId('utilisateur_cree_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->foreignId('traite_par')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('traite_le')->nullable();
            $table->timestampsTz();

            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_espace_salle');
    }
};
