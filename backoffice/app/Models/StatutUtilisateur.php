<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Statut d'un compte (F1.8) : spectateur (par défaut), gestionnaire de lieu (accordé par le super-admin, F9). */
class StatutUtilisateur extends Model
{
    use IdentifiantNumerique;

    public const SPECTATEUR = 'spectateur';

    public const GESTIONNAIRE_LIEU = 'gestionnaire_lieu';

    public $timestamps = false;

    protected $table = 'statuts_utilisateur';

    protected $fillable = ['utilisateur_id', 'statut', 'lieu_id', 'accorde_par', 'accorde_le'];

    protected function casts(): array
    {
        return ['accorde_le' => 'datetime'];
    }

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }
}
