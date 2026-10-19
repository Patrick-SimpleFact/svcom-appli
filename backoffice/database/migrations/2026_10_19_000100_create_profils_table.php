<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modèles de profil du back-office : chaque admin suit un modèle, qui donne la liste des menus ouverts.
 * « Super Admin » ouvre tout (y compris les administrateurs et les modèles) ; les comptes existants le reçoivent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profils', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100)->unique();
            $table->boolean('super_admin')->default(false);
            $table->jsonb('menus')->default('[]');
            $table->timestampsTz();
        });

        $superAdmin = DB::table('profils')->insertGetId([
            'nom' => 'Super Admin', 'super_admin' => true, 'menus' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('admins', function (Blueprint $table) {
            $table->foreignId('profil_id')->nullable()->constrained('profils')->restrictOnDelete();
        });

        DB::table('admins')->update(['profil_id' => $superAdmin]);
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profil_id');
        });

        Schema::dropIfExists('profils');
    }
};
