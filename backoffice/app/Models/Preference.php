<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Préférences d'un compte (F3) : genres et rayon, reprises du téléphone à la 1re connexion (F1.4). Complétées en P07. */
class Preference extends Model
{
    use IdentifiantNumerique;

    protected $fillable = ['utilisateur_id', 'genres', 'rayon_m'];

    protected $attributes = ['genres' => '[]'];

    protected function casts(): array
    {
        return ['genres' => 'array', 'rayon_m' => 'integer'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }
}
