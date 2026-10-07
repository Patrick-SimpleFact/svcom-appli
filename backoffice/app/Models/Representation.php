<?php

namespace App\Models;

use App\Casts\PointGeographique;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\Concerns\DatesEnUtc;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use App\Models\Concerns\VerrouilleCorrections;
use App\Support\Point;
use Carbon\CarbonInterface;
use Database\Factories\RepresentationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une représentation = un spectacle × un lieu × une date : c'est ce que l'app cherche et affiche.
 */
class Representation extends Model
{
    use DatesEnUtc;

    /** @use HasFactory<RepresentationFactory> */
    use HasFactory;

    use IdentifiantNumerique;
    use Journalise;
    use VerrouilleCorrections;

    /** Une séance commencée avant cette heure (locale) compte pour la soirée de la veille (F2.2). */
    public const HEURE_FIN_DE_SOIREE = 4;

    protected $fillable = [
        'spectacle_id', 'lieu_id', 'salle', 'type', 'debut', 'fin', 'date_locale', 'date_fin',
        'prix_min', 'prix_max', 'gratuit', 'complet', 'statut', 'champs_verrouilles',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeRepresentation::class,
            'statut' => StatutRepresentation::class,
            'debut' => 'datetime',
            'fin' => 'datetime',
            'date_locale' => 'date',
            'date_fin' => 'date',
            'position' => PointGeographique::class,
            'prix_min' => 'decimal:2',
            'prix_max' => 'decimal:2',
            'gratuit' => 'boolean',
            'complet' => 'boolean',
            'champs_verrouilles' => 'array',
        ];
    }

    /** Position, commune et genre sont recopiés du lieu et du spectacle : jamais verrouillés (ils suivent un lieu corrigé). */
    protected function champsNonVerrouillables(): array
    {
        return ['champs_verrouilles', 'position', 'ville_id', 'genre_id', 'created_at', 'updated_at'];
    }

    protected static function booted(): void
    {
        static::saving(function (Representation $representation) {
            $representation->recopierLieuEtGenre();
            $representation->calculerDateLocale();
        });
    }

    /** Copie la position et la ville du lieu, et le genre du spectacle (SCHEMA §10 point 2). */
    public function recopierLieuEtGenre(): void
    {
        // Valeur brute : lire la position (objet) la ferait réécrire à chaque sauvegarde, même inchangée.
        if ($this->isDirty('lieu_id') || blank($this->attributes['position'] ?? null)) {
            $lieu = $this->lieu;
            $this->position = $lieu?->position;
            $this->ville_id = $lieu?->ville_id;
        }

        if ($this->isDirty('spectacle_id') || $this->genre_id === null) {
            $this->genre_id = $this->spectacle?->genre_id;
        }
    }

    /** Jour du spectacle à l'heure du lieu ; une séance à 0 h 30 compte pour la veille. */
    public function calculerDateLocale(): void
    {
        if (in_array($this->type, [TypeRepresentation::Jour, TypeRepresentation::Periode], true) || $this->debut === null) {
            return;
        }

        $local = $this->debut->copy()->setTimezone($this->lieu?->fuseau_horaire ?? 'Europe/Paris');

        if ($local->hour < self::HEURE_FIN_DE_SOIREE) {
            $local->subDay();
        }

        $this->date_locale = $local->toDateString();
    }

    /** Offres des billetteries qui vendent cette séance (F5.4). */
    public function offres(): HasMany
    {
        return $this->hasMany(Offre::class);
    }

    public function spectacle(): BelongsTo
    {
        return $this->belongsTo(Spectacle::class);
    }

    public function lieu(): BelongsTo
    {
        return $this->belongsTo(Lieu::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /**
     * Ce que l'app peut montrer (F7.8) : représentation programmée, dont ni le spectacle ni le lieu ne sont masqués,
     * et vendue par au moins une source active et non masquée (F7.3 : une source désactivée disparaît de l'app)
     * ; une représentation saisie à la main, sans offre, reste visible.
     */
    public function scopeVisibles(Builder $requete): Builder
    {
        // Jointures directes (alias vis_spectacle, vis_lieu) plutôt que des sous-requêtes : avec elles, PostgreSQL
        // estimait une seule ligne et comparait chaque représentation à tous les lieux (Paris, un week-end : 2 s au lieu de 0,2 s).
        // ⚠️ Pour charger des représentations, sélectionner « representations.* » (les jointures ont aussi un « id »).
        return $requete
            ->join('spectacles as vis_spectacle', 'vis_spectacle.id', '=', 'representations.spectacle_id')
            ->join('lieux as vis_lieu', 'vis_lieu.id', '=', 'representations.lieu_id')
            ->where('representations.statut', StatutRepresentation::Programmee)
            ->where('vis_spectacle.masque', false)
            ->where('vis_lieu.masque', false)
            ->where(fn (Builder $q) => $q
                ->whereExists(fn ($o) => $o->selectRaw('1')->from('offres')->join('sources', 'sources.id', '=', 'offres.source_id')
                    ->whereColumn('offres.representation_id', 'representations.id')->whereNull('offres.disparue_le')->where('sources.actif', true)->where('sources.masquee', false))
                ->orWhereNotExists(fn ($o) => $o->selectRaw('1')->from('offres')->whereColumn('offres.representation_id', 'representations.id')));
    }

    /** Représentations visibles d'un jour donné, dans un rayon autour d'un point (index géographique). */
    public function scopeAutourDe(Builder $requete, Point $centre, int $rayonMetres, CarbonInterface|string $jour): Builder
    {
        return $requete
            ->whereDate('representations.date_locale', $jour)
            ->visibles()
            ->whereRaw('ST_DWithin(representations.position, ?::geography, ?)', [$centre->versEwkt(), $rayonMetres])
            ->selectRaw('representations.*, ST_Distance(representations.position, ?::geography) as distance_m', [$centre->versEwkt()]);
    }
}
