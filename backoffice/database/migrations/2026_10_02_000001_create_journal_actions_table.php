<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal de chaque action manuelle faite dans le back-office (F7.1, SCHEMA-BDD §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('action', 50);
            $table->string('cible_type', 100);
            $table->unsignedBigInteger('cible_id')->nullable();
            $table->jsonb('avant')->nullable();
            $table->jsonb('apres')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['cible_type', 'cible_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_actions');
    }
};
