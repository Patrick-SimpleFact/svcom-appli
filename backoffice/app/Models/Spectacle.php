<?php

namespace App\Models;

use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use App\Models\Concerns\VerrouilleCorrections;
use App\Support\Texte;
use Database\Factories\SpectacleFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Spectacle extends Model
{
    /** @use HasFactory<SpectacleFactory> */
    use HasFactory;

    use IdentifiantNumerique;
    use Journalise;
    use VerrouilleCorrections;

    protected $fillable = [
        'titre', 'description', 'genre_id', 'classification_fine', 'jeune_public', 'age_min',
        'duree_minutes', 'image_url', 'festival_id', 'masque', 'champs_verrouilles', 'demo',
    ];

    protected function casts(): array
    {
        return [
            'jeune_public' => 'boolean',
            'age_min' => 'integer',
            'duree_minutes' => 'integer',
            'masque' => 'boolean',
            'champs_verrouilles' => 'array',
            'demo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Le genre est recopié sur chaque représentation (filtre rapide) : on le garde à jour.
        static::updated(function (Spectacle $spectacle) {
            if ($spectacle->wasChanged('genre_id')) {
                $spectacle->representations()->update(['genre_id' => $spectacle->genre_id]);
            }
        });
    }

    protected function titre(): Attribute
    {
        return Attribute::make(set: fn (string $valeur): array => ['titre' => $valeur, 'titre_normalise' => Texte::normaliser($valeur)]);
    }

    /** Séances collectées rattachées à ce spectacle (K07), avant leur publication en représentations (K08). */
    public function offres(): HasMany
    {
        return $this->hasMany(Offre::class);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    public function festival(): BelongsTo
    {
        return $this->belongsTo(Festival::class);
    }

    public function representations(): HasMany
    {
        return $this->hasMany(Representation::class);
    }

    public function artistes(): BelongsToMany
    {
        return $this->belongsToMany(Artiste::class, 'spectacle_artiste')->withPivot('role');
    }
}
