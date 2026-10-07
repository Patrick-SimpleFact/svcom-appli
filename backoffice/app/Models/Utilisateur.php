<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Un compte de l'app (F1, SCHEMA §5) : sans mot de passe (code par e-mail, Apple, Google).
 * Jamais de position enregistrée (F2.11).
 */
class Utilisateur extends Model
{
    use HasApiTokens;
    use IdentifiantNumerique;
    use Notifiable;

    /** Âge minimum pour créer un compte : seuil du consentement numérique en France (F1.4). */
    public const AGE_MINIMUM = 15;

    protected $fillable = [
        'prenom', 'email', 'naissance_mois', 'naissance_annee', 'cgu_acceptees_le',
        'lettre_info', 'lettre_info_consentie_le', 'derniere_connexion', 'supprime_le',
    ];

    protected function casts(): array
    {
        return [
            'naissance_mois' => 'integer',
            'naissance_annee' => 'integer',
            'cgu_acceptees_le' => 'datetime',
            'lettre_info' => 'boolean',
            'lettre_info_consentie_le' => 'datetime',
            'derniere_connexion' => 'datetime',
            'supprime_le' => 'datetime',
        ];
    }

    public function connexionsExternes(): HasMany
    {
        return $this->hasMany(ConnexionExterne::class);
    }

    public function statuts(): HasMany
    {
        return $this->hasMany(StatutUtilisateur::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(Preference::class);
    }

    public function favoris(): HasMany
    {
        return $this->hasMany(Favori::class);
    }

    public function suivis(): HasMany
    {
        return $this->hasMany(Suivi::class);
    }

    public function nouveautes(): HasMany
    {
        return $this->hasMany(Nouveaute::class);
    }

    public function appareils(): HasMany
    {
        return $this->hasMany(Appareil::class);
    }

    /** Profil complété après la 1re connexion : prénom, naissance, conditions acceptées (F1.4). */
    public function profilComplet(): bool
    {
        return filled($this->prenom) && $this->naissance_mois !== null && $this->naissance_annee !== null && $this->cgu_acceptees_le !== null;
    }

    /** Âge révolu, au mois près (seuls le mois et l'année sont connus : l'anniversaire compte au 1er du mois). */
    public static function age(int $mois, int $annee): int
    {
        return now()->year - $annee - (now()->month < $mois ? 1 : 0);
    }
}
