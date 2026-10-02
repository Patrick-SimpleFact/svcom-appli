<?php

namespace App\Models;

use App\Enums\TypeParametre;
use App\Models\Concerns\IdentifiantNumerique;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class Parametre extends Model
{
    use IdentifiantNumerique;
    use Journalise;

    private const CLE_CACHE = 'parametres';

    protected $fillable = [
        'cle',
        'groupe',
        'libelle',
        'description',
        'type',
        'valeur',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeParametre::class,
            'valeur' => 'json',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CLE_CACHE));
        static::deleted(fn () => Cache::forget(self::CLE_CACHE));
    }

    /** Valeur d'un réglage (mise en cache, rechargée dès qu'un réglage change). */
    public static function valeur(string $cle): mixed
    {
        $tous = Cache::rememberForever(self::CLE_CACHE, fn () => self::query()->pluck('valeur', 'cle')->all());

        if (! array_key_exists($cle, $tous)) {
            throw new InvalidArgumentException("Réglage inconnu : {$cle}");
        }

        return $tous[$cle];
    }
}
