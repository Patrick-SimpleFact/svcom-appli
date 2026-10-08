<?php

namespace App\Models;

use App\Enums\StatutPiste;
use App\Enums\TypePiste;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Piste de source proposée par un utilisateur (F8) : jamais publiée, elle alimente la file « Pistes utilisateurs ».
 * Les pistes qui parlent de la même chose forment un groupe (groupe_id = 1re piste) traité d'un seul geste.
 */
class Piste extends Model
{
    use IdentifiantNumerique;

    /** L'e-mail est effacé 12 mois après le traitement (F7.15). */
    public const MOIS_CONSERVATION_EMAIL = 12;

    protected $fillable = [
        'type', 'ville_id', 'nom', 'lien', 'commentaire', 'email', 'travaille_pour_le_lieu', 'appareil', 'utilisateur_id',
        'cle_groupe', 'groupe_id', 'lieu_id', 'statut', 'motif_refus_id', 'message_personnel', 'reponse', 'reponse_envoyee_le', 'traite_par', 'traite_le',
    ];

    protected $attributes = ['statut' => 'nouvelle', 'travaille_pour_le_lieu' => false];

    protected function casts(): array
    {
        return [
            'type' => TypePiste::class,
            'statut' => StatutPiste::class,
            'travaille_pour_le_lieu' => 'boolean',
            'reponse_envoyee_le' => 'datetime',
            'traite_le' => 'datetime',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }

    public function motifRefus(): BelongsTo
    {
        return $this->belongsTo(MotifRefus::class);
    }

    /** Toutes les pistes du groupe (celle-ci comprise, si c'est la 1re). */
    public function membres(): HasMany
    {
        return $this->hasMany(self::class, 'groupe_id');
    }

    /** Les têtes de groupe : une ligne par sujet dans la file du back-office. */
    public function scopeTetesDeGroupe(Builder $requete): void
    {
        $requete->whereColumn('id', 'groupe_id');
    }
}
