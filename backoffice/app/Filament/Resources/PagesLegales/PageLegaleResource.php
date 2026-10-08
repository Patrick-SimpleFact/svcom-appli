<?php

namespace App\Filament\Resources\PagesLegales;

use App\Filament\Resources\PagesLegales\Pages\ManagePagesLegales;
use App\Models\PageLegale;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Pages légales (F1.6, W03) : confidentialité, conditions d'utilisation, mentions légales, en Markdown.
 * La date « mis à jour le » avance d'elle-même quand le texte change. 👤 Contenu juridique à faire valider.
 */
class PageLegaleResource extends Resource
{
    protected static ?string $model = PageLegale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Pages légales';

    protected static ?string $modelLabel = 'page légale';

    protected static ?string $pluralModelLabel = 'pages légales';

    protected static ?string $slug = 'pages-legales';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('titre')->required()->maxLength(120),
            MarkdownEditor::make('contenu')->required()
                ->toolbarButtons(['heading', 'bold', 'italic', 'link', 'bulletList', 'orderedList', 'undo', 'redo'])
                ->helperText('Les passages « [À COMPLÉTER : …] » sont surlignés en jaune sur la page publique.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('titre')->weight('bold'),
                TextColumn::make('mis_a_jour_le')->label('Mis à jour le')->date('d/m/Y'),
                TextColumn::make('a_completer')->label('À compléter')->badge()
                    ->state(fn (PageLegale $record): int => $record->aCompleter())
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Complet' : "{$state} passage(s)")
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'warning'),
            ])
            ->recordActions([
                Action::make('voir')->label('Voir la page')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->url(fn (PageLegale $record): string => $record->url(), shouldOpenInNewTab: true),
                EditAction::make()->modalWidth('5xl')
                    ->mutateDataUsing(fn (array $data, PageLegale $record): array => $data['contenu'] !== $record->contenu || $data['titre'] !== $record->titre
                        ? [...$data, 'mis_a_jour_le' => today()] : $data),
            ])
            ->paginated(false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManagePagesLegales::route('/')];
    }
}
