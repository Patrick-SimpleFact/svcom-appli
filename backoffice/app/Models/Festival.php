<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Festival extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $fillable = ['nom', 'ville_id', 'date_debut', 'date_fin', 'site_web'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function spectacles(): HasMany
    {
        return $this->hasMany(Spectacle::class);
    }
}
