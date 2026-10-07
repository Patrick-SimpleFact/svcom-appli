<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alertes de supervision des sources (F7.9, A03) : une alerte reste ouverte tant que le problème dure,
 * se ferme d'elle-même quand il disparaît ; l'e-mail part à l'ouverture et à la résolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources');
            $table->string('type', 30);
            $table->text('message');
            $table->timestampTz('ouverte_le');
            $table->timestampTz('resolue_le')->nullable();
            $table->timestampTz('notifiee_le')->nullable();
            $table->timestampsTz();

            $table->index(['source_id', 'type', 'resolue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes');
    }
};
