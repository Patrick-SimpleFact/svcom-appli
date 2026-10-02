<?php

namespace App\Models;

use App\Casts\PointGeographique;
use App\Enums\StatutRepresentation;
use App\Enums\TypeRepresentation;
use App\Models\Concerns\DatesEnUtc;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use App\Support\Point;
use Carbon\CarbonInterface;
use Database\Factories\RepresentationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** Une séance commencée avant cette heure (locale) compte pour la soirée de la veille (F2.2). */
    public const HEURE_FIN_DE_SOIREE = 4;

    protected $fillable = [
        'spectacle_id', 'lieu_id', 'type', 'debut', 'fin', 'date_locale',
        'prix_min', 'prix_max', 'gratuit', 'complet', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeRepresentation::class,
            'statut' => StatutRepresentation::class,
            'debut' => 'datetime',
            'fin' => 'datetime',
            'date_locale' => 'date',
            'position' => PointGeographique::class,
            'prix_min' => 'decimal:2',
            'prix_max' => 'decimal:2',
            'gratuit' => 'boolean',
            'complet' => 'boolean',
        ];
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
        if ($this->isDirty('lieu_id') || $this->position === null) {
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
        if ($this->type === TypeRepresentation::Jour || $this->debut === null) {
            return;
        }

        $local = $this->debut->copy()->setTimezone($this->lieu?->fuseau_horaire ?? 'Europe/Paris');

        if ($local->hour < self::HEURE_FIN_DE_SOIREE) {
            $local->subDay();
        }

        $this->date_locale = $local->toDateString();
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

    /** Représentations d'un jour donné, dans un rayon autour d'un point (index géographique). */
    public function scopeAutourDe(Builder $requete, Point $centre, int $rayonMetres, CarbonInterface|string $jour): Builder
    {
        return $requete
            ->whereDate('date_locale', $jour)
            ->where('statut', StatutRepresentation::Programmee)
            ->whereRaw('ST_DWithin(position, ?::geography, ?)', [$centre->versEwkt(), $rayonMetres])
            ->selectRaw('representations.*, ST_Distance(position, ?::geography) as distance_m', [$centre->versEwkt()]);
    }
}
