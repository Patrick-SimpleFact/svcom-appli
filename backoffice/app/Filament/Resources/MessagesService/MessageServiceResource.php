<?php

namespace App\Filament\Resources\MessagesService;

use App\Enums\TypeMessageService;
use App\Filament\Resources\MessagesService\Pages\ManageMessagesService;
use App\Models\MessageService;
use App\Models\Ville;
use App\Support\Texte;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Messages de service (F2.8, F7.12) : un bandeau dans l'app (maintenance, incident), programmé sur une période,
 * pour tout le monde ou une seule ville, sans publier de nouvelle version.
 */
class MessageServiceResource extends Resource
{
    protected static ?string $model = MessageService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Messages de service';

    protected static ?string $modelLabel = 'message de service';

    protected static ?string $pluralModelLabel = 'messages de service';

    protected static ?string $slug = 'messages-de-service';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Textarea::make('texte')->label('Texte du bandeau')->required()->maxLength(200)->rows(2)->columnSpanFull()
                ->helperText('Court et sans jargon, ex. « Maintenance ce soir de 23 h à minuit ». 200 caractères au plus.'),
            ToggleButtons::make('type')->options(TypeMessageService::class)->inline()->required()->default(TypeMessageService::Info->value)->columnSpanFull(),
            DateTimePicker::make('debut')->label('Affiché à partir du')->required()->seconds(false)->timezone('Europe/Paris')->default(now()),
            DateTimePicker::make('fin')->label('Jusqu’au')->seconds(false)->timezone('Europe/Paris')->after('debut')
                ->helperText('Vide : jusqu’à ce qu’on le désactive.'),
            Select::make('ville_id')->label('Ville')->placeholder('Toutes les villes')->searchable()->columnSpanFull()
                ->getSearchResultsUsing(fn (string $search): array => Ville::where('nom_normalise', 'like', Texte::normaliser($search).'%')
                    ->orderByDesc('est_pilote')->orderByDesc('population')->limit(20)->get()
                    ->mapWithKeys(fn (Ville $v) => [$v->id => "{$v->nom} ({$v->departement})"])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($v = Ville::find($value)) ? "{$v->nom} ({$v->departement})" : null)
                ->helperText('Seulement pour les utilisateurs de cette ville (ex. salle fermée, grève locale).'),
            Toggle::make('actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('debut', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('ville'))
            ->columns([
                TextColumn::make('etat')->label('État')->badge()
                    ->state(fn (MessageService $record): string => $record->etat())
                    ->color(fn (string $state): string => match ($state) {
                        'En cours' => 'success', 'Programmé' => 'info', default => 'gray'
                    }),
                TextColumn::make('type')->badge(),
                TextColumn::make('texte')->wrap(),
                TextColumn::make('periode')->label('Période')
                    ->state(fn (MessageService $record): string => 'du '.$record->debut->setTimezone('Europe/Paris')->format('d/m/Y H:i')
                        .($record->fin ? ' au '.$record->fin->setTimezone('Europe/Paris')->format('d/m/Y H:i') : ', sans fin')),
                TextColumn::make('ville.nom')->label('Ville')->placeholder('Toutes'),
                ToggleColumn::make('actif'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMessagesService::route('/')];
    }
}
