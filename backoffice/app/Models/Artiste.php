<?php

namespace App\Models;

use App\Enums\TypeArtiste;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use App\Support\Texte;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Artiste extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    protected $fillable = ['nom', 'type', 'image_url', 'fusionne_dans_id'];

    protected function casts(): array
    {
        return ['type' => TypeArtiste::class];
    }

    protected function nom(): Attribute
    {
        return Attribute::make(set: fn (string $valeur): array => ['nom' => $valeur, 'nom_normalise' => Texte::normaliser($valeur)]);
    }

    public function spectacles(): BelongsToMany
    {
        return $this->belongsToMany(Spectacle::class, 'spectacle_artiste')->withPivot('role');
    }
}
