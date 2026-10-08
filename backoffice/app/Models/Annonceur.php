<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Théâtre ou producteur qui achète des campagnes sponsorisées (F6.4, vente manuelle D5). */
class Annonceur extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $fillable = ['nom', 'contact', 'email', 'telephone', 'lieu_id', 'notes'];

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }

    public function campagnes(): HasMany
    {
        return $this->hasMany(Campagne::class);
    }
}
