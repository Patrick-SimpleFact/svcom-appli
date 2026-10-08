<?php

namespace App\Filament\Resources\MotifsRefus;

use App\Filament\Resources\MotifsRefus\Pages\ManageMotifsRefus;
use App\Models\MotifRefus;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Motifs pour écarter une piste (F8.6) : le message envoyé à l'utilisateur est rédigé à l'avance et modifiable ici.
 * Un motif sans message (« Autre ») oblige à écrire la réponse au moment d'écarter.
 */
class MotifRefusResource extends Resource
{
    protected static ?string $model = MotifRefus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'Contributions';

    protected static ?string $navigationLabel = 'Motifs de refus';

    protected static ?string $modelLabel = 'motif de refus';

    protected static ?string $pluralModelLabel = 'motifs de refus';

    protected static ?string $slug = 'motifs-de-refus';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('libelle')->label('Motif')->required()->maxLength(100),
            Textarea::make('message_public')->label('Message envoyé à l’utilisateur')->rows(5)->maxLength(1000)
                ->helperText('Vide : la réponse est écrite à chaque fois (comme pour « Autre »).'),
            TextInput::make('ordre')->label('Ordre dans la liste')->integer()->minValue(0)->default(10),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordre')
            ->columns([
                TextColumn::make('libelle')->label('Motif')->weight('bold'),
                TextColumn::make('message_public')->label('Message envoyé')->wrap()->placeholder('Écrit à chaque fois'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMotifsRefus::route('/')];
    }
}
