<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offres préparées par la collecte avant publication (K06) : ce que la chaîne a calculé (lieu, genre, jour local,
 * titre comparable) et le regroupement en séances : une offre reconnue comme la même séance qu'une autre pointe
 * vers la première offre du groupe (`meme_seance_que_id`). Les représentations seront créées à partir de ces groupes (K08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->foreignId('lieu_id')->nullable()->after('representation_id')->constrained('lieux');
            $table->foreignId('genre_id')->nullable()->after('lieu_id')->constrained('genres');
            $table->boolean('jeune_public')->default(false)->after('genre_id');
            $table->timestampTz('debut')->nullable()->after('jeune_public');
            $table->boolean('heure_connue')->default(true)->after('debut');
            $table->date('date_locale')->nullable()->after('heure_connue');
            $table->string('titre_comparable')->nullable()->after('date_locale');
            $table->foreignId('meme_seance_que_id')->nullable()->after('titre_comparable')->constrained('offres')->nullOnDelete();

            $table->index(['date_locale', 'lieu_id']);
            $table->index('meme_seance_que_id');
        });
    }

    public function down(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meme_seance_que_id');
            $table->dropConstrainedForeignId('genre_id');
            $table->dropConstrainedForeignId('lieu_id');
            $table->dropIndex(['date_locale', 'lieu_id']);
            $table->dropColumn(['jeune_public', 'debut', 'heure_connue', 'date_locale', 'titre_comparable']);
        });
    }
};
