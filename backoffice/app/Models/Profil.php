<?php

namespace App\Models;

use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle de profil du back-office : les menus qu'ouvrent les comptes qui le suivent. Modifier un modèle change
 * aussitôt les menus de tous ses comptes. « Super Admin » ouvre tout, y compris les administrateurs et les modèles.
 */
class Profil extends Model
{
    use Journalise;

    protected $table = 'profils';

    protected $fillable = ['nom', 'menus'];

    protected function casts(): array
    {
        return [
            'super_admin' => 'boolean',
            'menus' => 'array',
        ];
    }

    /** @return HasMany<Admin, $this> */
    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    /** @param  Builder<Profil>  $query */
    public function scopeSuperAdmin(Builder $query): void
    {
        $query->where('super_admin', true);
    }

    /** @param  class-string  $ecran  ressource ou page du back-office */
    public function ouvre(string $ecran): bool
    {
        return $this->super_admin || in_array($ecran, $this->menus ?? [], true);
    }
}
