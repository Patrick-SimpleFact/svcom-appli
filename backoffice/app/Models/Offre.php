<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une offre = une représentation vendue (ou annoncée) par une source : lien, prix, « complet » (F5.4).
 */
class Offre extends Model
{
    use IdentifiantNumerique;

    protected $fillable = [
        'source_id', 'identifiant_externe', 'representation_id', 'lien', 'prix_min', 'prix_max',
        'complet', 'donnees_normalisees', 'empreinte', 'vue_le', 'disparue_le',
    ];

    protected function casts(): array
    {
        return [
            'prix_min' => 'decimal:2',
            'prix_max' => 'decimal:2',
            'complet' => 'boolean',
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
}
