<?php

namespace App\Models;

use App\Enums\ActionSignalement;
use App\Enums\MotifSignalement;
use App\Enums\StatutSignalement;
use App\Models\Concerns\IdentifiantNumerique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** « Signaler une erreur » sur une séance (F5.6) : arrive dans la file « Signalements » du back-office. */
class Signalement extends Model
{
    use IdentifiantNumerique;

    protected $fillable = ['representation_id', 'motif', 'commentaire', 'appareil', 'utilisateur_id', 'statut', 'action', 'traite_par', 'traite_le'];

    protected $attributes = ['statut' => 'nouveau'];

    protected function casts(): array
    {
        return [
            'motif' => MotifSignalement::class,
            'statut' => StatutSignalement::class,
            'action' => ActionSignalement::class,
            'traite_le' => 'datetime',
        ];
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(Representation::class);
    }

    /** Les signalements de la même séance (celui-ci compris) : la file affiche combien de fois elle a été signalée. */
    public function autresDeLaSeance(): HasMany
    {
        return $this->hasMany(self::class, 'representation_id', 'representation_id');
    }
}
