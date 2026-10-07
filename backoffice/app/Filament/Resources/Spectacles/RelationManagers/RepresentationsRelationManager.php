<?php

namespace App\Filament\Resources\Spectacles\RelationManagers;

use App\Actions\MasquerRepresentation;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Filament\Resources\Lieux\LieuResource;
use App\Models\Representation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Séances à venir d'un spectacle : correction de chaque champ (verrouillée contre la collecte) et masquage
 * d'une séance (ex. annulée par l'organisateur mais encore en vente), immédiat et réversible (F7.8, A02).
 */
class RepresentationsRelationManager extends RelationManager
{
    protected static string $relationship = 'representations';

    protected static ?string $title = 'Représentations à venir';

    /** Libellés des champs, pour la mention « corrigé à la main ». */
    public const LIBELLES = [
        'debut' => 'horaire', 'date_locale' => 'jour', 'date_fin' => 'fin', 'lieu_id' => 'lieu', 'salle' => 'salle',
        'prix_min' => 'prix minimum', 'prix_max' => 'prix maximum', 'gratuit' => 'gratuit', 'complet' => 'complet', 'statut' => 'statut',
    ];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DateTimePicker::make('debut')->label('Date et heure')->seconds(false)->required()
                ->timezone(fn (?Representation $record): string => $record?->lieu?->fuseau_horaire ?? 'Europe/Paris')
                ->visible(fn (?Representation $record): bool => $record?->type === TypeRepresentation::Seance),
            DatePicker::make('date_locale')->label('Jour')->required()
                ->visible(fn (?Representation $record): bool => $record?->type !== TypeRepresentation::Seance),
            DatePicker::make('date_fin')->label('Jusqu’au')->afterOrEqual('date_locale')
                ->visible(fn (?Representation $record): bool => $record?->type === TypeRepresentation::Periode),
            LieuResource::champLieuAConserver(fn () => null, 'lieu_id', 'Lieu')->columnSpanFull(),
            TextInput::make('salle')->maxLength(255),
            Grid::make(2)->schema([
                TextInput::make('prix_min')->label('Prix minimum')->numeric()->minValue(0)->suffix('€'),
                TextInput::make('prix_max')->label('Prix maximum')->numeric()->minValue(0)->suffix('€')->gte('prix_min'),
            ]),
            Toggle::make('gratuit'),
            Toggle::make('complet'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lieu.ville'])->whereRaw('coalesce(date_fin, date_locale) >= ?', [today()->toDateString()]))
            ->defaultSort('date_locale')
            ->columns([
                TextColumn::make('date_locale')->label('Jour')
                    ->formatStateUsing(fn ($state, Representation $r): string => $r->date_fin
                        ? 'Du '.$r->date_locale->translatedFormat('d/m/Y').' au '.$r->date_fin->translatedFormat('d/m/Y')
                        : $r->date_locale->translatedFormat('D d/m/Y')),
                TextColumn::make('debut')->label('Heure')
                    ->state(fn (Representation $r): string => $r->debut?->setTimezone($r->lieu?->fuseau_horaire ?? 'Europe/Paris')->format('H:i') ?? ($r->date_fin ? '—' : 'à confirmer')),
                TextColumn::make('lieu.nom')->label('Lieu')->wrap()->description(fn (Representation $r): ?string => $r->salle),
                TextColumn::make('lieu.ville.nom')->label('Ville'),
                TextColumn::make('prix')->label('Prix')
                    ->state(fn (Representation $r): string => self::prix($r->prix_min, $r->prix_max, $r->gratuit))
                    ->description(fn (Representation $r): ?string => $r->complet ? 'Complet' : null),
                TextColumn::make('statut')->badge()
                    ->description(fn (Representation $r): ?string => filled($r->champs_verrouilles) ? 'corrigée à la main' : null)
                    ->tooltip(fn (Representation $r): ?string => $r->resumeCorrections(self::LIBELLES)),
            ])
            ->recordActions([
                EditAction::make()->label('Corriger')
                    ->modalDescription('Les champs modifiés ne seront plus écrasés par la collecte.')
                    // Seulement les champs du formulaire (la position, objet PostGIS, ne passe pas par le navigateur).
                    ->mutateRecordDataUsing(fn (array $data): array => Arr::only($data, ['debut', 'date_locale', 'date_fin', 'lieu_id', 'salle', 'prix_min', 'prix_max', 'gratuit', 'complet'])),
                Action::make('masquer')->label('Masquer')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
                    ->visible(fn (Representation $r): bool => $r->statut !== StatutRepresentation::Masquee)
                    ->requiresConfirmation()
                    ->modalDescription('La séance disparaît de l’app tout de suite, même si une billetterie la vend encore. Réversible avec « Réafficher ».')
                    ->action(function (Representation $r): void {
                        app(MasquerRepresentation::class)->masquer($r);
                        Notification::make()->success()->title('Séance masquée')->send();
                    }),
                Action::make('reafficher')->label('Réafficher')->icon(Heroicon::OutlinedEye)->color('success')
                    ->visible(fn (Representation $r): bool => $r->statut === StatutRepresentation::Masquee)
                    ->action(function (Representation $r): void {
                        app(MasquerRepresentation::class)->reafficher($r);
                        Notification::make()->success()->title('Séance réaffichée')->send();
                    }),
            ]);
    }

    /** « 8 – 22 € », « 15 € », « Gratuit » ou « — ». */
    public static function prix(mixed $min, mixed $max, bool $gratuit = false): string
    {
        if ($min === null && $max === null) {
            return $gratuit ? 'Gratuit' : '—';
        }

        $format = fn ($p) => fmod((float) $p, 1.0) === 0.0 ? number_format((float) $p, 0, ',', ' ') : number_format((float) $p, 2, ',', ' ');
        $min ??= $max;
        $max ??= $min;

        return (float) $min === (float) $max ? $format($min).' €' : $format($min).' – '.$format($max).' €';
    }
}
