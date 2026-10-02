<?php

namespace App\Models;

use App\Enums\FileATraiter;
use App\Enums\StatutElement;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un élément d'une file technique de la boîte de travail (F7.10).
 */
class ElementATraiter extends Model
{
    use IdentifiantNumerique;

    protected $table = 'elements_a_traiter';

    protected $fillable = ['file', 'cible_type', 'cible_id', 'donnees', 'priorite', 'statut', 'decision', 'traite_par', 'traite_le'];

    protected function casts(): array
    {
        return [
            'file' => FileATraiter::class,
            'statut' => StatutElement::class,
            'donnees' => 'array',
            'decision' => 'array',
            'traite_le' => 'datetime',
        ];
    }

    public function cible(): MorphTo
    {
        return $this->morphTo();
    }
}
