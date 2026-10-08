<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesure d'usage (SCHEMA §9, API §11, P10).
 * - evenements_app : événements envoyés par lots par l'app, sans position ni identifiant en clair (empreinte de l'appareil).
 *   Table partitionnée par mois : une partition par mois, créée à la première écriture du mois ; supprimée après 13 mois.
 * - stats_quotidiennes : résumés par jour × ville × source, calculés chaque nuit ; ils survivent à la purge.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table evenements_app (
                id bigint generated always as identity,
                horodatage timestamptz not null,
                appareil_hash char(64) not null,
                type varchar(40) not null,
                ville_id bigint null,
                donnees jsonb null,
                primary key (id, horodatage)
            ) partition by range (horodatage)
            SQL);
        DB::statement('create index evenements_app_type_horodatage on evenements_app (type, horodatage)');

        DB::statement(<<<'SQL'
            create table stats_quotidiennes (
                id bigint generated always as identity primary key,
                jour date not null,
                indicateur varchar(60) not null,
                ville_id bigint null references villes (id) on delete set null,
                source_id bigint null references sources (id) on delete set null,
                valeur bigint not null,
                calcule_le timestamptz not null default now()
            )
            SQL);
        // Une valeur par jour, indicateur, ville et source (ville ou source vide = toutes).
        DB::statement('create unique index stats_quotidiennes_unicite on stats_quotidiennes (jour, indicateur, ville_id, source_id) nulls not distinct');
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_quotidiennes');
        DB::statement('drop table if exists evenements_app cascade');
    }
};
