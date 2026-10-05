<?php

namespace App\Filament\Resources\CorrespondancesGenres;

use App\Actions\EnregistrerCorrespondanceGenre;
use App\Filament\Resources\CorrespondancesGenres\Pages\ManageCorrespondancesGenres;
use App\Models\CorrespondanceGenre;
use App\Models\Genre;
use App\Models\Source;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Correspondances « catégorie d'une source → genre de l'app » (F7.6). Une correspondance enregistrée
 * s'applique tout de suite aux spectacles restés en « Autres » à cause de cette catégorie.
 */
class CorrespondanceGenreResource extends Resource
{
    protected static ?string $model = CorrespondanceGenre::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Collecte';

    protected static ?string $navigationLabel = 'Correspondances de genres';

    protected static ?string $modelLabel = 'correspondance de genre';

    protected static ?string $pluralModelLabel = 'correspondances de genres';

    protected static ?string $slug = 'correspondances-genres';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('source_id')->label('Source')->relationship('source', 'nom')->required(),
            TextInput::make('categorie_source')->label('Catégorie de la source')->required()->maxLength(255)
                ->helperText('Telle que la source l’écrit (majuscules et accents ignorés).'),
            Select::make('genre_id')->label('Genre')->relationship('genre', 'libelle', fn ($query) => $query->orderBy('ordre'))->required(),
            Toggle::make('jeune_public')->label('Jeune public'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('categorie_source')
            ->paginated([50, 100, 'all'])
            ->columns([
                TextColumn::make('source.nom')->label('Source')->sortable(),
                TextColumn::make('categorie_source')->label('Catégorie de la source')->searchable()->sortable(),
                TextColumn::make('genre.libelle')->label('Genre')->badge(),
                IconColumn::make('jeune_public')->label('Jeune public')->boolean(),
                TextColumn::make('updated_at')->label('Modifiée le')->dateTime('d/m/Y H:i', 'Europe/Paris')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_id')->label('Source')->relationship('source', 'nom'),
                SelectFilter::make('genre_id')->label('Genre')->relationship('genre', 'libelle'),
            ])
            ->recordActions([
                EditAction::make()->using(fn (CorrespondanceGenre $record, array $data) => static::enregistrer($data, $record)),
                DeleteAction::make(),
            ]);
    }

    /** Création et modification passent par l'action métier (reclassement immédiat). */
    public static function enregistrer(array $data, ?CorrespondanceGenre $existante = null): CorrespondanceGenre
    {
        $source = Source::findOrFail($data['source_id']);
        $nombre = app(EnregistrerCorrespondanceGenre::class)->handle(
            $source, $data['categorie_source'], Genre::findOrFail($data['genre_id']), (bool) ($data['jeune_public'] ?? false), $existante,
        );

        if ($nombre > 0) {
            Notification::make()->success()->title("{$nombre} spectacle(s) reclassé(s)")->send();
        }

        return $existante?->fresh() ?? CorrespondanceGenre::where('source_id', $source->id)->where('categorie_source', trim($data['categorie_source']))->sole();
    }

    public static function getPages(): array
    {
        return ['index' => ManageCorrespondancesGenres::route('/')];
    }
}
