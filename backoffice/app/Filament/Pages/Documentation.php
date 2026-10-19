<?php

namespace App\Filament\Pages;

use App\Filament\Support\Documentation as Chapitres;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Documentation du back-office, ouverte à tous les comptes : chacun ne lit que les menus qu'il peut ouvrir.
 * Les textes sont dans resources/documentation (un fichier par écran) et se mettent à jour avec les menus.
 */
class Documentation extends Page
{
    protected string $view = 'filament.pages.documentation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'documentation';

    protected static ?string $navigationLabel = 'Documentation';

    protected static ?string $title = 'Documentation';

    public function getSubheading(): string
    {
        return 'À quoi sert chaque menu et ce que fait chaque bouton. Seuls les menus de votre compte sont décrits.';
    }

    /** @return array<string, list<array{ancre: string, titre: string, url: ?string, html: string}>> */
    public function chapitres(): array
    {
        return Chapitres::chapitres();
    }
}
