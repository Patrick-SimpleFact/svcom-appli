<?php

namespace App\Casts;

use App\Support\Point;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Colonne PostGIS geography(Point, 4326) ↔ objet Point.
 *
 * @implements CastsAttributes<Point, Point>
 */
class PointGeographique implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Point
    {
        return $value === null ? null : Point::depuisEwkb($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        // PostgreSQL convertit lui-même le texte EWKT en geography.
        return $value->versEwkt();
    }
}
