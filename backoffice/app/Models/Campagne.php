<?php

namespace App\Models;

use App\Enums\StatutCampagne;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Campagne sponsorisée (F6.4) : un spectacle mis en avant dans une zone, pour des goûts, sur une période,
 * jusqu'au nombre d'affichages acheté. Montrée seulement si elle correspond aussi au profil (F6.1).
 */
class Campagne extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $fillable = ['annonceur_id', 'spectacle_id', 'visuel', 'ville_id', 'zone_rayon_km', 'genres', 'debut', 'fin', 'affichages_achetes', 'statut'];

    protected $attributes = ['statut' => 'brouillon', 'genres' => '[]', 'zone_rayon_km' => 20];

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagne::class,
            'genres' => 'array',
            'debut' => 'date',
            'fin' => 'date',
            'affichages_achetes' => 'integer',
            'zone_rayon_km' => 'integer',
        ];
    }

    public function annonceur(): BelongsTo
    {
        return $this->belongsTo(Annonceur::class);
    }

    public function spectacle(): BelongsTo
    {
        return $this->belongsTo(Spectacle::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function affichages(): HasMany
    {
        return $this->hasMany(AffichageSuggestion::class);
    }

    /** Actives aujourd'hui (jour de Paris) et pas encore épuisées. */
    public function scopeDiffusables(Builder $requete, string $aujourdhui): void
    {
        $requete->where('statut', StatutCampagne::Active)
            ->where('debut', '<=', $aujourdhui)->where('fin', '>=', $aujourdhui)
            ->whereRaw('(select count(*) from affichages_suggestion a where a.campagne_id = campagnes.id) < campagnes.affichages_achetes');
    }

    public function urlVisuel(): ?string
    {
        return $this->visuel ? Storage::disk('public')->url($this->visuel) : null;
    }
}
