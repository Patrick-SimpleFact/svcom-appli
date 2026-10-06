<?php

namespace App\Models;

use App\Enums\StatutCollecte;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Collecte extends Model
{
    use IdentifiantNumerique;

    protected $fillable = [
        'source_id', 'version_detectee', 'debut', 'fin', 'statut', 'essai',
        'nb_recus', 'nb_illisibles', 'nb_retenus', 'nb_exclus', 'nb_a_trier', 'nb_hors_horizon', 'nb_nouveaux', 'nb_mis_a_jour', 'nb_retires', 'erreur', 'fichier_brut',
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

    /** Contenu du fichier brut, s'il est encore conservé. */
    public function contenuBrut(): ?string
    {
        $disque = Storage::disk(config('collecte.disque_bruts'));

        return $this->fichier_brut && $disque->exists($this->fichier_brut) ? $disque->get($this->fichier_brut) : null;
    }
}
