<?php

namespace App\Filament\Resources\Parametres\Schemas;

use App\Enums\ModeIa;
use App\Enums\TypeParametre;
use App\Ia\ClientOllama;
use App\Models\Parametre;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
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
        // Modèle de l'assistant IA : choisi dans la liste des modèles cloud d'ollama.com (plus la valeur actuelle),
        // et vérifié auprès d'Ollama à l'enregistrement (modèle retiré entre-temps, Ollama trop ancien…).
        if ($cle === 'ia_modele') {
            return Select::make('valeur')->required()->searchable()->native(false)
                ->options(fn (?Parametre $record): array => collect(app(ClientOllama::class)->modelesCloud())
                    ->push((string) $record?->valeur)->filter()->unique()->sort()->mapWithKeys(fn (string $m) => [$m => $m])->all())
                ->helperText('Modèles « cloud » proposés par ollama.com (liste mise à jour toutes les 6 heures).')
                ->rules([
                    fn () => function (string $attribut, mixed $valeur, Closure $echec): void {
                        if ($erreur = app(ClientOllama::class)->verifierModele(trim((string) $valeur))) {
                            $echec($erreur);
                        }
                    },
                ])->dehydrateStateUsing(fn ($state): string => trim((string) $state));
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
