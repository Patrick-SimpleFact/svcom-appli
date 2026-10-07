<?php

namespace App\Models;

use App\Enums\TypeMessageService;
use App\Models\Concerns\DatesEnUtc;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message de service (F2.8, F7.12) : bandeau de l'app pendant une période, pour tout le monde ou une seule ville.
 */
class MessageService extends Model
{
    use DatesEnUtc;
    use IdentifiantNumerique;
    use Journalise;

    protected $table = 'messages_service';

    protected $fillable = ['texte', 'type', 'debut', 'fin', 'ville_id', 'actif'];

    protected $attributes = ['actif' => true];

    protected function casts(): array
    {
        return [
            'type' => TypeMessageService::class,
            'debut' => 'datetime',
            'fin' => 'datetime',
            'actif' => 'boolean',
        ];
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    /**
     * Messages à afficher maintenant (utilisés par l'API, F2.8) : actifs, dans leur période,
     * pour tout le monde ou pour la ville de l'utilisateur.
     */
    public function scopeAAfficher(Builder $requete, ?int $villeId = null): Builder
    {
        return $requete
            ->where('actif', true)
            ->where('debut', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('fin')->orWhere('fin', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('ville_id')->when($villeId, fn (Builder $q) => $q->orWhere('ville_id', $villeId)))
            ->orderByRaw("case when type = 'alerte' then 0 else 1 end")
            ->orderByDesc('debut');
    }

    /** « Programmé », « En cours », « Terminé » ou « Désactivé », pour le back-office. */
    public function etat(): string
    {
        return match (true) {
            ! $this->actif => 'Désactivé',
            $this->debut->isFuture() => 'Programmé',
            $this->fin !== null && $this->fin->isPast() => 'Terminé',
            default => 'En cours',
        };
    }
}
