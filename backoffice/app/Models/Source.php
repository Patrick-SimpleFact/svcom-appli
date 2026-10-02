<?php

namespace App\Models;

use App\Enums\TypeAccesSource;
use App\Enums\TypeLienSource;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Déclaration d'une source (F7.3) : ajouter une source = écrire un connecteur et la déclarer ici.
 */
class Source extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $fillable = [
        'code', 'nom', 'logo_url', 'type_acces', 'licence', 'mention_obligatoire',
        'type_lien', 'actif', 'zone', 'fiabilite', 'config', 'remarques',
        'derniere_verification_le', 'derniere_version_vue', 'erreur_detection',
    ];

    protected function casts(): array
    {
        return [
            'type_acces' => TypeAccesSource::class,
            'type_lien' => TypeLienSource::class,
            'actif' => 'boolean',
            'zone' => 'array',
            'fiabilite' => 'array',
            'config' => 'array',
            'derniere_verification_le' => 'datetime',
        ];
    }

    public function collectes(): HasMany
    {
        return $this->hasMany(Collecte::class);
    }

    public function offres(): HasMany
    {
        return $this->hasMany(Offre::class);
    }
}
