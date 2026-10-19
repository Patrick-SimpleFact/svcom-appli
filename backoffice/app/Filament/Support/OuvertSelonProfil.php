<?php

namespace App\Filament\Support;

use App\Models\Admin;

/**
 * À mettre sur chaque ressource et page du back-office (sauf la boîte de travail, ouverte à tous) : le menu n'est
 * visible, cherchable et ouvrable que si le modèle de profil de l'admin l'ouvre (MenusProfil).
 */
trait OuvertSelonProfil
{
    public static function canAccess(): bool
    {
        $admin = auth()->user();

        return $admin instanceof Admin && $admin->peutOuvrir(static::class) && parent::canAccess();
    }
}
