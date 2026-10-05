<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lieu tel qu'une source l'écrit, déjà rattaché à un lieu du catalogue (COLLECTE §4, étape 1).
 */
class LieuSource extends Model
{
    use IdentifiantNumerique;

    protected $table = 'lieux_sources';

    protected $fillable = ['source_id', 'cle', 'nom', 'adresse', 'ville', 'lieu_id'];

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
