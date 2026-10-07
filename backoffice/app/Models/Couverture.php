<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mesure de couverture d'une ville pour un jour (F7.13).
 */
class Couverture extends Model
{
    use IdentifiantNumerique;

    public $timestamps = false;

    protected $fillable = ['jour', 'ville_id', 'ce_soir', 'week_end', 'trente_jours', 'spectacles', 'avec_horaire_pct', 'mesuree_le'];

    protected function casts(): array
    {
        return ['jour' => 'date', 'mesuree_le' => 'datetime'];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }
}
