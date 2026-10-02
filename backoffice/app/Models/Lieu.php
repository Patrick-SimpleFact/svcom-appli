<?php

namespace App\Models;

use App\Casts\PointGeographique;
use App\Enums\PrecisionPosition;
use App\Enums\TypeLieu;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use App\Models\Concerns\VerrouilleCorrections;
use App\Support\Texte;
use Database\Factories\LieuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lieu extends Model
{
    /** @use HasFactory<LieuFactory> */
    use HasFactory;

    use IdentifiantNumerique;
    use Journalise;
    use VerrouilleCorrections;

    protected $table = 'lieux';

    protected $fillable = [
        'nom',
        'type',
        'label',
        'adresse',
        'code_postal',
        'ville_id',
        'position',
        'precision_position',
        'fuseau_horaire',
        'telephone',
        'site_web',
        'jauge',
        'ref_ministere',
        'fusionne_dans_id',
        'masque',
        'champs_verrouilles',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeLieu::class,
            'position' => PointGeographique::class,
            'precision_position' => PrecisionPosition::class,
            'jauge' => 'integer',
            'masque' => 'boolean',
            'champs_verrouilles' => 'array',
        ];
    }

    /** Le nom normalisé (rapprochements, recherche) suit toujours le nom. */
    protected function nom(): Attribute
    {
        return Attribute::make(
            set: fn (string $valeur): array => ['nom' => $valeur, 'nom_normalise' => Texte::normaliser($valeur)],
        );
    }

    protected static function booted(): void
    {
        // La position et la ville sont recopiées sur les représentations : on les garde à jour.
        static::updated(function (Lieu $lieu) {
            if ($lieu->wasChanged(['position', 'ville_id'])) {
                $lieu->representations()->update([
                    'position' => $lieu->position?->versEwkt(),
                    'ville_id' => $lieu->ville_id,
                ]);
            }
        });
    }

    public function representations(): HasMany
    {
        return $this->hasMany(Representation::class);
    }

    public function ville(): BelongsTo
    {
        return $this->belongsTo(Ville::class);
    }

    public function fusionneDans(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fusionne_dans_id');
    }

    /** Lieux réels (non fusionnés dans un autre). */
    public function scopeActifs(Builder $requete): Builder
    {
        return $requete->whereNull('fusionne_dans_id');
    }
}
