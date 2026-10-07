<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une recherche faite dans l'app, journalisée sans identifiant (F4.8).
 */
class Recherche extends Model
{
    use IdentifiantNumerique;

    public $timestamps = false;

    protected $fillable = ['texte', 'ville_id', 'nb_resultats', 'filtres', 'cree_le'];

    protected function casts(): array
    {
        return ['filtres' => 'array', 'cree_le' => 'datetime'];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }
}
