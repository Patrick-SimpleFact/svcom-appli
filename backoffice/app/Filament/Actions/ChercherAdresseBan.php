<?php

namespace App\Filament\Actions;

use App\Actions\AppliquerAdresseBan;
use App\Collecte\BaseAdresseNationale;
use App\Models\ElementATraiter;
use App\Models\Lieu;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * « Chercher l'adresse dans la BAN » (K04b) : texte de recherche modifiable, les 5 meilleurs résultats,
 * le choix est appliqué au lieu. Utilisé sur la fiche d'un lieu et dans la file « Lieux à vérifier ».
 */
class ChercherAdresseBan
{
    /** @param  Closure(mixed): ?Lieu  $lieu  retrouve le lieu à partir de l'enregistrement de la page ou de la ligne */
    public static function make(Closure $lieu, string $nom = 'chercherBan'): Action
    {
        return Action::make($nom)
            ->label('Chercher l’adresse (BAN)')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('gray')
            ->modalHeading('Chercher l’adresse dans la Base Adresse Nationale')
            ->modalDescription('La BAN connaît les adresses, pas les noms de salles : chercher avec l’adresse (trouvée par exemple sur le site du lieu).')
            ->modalSubmitActionLabel('Appliquer au lieu')
            ->fillForm(fn ($record) => ['recherche' => self::texteDeDepart($lieu($record))])
            ->schema(fn ($record) => [
                TextInput::make('recherche')->label('Adresse recherchée')->required()->live(onBlur: true)
                    ->suffixAction(Action::make('relancer')->icon(Heroicon::OutlinedMagnifyingGlass)->tooltip('Rechercher')
                        ->action(fn () => null)) // un aller-retour suffit : la liste des résultats est recalculée
                    ->helperText('Modifier l’adresse puis cliquer sur la loupe pour relancer la recherche.'),
                Select::make('choix')->label('Résultat à appliquer')->required()
                    ->options(fn (Get $get): array => collect(self::resultats($lieu($record), (string) $get('recherche')))
                        ->map(fn (array $r) => sprintf('%s — %s, score %d %%', $r['libelle'], self::type($r['type']), round($r['score'] * 100)))
                        ->all())
                    ->placeholder('Aucun résultat : modifier la recherche'),
            ])
            ->action(function (array $data, $record) use ($lieu): void {
                $cible = $lieu($record);
                $resultat = self::resultats($cible, (string) $data['recherche'])[(int) $data['choix']] ?? null;

                if ($cible === null || $resultat === null) {
                    Notification::make()->danger()->title('Résultat introuvable, relancer la recherche.')->send();

                    return;
                }

                app(AppliquerAdresseBan::class)->handle($cible, $resultat);
                Notification::make()->success()->title('Adresse appliquée')->body($resultat['libelle'])->send();
            });
    }

    /** Recherche de départ : l'adresse connue, sinon le nom ; avec la commune. */
    private static function texteDeDepart(?Lieu $lieu): string
    {
        return trim(implode(' ', array_filter([$lieu?->adresse ?: $lieu?->nom, $lieu?->code_postal, $lieu?->ville?->nom])));
    }

    /** @var array<string, list<array>> résultats déjà demandés pendant cette requête (options + application) */
    private static array $cache = [];

    private static function resultats(?Lieu $lieu, string $texte): array
    {
        return self::$cache[$texte] ??= app(BaseAdresseNationale::class)->rechercher($texte, null, null, 5);
    }

    private static function type(string $type): string
    {
        return match ($type) {
            'housenumber' => 'numéro',
            'street' => 'rue',
            'locality' => 'lieu-dit',
            'municipality' => 'commune seule',
            default => $type,
        };
    }

    /** Le lieu d'une ligne de la file « Lieux à vérifier ». */
    public static function lieuDeLElement(): Closure
    {
        return fn (ElementATraiter $record): ?Lieu => $record->cible instanceof Lieu ? $record->cible : null;
    }
}
