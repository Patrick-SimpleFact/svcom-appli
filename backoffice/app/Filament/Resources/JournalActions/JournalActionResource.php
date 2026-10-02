<?php

namespace App\Filament\Resources\JournalActions;

use App\Filament\Resources\JournalActions\Pages\ListJournalActions;
use App\Filament\Resources\JournalActions\Pages\ViewJournalAction;
use App\Filament\Resources\JournalActions\Schemas\JournalActionInfolist;
use App\Filament\Resources\JournalActions\Tables\JournalActionsTable;
use App\Models\JournalAction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Journal des actions manuelles : en lecture seule (F7.1).
 */
class JournalActionResource extends Resource
{
    protected static ?string $model = JournalAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'action';

    protected static ?string $pluralModelLabel = 'journal des actions';

    protected static ?string $navigationLabel = 'Journal des actions';

    public static function infolist(Schema $schema): Schema
    {
        return JournalActionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalActionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalActions::route('/'),
            'view' => ViewJournalAction::route('/{record}'),
        ];
    }
}
