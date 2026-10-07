<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** « Continuer avec Apple » ou « avec Google » rattaché à un compte (F1.4). */
class ConnexionExterne extends Model
{
    use IdentifiantNumerique;

    protected $table = 'connexions_externes';

    protected $fillable = ['utilisateur_id', 'fournisseur', 'identifiant_fournisseur'];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }
}
