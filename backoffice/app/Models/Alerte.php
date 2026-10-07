<?php

namespace App\Models;

use App\Enums\TypeAlerte;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une alerte de supervision d'une source (F7.9) : ouverte tant que le problème dure.
 */
class Alerte extends Model
{
    use IdentifiantNumerique;

    protected $fillable = ['source_id', 'type', 'message', 'ouverte_le', 'resolue_le', 'notifiee_le'];

    protected function casts(): array
    {
        return [
            'type' => TypeAlerte::class,
            'ouverte_le' => 'datetime',
            'resolue_le' => 'datetime',
            'notifiee_le' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function scopeOuvertes(Builder $requete): Builder
    {
        return $requete->whereNull('resolue_le');
    }
}
