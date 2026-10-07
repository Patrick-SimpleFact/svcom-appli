<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Préférences d'un compte (F3) : genres, rayon, zone des alertes (une commune et un rayon, jamais une position), alertes et rappel du jour J. */
class Preference extends Model
{
    use IdentifiantNumerique;

    protected $fillable = ['utilisateur_id', 'genres', 'rayon_m', 'zone_alertes_ville_id', 'zone_alertes_rayon_km', 'alertes_actives', 'rappel_jour_j'];

    protected $attributes = ['genres' => '[]', 'alertes_actives' => true, 'rappel_jour_j' => true];

    protected function casts(): array
    {
        return ['genres' => 'array', 'rayon_m' => 'integer', 'zone_alertes_rayon_km' => 'integer', 'alertes_actives' => 'boolean', 'rappel_jour_j' => 'boolean'];
    }

    public function zoneAlertesVille(): BelongsTo
    {
        return $this->belongsTo(Ville::class, 'zone_alertes_ville_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }
}
