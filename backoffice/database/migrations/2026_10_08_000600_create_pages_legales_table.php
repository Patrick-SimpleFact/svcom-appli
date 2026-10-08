<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pages légales (F1.6, F7.15, W03) : confidentialité, conditions d'utilisation, mentions légales.
 * Textes en Markdown, modifiables dans le back-office ; premières versions dans database/legal/ (à faire valider, 👤).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages_legales', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('titre', 120);
            $table->text('contenu');
            $table->date('mis_a_jour_le');
            $table->timestampsTz();
        });

        foreach ([
            ['confidentialite', 'Politique de confidentialité'],
            ['conditions', 'Conditions d’utilisation'],
            ['mentions-legales', 'Mentions légales'],
        ] as [$slug, $titre]) {
            DB::table('pages_legales')->insert([
                'slug' => $slug, 'titre' => $titre, 'contenu' => file_get_contents(database_path("legal/{$slug}.md")),
                'mis_a_jour_le' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pages_legales');
    }
};
