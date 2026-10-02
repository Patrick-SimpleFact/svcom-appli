<?php

namespace App\Models;

use App\Enums\TypeDecisionDedoublonnage;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision manuelle mémorisée et réappliquée à chaque collecte (F7.7).
 */
class DecisionDedoublonnage extends Model
{
    use IdentifiantNumerique;

    protected $table = 'decisions_dedoublonnage';

    protected $fillable = ['type', 'offre_a_id', 'offre_b_id', 'admin_id'];

    protected function casts(): array
    {
        return ['type' => TypeDecisionDedoublonnage::class];
    }

    public function offreA(): BelongsTo
    {
        return $this->belongsTo(Offre::class, 'offre_a_id');
    }

    public function offreB(): BelongsTo
    {
        return $this->belongsTo(Offre::class, 'offre_b_id');
    }
}
