<?php

namespace App\Filament\Support;

use App\Models\Admin;

/** Administrateurs et modèles de profil : réservés aux super admins, quel que soit le modèle. */
trait ReserveAuSuperAdmin
{
    public static function canAccess(): bool
    {
        $admin = auth()->user();

        return $admin instanceof Admin && $admin->estSuperAdmin() && parent::canAccess();
    }
}
