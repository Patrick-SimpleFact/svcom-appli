<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement des séances collectées à un spectacle (K07, COLLECTE §7.3) : toutes les offres d'un même
 * groupe de séances pointent vers le même spectacle ; K08 créera les représentations sous ce spectacle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->foreignId('spectacle_id')->nullable()->after('meme_seance_que_id')->constrained('spectacles')->nullOnDelete();
            $table->index(['source_id', 'titre_comparable']);
            $table->index('titre_comparable');
        });
    }

    public function down(): void
    {
        Schema::table('offres', function (Blueprint $table) {
            $table->dropIndex(['source_id', 'titre_comparable']);
            $table->dropIndex(['titre_comparable']);
            $table->dropConstrainedForeignId('spectacle_id');
        });
    }
};
