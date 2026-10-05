<?php

namespace App\Filament\Resources\Doublons;

use App\Enums\FileATraiter;
use App\Filament\Resources\Doublons\Pages\ListDoublonsProbables;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * File « Doublons probables » (F7.7) : un critère est limite (titres à 70-80 %, heures à 30-60 min d'écart).
 * En attendant la décision, les deux séances restent séparées et publiées.
 */
class DoublonProbableResource extends FileDoublonsResource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Doublons probables';

    protected static ?string $modelLabel = 'doublon probable';

    protected static ?string $pluralModelLabel = 'doublons probables';

    protected static ?string $slug = 'doublons-probables';

    protected static function file(): FileATraiter
    {
        return FileATraiter::DoublonProbable;
    }

    protected static function libellesDecisions(): array
    {
        return ['Même séance', 'Séances différentes'];
    }

    public static function getPages(): array
    {
        return ['index' => ListDoublonsProbables::route('/')];
    }
}
