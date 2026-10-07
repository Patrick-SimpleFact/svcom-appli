<?php

namespace App\Filament\Resources\Doublons;

use App\Enums\FileATraiter;
use App\Filament\Resources\Doublons\Pages\ListFusionsAControler;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * File « Fusions à contrôler » (COLLECTE §7.2) : fusions automatiques de séances dont les heures diffèrent (1 à 30 min).
 * La fusion est déjà appliquée ; la file sert à vérifier la règle dans le temps. Désactivable dans les réglages.
 */
class FusionAControlerResource extends FileDoublonsResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Fusions à contrôler';

    protected static ?string $modelLabel = 'fusion à contrôler';

    protected static ?string $pluralModelLabel = 'fusions à contrôler';

    protected static ?string $slug = 'fusions-a-controler';

    protected static function file(): FileATraiter
    {
        return FileATraiter::FusionAControler;
    }

    public static function libellesDecisions(): array
    {
        return ['Confirmer', 'Séparer'];
    }

    public static function getPages(): array
    {
        return ['index' => ListFusionsAControler::route('/')];
    }
}
