<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un spectacle mis en favori (F5.5), avec la séance choisie s'il y en a une. */
class Favori extends Model
{
    use IdentifiantNumerique;

    protected $table = 'favoris';

    protected $fillable = ['utilisateur_id', 'spectacle_id', 'representation_id'];

    public function spectacle(): BelongsTo
    {
        return $this->belongsTo(Spectacle::class);
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(Representation::class);
    }
}
