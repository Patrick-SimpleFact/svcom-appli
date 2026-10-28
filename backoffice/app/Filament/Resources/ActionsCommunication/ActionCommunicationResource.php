<?php

namespace App\Filament\Resources\ActionsCommunication;

use App\Filament\Resources\ActionsCommunication\Pages\ManageActionsCommunication;
use App\Filament\Support\OuvertSelonProfil;
use App\Models\ActionCommunication;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Journal des actions de communication (post, article, affiche…) : chacune devient un repère sur les courbes de la page
 * Audience, pour voir si un pic d'installations la suit.
 */
class ActionCommunicationResource extends Resource
{
    use OuvertSelonProfil;

    protected static ?string $model = ActionCommunication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Statistiques';

    protected static ?string $navigationLabel = 'Actions de communication';

    protected static ?string $modelLabel = 'action de communication';

    protected static ?string $pluralModelLabel = 'actions de communication';

    protected static ?string $slug = 'actions-de-communication';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            DateTimePicker::make('date_heure')->label('Date et heure')->required()->seconds(false)->timezone('Europe/Paris')->default(now())
                ->helperText('L’heure de publication ou de parution : le repère se place sur ce jour et cette heure.'),
            TextInput::make('canal')->label('Canal')->required()->maxLength(30)->datalist(ActionCommunication::CANAUX)
                ->helperText('Choisissez dans la liste ou écrivez un autre canal.'),
            TextInput::make('libelle')->label('Action')->required()->maxLength(200)->placeholder('Post de lancement, article dans La Provence…'),
            TextInput::make('lien')->label('Lien')->url()->maxLength(500)->placeholder('https://…'),
            Textarea::make('notes')->label('Notes')->rows(3)->maxLength(2000)->placeholder('Budget, public visé, ville…'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_heure', 'desc')
            ->columns([
                TextColumn::make('date_heure')->label('Date et heure')->dateTime('d/m/Y à H:i', 'Europe/Paris')->sortable(),
                TextColumn::make('canal')->label('Canal')->badge()->color('gray')->searchable(),
                TextColumn::make('libelle')->label('Action')->weight('bold')->wrap()->searchable(),
                TextColumn::make('lien')->label('Lien')->limit(40)->url(fn (ActionCommunication $a) => $a->lien, shouldOpenInNewTab: true)->placeholder('—'),
                TextColumn::make('notes')->label('Notes')->limit(60)->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageActionsCommunication::route('/')];
    }
}
