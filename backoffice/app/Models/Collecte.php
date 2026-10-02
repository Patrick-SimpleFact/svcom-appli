<?php

namespace App\Models;

use App\Enums\StatutCollecte;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Collecte extends Model
{
    use IdentifiantNumerique;

    protected $fillable = [
        'source_id', 'version_detectee', 'debut', 'fin', 'statut', 'essai',
        'nb_recus', 'nb_retenus', 'nb_nouveaux', 'nb_retires', 'erreur', 'fichier_brut',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCollecte::class,
            'debut' => 'datetime',
            'fin' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
