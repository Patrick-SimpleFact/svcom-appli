<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un événement d'usage envoyé par l'app (API §11) : anonyme (empreinte de l'appareil), jamais de position.
 * Écrit par App\Mesure\Evenements ; ce modèle sert seulement à la lecture dans le back-office.
 */
class EvenementApp extends Model
{
    use IdentifiantNumerique;

    public $timestamps = false;

    protected $table = 'evenements_app';

    protected function casts(): array
    {
        return ['horodatage' => 'datetime', 'donnees' => 'array'];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }
}
