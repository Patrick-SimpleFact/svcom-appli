<?php

namespace App\Filament\Pages;

use App\Actions\PrioriserBoiteDeTravail;
use App\Enums\FileATraiter;
use App\Filament\Resources\AClasser\AClasserResource;
use App\Filament\Resources\ATrier\ATrierResource;
use App\Filament\Resources\Doublons\DoublonProbableResource;
use App\Filament\Resources\Doublons\FusionAControlerResource;
use App\Filament\Resources\LieuxAVerifier\LieuAVerifierResource;
use App\Filament\Resources\SpectaclesAControler\SpectacleAControlerResource;
use App\Filament\Widgets\CompteursFiles;
use App\Filament\Widgets\ElementsUrgents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Page d'accueil du back-office : toutes les files qui attendent une décision, avec leurs compteurs,
 * et les éléments les plus urgents toutes files confondues (F7.10, A01).
 */
class BoiteDeTravail extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    /** Écran de chaque file (les files sans écran, comme « Artistes à vérifier », viendront avec leur étape). */
    public const RESSOURCES = [
        FileATraiter::DoublonProbable->value => DoublonProbableResource::class,
        FileATraiter::FusionAControler->value => FusionAControlerResource::class,
        FileATraiter::ATrier->value => ATrierResource::class,
        FileATraiter::AClasser->value => AClasserResource::class,
        FileATraiter::LieuAVerifier->value => LieuAVerifierResource::class,
        FileATraiter::SpectacleAControler->value => SpectacleAControlerResource::class,
    ];

    /** Files que l'admin connecté peut ouvrir (modèle de profil) : seules celles-ci comptent dans la boîte de travail. */
    public static function ressourcesOuvertes(): array
    {
        return array_filter(self::RESSOURCES, fn (string $ressource): bool => $ressource::canAccess());
    }

    public static function getNavigationLabel(): string
    {
        return 'Boîte de travail';
    }

    public function getTitle(): string
    {
        return 'Boîte de travail';
    }

    public function getWidgets(): array
    {
        return [CompteursFiles::class, ElementsUrgents::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculer')->label('Recalculer l’urgence')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->tooltip('Fait automatiquement toutes les 30 minutes')
                ->action(function (): void {
                    $nombre = app(PrioriserBoiteDeTravail::class)->handle();
                    Notification::make()->success()->title("{$nombre} élément(s) reclassé(s)")->send();
                    $this->dispatch('boite-reclassee');
                }),
        ];
    }
}
