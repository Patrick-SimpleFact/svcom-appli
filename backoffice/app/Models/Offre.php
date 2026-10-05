<?php

namespace App\Models;

use App\Models\Concerns\DatesEnUtc;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une offre = une représentation vendue (ou annoncée) par une source : lien, prix, « complet » (F5.4).
 */
class Offre extends Model
{
    use DatesEnUtc;
    use IdentifiantNumerique;

    protected $fillable = [
        'source_id', 'identifiant_externe', 'representation_id', 'lieu_id', 'genre_id', 'jeune_public', 'debut',
        'heure_connue', 'date_locale', 'titre_comparable', 'meme_seance_que_id', 'spectacle_id', 'lien', 'prix_min', 'prix_max',
        'complet', 'donnees_normalisees', 'empreinte', 'vue_le', 'derniere_collecte_id', 'disparue_le',
    ];

    protected function casts(): array
    {
        return [
            'prix_min' => 'decimal:2',
            'prix_max' => 'decimal:2',
            'complet' => 'boolean',
            'jeune_public' => 'boolean',
            'debut' => 'datetime',
            'heure_connue' => 'boolean',
            'date_locale' => 'date',
            'donnees_normalisees' => 'array',
            'vue_le' => 'datetime',
            'disparue_le' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(Representation::class);
    }

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    public function spectacle(): BelongsTo
    {
        return $this->belongsTo(Spectacle::class);
    }

    /** Première offre du groupe : les offres d'une même séance pointent toutes vers elle (K06). */
    public function memeSeanceQue(): BelongsTo
    {
        return $this->belongsTo(self::class, 'meme_seance_que_id');
    }

    /** Offre qui représente le groupe de séances (elle-même si elle n'est rattachée à aucune). */
    public function premiereDuGroupe(): self
    {
        return $this->meme_seance_que_id ? $this->memeSeanceQue : $this;
    }
}
