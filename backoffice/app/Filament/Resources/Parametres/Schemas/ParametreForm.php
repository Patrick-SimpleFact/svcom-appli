<?php

namespace App\Filament\Resources\Parametres\Schemas;

use App\Enums\ModeIa;
use App\Enums\TypeParametre;
use App\Ia\Assistant;
use App\Ia\ClientOllama;
use App\Models\Parametre;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class ParametreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(fn (?Parametre $record): array => [
                Text::make(fn (): string => $record?->description ?? ''),
                self::champValeur($record?->type, $record?->cle)
                    ->label($record?->libelle ?? 'Valeur'),
            ]);
    }

    private static function champValeur(?TypeParametre $type, ?string $cle)
    {
        // Modèles de l'assistant IA : choisis dans la liste des modèles cloud d'ollama.com (plus les valeurs actuelles),
        // et vérifiés auprès d'Ollama à l'enregistrement (modèle retiré entre-temps, hors forfait, Ollama trop ancien…).
        if (in_array($cle, ['ia_modeles_niveau1', 'ia_modele_niveau2'], true)) {
            $plusieurs = $cle === 'ia_modeles_niveau1';
            $options = fn (?Parametre $record): array => collect(app(ClientOllama::class)->modelesCloud())
                ->merge(Assistant::liste((string) $record?->valeur))->filter()->unique()->sort()->mapWithKeys(fn (string $m) => [$m => $m])->all();
            $verifier = fn () => function (string $attribut, mixed $valeur, Closure $echec): void {
                foreach ((array) $valeur as $modele) {
                    if ($erreur = app(ClientOllama::class)->verifierModele(trim((string) $modele))) {
                        $echec($erreur);
                    }
                }
            };

            return Select::make('valeur')->searchable()->native(false)->multiple($plusieurs)->required($plusieurs)
                ->options($options)
                ->maxItems($plusieurs ? 3 : null)
                ->formatStateUsing(fn ($state) => match (true) {
                    is_array($state) => $state,
                    $plusieurs => Assistant::liste((string) $state),
                    default => $state ?: null,
                })
                ->helperText('Modèles « cloud » proposés par ollama.com (liste mise à jour toutes les 6 heures). Les modèles facturés en plus du forfait n’apparaissent pas.')
                ->rules([$verifier])
                ->dehydrateStateUsing(fn ($state): string => implode(', ', array_map('trim', (array) $state)));
        }

        // Heure lue chaque minute par la tâche planifiée : toujours au format HH:MM.
        if ($cle === 'boite_heure_sortie_passes') {
            return TimePicker::make('valeur')->seconds(false)->required()->helperText('Heure de Paris.');
        }

        return self::champSelonType($type);
    }

    private static function champSelonType(?TypeParametre $type)
    {
        return match ($type) {
            TypeParametre::Entier => TextInput::make('valeur')->integer()->required()->minValue(0),
            TypeParametre::Decimal => TextInput::make('valeur')->numeric()->required()->minValue(0)->maxValue(1)->step(0.05),
            TypeParametre::Booleen => Toggle::make('valeur'),
            TypeParametre::ListeEntiers => TagsInput::make('valeur')
                ->required()
                ->nestedRecursiveRules(['integer', 'min:0'])
                ->separator(',')
                ->helperText('Saisir chaque nombre puis Entrée.')
                ->dehydrateStateUsing(fn ($state): array => array_values(array_map('intval', (array) $state))),
            TypeParametre::ModeIa => Select::make('valeur')->options(ModeIa::class)->required()->native(false),
            default => TextInput::make('valeur')->required(),
        };
    }
}
