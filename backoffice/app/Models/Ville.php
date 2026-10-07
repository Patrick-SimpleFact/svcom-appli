<?php

namespace App\Models;

use App\Casts\PointGeographique;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ville extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $fillable = [
        'nom',
        'nom_normalise',
        'code_insee',
        'departement',
        'codes_postaux',
        'population',
        'position',
        'fuseau_horaire',
        'est_pilote',
    ];

    protected function casts(): array
    {
        return [
            'codes_postaux' => 'array',
            'population' => 'integer',
            'position' => PointGeographique::class,
            'est_pilote' => 'boolean',
        ];
    }

    /** Mesures de couverture, une par jour (F7.13). */
    public function couvertures(): HasMany
    {
        return $this->hasMany(Couverture::class);
    }
}
