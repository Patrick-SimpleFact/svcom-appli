<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une nouveauté pour un utilisateur (F3.4) : nouveau spectacle dans un lieu suivi, nouvelle date d'un artiste suivi dans sa zone,
 * rappel du jour J d'un favori. Montrée dans l'app (badge) et regroupée en une notification à 18 h (P11).
 */
class Nouveaute extends Model
{
    use IdentifiantNumerique;

    public const NOUVEAU_SPECTACLE_LIEU = 'nouveau_spectacle_lieu';

    public const NOUVELLE_DATE_ARTISTE = 'nouvelle_date_artiste';

    public const RAPPEL_JOUR_J = 'rappel_jour_j';

    public $timestamps = false;

    protected $fillable = ['utilisateur_id', 'type', 'cle', 'representation_id', 'spectacle_id', 'suivi_id', 'cree_le', 'notifiee_le', 'vue_le'];

    protected function casts(): array
    {
        return ['cree_le' => 'datetime', 'notifiee_le' => 'datetime', 'vue_le' => 'datetime'];
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(Representation::class);
    }

    public function spectacle(): BelongsTo
    {
        return $this->belongsTo(Spectacle::class);
    }
}
