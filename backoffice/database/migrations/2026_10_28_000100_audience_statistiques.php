<?php

use App\Models\ClicSortant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audience de l'app (page Statistiques › Audience) :
 * - appareils.appareil_hash : l'empreinte des événements et des clics (ClicSortant::empreinte), pour relier un appareil à son
 *   usage (fidélité, parcours) sans jamais garder l'identifiant dans ces tables ;
 * - appareils.est_test : téléphones de test, écartés des statistiques ;
 * - actions_communication : le journal des actions de communication, en repères sur les courbes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appareils', function (Blueprint $table) {
            $table->char('appareil_hash', 64)->nullable()->index();
            $table->boolean('est_test')->default(false);
        });

        DB::table('appareils')->select(['id', 'identifiant'])->orderBy('id')->chunkById(500, function ($appareils) {
            foreach ($appareils as $appareil) {
                DB::table('appareils')->where('id', $appareil->id)->update(['appareil_hash' => ClicSortant::empreinte($appareil->identifiant)]);
            }
        });

        // Fidélité et parcours : les événements d'un appareil sur quelques jours (créé sur chaque partition mensuelle).
        DB::statement('create index if not exists evenements_app_appareil_horodatage on evenements_app (appareil_hash, horodatage)');

        Schema::create('actions_communication', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('date_heure');
            $table->string('canal', 30);
            $table->string('libelle', 200);
            $table->string('lien', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index('date_heure');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions_communication');
        DB::statement('drop index if exists evenements_app_appareil_horodatage');
        Schema::table('appareils', function (Blueprint $table) {
            $table->dropColumn(['appareil_hash', 'est_test']);
        });
    }
};
