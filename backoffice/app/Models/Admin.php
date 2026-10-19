<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Database\Factories\AdminFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Compte du back-office. Distinct des comptes de l'app. Ses menus viennent de son modèle de profil,
 * ou de sa propre liste pour un compte « Libre » (sans modèle).
 */
class Admin extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory;

    use IdentifiantNumerique;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Journalise;
    use Notifiable;

    protected $fillable = [
        'nom',
        'email',
        'password',
        'actif',
        'derniere_connexion_le',
        'profil_id',
        'menus',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'actif' => 'boolean',
            'derniere_connexion_le' => 'datetime',
            'menus' => 'array',
        ];
    }

    /** @return BelongsTo<Profil, $this> */
    public function profil(): BelongsTo
    {
        return $this->belongsTo(Profil::class);
    }

    public function estSuperAdmin(): bool
    {
        return (bool) $this->profil?->super_admin;
    }

    /** @param  class-string  $ecran  ressource ou page du back-office ; compte « Libre » (sans modèle) : ses propres menus */
    public function peutOuvrir(string $ecran): bool
    {
        return $this->profil ? $this->profil->ouvre($ecran) : in_array($ecran, $this->menus ?? [], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->actif;
    }

    public function getFilamentName(): string
    {
        return $this->nom;
    }
}
