<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contributions des utilisateurs (SCHEMA §7, F5.6, F8, P08) : signalements d'erreur et pistes de sources,
 * avec une réponse motivée à chaque piste (F8.6).
 * L'appareil est gardé par son identifiant (en-tête X-Appareil) : il n'est pas forcément enregistré par POST /v1/appareils.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signalements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('representation_id')->constrained('representations')->cascadeOnDelete();
            $table->string('motif', 20); // horaire_faux, annule, mauvais_lieu, doublon, autre
            $table->text('commentaire')->nullable();
            $table->string('appareil', 100);
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('statut', 10)->default('nouveau'); // nouveau, traite, rejete
            $table->string('action', 10)->nullable(); // corrige, masque, rien
            $table->foreignId('traite_par')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('traite_le')->nullable();
            $table->timestampsTz();

            $table->index(['statut', 'representation_id']);
            $table->index(['appareil', 'representation_id']);
        });

        Schema::create('motifs_refus', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle');
            // Vide pour « Autre » : le message est alors rédigé par le super-admin.
            $table->text('message_public')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestampsTz();
        });

        Schema::create('pistes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 25); // salle, spectacle, billetterie_ou_agenda
            $table->foreignId('ville_id')->nullable()->constrained('villes')->nullOnDelete();
            $table->string('nom', 200);
            $table->string('lien', 500)->nullable();
            $table->text('commentaire')->nullable();
            $table->string('email')->nullable();
            $table->boolean('travaille_pour_le_lieu')->default(false);
            $table->string('appareil', 100);
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            // Regroupement (F8.5) : même lieu, même site, ou même nom dans la même ville ; groupe_id = 1re piste du groupe.
            $table->string('cle_groupe', 300);
            $table->unsignedBigInteger('groupe_id')->nullable();
            $table->foreignId('lieu_id')->nullable()->constrained('lieux')->nullOnDelete();
            $table->string('statut', 10)->default('nouvelle'); // nouvelle, etudiee, integree, ecartee
            $table->foreignId('motif_refus_id')->nullable()->constrained('motifs_refus')->nullOnDelete();
            $table->text('message_personnel')->nullable();
            // Texte de la réponse tel qu'envoyé (les motifs restent modifiables ensuite).
            $table->text('reponse')->nullable();
            $table->timestampTz('reponse_envoyee_le')->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('traite_le')->nullable();
            $table->timestampsTz();

            $table->index(['appareil', 'created_at']);
            $table->index(['cle_groupe', 'statut']);
            $table->index('groupe_id');
            $table->index('utilisateur_id');
        });

        DB::table('motifs_refus')->insert(collect([
            ['pas_de_programme', 'Pas de programme réutilisable', 'Ce lieu ne publie pas son programme sous une forme que nous pouvons reprendre pour le moment. Nous gardons votre proposition et réessaierons. S’il le souhaite, le lieu peut nous rejoindre directement en demandant un espace salle depuis l’app Spettacoli (Profil › Je travaille pour un lieu).'],
            ['pas_spectacle_vivant', 'Pas du spectacle vivant', 'Spettacoli présente uniquement des spectacles vivants (théâtre, concerts, danse…). Ce lieu ou cet événement n’entre pas dans ce cadre.'],
            ['deja_present', 'Déjà présent', 'Ce lieu est déjà dans Spettacoli : vous pouvez retrouver ses spectacles en le cherchant dans l’app. S’il manque un spectacle précis, dites-le-nous.'],
            ['ferme_introuvable', 'Lieu fermé ou introuvable', 'Nous n’avons pas trouvé de programmation active pour ce lieu. Si c’est une erreur, répondez à ce message.'],
            ['autre', 'Autre', null],
        ])->map(fn (array $m, int $i) => ['code' => $m[0], 'libelle' => $m[1], 'message_public' => $m[2], 'ordre' => $i + 1, 'created_at' => now(), 'updated_at' => now()])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('pistes');
        Schema::dropIfExists('motifs_refus');
        Schema::dropIfExists('signalements');
    }
};
